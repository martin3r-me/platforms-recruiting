# HR-Desk Legal-Status-Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Verhindern, dass ein `non_eu_citizen`-HR-Desk-Fall freigegeben wird, solange der Rechtsstatus nicht als geprüft markiert ist.

**Architecture:** Pure-testbarer `HrDeskApprovalGate` liefert die Entscheidung „darf freigegeben werden?". Der Service `approveCase` erzwingt sie autoritativ (wirft `LegalStatusNotCheckedException`), die Livewire-Komponente fängt die Exception freundlich ab, und die Blade-Karte deaktiviert den „Freigeben"-Button vorab. Der automatische `autoCloseObsoleteCases`-Pfad bleibt bewusst ungegated.

**Tech Stack:** PHP 8.4, Laravel/Livewire, Blade, PHPUnit 11.5 (pure-unit, kein testbench).

## Global Constraints

- PHP 8.4 / PHPUnit 11.5.55. Tests laufen **nur** als pure Unit-Tests (kein Laravel/DB/testbench) — Logik pure-unit-testbar schneiden.
- Test-Runner (vom Modul-Root `platforms-recruiting/` aus): `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter <Name>`
- Test-Namespace: `Platform\Recruiting\Tests\Unit`, Basisklasse `PHPUnit\Framework\TestCase`, Dateien unter `tests/Unit/`.
- Modul-Namespace: `Platform\Recruiting`.
- Blade-Falle: KEINE inline-`@if`/Ternaries in Component-Attributen — Werte im `@php`-Block vorberechnen.
- Der „Freigeben"-Button ist ein rohes `<button>` (kein `x-ui-button`) → `@disabled(...)` + `@class([...])` sind sicher nutzbar.
- Wir arbeiten auf Branch `feat/hr-desk-legal-status-guard`. Commits nur nach Freigabe durch den User.

## File Structure

- **Neu:** `src/Services/HrDeskApprovalGate.php` — pure Entscheidungslogik.
- **Neu:** `tests/Unit/HrDeskApprovalGateTest.php` — pure Unit-Tests dafür.
- **Neu:** `src/Exceptions/LegalStatusNotCheckedException.php` — Domain-Exception (Verzeichnis `src/Exceptions/` existiert noch nicht → wird angelegt).
- **Ändern:** `src/Services/HrDeskRoutingService.php` — Guard-Clause in `approveCase`.
- **Ändern:** `src/Livewire/HrDesk/Index.php` — `confirmResolve` fängt die Exception.
- **Ändern:** `resources/views/livewire/hr-desk/index.blade.php` — `$approveBlocked` + Button-Disable + Hinweis.

---

### Task 1: Pure-Gate `HrDeskApprovalGate` (TDD)

**Files:**
- Create: `src/Services/HrDeskApprovalGate.php`
- Test: `tests/Unit/HrDeskApprovalGateTest.php`

**Interfaces:**
- Consumes: `RecHrDeskCase::REASON_NON_EU_CITIZEN` (bestehende String-Konstante `'non_eu_citizen'`).
- Produces: `HrDeskApprovalGate::blocksApproval(string $reason, bool $isLegalStatusUnchecked): bool` — `true` = Freigabe muss blockiert werden.

- [ ] **Step 1: Failing Test schreiben**

`tests/Unit/HrDeskApprovalGateTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Services\HrDeskApprovalGate;

class HrDeskApprovalGateTest extends TestCase
{
    public function test_non_eu_unchecked_blocks_approval(): void
    {
        // Nicht-EU-Fall + Rechtsstatus ungeprüft → Freigabe blockieren.
        $this->assertTrue(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_NON_EU_CITIZEN, true)
        );
    }

    public function test_non_eu_checked_does_not_block(): void
    {
        // Nicht-EU-Fall, aber geprüft → Freigabe erlaubt.
        $this->assertFalse(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_NON_EU_CITIZEN, false)
        );
    }

    public function test_other_reasons_never_block_even_when_unchecked(): void
    {
        // Andere Fall-Gründe hängen nicht am Rechtsstatus → nie blockieren.
        $this->assertFalse(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE, true)
        );
        $this->assertFalse(
            HrDeskApprovalGate::blocksApproval(RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING, true)
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen → muss fehlschlagen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HrDeskApprovalGateTest`
Expected: FAIL — `Class "Platform\Recruiting\Services\HrDeskApprovalGate" not found`.

