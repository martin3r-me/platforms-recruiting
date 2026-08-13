# HR-Desk Legal-Status-Guard — Design

**Datum:** 2026-07-09
**Modul:** platforms-recruiting
**Status:** Entwurf (zur Review)

## Problem

Auf dem HR-Schreibtisch (`HrDesk\Index`) kann ein `non_eu_citizen`-Fall über den
grünen „Freigeben"-Button aufgelöst werden, **ohne** dass der Rechtsstatus vorher
als geprüft markiert wurde. „Freigeben" (`HrDeskRoutingService::approveCase`) und
„Als geprüft markieren" (`toggleLegalStatusChecked` → `legal_status_checked_at`)
sind heute vollständig entkoppelt: `approveCase` liest oder erzwingt
`legal_status_checked_at` an keiner Stelle.

Folge nach einer Freigabe ohne Prüfung:

- `is_on_hr_desk` wird `false` → der Bewerber verschwindet vom HR-Schreibtisch,
  dem einzigen Ort, an dem „Rechtsstatus ungeprüft" angezeigt wird. Der ungeprüfte
  Zustand wird damit **unsichtbar**.
- Solange `legal_status_checked_at` leer ist, überspringt der `LegalStatusGate`
  den Bewerber weiterhin **still** im Vertrags-Bulk-Send und bei Schulungs-
  Remindern. Der Bewerber ist also „freigegeben", bleibt aber faktisch hängen —
  und niemand sieht mehr, warum.

## Ziel

Verhindern, dass ein `non_eu_citizen`-Fall freigegeben wird, bevor der Rechtsstatus
geprüft ist — als **harte Sperre** (kein Override). Die bewusste Zwei-Schritt-
Trennung (erst prüfen inkl. optionalem Zusatzvertrag, dann freigeben) bleibt
erhalten; es wird nur das Überspringen des Prüf-Schritts unterbunden.

## Nicht-Ziel / bewusste Abgrenzung

- Die zwei getrennten Zustände (`RecHrDeskCase` als Workflow-Artefakt vs.
  `legal_status_checked_at` als Compliance-Tatsache) werden **nicht**
  zusammengelegt. Sie modellieren Verschiedenes und sollen getrennt bleiben.
- Kein Auto-Setzen von `legal_status_checked_at` beim Freigeben (verworfene
  Option): „Freigeben" ist generisch über mehrere Fall-Gründe und würde die
  eigentliche Dokumentenprüfung still überspringen.
- Der Randfall „Bewerber mit unbeantwortetem EU-Status (`is_eu_citizen = null`)
  ohne `non_eu_citizen`-Fall" wird **nicht** mitgelöst (Scope-Creep). In der
  Praxis kaum relevant, da `eu_burger` Pflichtfeld der Onboarding-Phase ist,
  bevor überhaupt aufs HR-Desk geroutet wird.

## Architektur / Komponenten

### 1. Neue pure-testbare Kernlogik: `HrDeskApprovalGate`

Kleiner Gate analog zum bestehenden `LegalStatusGate` — reine Logik, keine DB,
keine Laravel-Abhängigkeit (entspricht der Test-Konvention des Moduls).

```php
// src/Services/HrDeskApprovalGate.php
namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecHrDeskCase;

class HrDeskApprovalGate
{
    /**
     * True, wenn die Freigabe blockiert werden muss: ein Nicht-EU-Fall,
     * dessen Rechtsstatus noch nicht geprüft ist.
     */
    public static function blocksApproval(string $reason, bool $isLegalStatusUnchecked): bool
    {
        return $reason === RecHrDeskCase::REASON_NON_EU_CITIZEN && $isLegalStatusUnchecked;
    }
}
```

Einzige Wahrheit für „darf dieser Fall freigegeben werden?". Service und UI fragen
beide hier.

### 2. Neue Exception: `LegalStatusNotCheckedException`

```php
// src/Exceptions/LegalStatusNotCheckedException.php
namespace Platform\Recruiting\Exceptions;

class LegalStatusNotCheckedException extends \DomainException
{
    public function __construct(public readonly int $applicantId)
    {
        parent::__construct('Rechtsstatus noch nicht geprüft — Freigabe nicht möglich.');
    }
}
```

### 3. Service = autoritative Instanz (`HrDeskRoutingService::approveCase`)

Guard-Clause ganz oben in `approveCase`, null-sicher:

```php
$applicant = $case->applicant;
if ($applicant && HrDeskApprovalGate::blocksApproval($case->reason, $applicant->isLegalStatusUnchecked())) {
    throw new LegalStatusNotCheckedException($case->rec_applicant_id);
}
```

Schützt **jeden Aufrufer von `approveCase`** — dem menschlichen „Freigeben".

