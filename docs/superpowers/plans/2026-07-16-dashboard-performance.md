# Dashboard-Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recruiting-Dashboard von ~1000+ Queries pro Render auf ~30–40 bringen — verlustfrei, einzige sichtbare Änderung: „Abgeschlossen" ist initial eingeklappt.

**Architecture:** Drei pure, DB-freie Service-Klassen (`DashboardChangeToken`, `WhatsAppWindowResolver`, `ExtraFieldCounts`) kapseln die testbare Logik. Die Livewire-Component `Dashboard` bekommt zwei Batch-Computeds (`whatsAppWindowMap`, `extraFieldCountsMap`), einen Dirty-Check-Poll (`checkForUpdates` + `skipRender`) und eine lazy Abgeschlossen-Sektion. Task 1 nagelt den geteilten Vertrag fest (ID-Sammel-Helper, zentrale Cache-Invalidierung, changeToken-Lifecycle); Task 5 macht das Wiring mit Kenntnis dieses Vertrags.

**Tech Stack:** Laravel/Livewire v3.8.1, PHPUnit 11 (pure, ohne Laravel-Bootstrap — `tests/bootstrap.php`-Autoloader), MySQL live.

**Spec:** `docs/superpowers/specs/2026-07-16-dashboard-performance-design.md`

## Global Constraints

- **Nur `platforms-recruiting` editieren.** Core (`platforms-core`) und CRM (`platform-crm`) werden ausschließlich gelesen/gequeried, nie editiert.
- **Tests sind reines PHPUnit ohne Laravel/DB** (Repo-Konvention). Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` aus dem Modul-Root (`/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting`). Das Modul hat KEIN eigenes `vendor/`.
- **Pure Klassen dürfen nichts aus Laravel importieren** — der Test-Autoloader lädt nur `Platform\Recruiting\*` aus `src/` und `tests/`. Keine Facades, kein Eloquent, kein `now()`.
- **Livewire 3.8.1:** `render()` läuft bei JEDEM Request; der No-Op-Zweig des Polls MUSS `$this->skipRender()` aufrufen.
- **Blade-Pitfalls (Repo-Erfahrung):** In `x-ui-*`-Komponenten-Attributen kein inline `@if` und keine `??`-Fallbacks; `@php` immer als Block (`@php ... @endphp`), NIE als inline `@php($x)` — sonst ParseError.
- **Kein `gh` CLI, keine PRs.** Commits lokal auf dem Feature-Branch; Merge/Push erst nach Freigabe durch den User.
- **`unset($this->foo)` auf noch-nicht-existierende Computed-Namen ist in Livewire 3 ein No-Op** (verifiziert: `SupportComputed`-Handler prüfen `hasComputedProperty` und fallen sonst durch). Deshalb darf Task 1 die vollständige Invalidierungs-Liste schreiben, bevor alle Computeds existieren.
- Commit-Messages enden mit `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

## Setup (vor Task 1)

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git status   # muss clean sein bzgl. src/, resources/, tests/ (docs/-Untracked ist ok)
git checkout -b feat/dashboard-performance origin/main
```

---

### Task 1: Geteilter Vertrag — DashboardChangeToken (pure) + Component-Grundgerüst

**Files:**
- Create: `src/Services/Dashboard/DashboardChangeToken.php`
- Test: `tests/Unit/Dashboard/DashboardChangeTokenTest.php`
- Modify: `src/Livewire/Dashboard/Dashboard.php`

**Interfaces:**
- Consumes: nichts (erster Task).
- Produces (spätere Tasks verlassen sich exakt hierauf):
  - `DashboardChangeToken::build(array $counters, array $enrichingIds, string $timeBucket): string`
  - Component-Properties: `public string $changeToken = ''`, `public bool $showCompleted = false`, `public int $completedLimit = 25`
  - `private function visibleApplicantIds(): array` — IDs aller aktuell gerenderten Bewerber (inkl. Completed nur bei `showCompleted`)
  - `private function clearApplicantCaches(): void` — EINZIGE Stelle für Listen-/Map-Invalidierung; enthält bereits `whatsAppWindowMap`, `extraFieldCountsMap`, `completedCount` (existieren erst ab Task 2/5 — No-Op bis dahin, siehe Global Constraints)
  - `private function buildChangeToken(): string` — 4 leichte Queries + Cache-Lookups, fasst KEINE schweren Computeds an
  - `refreshDashboard()` setzt am Ende `$this->changeToken = $this->buildChangeToken();` (Lifecycle-Vertrag: JEDER Voll-Refresh aktualisiert das Token)
  - `mount()` setzt Baseline-Token

- [ ] **Step 1: Failing Test schreiben**

`tests/Unit/Dashboard/DashboardChangeTokenTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Dashboard\DashboardChangeToken;

class DashboardChangeTokenTest extends TestCase
{
    public function test_gleiche_inputs_ergeben_gleiches_token(): void
    {
        $a = DashboardChangeToken::build([5, '2026-07-16 10:00:00', 2, null, 0, null], [7, 3], '2026-07-16 10');
        $b = DashboardChangeToken::build([5, '2026-07-16 10:00:00', 2, null, 0, null], [7, 3], '2026-07-16 10');
        $this->assertSame($a, $b);
    }