- [ ] **Step 3: Minimale Implementierung**

`src/Services/HrDeskApprovalGate.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * Entscheidet, ob die HR-Freigabe eines Falls blockiert werden muss, weil ein
 * Nicht-EU-Bewerber noch nicht rechtlich geprüft wurde. Reine Logik
 * (pure-unit-testbar), spiegelt das Muster von LegalStatusGate.
 *
 * WICHTIG: Nur der menschliche Approve-Pfad (HrDeskRoutingService::approveCase)
 * fragt hier. Der automatische autoCloseObsoleteCases-Pfad NICHT — er feuert nur,
 * wenn die Nicht-EU-Bedingung obsolet ist (Bewerber wurde EU), womit keine
 * Prüfung mehr nötig ist.
 */
class HrDeskApprovalGate
{
    /**
     * @param string $reason                Reason-Code des HR-Desk-Falls.
     * @param bool   $isLegalStatusUnchecked Ergebnis von RecApplicant::isLegalStatusUnchecked().
     */
    public static function blocksApproval(string $reason, bool $isLegalStatusUnchecked): bool
    {
        return $reason === RecHrDeskCase::REASON_NON_EU_CITIZEN && $isLegalStatusUnchecked;
    }
}
```

- [ ] **Step 4: Test laufen lassen → muss grün sein**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter HrDeskApprovalGateTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit** (nur nach User-Freigabe)

```bash
git add src/Services/HrDeskApprovalGate.php tests/Unit/HrDeskApprovalGateTest.php
git commit -m "feat(recruiting): HrDeskApprovalGate — pure Freigabe-Gate fuer Rechtsstatus

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Backend-Enforcement (Exception + Service-Guard + Livewire-Catch)

**Files:**
- Create: `src/Exceptions/LegalStatusNotCheckedException.php`
- Modify: `src/Services/HrDeskRoutingService.php` (`approveCase`, ab Zeile 209)
- Modify: `src/Livewire/HrDesk/Index.php` (`confirmResolve`, ab Zeile 123)

**Interfaces:**
- Consumes: `HrDeskApprovalGate::blocksApproval()` (Task 1); `RecApplicant::isLegalStatusUnchecked(): bool` (bestehend, public).
- Produces: `LegalStatusNotCheckedException` mit `public readonly int $applicantId`. `approveCase` wirft sie, wenn die Freigabe blockiert ist.

- [ ] **Step 1: Exception-Klasse anlegen**

`src/Exceptions/LegalStatusNotCheckedException.php`:

```php
<?php

namespace Platform\Recruiting\Exceptions;

/**
 * Wird von HrDeskRoutingService::approveCase geworfen, wenn ein
 * non_eu_citizen-Fall freigegeben werden soll, obwohl der Rechtsstatus noch
 * nicht geprüft ist.
 */