**Wichtig — nur `approveCase` wird gegated, NICHT `autoCloseObsoleteCases`:**
Der Service hat einen zweiten Pfad, der Cases auf `approved` setzt und vom
HR-Desk entlässt: `autoCloseObsoleteCases()`. Dieser schließt einen Nicht-EU-Fall
**automatisch**, wenn die Routing-Bedingung wegfällt (z.B. Bewerber korrigiert den
EU-Status auf „EU-Bürger"). Er darf **bewusst nicht** gegated werden — er feuert
gerade *weil* die Nicht-EU-Bedingung obsolet ist, womit keine Rechtsprüfung mehr
nötig ist (bei EU-Bürger ist `isLegalStatusUnchecked()` ohnehin `false`, der Guard
wäre also selbst dann wirkungslos). Der Guard gehört ausschließlich auf den
menschlichen Approve-Pfad.

### 4. UI (`resources/views/livewire/hr-desk/index.blade.php`)

Im bestehenden `@php`-Block der Karte (wo `$legalStatus` / `$showLegalSection`
berechnet werden) zusätzlich vorberechnen — **nicht** inline im `@if` (bekannte
Blade-Component-Falle):

```php
$approveBlocked = $applicant
    && \Platform\Recruiting\Services\HrDeskApprovalGate::blocksApproval(
        $case->reason,
        $applicant->isLegalStatusUnchecked()
    );
```

Der „Freigeben"-Button ist ein rohes `<button>` (kein `x-ui-button`) — damit
umgehen wir die bekannte x-ui-Attribut-Falle. Umsetzung:
- `@disabled($approveBlocked)` am Button; emerald-Klassen bedingt gegen graue
  „disabled"-Klassen tauschen (im `@php`-Block vorberechnet, nicht inline).
- Kurzer Hinweis daneben: *„Erst Rechtsstatus prüfen"*.
- „Ablehnen" bleibt **immer** aktiv (Ablehnen ist nie blockiert).

### 5. Fehlerbehandlung (`HrDesk\Index::confirmResolve`)

Der `approveCase`-Aufruf wird in `try/catch` gekapselt. Fängt
`LegalStatusNotCheckedException` → freundliche Flash-Message („Rechtsstatus noch
nicht geprüft — bitte zuerst als geprüft markieren."), Modal schließen. Kein
Crash, selbst wenn der Button (z.B. durch Race/manipulierten Request) doch
ausgelöst würde.

## Datenfluss

1. HR öffnet den HR-Schreibtisch → `cases()` (computed) rendert Karten.
2. Pro Karte wird `$approveBlocked` berechnet.
3. Ist der Rechtsstatus ungeprüft (Nicht-EU): „Freigeben" disabled + Hinweis.
4. HR klickt „Als geprüft markieren" → `toggleLegalStatusChecked()` setzt
   `legal_status_checked_at` und ruft `unset($this->cases)` → Karte rendert neu →
   `$approveBlocked` wird `false` → „Freigeben" wird **automatisch** aktiv.
5. HR klickt „Freigeben" → Modal → `confirmResolve()` → `approveCase()` läuft
   ohne Guard-Treffer durch.

## Verhalten bei Mehrfach-Fällen (positiver Nebeneffekt)

Hat ein Bewerber sowohl einen `non_eu_citizen`- als auch z.B. einen
Deutschkenntnisse-Fall: Der Nicht-EU-Fall bleibt blockiert (offen), bis geprüft.
Da `approveCase` erst vom HR-Schreibtisch entlässt (`is_on_hr_desk = false`), wenn
**kein** offener Fall mehr existiert, bleibt der Bewerber sichtbar, bis der
Rechtsstatus tatsächlich geprüft ist. Genau das behebt das „unsichtbar +
ungeprüft"-Problem an der Wurzel.

## Fehler- und Randfälle

- **`$case->applicant === null`** (gelöschter Bewerber): Guard greift nicht
  (null-sicher), `approveCase` läuft wie bisher.
- **`is_eu_citizen === null`** ohne Nicht-EU-Fall: nicht abgedeckt (siehe
  Abgrenzung oben).
- **Ablehnen (`rejectCase`)**: nie blockiert.

## Tests

- **Pure-Unit** für `HrDeskApprovalGate::blocksApproval` (analog
  `LegalStatusGateTest`, ohne DB):
  - `non_eu_citizen` + ungeprüft → `true`
  - `non_eu_citizen` + geprüft → `false`
  - `no_german_knowledge` + ungeprüft → `false`
  - `applicant_cancelled_training` + ungeprüft → `false`
- Service-Throw und Blade-Verdrahtung sind dünn; die testbare Logik sitzt im
  Gate. DB-/Feature-Tests sind im Modul nicht möglich (kein testbench) — die
  Wiring-Ebene wird manuell verifiziert.
- Der Test nutzt `PHPUnit\Framework\TestCase` direkt (wie `LegalStatusGateTest`).
  Der Gate referenziert `RecHrDeskCase::REASON_NON_EU_CITIZEN` — das lädt nur die
  Klassendefinition (Konstante), keine DB. Leichte Kopplung, akzeptabel für die
  Single-Source-of-Truth.

## Betroffene Dateien

- **Neu:** `src/Services/HrDeskApprovalGate.php`
- **Neu:** `src/Exceptions/LegalStatusNotCheckedException.php`
- **Neu:** `tests/Unit/HrDeskApprovalGateTest.php`
- **Ändern:** `src/Services/HrDeskRoutingService.php` (`approveCase` Guard-Clause)
- **Ändern:** `src/Livewire/HrDesk/Index.php` (`confirmResolve` try/catch)
- **Ändern:** `resources/views/livewire/hr-desk/index.blade.php` (`$approveBlocked`,
  Button disabled + Hinweis)