    public function test_enriching_ids_sind_reihenfolge_unabhaengig(): void
    {
        $a = DashboardChangeToken::build([1], [3, 7], 'b');
        $b = DashboardChangeToken::build([1], [7, 3], 'b');
        $this->assertSame($a, $b);
    }

    public function test_jede_input_aenderung_aendert_das_token(): void
    {
        $base = DashboardChangeToken::build([5, 'x'], [1], 'bucket');
        $this->assertNotSame($base, DashboardChangeToken::build([6, 'x'], [1], 'bucket'), 'Counter');
        $this->assertNotSame($base, DashboardChangeToken::build([5, 'y'], [1], 'bucket'), 'Timestamp');
        $this->assertNotSame($base, DashboardChangeToken::build([5, 'x'], [1, 2], 'bucket'), 'Enriching-IDs');
        $this->assertNotSame($base, DashboardChangeToken::build([5, 'x'], [1], 'anders'), 'Zeitbucket');
    }

    public function test_null_und_leer_unterscheidbar(): void
    {
        // MAX(updated_at) auf leerer Tabelle = null; darf nicht mit 0/'' kollidieren
        $this->assertNotSame(
            DashboardChangeToken::build([0, null], [], 'b'),
            DashboardChangeToken::build([0, 0], [], 'b')
        );
        $this->assertNotSame(
            DashboardChangeToken::build([0, null], [], 'b'),
            DashboardChangeToken::build([0, ''], [], 'b')
        );
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DashboardChangeTokenTest
```
Expected: FAIL/ERROR — `Class "Platform\Recruiting\Services\Dashboard\DashboardChangeToken" not found`

- [ ] **Step 3: Implementierung**

`src/Services/Dashboard/DashboardChangeToken.php`:

```php
<?php

namespace Platform\Recruiting\Services\Dashboard;

/**
 * Baut das Change-Token für den Dashboard-Dirty-Check-Poll.
 * Pure: Inputs rein, deterministischer Hash raus — keine DB, kein Laravel.
 */
class DashboardChangeToken
{
    /**
     * @param array $counters      flache COUNT/MAX-Werte (int|string|null) in fester Reihenfolge
     * @param array $enrichingIds  Bewerber-IDs mit laufendem Enrichment (Reihenfolge egal)
     * @param string $timeBucket   grober Zeit-Bucket, z. B. now()->format('Y-m-d H')
     */
    public static function build(array $counters, array $enrichingIds, string $timeBucket): string
    {
        $enrichingIds = array_values($enrichingIds);
        sort($enrichingIds);

        return hash('sha256', json_encode([
            array_values($counters),
            $enrichingIds,
            $timeBucket,
        ]));
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DashboardChangeTokenTest
```
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Component-Vertrag in Dashboard.php**

Alle Änderungen in `src/Livewire/Dashboard/Dashboard.php`:

**(a)** Import ergänzen (bei den anderen `use`-Statements):

```php
use Platform\Recruiting\Services\Dashboard\DashboardChangeToken;
```

**(b)** Properties ergänzen (nach `public array $activityStatsUniqueTotals = [];`):

```php
public string $changeToken = '';
public bool $showCompleted = false;
public int $completedLimit = 25;
```

**(c)** `mount()` erweitern — Baseline-Token (Vertrag: erster Poll darf keinen Refresh feuern):

```php
public function mount(): void
{
    $this->showParked = request()->routeIs('recruiting.dashboard.parked');
    $this->showHrDesk = request()->routeIs('recruiting.dashboard.hr-desk');
    $this->changeToken = $this->buildChangeToken();
}
```

**(d)** Neue private Helper einfügen (z. B. direkt nach `applicantBaseQuery()`):

```php
/**
 * IDs aller Bewerber, die im aktuellen Render sichtbar sind. Grundlage
 * für die Batch-Maps (WhatsApp-Fenster, Extra-Feld-Zähler). Greift auf
 * die Listen-Computeds zu — die sind im Render ohnehin fällig, der
 * Zugriff hier kostet also keine zusätzlichen Queries.
 */
private function visibleApplicantIds(): array
{
    $ids = collect($this->phasedApplicants)->flatten()->pluck('id')
        ->merge($this->inboxApplicants->pluck('id'))
        ->merge($this->needsReviewApplicants->pluck('id'));

    if ($this->showCompleted) {
        $ids = $ids->merge($this->completedApplicants->pluck('id'));
    }

    return $ids->unique()->values()->all();
}

/**
 * Vertrag: EINE zentrale Stelle für die Invalidierung der Bewerber-Listen
 * und aller davon abgeleiteten Batch-Maps. Bewusst über-invalidierend —
 * ein unnötiger Recompute ist billig, eine stale Map wäre ein Bug.
 * whatsAppWindowMap/extraFieldCountsMap/completedCount existieren erst ab
 * späteren Tasks; unset auf unbekannte Namen ist in Livewire 3 ein No-Op.
 */
private function clearApplicantCaches(): void
{
    unset(
        $this->inboxApplicants,
        $this->needsReviewApplicants,
        $this->activeApplicants,
        $this->completedApplicants,
        $this->phasedApplicants,
        $this->applicantCount,
        $this->autoPilotProcessingIds,
        $this->enrichingApplicantIds,
        $this->whatsAppWindowMap,
        $this->extraFieldCountsMap,
        $this->completedCount,
    );
}

/**
 * Change-Token für den Dirty-Check-Poll. Nur leichte Queries
 * (COUNT/MAX je Tabelle + ID-only-Inbox-Query) — fasst bewusst KEINE
 * schweren Computeds an. Scope bewusst breiter als das Dashboard
 * (ohne withoutImports etc.): Über-Triggern kostet einen Refresh,
 * Unter-Triggern wäre der echte Fehler.
 */
private function buildChangeToken(): string
{
    $teamId = auth()->user()->currentTeam->id;

    $applicants = RecApplicant::forTeam($teamId)
        ->selectRaw('COUNT(*) AS c, MAX(updated_at) AS m')->first();
    $bookings = RecInterviewBooking::query()->where('team_id', $teamId)
        ->selectRaw('COUNT(*) AS c, MAX(updated_at) AS m')->first();
    $contracts = RecContract::query()->where('team_id', $teamId)
        ->selectRaw('COUNT(*) AS c, MAX(updated_at) AS m')->first();

    $inboxIds = $this->applicantBaseQuery()
        ->where(fn ($q) => $q->whereNull('enrichment_status')->orWhere('enrichment_status', ''))
        ->pluck('rec_applicants.id')
        ->all();
    $enrichingIds = array_values(array_filter(
        $inboxIds,
        fn ($id) => Cache::has("enrichment:processing:{$id}")
    ));

    return DashboardChangeToken::build(
        [$applicants->c, $applicants->m, $bookings->c, $bookings->m, $contracts->c, $contracts->m],
        $enrichingIds,
        now()->format('Y-m-d H'),
    );
}
```

**(e)** `refreshDashboard()` komplett ersetzen (nutzt den zentralen Helper und schließt den Token-Lifecycle):

```php
public function refreshDashboard(): void
{
    $this->clearApplicantCaches();
    unset(
        $this->positionCount,
        $this->postingCount,
        $this->teamChannels,
        $this->positions,
        $this->phases,
        $this->availablePostings,
        $this->availableActivities,
        $this->statsApplicantPool,
        $this->positionStats,
        $this->activityStats,
        $this->timeToHire,
        $this->stuckCounts,
        $this->hrDeskCount,
    );
    // Lifecycle-Vertrag: jeder Voll-Refresh aktualisiert das Token —
    // sonst triggert jeder Folge-Poll ewig einen weiteren Refresh.
    $this->changeToken = $this->buildChangeToken();
}
```

**(f)** In den Action-Methoden die verstreuten `unset(...)`-Listen durch den zentralen Helper ersetzen. Betroffen sind exakt diese Methoden (jeweils den kompletten bisherigen `unset(...)`-Aufruf durch `$this->clearApplicantCaches();` ersetzen; `$this->dispatch('sidebar-refresh')` bleibt, wo vorhanden):

- `advanceToNextPhase()` (bisher: activeApplicants, completedApplicants, phasedApplicants)
- `parkApplicant()` (bisher: inbox, needsReview, active, completed, applicantCount, autoPilotProcessingIds, phasedApplicants)
- `unparkApplicant()`
- `toggleAutoPilot()`
- `retryEnrichment()`
- `assignPosting()`
- `deleteApplicant()`
- `deleteAndBlacklistApplicant()`

- [ ] **Step 6: Syntax-Check + kompletter Testlauf**

```bash
php -l src/Livewire/Dashboard/Dashboard.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: `No syntax errors detected` und alle Tests grün.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Dashboard/DashboardChangeToken.php tests/Unit/Dashboard/DashboardChangeTokenTest.php src/Livewire/Dashboard/Dashboard.php
git commit -m "feat(recruiting): Dashboard-Vertrag — ChangeToken, zentrale Cache-Invalidierung, ID-Helper

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: WhatsApp-Fenster batchen — WhatsAppWindowResolver (pure) + whatsAppWindowMap

**Files:**
- Create: `src/Services/Dashboard/WhatsAppWindowResolver.php`
- Test: `tests/Unit/Dashboard/WhatsAppWindowResolverTest.php`
- Modify: `src/Livewire/Dashboard/Dashboard.php` (neues Computed + `getWhatsAppStatus()`-Umbau)

**Interfaces:**
- Consumes: `visibleApplicantIds(): array` und `clearApplicantCaches()` aus Task 1 (Map ist dort schon in der Invalidierungs-Liste).
- Produces:
  - `WhatsAppWindowResolver::windowMap(array $lastInboundByApplicantId, \DateTimeImmutable $now): array` — `[applicant_id => bool]`
  - Computed `whatsAppWindowMap(): array` — `[applicant_id => bool]` für alle sichtbaren Bewerber
  - `getWhatsAppStatus(RecApplicant $applicant): array` — Signatur und Rückgabeformat UNVERÄNDERT (`['color' => ..., 'status' => ..., 'window_open' => ...]`), die View bleibt unangetastet.

**Referenz-Semantik (aus CRM, read-only):** `CommsWhatsAppThread::isWindowOpen()` = `last_inbound_at !== null && last_inbound_at > now - 24h` (strikt größer). Die bisherige Einzelquery nahm den Thread mit `orderByDesc(last_inbound_at)->first()` — `MAX(last_inbound_at)` ist äquivalent (NULL-only ⇒ beide false).

- [ ] **Step 1: Failing Test schreiben**

`tests/Unit/Dashboard/WhatsAppWindowResolverTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Dashboard\WhatsAppWindowResolver;

class WhatsAppWindowResolverTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-16 12:00:00');
    }

    public function test_inbound_vor_23h_ist_offen(): void
    {
        $map = WhatsAppWindowResolver::windowMap([7 => '2026-07-15 13:00:00'], $this->now);
        $this->assertSame([7 => true], $map);
    }

    public function test_inbound_vor_25h_ist_zu(): void
    {
        $map = WhatsAppWindowResolver::windowMap([7 => '2026-07-15 11:00:00'], $this->now);
        $this->assertSame([7 => false], $map);
    }

    public function test_exakt_24h_ist_zu(): void
    {
        // isWindowOpen nutzt striktes greaterThan — exakt 24h alt = zu
        $map = WhatsAppWindowResolver::windowMap([7 => '2026-07-15 12:00:00'], $this->now);
        $this->assertSame([7 => false], $map);
    }

    public function test_null_ist_zu(): void
    {
        $map = WhatsAppWindowResolver::windowMap([7 => null], $this->now);
        $this->assertSame([7 => false], $map);
    }

    public function test_string_keys_werden_int(): void
    {
        $map = WhatsAppWindowResolver::windowMap(['7' => '2026-07-16 11:00:00'], $this->now);
        $this->assertSame([7 => true], $map);
    }

    public function test_leere_map(): void
    {
        $this->assertSame([], WhatsAppWindowResolver::windowMap([], $this->now));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter WhatsAppWindowResolverTest
```
Expected: FAIL/ERROR — Klasse nicht gefunden.

- [ ] **Step 3: Implementierung**

`src/Services/Dashboard/WhatsAppWindowResolver.php`:

```php
<?php

namespace Platform\Recruiting\Services\Dashboard;

/**
 * Entscheidet pro Bewerber, ob das 24h-WhatsApp-Fenster offen ist.
 * Semantik identisch zu CommsWhatsAppThread::isWindowOpen():
 * last_inbound_at !== null && last_inbound_at > now - 24h (strikt).
 * Pure: Timestamp-Map rein, Bool-Map raus — keine DB, kein Laravel.
 */
class WhatsAppWindowResolver
{
    /**
     * @param array<int|string, string|null> $lastInboundByApplicantId MAX(last_inbound_at) je Bewerber
     * @return array<int, bool> applicant_id => Fenster offen
     */
    public static function windowMap(array $lastInboundByApplicantId, \DateTimeImmutable $now): array
    {
        $cutoff = $now->sub(new \DateInterval('PT24H'));

        $map = [];
        foreach ($lastInboundByApplicantId as $id => $lastInbound) {
            $map[(int) $id] = $lastInbound !== null
                && new \DateTimeImmutable($lastInbound) > $cutoff;
        }

        return $map;
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter WhatsAppWindowResolverTest
```
Expected: `OK (6 tests, ...)`

- [ ] **Step 5: Computed + getWhatsAppStatus-Umbau in Dashboard.php**

**(a)** Import ergänzen:

```php
use Platform\Recruiting\Services\Dashboard\WhatsAppWindowResolver;
```

**(b)** Neues Computed einfügen (z. B. nach `autoPilotProcessingIds()`):

```php
/**
 * Batch: MAX(last_inbound_at) je sichtbarem Bewerber in EINER Query
 * statt einer Thread-Query pro Karte. context_model deckt beide
 * historischen Schreibweisen ab (Morph-Alias + FQCN); groupBy nur auf
 * context_model_id merged beide Varianten aufs MAX.
 */
#[Computed]
public function whatsAppWindowMap(): array
{
    $ids = $this->visibleApplicantIds();
    if (empty($ids)) {
        return [];
    }

    $lastInbound = CommsWhatsAppThread::query()
        ->whereIn('context_model', [(new RecApplicant())->getMorphClass(), RecApplicant::class])
        ->whereIn('context_model_id', $ids)
        ->groupBy('context_model_id')
        ->selectRaw('context_model_id, MAX(last_inbound_at) AS max_last_inbound_at')
        ->pluck('max_last_inbound_at', 'context_model_id')
        ->all();

    return WhatsAppWindowResolver::windowMap($lastInbound, now()->toImmutable());
}
```

**(c)** In `getWhatsAppStatus()` den kompletten Thread-Query-Block ersetzen — von `$windowOpen = false;` bis einschließlich dem `if ($thread && $thread->isWindowOpen()) { ... }`-Block (inkl. der Zeilen `$morphClass = ...` und `$fullClass = ...`) durch:

```php
// 24h-Fenster: gebatcht über whatsAppWindowMap (eine Query für alle Karten)
$windowOpen = $this->whatsAppWindowMap[$applicant->id] ?? false;
```

Der Rest der Methode (Telefon-Suche, Verfügbarkeits-Check, Return-Arrays) bleibt unverändert. Der Import `CommsWhatsAppThread` bleibt (wird jetzt vom Computed genutzt).

- [ ] **Step 6: Syntax-Check + kompletter Testlauf**

```bash
php -l src/Livewire/Dashboard/Dashboard.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: keine Syntax-Fehler, alle Tests grün.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Dashboard/WhatsAppWindowResolver.php tests/Unit/Dashboard/WhatsAppWindowResolverTest.php src/Livewire/Dashboard/Dashboard.php
git commit -m "perf(recruiting): WhatsApp-Fenster-Status gebatcht — 1 Query statt 1 pro Karte

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Extra-Feld-Zähler batchen — ExtraFieldCounts (pure) + extraFieldCountsMap

**Files:**
- Create: `src/Services/Dashboard/ExtraFieldCounts.php`
- Test: `tests/Unit/Dashboard/ExtraFieldCountsTest.php`
- Modify: `src/Livewire/Dashboard/Dashboard.php` (Computed, `getExtraFieldCounts()`-Umbau, Eager-Loads)

**Interfaces:**
- Consumes: `clearApplicantCaches()` aus Task 1 (Map ist dort schon in der Liste).
- Produces:
  - `ExtraFieldCounts::forApplicant(array $definitionIds, array $valuesByDefinitionId): array` — `['filled' => int, 'total' => int]`
  - Computed `extraFieldCountsMap(): array` — `[applicant_id => ['filled' => int, 'total' => int]]`
  - `getExtraFieldCounts(RecApplicant $applicant): array` — Signatur/Format UNVERÄNDERT, View bleibt unangetastet.

**Referenz-Semantik (heutiger Code):** total = Anzahl aller geltenden Definitionen; filled = Werte, die nicht `null`/`''`/`[]`/`'[]'` sind. Definitionsmenge ist reine Funktion von `rec_phase_id` (bzw. Import-Flag); instanzspezifische Definitionen (`context_type = RecApplicant`, `context_id = applicant.id`) sind der einzige Sonderfall.

- [ ] **Step 1: Failing Test schreiben**

`tests/Unit/Dashboard/ExtraFieldCountsTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Dashboard\ExtraFieldCounts;

class ExtraFieldCountsTest extends TestCase
{
    public function test_zaehlt_gefuellte_und_gesamt(): void
    {
        $this->assertSame(
            ['filled' => 2, 'total' => 3],
            ExtraFieldCounts::forApplicant([1, 2, 3], [1 => 'Köln', 2 => 42.0, 3 => null])
        );
    }

    public function test_leere_werte_zaehlen_nicht(): void
    {
        $this->assertSame(
            ['filled' => 0, 'total' => 4],
            ExtraFieldCounts::forApplicant([1, 2, 3, 4], [1 => null, 2 => '', 3 => [], 4 => '[]'])
        );
    }

    public function test_werte_ohne_definition_zaehlen_nicht(): void
    {
        // Wert zu einer Definition, die nicht (mehr) gilt → ignoriert
        $this->assertSame(
            ['filled' => 1, 'total' => 1],
            ExtraFieldCounts::forApplicant([1], [1 => 'x', 99 => 'verwaist'])
        );
    }

    public function test_falsy_aber_gefuellte_werte_zaehlen(): void
    {
        // 0, false, '0' sind echte Werte (heutige Semantik: nur null/''/[]/'[]' sind leer)
        $this->assertSame(
            ['filled' => 3, 'total' => 3],
            ExtraFieldCounts::forApplicant([1, 2, 3], [1 => 0.0, 2 => false, 3 => '0'])
        );
    }

    public function test_keine_definitionen(): void
    {
        $this->assertSame(['filled' => 0, 'total' => 0], ExtraFieldCounts::forApplicant([], []));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ExtraFieldCountsTest
```
Expected: FAIL/ERROR — Klasse nicht gefunden.

- [ ] **Step 3: Implementierung**

`src/Services/Dashboard/ExtraFieldCounts.php`:

```php
<?php

namespace Platform\Recruiting\Services\Dashboard;

/**
 * Zählt gefüllte/gesamt Extra-Felder für den Dashboard-Badge.
 * Semantik identisch zum bisherigen Dashboard::getExtraFieldCounts():
 * total = Anzahl geltender Definitionen, filled = Werte, die nicht
 * null/''/[]/'[]' sind. Pure: Arrays rein, Array raus.
 */
class ExtraFieldCounts
{
    /**
     * @param array $definitionIds  IDs aller für den Bewerber geltenden Definitionen
     * @param array<int|string, mixed> $valuesByDefinitionId  definition_id => typed_value
     * @return array{filled: int, total: int}
     */
    public static function forApplicant(array $definitionIds, array $valuesByDefinitionId): array
    {
        $filled = 0;
        foreach ($definitionIds as $definitionId) {
            $value = $valuesByDefinitionId[$definitionId] ?? null;
            if ($value !== null && $value !== '' && $value !== [] && $value !== '[]') {
                $filled++;
            }
        }

        return ['filled' => $filled, 'total' => count($definitionIds)];
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ExtraFieldCountsTest
```
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Computed + Umbau in Dashboard.php**

**(a)** Imports ergänzen:

```php
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Services\Dashboard\ExtraFieldCounts;
```

**(b)** Neues Computed einfügen (nach `whatsAppWindowMap()`):

```php
/**
 * Batch: Extra-Feld-Zähler für alle Karten mit Badge (Inbox, NeedsReview,
 * Phasen-Boards — Abgeschlossen rendert keinen Badge). Die Definitions-
 * Liste ist eine reine Funktion der Phase (extraFieldParents) und wird
 * einmal pro Gruppe über einen Repräsentanten aufgelöst — die Core-
 * Merge-Logik wird nicht dupliziert. Werte kommen aus den eager-
 * geladenen extraFieldValues (mit .definition — typed_value braucht den
 * Definition-Type). Bewerber mit instanzspezifischen Definitionen
 * (praktisch: keine) fallen auf den Einzelpfad zurück.
 */
#[Computed]
public function extraFieldCountsMap(): array
{
    $applicants = collect($this->phasedApplicants)->flatten()
        ->merge($this->inboxApplicants)
        ->merge($this->needsReviewApplicants)
        ->unique('id')
        ->values();

    if ($applicants->isEmpty()) {
        return [];
    }

    $instanceSpecificIds = CoreExtraFieldDefinition::query()
        ->where('context_type', RecApplicant::class)
        ->whereIn('context_id', $applicants->pluck('id'))
        ->pluck('context_id')
        ->flip();

    $definitionIdsByGroup = [];
    $map = [];
    foreach ($applicants as $applicant) {
        if (isset($instanceSpecificIds[$applicant->id])) {
            $map[$applicant->id] = $this->legacyExtraFieldCounts($applicant);
            continue;
        }

        $group = $applicant->import_source
            ? 'import'
            : 'phase_' . ($applicant->rec_phase_id ?? 'none');
        if (!array_key_exists($group, $definitionIdsByGroup)) {
            $definitionIdsByGroup[$group] = $applicant->getExtraFieldDefinitions()->pluck('id')->all();
        }

        $values = $applicant->extraFieldValues
            ->mapWithKeys(fn ($v) => [$v->definition_id => $v->typed_value])
            ->all();

        $map[$applicant->id] = ExtraFieldCounts::forApplicant($definitionIdsByGroup[$group], $values);
    }

    return $map;
}
```

**(c)** `getExtraFieldCounts()` ersetzen (Signatur bleibt, View unverändert):

```php
public function getExtraFieldCounts(RecApplicant $applicant): array
{
    return $this->extraFieldCountsMap[$applicant->id] ?? $this->legacyExtraFieldCounts($applicant);
}

/**
 * Einzelpfad (bisheriges Verhalten) — nur noch Fallback für Bewerber mit
 * instanzspezifischen Definitionen oder außerhalb der Batch-Listen.
 */
private function legacyExtraFieldCounts(RecApplicant $applicant): array
{
    $fields = $applicant->getExtraFieldsWithLabels();
    $total = count($fields);
    $filled = collect($fields)->filter(function ($f) {
        $v = $f['value'];
        return $v !== null && $v !== '' && $v !== [] && $v !== '[]';
    })->count();
    return ['filled' => $filled, 'total' => $total];
}
```

**(d)** Eager-Loads umstellen: In `phasedApplicants()`, `inboxApplicants()`, `needsReviewApplicants()` und `activeApplicants()` jeweils `'extraFieldValues',` durch `'extraFieldValues.definition',` ersetzen (4 Stellen — `completedApplicants` bewusst NICHT, siehe Task 5). Grund: `typed_value` liest `$this->definition?->type` (CoreExtraFieldValue.php:103) — ohne Eager-Load würde jeder Wert einzeln lazy-loaden.

- [ ] **Step 6: Syntax-Check + kompletter Testlauf**

```bash
php -l src/Livewire/Dashboard/Dashboard.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: keine Syntax-Fehler, alle Tests grün.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Dashboard/ExtraFieldCounts.php tests/Unit/Dashboard/ExtraFieldCountsTest.php src/Livewire/Dashboard/Dashboard.php
git commit -m "perf(recruiting): Extra-Feld-Zähler gebatcht — Definitionen 1x pro Phase, Werte aus Eager-Load

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Stats-Pool nur einmal laden

**Files:**
- Modify: `src/Livewire/Dashboard/Dashboard.php` (nur `statsApplicantPool` + 2 Call-Sites)

**Interfaces:**
- Consumes: `refreshDashboard()` aus Task 1 hat `unset($this->statsApplicantPool)` bereits in der Liste.
- Produces: Computed `statsApplicantPool` (Eloquent Collection) — `positionStats` und `activityStats` teilen sich denselben geladenen Pool.

- [ ] **Step 1: Methode zum Computed machen**

In `src/Livewire/Dashboard/Dashboard.php` die Zeilen

```php
    private function statsApplicantPool()
    {
```

ersetzen durch:

```php
    /**
     * Gemeinsamer Pool für positionStats UND activityStats — als Computed,
     * damit er pro Request nur EINMAL geladen wird (vorher: 2x identische
     * Voll-Ladung des Team-Bewerberpools inkl. Eager-Loads).
     */
    #[Computed]
    public function statsApplicantPool()
    {
```

- [ ] **Step 2: Call-Sites anpassen**

In `positionStats()` und `activityStats()` jeweils:

```php
        $applicants = $this->statsApplicantPool();
```

ersetzen durch:

```php
        $applicants = $this->statsApplicantPool;
```

(2 Stellen. `timeToHire()` hat eine eigene Query und bleibt unverändert.)

- [ ] **Step 3: Syntax-Check + Testlauf**

```bash
php -l src/Livewire/Dashboard/Dashboard.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: keine Syntax-Fehler, alle Tests grün. Zusätzlich prüfen, dass keine Call-Site vergessen wurde:

```bash
grep -n "statsApplicantPool()" src/Livewire/Dashboard/Dashboard.php
```
Expected: nur noch die Methoden-Definition selbst (`public function statsApplicantPool()`), keine Aufrufe mit `()`.

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Dashboard/Dashboard.php
git commit -m "perf(recruiting): Stats-Pool als Computed — 1x statt 2x geladen

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Wiring — Dirty-Check-Poll + Abgeschlossen eingeklappt (letzter Task, kennt den Vertrag aus Task 1)

**Files:**
- Modify: `src/Livewire/Dashboard/Dashboard.php` (checkForUpdates, completedQuery/-Count/-Applicants, Toggle-Actions)
- Modify: `resources/views/livewire/dashboard/dashboard.blade.php` (Poll-Zeile, Abgeschlossen-Sektion)

**Interfaces:**
- Consumes (alles aus Task 1): `$changeToken`-Lifecycle (`mount()` setzt Baseline; `refreshDashboard()` setzt am Ende selbst den neuen Token), `buildChangeToken()`, `$showCompleted`, `$completedLimit`, `visibleApplicantIds()` (nimmt Completed-IDs bei `showCompleted` bereits auf → `whatsAppWindowMap` deckt die Completed-Ampeln automatisch ab), `clearApplicantCaches()`.
- Produces: `checkForUpdates(): void`, `toggleCompleted(): void`, `loadMoreCompleted(): void`, Computed `completedCount(): int`, `completedApplicants` (limitiert via `completedQuery()`).

- [ ] **Step 1: checkForUpdates() in Dashboard.php einfügen** (direkt vor `refreshDashboard()`):

```php
/**
 * Dirty-Check für den 15s-Poll: billiges Change-Token vergleichen statt
 * Voll-Render. skipRender() ist zwingend — Livewire 3 führt render()
 * sonst bei jedem Poll aus und der Check wäre wirkungslos.
 * Token-Set im Änderungs-Zweig übernimmt refreshDashboard() selbst
 * (Lifecycle-Vertrag aus Task 1).
 */
public function checkForUpdates(): void
{
    if ($this->buildChangeToken() === $this->changeToken) {
        $this->skipRender();
        return;
    }

    $this->refreshDashboard();
}
```

- [ ] **Step 2: Abgeschlossen-Query + Count + Limit in Dashboard.php**

Die bisherige `completedApplicants()`-Methode komplett ersetzen durch:

```php
/**
 * Gemeinsame Basis für Badge-Count UND Liste — Zahl und Inhalt können
 * per Konstruktion nicht divergieren.
 */
private function completedQuery()
{
    return $this->applicantBaseQuery()
        ->whereNotNull('enrichment_status')
        ->where('enrichment_status', '!=', 'no_contact')
        ->whereNotNull('auto_pilot_completed_at');
}

#[Computed]
public function completedCount(): int
{
    return $this->completedQuery()->count();
}

/**
 * Lazy + limitiert: lädt erst bei aufgeklappter Sektion, dann in
 * 25er-Häppchen. Eager-Loads bewusst OHNE extraFieldValues — die
 * Abgeschlossen-Karten rendern keinen Extra-Feld-Badge (Blade
 * verifiziert 2026-07-16).
 */
#[Computed]
public function completedApplicants()
{
    if (!$this->showCompleted) {
        return collect();
    }

    return $this->completedQuery()
        ->with([
            'crmContactLinks.contact.emailAddresses',
            'crmContactLinks.contact.phoneNumbers',
            'postings.position',
            'preferredCommsChannel',
            'phase',
        ])
        ->orderByDesc('created_at')
        ->limit($this->completedLimit)
        ->get();
}

public function toggleCompleted(): void
{
    $this->showCompleted = !$this->showCompleted;
    // Batch-Maps hängen von der sichtbaren ID-Menge ab → mit invalidieren.
    $this->clearApplicantCaches();
}

public function loadMoreCompleted(): void
{
    $this->completedLimit += 25;
    $this->clearApplicantCaches();
}
```

- [ ] **Step 3: Blade — Poll-Zeile umstellen**

In `resources/views/livewire/dashboard/dashboard.blade.php` Zeile 1:

```blade
<div class="h-full" wire:poll.15s="refreshDashboard">
```

ersetzen durch:

```blade
<div class="h-full" wire:poll.15s="checkForUpdates">
```

- [ ] **Step 4: Blade — Abgeschlossen-Sektion einklappbar machen**

Die Sektion beginnt bei `{{-- Abgeschlossene Bewerbungen --}}` / `<x-ui-panel title="Abgeschlossen" subtitle="Alle Phasen durchlaufen">` (~Zeile 938) und endet mit dem zugehörigen `</x-ui-panel>` (~Zeile 1072).

**(a)** Direkt VOR der Panel-Zeile einen Block einfügen (Block-Form, NIE inline `@php(...)` — ParseError-Pitfall):

```blade
@php
    $completedSubtitle = 'Alle Phasen durchlaufen — ' . $this->completedCount . ' Bewerber';
@endphp
```

**(b)** Die Panel-Zeile ersetzen durch:

```blade
<x-ui-panel title="Abgeschlossen" :subtitle="$completedSubtitle">
```

**(c)** Direkt nach der Panel-Zeile den Toggle einfügen:

```blade
    <div class="px-4 pt-4 {{ $this->showCompleted ? '' : 'pb-4' }}">
        <x-ui-button variant="secondary" size="sm" wire:click="toggleCompleted">
            {{ $this->showCompleted ? 'Einklappen' : 'Anzeigen (' . $this->completedCount . ')' }}
        </x-ui-button>
    </div>
    @if($this->showCompleted)
```

**(d)** Direkt VOR dem schließenden `</x-ui-panel>` der Sektion einfügen:

```blade
        @if($this->completedCount > $this->completedLimit)
            <div class="px-4 pb-4 pt-2">
                <x-ui-button variant="secondary" size="sm" wire:click="loadMoreCompleted">
                    Mehr laden ({{ $this->completedApplicants->count() }} von {{ $this->completedCount }})
                </x-ui-button>
            </div>
        @endif
    @endif
```

Der Tabellen-Inhalt dazwischen (inkl. `@forelse($this->completedApplicants ...)` und `@empty`-Block) bleibt unverändert — er liegt jetzt komplett im `@if($this->showCompleted)`.

- [ ] **Step 5: Syntax-Check + kompletter Testlauf**

```bash
php -l src/Livewire/Dashboard/Dashboard.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
grep -c "wire:poll" resources/views/livewire/dashboard/dashboard.blade.php
```
Expected: keine Syntax-Fehler, alle Tests grün, `wire:poll`-Count = 1 (nur die umgestellte Zeile).

Blade-Balance prüfen (jedes `@if` hat sein `@endif`):

```bash
php -r '$c = file_get_contents("resources/views/livewire/dashboard/dashboard.blade.php"); printf("if=%d endif=%d foreach=%d endforeach=%d\n", preg_match_all("/@if\W/", $c), preg_match_all("/@endif/", $c), preg_match_all("/@foreach/", $c), preg_match_all("/@endforeach/", $c));'
```
Expected: if-Count == endif-Count (foreach unverändert zueinander passend).

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Dashboard/Dashboard.php resources/views/livewire/dashboard/dashboard.blade.php
git commit -m "perf(recruiting): Dirty-Check-Poll (skipRender) + Abgeschlossen lazy/eingeklappt

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Live-Verifikation nach Merge + Deploy (kein Staging!)

Nicht Teil der Subagent-Tasks — macht der User/Hauptagent nach Freigabe:

1. Merge ff auf `main`, Push, danach `meingedeck` composer.lock bumpen (Pflicht — sonst nicht live), Forge-Deploy abwarten. `queue:restart` ist hier nicht nötig (kein Job-/Queue-Code — reine Livewire/Blade/Query-Änderung).
2. Erster Live-Klick auf `/recruiting`: Badge-Zahlen, WhatsApp-Ampeln, Stats-Tabellen und Zähler stichprobenartig mit Vorher-Screenshot/Erinnerung abgleichen.
3. Query-Log/Debugbar: Seitenaufbau sollte bei ~30–40 Queries liegen (vorher 1000+).
4. Poll: Tab offen lassen, in zweitem Browser einen Bewerber parken → erscheint binnen 15 s. Ruhezustand: Netzwerk-Tab zeigt kleine Poll-Responses (skipRender, kein HTML-Diff).
5. Abgeschlossen: Badge-Zahl korrekt, „Anzeigen" lädt 25, „Mehr laden" erweitert, WhatsApp-Ampeln auf Completed-Karten funktionieren.