class LegalStatusNotCheckedException extends \DomainException
{
    public function __construct(public readonly int $applicantId)
    {
        parent::__construct('Rechtsstatus noch nicht geprüft — Freigabe nicht möglich.');
    }
}
```

- [ ] **Step 2: Guard-Clause in `approveCase` einbauen**

In `src/Services/HrDeskRoutingService.php` den Import ergänzen (zu den bestehenden `use`-Zeilen oben; `HrDeskApprovalGate` liegt im selben Namespace `Platform\Recruiting\Services` → kein Import nötig):

```php
use Platform\Recruiting\Exceptions\LegalStatusNotCheckedException;
```

Dann `approveCase` (aktuell Zeilen 209–218) so umbauen — `$applicant` nach oben ziehen und Guard davorsetzen. **Vorher:**

```php
    public function approveCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        $case->update([
            'status' => RecHrDeskCase::STATUS_APPROVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        $applicant = $case->applicant;

        // Only release from HR desk if no other open cases remain
```

**Nachher:**

```php
    public function approveCase(RecHrDeskCase $case, int $userId, ?string $notes = null): void
    {
        $applicant = $case->applicant;

        // Guard: Nicht-EU-Fall darf nicht freigegeben werden, solange der
        // Rechtsstatus ungeprüft ist. Nur dieser menschliche Approve-Pfad
        // wird gegated — autoCloseObsoleteCases bewusst NICHT.
        if ($applicant && HrDeskApprovalGate::blocksApproval($case->reason, $applicant->isLegalStatusUnchecked())) {
            throw new LegalStatusNotCheckedException($case->rec_applicant_id);
        }

        $case->update([
            'status' => RecHrDeskCase::STATUS_APPROVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_notes' => $notes,
        ]);

        // Only release from HR desk if no other open cases remain
```

(Die alte Zeile `$applicant = $case->applicant;` unterhalb des `$case->update` ist damit entfernt — sie wurde nach oben verschoben, nicht dupliziert.)

- [ ] **Step 3: `confirmResolve` die Exception abfangen lassen**

In `src/Livewire/HrDesk/Index.php` den Import ergänzen:

```php
use Platform\Recruiting\Exceptions\LegalStatusNotCheckedException;
```

`confirmResolve` (aktuell Zeilen 123–129) — **Vorher:**

```php
        if ($this->resolvingAction === 'approve') {
            $service->approveCase($case, $userId, $notes);
            session()->flash('message', 'Case freigegeben — Bewerber zurück im normalen Flow.');
        } else {
            $service->rejectCase($case, $userId, $notes);
            session()->flash('message', 'Bewerber abgelehnt.');
        }
```

**Nachher:**

```php
        if ($this->resolvingAction === 'approve') {
            try {
                $service->approveCase($case, $userId, $notes);
                session()->flash('message', 'Case freigegeben — Bewerber zurück im normalen Flow.');
            } catch (LegalStatusNotCheckedException) {
                session()->flash('message', 'Rechtsstatus noch nicht geprüft — bitte zuerst als geprüft markieren.');
            }
        } else {
            $service->rejectCase($case, $userId, $notes);
            session()->flash('message', 'Bewerber abgelehnt.');
        }
```

- [ ] **Step 4: Syntax-Lint + volle Pure-Suite (keine Regression)**

Run:
```bash
php -l src/Exceptions/LegalStatusNotCheckedException.php
php -l src/Services/HrDeskRoutingService.php
php -l src/Livewire/HrDesk/Index.php
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: `No syntax errors detected` für alle drei Dateien; PHPUnit-Suite grün (inkl. `HrDeskApprovalGateTest`, keine bestehenden Tests kaputt).

- [ ] **Step 5: Commit** (nur nach User-Freigabe)

```bash
git add src/Exceptions/LegalStatusNotCheckedException.php src/Services/HrDeskRoutingService.php src/Livewire/HrDesk/Index.php
git commit -m "feat(recruiting): approveCase verweigert Freigabe bei ungeprueftem Rechtsstatus

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: UI — „Freigeben" deaktivieren + Hinweis

**Files:**
- Modify: `resources/views/livewire/hr-desk/index.blade.php` (`@php`-Block ab Zeile 133; „Freigeben"-Button ab Zeile 195)

**Interfaces:**
- Consumes: `HrDeskApprovalGate::blocksApproval()` (Task 1); `$applicant`, `$case` (bestehende Loop-Variablen).
- Produces: `$approveBlocked` (bool) im `@php`-Block, sichtbar bis zum Button-Block derselben Schleifen-Iteration.

- [ ] **Step 1: `$approveBlocked` im bestehenden `@php`-Block vorberechnen**

`@php`-Block (Zeilen 133–139) — **Vorher:**

```php
                                @php
                                    $legalStatus = $applicant?->legalStatus;
                                    $showLegalSection = $applicant && (
                                        $case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NON_EU_CITIZEN
                                        || ($legalStatus && $legalStatus->is_eu_citizen === false)
                                    );
                                @endphp
```

**Nachher:**

```php
                                @php
                                    $legalStatus = $applicant?->legalStatus;
                                    $showLegalSection = $applicant && (
                                        $case->reason === \Platform\Recruiting\Models\RecHrDeskCase::REASON_NON_EU_CITIZEN
                                        || ($legalStatus && $legalStatus->is_eu_citizen === false)
                                    );
                                    $approveBlocked = $applicant
                                        && \Platform\Recruiting\Services\HrDeskApprovalGate::blocksApproval(
                                            $case->reason,
                                            $applicant->isLegalStatusUnchecked()
                                        );
                                @endphp
```

- [ ] **Step 2: „Freigeben"-Button deaktivieren + Hinweis**

Button (Zeilen 195–201) — **Vorher:**

```php
                                <button
                                    wire:click="openResolveModal({{ $case->id }}, 'approve')"
                                    class="px-3 py-1.5 text-sm font-medium rounded-md border border-emerald-200 text-emerald-700 bg-white hover:bg-emerald-50"
                                >
                                    @svg('heroicon-o-check', 'w-4 h-4 inline-block -mt-0.5')
                                    Freigeben
                                </button>
```

**Nachher:**

```php
                                <button
                                    wire:click="openResolveModal({{ $case->id }}, 'approve')"
                                    @disabled($approveBlocked)
                                    @class([
                                        'px-3 py-1.5 text-sm font-medium rounded-md border',
                                        'border-emerald-200 text-emerald-700 bg-white hover:bg-emerald-50' => ! $approveBlocked,
                                        'border-gray-200 text-gray-400 bg-gray-50 cursor-not-allowed' => $approveBlocked,
                                    ])
                                >
                                    @svg('heroicon-o-check', 'w-4 h-4 inline-block -mt-0.5')
                                    Freigeben
                                </button>
                                @if($approveBlocked)
                                    <span class="text-xs text-amber-700 text-center">Erst Rechtsstatus prüfen</span>
                                @endif
```

- [ ] **Step 3: Manuelle Verifikation (kein Browser-Test im Modul)**

1. HR-Schreibtisch öffnen mit einem **Nicht-EU-Fall, Rechtsstatus ungeprüft** (z.B. Bewerber wie in der Analyse).
   Erwartet: „Freigeben" ist ausgegraut/`disabled`, darunter „Erst Rechtsstatus prüfen". „Ablehnen" bleibt aktiv.
2. „Als geprüft markieren" klicken.
   Erwartet: Karte rendert neu (`toggleLegalStatusChecked` ruft `unset($this->cases)`), „Freigeben" wird **automatisch** grün/aktiv.
3. Jetzt „Freigeben" → Modal → bestätigen.
   Erwartet: Fall wird freigegeben, Flash „Case freigegeben …".
4. Gegenprobe Backstop: Falls möglich, `confirmResolve` mit `approve` auf einem noch ungeprüften Nicht-EU-Fall auslösen (Race) → Flash „Rechtsstatus noch nicht geprüft …", kein Crash.

- [ ] **Step 4: Commit** (nur nach User-Freigabe)

```bash
git add resources/views/livewire/hr-desk/index.blade.php
git commit -m "feat(recruiting): HR-Desk 'Freigeben' gesperrt bei ungeprueftem Rechtsstatus

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Neue pure Kernlogik `HrDeskApprovalGate` → Task 1. ✓
- Exception `LegalStatusNotCheckedException` → Task 2, Step 1. ✓
- Service-Guard in `approveCase` (nur menschlicher Pfad, `autoCloseObsoleteCases` ausgenommen) → Task 2, Step 2 + Doc-Kommentar. ✓
- `confirmResolve` try/catch → Task 2, Step 3. ✓
- UI Button-Disable + Hinweis, raw `<button>` + `@disabled`/`@class`, `$approveBlocked` im `@php`-Block vorberechnet → Task 3. ✓
- Reaktivität (Button aktiviert sich nach „Als geprüft markieren") → Task 3, Step 3 Verifikation. ✓
- Tests: pure-unit für Gate, Wiring manuell → Task 1 (auto) + Task 2 Step 4 (lint + Suite) + Task 3 Step 3 (manuell). ✓

**Placeholder scan:** Keine TBD/TODO; jeder Code-Step zeigt vollständigen Code. ✓

**Type consistency:** `blocksApproval(string, bool): bool` einheitlich in Task 1/2/3. `LegalStatusNotCheckedException(int $applicantId)` konsistent geworfen (Task 2/2) und gefangen (Task 2/3). `isLegalStatusUnchecked(): bool` (bestehend). ✓
