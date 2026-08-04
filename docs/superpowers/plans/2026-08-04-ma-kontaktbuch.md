# MA-Kontaktbuch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine sync-verwaltete CRM-Kontaktliste „Aktive Mitarbeiter" pro Team: Voll-Sync (Service + Command + Panel) plus Observer für Einzeländerungen.

**Architecture:** Ein Service (`EmployeeContactListSyncService`) kapselt Soll-Auflösung (Link-Priorisierung), pure Diff-Klassifikation mit Hard-Delete-Guard und die Writes (subscribe via CRM-API, Entfernen per Row-Delete). Observer, Artisan-Command und Livewire-Panel sind dünne Aufrufer. Spec: `docs/superpowers/specs/2026-08-04-ma-kontaktbuch-design.md`.

**Tech Stack:** Laravel/Eloquent (Modul-Package), Livewire 3, PHPUnit 11.4 (kein Pest), Integrationstests via `Illuminate\Database\Capsule` + SQLite in-memory.

## Global Constraints

- **HARTE GRENZE:** Es wird keine Datei außerhalb von `platforms-recruiting` geändert — kein platform-crm, keine CRM-Migration, kein CRM-Test. Erlaubt ist der Datenzugriff auf `crm_contact_list_members` aus Recruiting-Code (Präzedenz: platform-crm `ContactList::removeMember()`). Wenn beim Bauen auffällt, dass etwas eine CRM-Änderung braucht: **STOP, melden, nicht umsetzen.**
- Alle Pfade in diesem Plan sind relativ zu `modules/platforms-recruiting/`.
- Morph-Key für Employees NIE als String hartkodieren — immer `(new RecEmployee)->getMorphClass()` bzw. über die Relation (`RecEmployee` steht nicht in der morphMap; `linkable_type` ist heute der FQCN).
- Befüllen ausschließlich `SubscriptionService::subscribe($list, $contact, 'manual_admin')`; Entfernen ausschließlich `CrmContactListMember::delete()` + `$list->updateMemberCount()`.
- `SyncReport`-Felder sind festgenagelt (siehe Task 1) — nicht umbenennen, nicht erweitern.
- Settings: JSON Read-Modify-Write — immer `setSetting()` + explizites `save()`; `last_sync` schreibt NUR `syncAll()` (echter Lauf, Status `ok`), nie `syncEmployee()`.
- Tests laufen mit `vendor/bin/phpunit -c phpunit.xml` des Runner-Projekts (Modul hat kein eigenes vendor/). Suites: `Unit` (kein Laravel/Eloquent!) und `Integration` (Capsule + SQLite in-memory).
- Commits im Modul-Repo `platforms-recruiting`, Prefix `feat(recruiting):` bzw. `test(recruiting):`.

---

### Task 1: Sync-Kern — `EmployeeContactListSyncService` + `EmployeeContactListSyncReport`

**Files:**
- Create: `src/Services/EmployeeContactListSyncReport.php`
- Create: `src/Services/DiffResult.php`
- Create: `src/Services/EmployeeContactListSyncService.php`
- Test (Unit, pure): `tests/Unit/EmployeeContactListDiffTest.php`
- Test (Integration): `tests/Integration/EmployeeContactListSyncTest.php`

**Interfaces:**
- Consumes (alles existiert bereits):
  - `Platform\Crm\Services\Comms\SubscriptionService::subscribe(CrmContactList $list, CrmContact $contact, string $source = 'manual_admin', ?int $userId = null): CrmContactListMember` — idempotent, reaktiviert `unsubscribed`, mit `manual_admin` ohne DOI.
  - `Platform\Crm\Models\CrmContactList::updateMemberCount(): void` — zählt nur `status='subscribed'`.
  - `Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam(int $teamId): self`, `->getSetting(string $key, $default = null)`, `->setSetting(string $key, $value): void` (+ `->save()`).
  - `Platform\Recruiting\Models\RecEmployee` — `team_id`, `is_active`, Relation `crmContactLinks(): MorphMany`.
- Produces (Vertrag für Task 2–4, wörtlich):
  - `final readonly class Platform\Recruiting\Services\EmployeeContactListSyncReport` mit Konstruktor-Props `int $added, int $removed, int $normalized, int $unchanged, int $skipped_without_contact, int $hidden_from_carddav, int $ambiguous_multi_link, bool $dry_run, string $status` (`status` ∈ `ok | partial | not_configured | list_missing | guard_tripped`). **Festgenagelt — trägt bewusst KEINEN guardReason.** Bei echten Läufen zählen `added`/`normalized`/`removed` die **tatsächlich erfolgreichen Writes** (inkl. Row-Deletes via affected rows); Dry-Runs zählen aus dem Diff. Mindestens ein fehlgeschlagener Write (oder Abweichung echte Writes ≠ Diff) → `status: partial`.
  - `final readonly class Platform\Recruiting\Services\DiffResult` mit Konstruktor-Props `array $toAdd, array $toNormalize, array $toRemove` (jeweils contact_ids), `int $unchanged, bool $guardTripped, ?string $guardReason` (`'empty_soll' | 'threshold' | null`). Pures Diff-Ergebnis; die Resolver-Zähler gehören NICHT hierher.
  - `Platform\Recruiting\Services\EmployeeContactListSyncService`:
    - `public function syncAll(int $teamId, bool $dryRun = false, bool $force = false): EmployeeContactListSyncReport` — baut den SyncReport aus `DiffResult` + Resolver-Zählern.
    - `public function syncEmployee(RecEmployee $employee): void`
    - `public function preview(int $teamId): array` — `['report' => EmployeeContactListSyncReport (dry_run=true), 'guard_reason' => ?string]`; Dry-Run inkl. Guard-Begründung fürs Panel (Task 4).
    - `public static function computeDiff(array $soll, array $ist, bool $force = false): DiffResult` — pure, keine DB.
    - Konstanten: `SETTING_LIST_ID = 'employee_contact_list_id'`, `SETTING_LAST_SYNC = 'employee_contact_list_last_sync'`, `GUARD_MAX_REMOVALS = 25`, `GUARD_MAX_REMOVAL_RATIO = 0.5`.
  - Der Service **wirft in den definierten Flows nie** — `not_configured`/`list_missing`/`guard_tripped` sind Report-Stati. (Unerwartete Infrastrukturfehler propagieren; Task 2 fängt sie im Observer.)

- [ ] **Step 1: Report-Klasse anlegen**

`src/Services/EmployeeContactListSyncReport.php`:

```php
<?php

namespace Platform\Recruiting\Services;

/**
 * Ergebnis eines Kontaktbuch-Syncs. Felder sind Vertrag für Command/Panel —
 * siehe docs/superpowers/specs/2026-08-04-ma-kontaktbuch-design.md.
 */
final readonly class EmployeeContactListSyncReport
{
    public function __construct(
        public int $added,
        public int $removed,
        public int $normalized,
        public int $unchanged,
        public int $skipped_without_contact,
        public int $hidden_from_carddav,
        public int $ambiguous_multi_link,
        public bool $dry_run,
        public string $status, // ok | partial | not_configured | list_missing | guard_tripped
    ) {
    }
}
```

`src/Services/DiffResult.php`:

```php
<?php

namespace Platform\Recruiting\Services;

/**
 * Pures Diff-Ergebnis von EmployeeContactListSyncService::computeDiff().
 * Traegt die konkreten contact_id-Listen fuer die Write-Phase plus den
 * Guard-Zustand. Der nach aussen festgenagelte 9-Felder-SyncReport wird in
 * syncAll()/preview() aus DiffResult + Resolver-Zaehlern gebaut — die Zaehler
 * (skipped/hidden/multi-link) gehoeren bewusst NICHT hierher.
 */
final readonly class DiffResult
{
    public function __construct(
        public array $toAdd,        // contact_ids
        public array $toNormalize,  // contact_ids
        public array $toRemove,     // contact_ids
        public int $unchanged,
        public bool $guardTripped,
        public ?string $guardReason, // 'empty_soll' | 'threshold' | null
    ) {
    }
}
```

- [ ] **Step 2: Failing Unit-Tests für `computeDiff` schreiben**

`tests/Unit/EmployeeContactListDiffTest.php` (Unit-Suite = pure PHP, kein Eloquent — `computeDiff` ist statisch und DB-frei):

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

class EmployeeContactListDiffTest extends TestCase
{
    public function test_klassifiziert_add_normalize_remove_unchanged(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff(
            [1, 2, 3],
            [2 => 'subscribed', 3 => 'unsubscribed', 4 => 'subscribed'],
        );

        $this->assertSame([1], $diff->toAdd);
        $this->assertSame([3], $diff->toNormalize);  // unsubscribed -> subscribe
        $this->assertSame([4], $diff->toRemove);
        $this->assertSame(1, $diff->unchanged);      // Kontakt 2
        $this->assertFalse($diff->guardTripped);
        $this->assertNull($diff->guardReason);
    }

    public function test_guard_bei_leerer_soll_menge_nicht_mit_force_uebersteuerbar(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff([], [1 => 'subscribed'], force: true);

        $this->assertTrue($diff->guardTripped);
        $this->assertSame('empty_soll', $diff->guardReason);
        $this->assertSame([1], $diff->toRemove);
    }

    public function test_leere_soll_und_leere_ist_menge_ist_ok(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff([], []);

        $this->assertFalse($diff->guardTripped);
        $this->assertNull($diff->guardReason);
    }

    public function test_guard_bei_mehr_als_25_entfernungen(): void
    {
        $ist = [];
        foreach (range(1, 100) as $i) {
            $ist[$i] = 'subscribed';
        }

        // Soll = 1..74 -> 26 Entfernungen (26 % der Liste): > 25 triggert, obwohl Ratio < 50 %.
        $diff = EmployeeContactListSyncService::computeDiff(range(1, 74), $ist);

        $this->assertTrue($diff->guardTripped);
        $this->assertSame('threshold', $diff->guardReason);
        $this->assertCount(26, $diff->toRemove);
    }

    public function test_guard_bei_mehr_als_50_prozent_entfernungen(): void
    {
        $ist = [1 => 'subscribed', 2 => 'subscribed', 3 => 'subscribed'];

        // 2 von 3 Zeilen = 66 % > 50 %, obwohl absolut <= 25.
        $diff = EmployeeContactListSyncService::computeDiff([1], $ist);

        $this->assertTrue($diff->guardTripped);
        $this->assertSame('threshold', $diff->guardReason);
    }

    public function test_force_uebersteuert_schwellen_guard(): void
    {
        $ist = [1 => 'subscribed', 2 => 'subscribed', 3 => 'subscribed'];

        $diff = EmployeeContactListSyncService::computeDiff([1], $ist, force: true);

        $this->assertFalse($diff->guardTripped);
        $this->assertNull($diff->guardReason);
        $this->assertCount(2, $diff->toRemove);
    }

    public function test_soll_menge_wird_dedupliziert(): void
    {
        $diff = EmployeeContactListSyncService::computeDiff([5, 5, 5], []);

        $this->assertSame([5], $diff->toAdd);
    }
}
```

- [ ] **Step 3: Unit-Tests laufen lassen — müssen fehlschlagen**

Run (im Runner-Projekt, das das Modul einbindet): `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml --testsuite Unit --filter EmployeeContactListDiffTest`
Expected: FAIL — `Class ... EmployeeContactListSyncService not found`.

- [ ] **Step 4: Service implementieren**

`src/Services/EmployeeContactListSyncService.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactList;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Crm\Services\Comms\SubscriptionService;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Sync-verwaltete CRM-Kontaktliste "Aktive Mitarbeiter" (MA-Kontaktbuch).
 * Design: docs/superpowers/specs/2026-08-04-ma-kontaktbuch-design.md.
 *
 * Befuellen via SubscriptionService::subscribe(..., 'manual_admin') (idempotent,
 * kein DOI). Entfernen per Row-Delete + updateMemberCount() — CRM-eigenes Muster
 * (ContactList::removeMember); noetig, weil die CardDAV-Auslieferung NICHT auf
 * Member-Status filtert und unsubscribe() Abgemeldete im Telefonbuch liesse.
 *
 * Der Service wirft in den definierten Flows nie: not_configured, list_missing
 * und guard_tripped sind Report-Stati, keine Exceptions.
 */
class EmployeeContactListSyncService
{
    public const SETTING_LIST_ID = 'employee_contact_list_id';
    public const SETTING_LAST_SYNC = 'employee_contact_list_last_sync';
    public const GUARD_MAX_REMOVALS = 25;
    public const GUARD_MAX_REMOVAL_RATIO = 0.5;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * Voll-Sync eines Teams. Soll = aktive MA mit auslieferbarem Kontakt,
     * Ist = ALLE Member-Zeilen der Liste (statusunabhaengig).
     */
    public function syncAll(int $teamId, bool $dryRun = false, bool $force = false): EmployeeContactListSyncReport
    {
        [$list, $settings, $error] = $this->resolveList($teamId);
        if ($error !== null) {
            return self::emptyReport($dryRun, $error);
        }

        $resolved = $this->resolveDesired($teamId);
        $ist = $this->currentMembers($list);

        $diff = self::computeDiff($resolved['contact_ids'], $ist, $force);

        if ($dryRun || $diff->guardTripped) {
            // Dry-Run und Guard melden das berechnete Diff (Intention).
            return self::reportFrom($diff, $resolved['counters'], $dryRun);
        }

        // Echte Laeufe zaehlen die TATSAECHLICH erfolgreichen Writes —
        // ein still verschluckter Fehler darf den Report nicht aufblasen.
        $addedActual = 0;
        $normalizedActual = 0;
        $writeFailed = false;

        $toNormalizeSet = array_flip($diff->toNormalize);
        $toSubscribe = array_merge($diff->toAdd, $diff->toNormalize);

        if ($toSubscribe !== []) {
            $contacts = CrmContact::query()->whereIn('id', $toSubscribe)->get()->keyBy('id');

            foreach ($toSubscribe as $contactId) {
                $contact = $contacts->get($contactId);
                if (!$contact) {
                    // Kontakt zwischen Diff und Write verschwunden -> kein Write.
                    $writeFailed = true;
                    continue;
                }

                try {
                    $this->subscriptions->subscribe($list, $contact, 'manual_admin');
                    isset($toNormalizeSet[$contactId]) ? $normalizedActual++ : $addedActual++;
                } catch (\Throwable $e) {
                    $writeFailed = true;
                    Log::error('[EmployeeContactListSync] subscribe fehlgeschlagen', [
                        'contact_id' => $contactId,
                        'list_id' => $list->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $removedActual = 0;
        if ($diff->toRemove !== []) {
            try {
                // delete() liefert die Zahl der tatsaechlich geloeschten Zeilen.
                $removedActual = CrmContactListMember::query()
                    ->where('contact_list_id', $list->id)
                    ->whereIn('contact_id', $diff->toRemove)
                    ->delete();
            } catch (\Throwable $e) {
                $writeFailed = true;
                Log::error('[EmployeeContactListSync] Row-Delete fehlgeschlagen', [
                    'list_id' => $list->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $list->updateMemberCount();

        $partial = $writeFailed
            || $addedActual !== count($diff->toAdd)
            || $normalizedActual !== count($diff->toNormalize)
            || $removedActual !== count($diff->toRemove);

        $report = new EmployeeContactListSyncReport(
            added: $addedActual,
            removed: $removedActual,
            normalized: $normalizedActual,
            unchanged: $diff->unchanged,
            skipped_without_contact: (int) ($resolved['counters']['skipped_without_contact'] ?? 0),
            hidden_from_carddav: (int) ($resolved['counters']['hidden_from_carddav'] ?? 0),
            ambiguous_multi_link: (int) ($resolved['counters']['ambiguous_multi_link'] ?? 0),
            dry_run: false,
            status: $partial ? 'partial' : 'ok',
        );

        // Nur syncAll schreibt last_sync, und NUR bei status ok — bei partial
        // nicht (JSON Read-Modify-Write; Observer-Saves wuerden parallele
        // Aenderungen anderer Keys klobbern).
        if ($report->status === 'ok') {
            $settings->setSetting(self::SETTING_LAST_SYNC, now()->toIso8601String());
            $settings->save();
        }

        return $report;
    }

    /**
     * Einzel-Sync fuer den Observer. Wertet dieselbe Soll-Logik TEAM-WEIT aus,
     * beschraenkt auf die Kontakte dieses MA: der Kontakt des deaktivierten MA #1
     * kann der gewaehlte Kontakt eines aktiven MA #2 sein und darf dann bleiben.
     * Kein Guard (Blast-Radius = Kontakte eines einzelnen MA), kein last_sync.
     */
    public function syncEmployee(RecEmployee $employee): void
    {
        [$list, , $error] = $this->resolveList((int) $employee->team_id);
        if ($error !== null) {
            return;
        }

        $affected = $employee->crmContactLinks()
            ->pluck('contact_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($affected === []) {
            return;
        }

        $resolved = $this->resolveDesired((int) $employee->team_id, $affected);
        $desired = array_values(array_intersect($resolved['contact_ids'], $affected));

        $removedAny = false;

        foreach ($affected as $contactId) {
            $member = CrmContactListMember::query()
                ->where('contact_list_id', $list->id)
                ->where('contact_id', $contactId)
                ->first();

            if (in_array($contactId, $desired, true)) {
                if (!$member || $member->status !== 'subscribed') {
                    $contact = CrmContact::query()->find($contactId);
                    if ($contact) {
                        // subscribe() ruft updateMemberCount() selbst.
                        $this->subscriptions->subscribe($list, $contact, 'manual_admin');
                    }
                }
            } elseif ($member) {
                $member->delete();
                $removedAny = true;
            }
        }

        if ($removedAny) {
            $list->updateMemberCount();
        }
    }

    /**
     * Pure Funktion (keine DB): Diff + Hard-Delete-Guard.
     * $force uebersteuert NUR den Schwellen-Guard ('threshold');
     * 'empty_soll' ist nie uebersteuerbar (leere Soll-Menge wischt nie die Liste).
     *
     * @param int[]              $soll gewuenschte contact_ids (wird dedupliziert)
     * @param array<int, string> $ist  contact_id => status ALLER Member-Zeilen
     */
    public static function computeDiff(array $soll, array $ist, bool $force = false): DiffResult
    {
        $sollIds = array_values(array_unique(array_map('intval', $soll)));

        $toAdd = [];
        $toNormalize = [];
        $unchanged = 0;

        foreach ($sollIds as $id) {
            if (!array_key_exists($id, $ist)) {
                $toAdd[] = $id;
            } elseif ($ist[$id] !== 'subscribed') {
                $toNormalize[] = $id;
            } else {
                $unchanged++;
            }
        }

        $toRemove = array_values(array_diff(array_map('intval', array_keys($ist)), $sollIds));

        $guardTripped = false;
        $guardReason = null;

        if ($sollIds === [] && $ist !== []) {
            $guardTripped = true;
            $guardReason = 'empty_soll';
        } elseif (count($toRemove) > self::GUARD_MAX_REMOVALS
            || ($ist !== [] && count($toRemove) > self::GUARD_MAX_REMOVAL_RATIO * count($ist))) {
            $guardTripped = !$force;
            $guardReason = $guardTripped ? 'threshold' : null;
        }

        return new DiffResult(
            toAdd: $toAdd,
            toNormalize: $toNormalize,
            toRemove: $toRemove,
            unchanged: $unchanged,
            guardTripped: $guardTripped,
            guardReason: $guardReason,
        );
    }

    /**
     * Dry-Run inkl. Guard-Begruendung fuers Panel. Der SyncReport bleibt der
     * festgenagelte 9-Felder-Vertrag und traegt selbst keinen guardReason.
     *
     * @return array{report: EmployeeContactListSyncReport, guard_reason: ?string}
     */
    public function preview(int $teamId): array
    {
        [$list, , $error] = $this->resolveList($teamId);
        if ($error !== null) {
            return ['report' => self::emptyReport(true, $error), 'guard_reason' => null];
        }

        $resolved = $this->resolveDesired($teamId);
        $diff = self::computeDiff($resolved['contact_ids'], $this->currentMembers($list));

        return [
            'report' => self::reportFrom($diff, $resolved['counters'], true),
            'guard_reason' => $diff->guardReason,
        ];
    }

    /**
     * Baut den festgenagelten 9-Felder-Report aus DiffResult + Resolver-Zaehlern.
     */
    private static function reportFrom(DiffResult $diff, array $counters, bool $dryRun): EmployeeContactListSyncReport
    {
        return new EmployeeContactListSyncReport(
            added: count($diff->toAdd),
            removed: count($diff->toRemove),
            normalized: count($diff->toNormalize),
            unchanged: $diff->unchanged,
            skipped_without_contact: (int) ($counters['skipped_without_contact'] ?? 0),
            hidden_from_carddav: (int) ($counters['hidden_from_carddav'] ?? 0),
            ambiguous_multi_link: (int) ($counters['ambiguous_multi_link'] ?? 0),
            dry_run: $dryRun,
            status: $diff->guardTripped ? 'guard_tripped' : 'ok',
        );
    }

    /**
     * Einzige Stelle mit Link-Priorisierung. Auslieferbar (CardDAV) =
     * contact.is_active UND owned_by_user_id IS NULL; Tie-Break kleinste contact_id.
     *
     * @param  int[]|null  $restrictToContactIds  nur MA betrachten, die auf diese
     *                     Kontakte verlinken (Observer-Pfad); null = alle aktiven MA.
     * @return array{contact_ids: int[], counters: array{skipped_without_contact: int, hidden_from_carddav: int, ambiguous_multi_link: int}}
     */
    private function resolveDesired(int $teamId, ?array $restrictToContactIds = null): array
    {
        $query = RecEmployee::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->with('crmContactLinks.contact');

        if ($restrictToContactIds !== null) {
            $query->whereHas('crmContactLinks', fn ($q) => $q->whereIn('contact_id', $restrictToContactIds));
        }

        $counters = ['skipped_without_contact' => 0, 'hidden_from_carddav' => 0, 'ambiguous_multi_link' => 0];
        $desired = [];

        foreach ($query->get() as $employee) {
            $links = $employee->crmContactLinks;

            if ($links->isEmpty()) {
                $counters['skipped_without_contact']++;
                continue;
            }

            if ($links->count() > 1) {
                $counters['ambiguous_multi_link']++;
            }

            $deliverable = $links
                ->filter(fn ($link) => $link->contact
                    && $link->contact->is_active
                    && $link->contact->owned_by_user_id === null)
                ->sortBy('contact_id');

            if ($deliverable->isEmpty()) {
                $counters['hidden_from_carddav']++;
                continue;
            }

            $desired[(int) $deliverable->first()->contact_id] = true;
        }

        return ['contact_ids' => array_keys($desired), 'counters' => $counters];
    }

    /**
     * @return array{0: ?CrmContactList, 1: RecApplicantSettings, 2: ?string}
     */
    private function resolveList(int $teamId): array
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $listId = $settings->getSetting(self::SETTING_LIST_ID);

        if (!$listId) {
            return [null, $settings, 'not_configured'];
        }

        $list = CrmContactList::query()
            ->where('id', (int) $listId)
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->first();

        return [$list, $settings, $list ? null : 'list_missing'];
    }

    /**
     * ALLE Member-Zeilen (statusunabhaengig): globalUnsubscribe() kann Zeilen von
     * aussen auf 'unsubscribed' setzen, CardDAV liefert sie trotzdem aus.
     *
     * @return array<int, string> contact_id => status
     */
    private function currentMembers(CrmContactList $list): array
    {
        return CrmContactListMember::query()
            ->where('contact_list_id', $list->id)
            ->pluck('status', 'contact_id')
            ->all();
    }

    private static function emptyReport(bool $dryRun, string $status): EmployeeContactListSyncReport
    {
        return new EmployeeContactListSyncReport(0, 0, 0, 0, 0, 0, 0, $dryRun, $status);
    }
}
```

- [ ] **Step 5: Unit-Tests laufen lassen — müssen grün sein**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml --testsuite Unit --filter EmployeeContactListDiffTest`
Expected: PASS (7 Tests). Hinweis: `dry_run` und die Resolver-Zähler sind bewusst NICHT Teil von `computeDiff` — sie werden in `syncAll`/`preview` (Integrationstests) abgedeckt.

- [ ] **Step 6: Failing Integration-Test schreiben (Capsule + SQLite in-memory)**

`tests/Integration/EmployeeContactListSyncTest.php`. Muster: `tests/Integration/DuplicateMatchQueryTest.php` (echte Models auf SQLite, kein Testbench). **Bekannte Grenze, im Test-Docblock notieren:** Das handgebaute Schema findet keine NOT-NULL-Drift gegenüber den echten Migrationen.

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmContactList;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Crm\Services\Comms\SubscriptionService;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Integrationstests des Kontaktbuch-Syncs gegen die ECHTEN Modelle auf SQLite
 * in-memory via Capsule. Bekannte Grenze: das handgebaute Schema unten prueft
 * KEINE NOT-NULL-/Spalten-Drift gegenueber den echten Migrationen.
 */
class EmployeeContactListSyncTest extends TestCase
{
    protected const TEAM = 7;

    protected static Container $container;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        self::$container = $container;

        // LogsActivity (CrmContact/CrmContactList) verlangt config().
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        // event()-Helper (ContactListSubscriptionChanged::dispatch) + Model-Hooks.
        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);

        // Log-Facade (Fehlerpfade des Service/Observers).
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });

        // CrmContactLink::creating ruft auth()->check() — Guard-Stub ohne User.
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });
        $container->alias(\Illuminate\Contracts\Auth\Factory::class, 'auth');

        Facade::setFacadeApplication($container);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

        self::createSchema();
    }

    protected function setUp(): void
    {
        foreach (['rec_employees', 'crm_contacts', 'crm_contact_links', 'crm_contact_lists', 'crm_contact_list_members', 'rec_applicant_settings'] as $table) {
            Capsule::table($table)->delete();
        }
    }

    protected static function createSchema(): void
    {
        $schema = Capsule::schema();

        $schema->create('rec_employees', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->date('employment_ended_at')->nullable();
            $t->timestamps();
        });

        $schema->create('crm_contacts', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('owned_by_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('contact_status_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $schema->create('crm_contact_links', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('linkable_id');
            $t->string('linkable_type');
            $t->timestamps();
        });

        $schema->create('crm_contact_lists', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('color')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('requires_doi')->default(false);
            $t->string('doi_confirmation_subject')->nullable();
            $t->text('doi_confirmation_body')->nullable();
            $t->integer('member_count')->default(0);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('owned_by_user_id')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->timestamps();
            $t->softDeletes();
        });

        $schema->create('crm_contact_list_members', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->unsignedBigInteger('contact_list_id');
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('added_by_user_id')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('subscribed');
            $t->timestamp('subscribed_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->string('consent_source', 100)->nullable();
            $t->timestamp('opt_in_confirmed_at')->nullable();
            $t->string('doi_token', 64)->nullable();
            $t->timestamps();
        });

        $schema->create('rec_applicant_settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->text('settings')->nullable();
            $t->timestamps();
        });
    }

    // ---- Helpers -----------------------------------------------------

    protected function service(): EmployeeContactListSyncService
    {
        return new EmployeeContactListSyncService(new SubscriptionService());
    }

    protected function makeList(): CrmContactList
    {
        $list = CrmContactList::create([
            'name' => 'Aktive Mitarbeiter',
            'team_id' => self::TEAM,
            'is_active' => true,
            'requires_doi' => false,
        ]);

        $settings = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        $settings->setSetting(EmployeeContactListSyncService::SETTING_LIST_ID, $list->id);
        $settings->save();

        return $list;
    }

    /**
     * @param array $contactOverrides z.B. ['is_active' => false] oder ['owned_by_user_id' => 9]
     */
    protected function makeEmployeeWithContact(array $employeeOverrides = [], array $contactOverrides = []): array
    {
        $employee = RecEmployee::create(array_merge([
            'team_id' => self::TEAM,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'is_active' => true,
        ], $employeeOverrides));

        $contact = CrmContact::create(array_merge([
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'team_id' => self::TEAM,
            'is_active' => true,
        ], $contactOverrides));

        $this->link($employee, $contact);

        return [$employee, $contact];
    }

    protected function link(RecEmployee $employee, CrmContact $contact): CrmContactLink
    {
        return CrmContactLink::create([
            'contact_id' => $contact->id,
            'team_id' => self::TEAM,
            'linkable_id' => $employee->id,
            'linkable_type' => $employee->getMorphClass(),
        ]);
    }

    /** Invariante nach jedem syncAll: nur subscribed-Zeilen, member_count == Zeilenzahl. */
    protected function assertListInvariant(CrmContactList $list): void
    {
        $rows = CrmContactListMember::where('contact_list_id', $list->id)->get();

        $this->assertTrue(
            $rows->every(fn ($m) => $m->status === 'subscribed'),
            'Invariante verletzt: Liste enthaelt Nicht-subscribed-Zeilen.'
        );
        $this->assertSame(
            $rows->count(),
            (int) $list->fresh()->member_count,
            'Invariante verletzt: member_count != Zeilenzahl.'
        );
    }

    // ---- Tests -------------------------------------------------------

    public function test_aktiver_ma_mit_link_wird_mitglied(): void
    {
        $list = $this->makeList();
        [, $contact] = $this->makeEmployeeWithContact();

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('ok', $report->status);
        $this->assertSame(1, $report->added);
        $this->assertNotNull(
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()
        );
        $this->assertListInvariant($list);
    }

    public function test_inaktiver_ma_zeile_wird_geloescht(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact();

        $this->service()->syncAll(self::TEAM);
        $employee->update(['is_active' => false]);

        $report = $this->service()->syncAll(self::TEAM, force: true); // 1 von 1 = 100 % -> Schwellen-Guard

        $this->assertSame('ok', $report->status);
        $this->assertSame(1, $report->removed);
        $this->assertSame(
            0,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count(),
            'Zeile muss geloescht sein (nicht nur unsubscribed) — CardDAV filtert nicht auf Status.'
        );
        $this->assertListInvariant($list);
    }

    public function test_ma_ohne_link_wird_gezaehlt_und_uebersprungen(): void
    {
        $list = $this->makeList();
        RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Ohne', 'last_name' => 'Link', 'is_active' => true]);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(1, $report->skipped_without_contact);
        $this->assertSame(0, $report->added);
        $this->assertListInvariant($list);
    }

    public function test_nicht_auslieferbarer_kontakt_zaehlt_als_hidden(): void
    {
        $list = $this->makeList();
        // Kontakt inaktiv -> nicht CardDAV-auslieferbar.
        $this->makeEmployeeWithContact([], ['is_active' => false]);
        // Kontakt owned -> nicht auslieferbar fuer Team-Abos.
        $this->makeEmployeeWithContact(['first_name' => 'Zweiter'], ['owned_by_user_id' => 42]);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(2, $report->hidden_from_carddav);
        $this->assertSame(0, $report->added);
        $this->assertListInvariant($list);
    }

    public function test_mehrfach_link_auslieferbarer_gewinnt_und_wird_gezaehlt(): void
    {
        $list = $this->makeList();
        [$employee, $ownedContact] = $this->makeEmployeeWithContact([], ['owned_by_user_id' => 42]);
        $deliverable = CrmContact::create([
            'first_name' => 'Zweit',
            'last_name' => 'Kontakt',
            'team_id' => self::TEAM,
            'is_active' => true,
        ]);
        $this->link($employee, $deliverable);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(1, $report->ambiguous_multi_link);
        $this->assertSame(1, $report->added);
        $this->assertNotNull(
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $deliverable->id)->first()
        );
        $this->assertSame(
            0,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $ownedContact->id)->count()
        );
        $this->assertListInvariant($list);
    }

    public function test_von_aussen_unsubscribed_wird_renormalisiert(): void
    {
        $list = $this->makeList();
        [, $contact] = $this->makeEmployeeWithContact();
        $this->service()->syncAll(self::TEAM);

        // Simuliert globalUnsubscribe() von aussen.
        CrmContactListMember::where('contact_list_id', $list->id)
            ->where('contact_id', $contact->id)
            ->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(1, $report->normalized);
        $this->assertSame('subscribed', CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()->status);
        $this->assertListInvariant($list);
    }

    public function test_fremdkontakt_fliegt_raus(): void
    {
        $list = $this->makeList();
        $this->makeEmployeeWithContact();
        $fremd = CrmContact::create(['first_name' => 'Manuell', 'last_name' => 'Dazu', 'team_id' => self::TEAM, 'is_active' => true]);
        (new SubscriptionService())->subscribe($list, $fremd, 'manual_admin');

        $report = $this->service()->syncAll(self::TEAM, force: true); // 1 von 2 = 50 %? -> nein: > 50 % noetig; force schadet nicht

        $this->assertSame(1, $report->removed);
        $this->assertSame(0, CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $fremd->id)->count());
        $this->assertListInvariant($list);
    }

    public function test_zweiter_lauf_ist_idempotent(): void
    {
        $list = $this->makeList();
        $this->makeEmployeeWithContact();
        $this->service()->syncAll(self::TEAM);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('ok', $report->status);
        $this->assertSame(0, $report->added);
        $this->assertSame(0, $report->removed);
        $this->assertSame(0, $report->normalized);
        $this->assertSame(1, $report->unchanged);
        $this->assertListInvariant($list);
    }

    public function test_dry_run_schreibt_nichts(): void
    {
        $list = $this->makeList();
        $this->makeEmployeeWithContact();

        $report = $this->service()->syncAll(self::TEAM, dryRun: true);

        $this->assertTrue($report->dry_run);
        $this->assertSame(1, $report->added);
        $this->assertSame(0, CrmContactListMember::where('contact_list_id', $list->id)->count());
        $this->assertNull(
            RecApplicantSettings::getOrCreateForTeam(self::TEAM)->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC)
        );
    }

    public function test_ohne_konfiguration_not_configured(): void
    {
        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('not_configured', $report->status);
    }

    public function test_geloeschte_liste_list_missing(): void
    {
        $list = $this->makeList();
        $list->delete(); // SoftDelete

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('list_missing', $report->status);
    }

    public function test_last_sync_wird_nur_bei_echtem_ok_lauf_geschrieben(): void
    {
        $this->makeList();
        $this->makeEmployeeWithContact();

        $this->service()->syncAll(self::TEAM);

        $this->assertNotNull(
            RecApplicantSettings::getOrCreateForTeam(self::TEAM)->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC)
        );
    }

    public function test_fehlgeschlagener_subscribe_ergibt_partial_und_echte_zaehler(): void
    {
        $this->makeList();
        $this->makeEmployeeWithContact();
        [, $contactFail] = $this->makeEmployeeWithContact(['first_name' => 'Zweiter'], ['first_name' => 'Zweiter']);

        // Wirft nur fuer den zweiten Kontakt — der Rest laeuft echt durch.
        $failing = new class((int) $contactFail->id) extends SubscriptionService {
            public function __construct(private readonly int $failForContactId)
            {
            }

            public function subscribe(CrmContactList $list, CrmContact $contact, string $source = 'manual_admin', ?int $userId = null): CrmContactListMember
            {
                if ((int) $contact->id === $this->failForContactId) {
                    throw new \RuntimeException('subscribe kaputt (Testfall partial)');
                }

                return parent::subscribe($list, $contact, $source, $userId);
            }
        };

        $report = (new EmployeeContactListSyncService($failing))->syncAll(self::TEAM);

        $this->assertSame('partial', $report->status);
        $this->assertSame(1, $report->added, 'added zaehlt nur den tatsaechlich erfolgreichen Write.');
        $this->assertNull(
            RecApplicantSettings::getOrCreateForTeam(self::TEAM)->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC),
            'last_sync darf bei partial nicht geschrieben werden.'
        );
    }
}
```

Hinweis zu `test_fremdkontakt_fliegt_raus`: 1 Entfernung von 2 Ist-Zeilen = exakt 50 % — der Guard verlangt **> 50 %**, triggert also nicht; `force: true` ist dort nur Belt-and-Braces und kann nach grünem Lauf entfernt werden, wenn er sich als unnötig erweist.

- [ ] **Step 7: Integration-Tests laufen lassen — erst FAIL (vor Step 4) bzw. jetzt PASS**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml --testsuite Integration --filter EmployeeContactListSyncTest`
Expected: PASS (13 Tests). Der Integrationstest braucht dafür zusätzlich `use Platform\Crm\Models\CrmContactList;` und `use Platform\Crm\Models\CrmContactListMember;` (für die anonyme Failing-Subclass). Falls FAIL: Fehlermeldung lesen — häufigste Ursache ist eine fehlende Spalte im handgebauten Schema (dann Schema ergänzen, nicht die Assertion aufweichen).

- [ ] **Step 8: Beide Suiten komplett laufen lassen (Regression)**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml`
Expected: PASS, keine Regression in bestehenden Tests.

- [ ] **Step 9: Commit**

```bash
git add src/Services/EmployeeContactListSyncReport.php src/Services/EmployeeContactListSyncService.php tests/Unit/EmployeeContactListDiffTest.php tests/Integration/EmployeeContactListSyncTest.php
git commit -m "feat(recruiting): MA-Kontaktbuch Sync-Kern (Service, Report, Guard) inkl. Tests"
```

---

### Task 2: Observer — `RecEmployeeContactListObserver`

**Files:**
- Create: `src/Observers/RecEmployeeContactListObserver.php`
- Modify: `src/RecruitingServiceProvider.php` (boot, bei den bestehenden `::observe`-Aufrufen um Zeile 163)
- Test: `tests/Integration/EmployeeContactListObserverTest.php`

**Interfaces:**
- Consumes (aus Task 1, wörtlich): `Platform\Recruiting\Services\EmployeeContactListSyncService::syncEmployee(RecEmployee $employee): void` — No-Op ohne konfigurierte Liste; wertet team-weit aus, beschränkt auf die Kontakte des MA.
- Produces: `Platform\Recruiting\Observers\RecEmployeeContactListObserver` mit `updated(RecEmployee): void` (KEIN `created` — strukturell tot, s. Docblock/Spec; Regel: Link-Anleger rufen selbst `syncEmployee()`).

- [ ] **Step 1: Failing Test schreiben**

`tests/Integration/EmployeeContactListObserverTest.php` — **erbt das komplette Setup aus Task 1s Testklasse** (Container/Schema/Helpers), registriert den Observer aber manuell, weil `RecruitingServiceProvider::boot()` im Capsule-Setup nicht läuft:

```php
<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeContactListObserver;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Observer-Pfad. WICHTIG: RecruitingServiceProvider::boot() laeuft hier nicht —
 * der Observer wird manuell registriert. Gleiche Schema-Grenze wie im Sync-Test.
 */
class EmployeeContactListObserverTest extends EmployeeContactListSyncTest
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // app()-Aufloesung im Observer: Service-Abhaengigkeiten sind auto-resolvebar.
        RecEmployee::observe(RecEmployeeContactListObserver::class);
    }

    public function test_is_active_flip_aendert_mitgliedschaft(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact(); // created-Hook feuert bereits

        $this->assertSame('subscribed', CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()?->status);

        $employee->update(['is_active' => false]);

        $this->assertSame(0, CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count());
        $this->assertSame(0, (int) $list->fresh()->member_count);
    }

    public function test_geteilter_kontakt_bleibt_wenn_zweiter_ma_aktiv_ist(): void
    {
        $list = $this->makeList();
        [$employee1, $contact] = $this->makeEmployeeWithContact();
        $employee2 = RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Zwei', 'last_name' => 'Aktiv', 'is_active' => true]);
        $this->link($employee2, $contact);

        $employee1->update(['is_active' => false]);

        $this->assertSame(
            1,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count(),
            'Kontakt des aktiven MA #2 darf durch Deaktivierung von MA #1 nicht entfernt werden.'
        );
    }

    public function test_unbeteiligtes_feld_update_loest_keinen_sync_aus(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact();

        // Zeile von aussen manipulieren; ein Nicht-Trigger-Update darf sie nicht anfassen.
        CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)
            ->update(['status' => 'unsubscribed']);

        $employee->update(['phone' => '+491234567']);

        $this->assertSame(
            'unsubscribed',
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()->status,
            'phone-Update darf den Sync nicht triggern (dirty-Check auf is_active/employment_ended_at).'
        );
    }

    public function test_crm_exception_kippt_den_save_nicht(): void
    {
        $this->makeList();
        [$employee] = $this->makeEmployeeWithContact();

        // Member-Tabelle wegreissen -> jeder Sync-Zugriff wirft.
        Capsule::schema()->drop('crm_contact_list_members');

        try {
            $employee->update(['is_active' => false]); // darf NICHT werfen
            $this->assertFalse($employee->fresh()->is_active);
        } finally {
            // Tabelle fuer nachfolgende Tests wiederherstellen.
            self::createSchema_members();
        }
    }

    public function test_ohne_konfigurierte_liste_ist_der_observer_ein_noop(): void
    {
        [$employee] = $this->makeEmployeeWithContact(); // keine Liste konfiguriert

        $employee->update(['is_active' => false]);

        $this->assertSame(0, CrmContactListMember::count());
    }

    /** Nur die Member-Tabelle neu anlegen (fuer den Exception-Test). */
    protected static function createSchema_members(): void
    {
        Capsule::schema()->create('crm_contact_list_members', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->unsignedBigInteger('contact_list_id');
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('added_by_user_id')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('subscribed');
            $t->timestamp('subscribed_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->string('consent_source', 100)->nullable();
            $t->timestamp('opt_in_confirmed_at')->nullable();
            $t->string('doi_token', 64)->nullable();
            $t->timestamps();
        });
    }
}
```

Voraussetzung dafür: In Task 1s Testklasse `EmployeeContactListSyncTest` müssen `TEAM`, `$container`, `createSchema()`, `service()`, `makeList()`, `makeEmployeeWithContact()`, `link()`, `assertListInvariant()` `protected` sein (sind sie laut Task 1) — die Vererbung ist beabsichtigt, um das Container/Schema-Setup nicht zu duplizieren. Hinweis: PHPUnit führt geerbte Tests der Elternklasse hier erneut aus — das ist akzeptiert (doppelte Abdeckung, kein Schaden), alternativ die Elternklasse in eine abstrakte `EmployeeContactListTestCase` + zwei konkrete Klassen aufteilen, wenn die Laufzeit stört.

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml --testsuite Integration --filter EmployeeContactListObserverTest`
Expected: FAIL — `Class ... RecEmployeeContactListObserver not found`.

- [ ] **Step 3: Observer implementieren**

`src/Observers/RecEmployeeContactListObserver.php`:

```php
<?php

namespace Platform\Recruiting\Observers;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Haelt das MA-Kontaktbuch (sync-verwaltete CRM-Liste) bei Einzel-Aenderungen
 * aktuell. Voll-Sync + Guard: EmployeeContactListSyncService::syncAll
 * (Command recruiting:sync-employee-contact-list / Settings-Panel).
 *
 * Bewusst KEIN deleted-Hook: recruiting:delete-employee loescht die
 * CrmContactLink-Zeilen vor dem forceDelete — beim deleted-Event ist der
 * Kontakt nicht mehr aufloesbar. Aufraeumen uebernimmt der Voll-Sync
 * (Spec: benannte Luecke).
 */
class RecEmployeeContactListObserver
{
    // BEWUSST kein created()-Hook: crm_contact_links.linkable_id braucht die
    // Employee-ID, ein Link kann also erst NACH dem created-Event existieren —
    // der Hook waere strukturell tot (beide Produktionspfade legen Links nach
    // dem Create an). REGEL: Wer einen CrmContactLink fuer einen RecEmployee
    // anlegt, ruft danach selbst syncEmployee() auf (siehe Spec, Benannte
    // Luecken). Bis dahin holt der Voll-/Scheduler-Sync Neuzugaenge nach.

    public function updated(RecEmployee $employee): void
    {
        if (!$employee->wasChanged(['is_active', 'employment_ended_at'])) {
            return;
        }

        $this->sync($employee);
    }

    private function sync(RecEmployee $employee): void
    {
        try {
            app(EmployeeContactListSyncService::class)->syncEmployee($employee);
        } catch (\Throwable $e) {
            // CRM-Fehler duerfen den MA-Save nie kippen (Muster: IncomingApplicationService).
            Log::error('[EmployeeContactListSync] Observer-Sync fehlgeschlagen', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml --testsuite Integration --filter EmployeeContactListObserverTest`
Expected: PASS.

- [ ] **Step 5: Observer im ServiceProvider registrieren**

In `src/RecruitingServiceProvider.php`, direkt nach den bestehenden Observer-Registrierungen (`RecPosition::observe(...)`, um Zeile 169) ergänzen:

```php
        // MA-Kontaktbuch: haelt die sync-verwaltete CRM-Kontaktliste bei
        // Einzel-Aenderungen (is_active/employment_ended_at) aktuell.
        \Platform\Recruiting\Models\RecEmployee::observe(\Platform\Recruiting\Observers\RecEmployeeContactListObserver::class);
```

- [ ] **Step 6: Beide Suiten komplett laufen lassen**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Observers/RecEmployeeContactListObserver.php src/RecruitingServiceProvider.php tests/Integration/EmployeeContactListObserverTest.php
git commit -m "feat(recruiting): Observer haelt MA-Kontaktbuch bei is_active-Aenderungen aktuell"
```

---

### Task 3: Artisan-Command — `recruiting:sync-employee-contact-list`

**Files:**
- Create: `src/Console/Commands/SyncEmployeeContactList.php`
- Modify: `src/RecruitingServiceProvider.php` (Commands-Array, um Zeile 45–58, alphabetisch neben `ZasCrmContactBackfill`)

**Interfaces:**
- Consumes (aus Task 1, wörtlich): `EmployeeContactListSyncService::syncAll(int $teamId, bool $dryRun = false, bool $force = false): EmployeeContactListSyncReport`; Report-Felder `added, removed, normalized, unchanged, skipped_without_contact, hidden_from_carddav, ambiguous_multi_link, dry_run, status`; Konstante `EmployeeContactListSyncService::SETTING_LIST_ID`.
- Produces: Artisan-Command `recruiting:sync-employee-contact-list {--team=} {--dry-run} {--force}`.

**Test-Hinweis:** Der Command ist dünnes Glue (Option-Parsing + Tabellen-Output); `--dry-run`/`--force`-Verhalten und alle Stati sind in Task 1 auf Service-Ebene getestet. Der Command selbst wird per Live-Dry-Run auf der Instanz verifiziert (wie das Panel) — ohne Laravel-Testharness im Modul wäre ein Command-Test unverhältnismäßig. Das ist eine dokumentierte Grenze, kein übersehener Test.

- [ ] **Step 1: Command implementieren**

`src/Console/Commands/SyncEmployeeContactList.php`:

```php
<?php

namespace Platform\Recruiting\Console\Commands;

use Illuminate\Console\Command;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Voll-Sync des MA-Kontaktbuchs (sync-verwaltete CRM-Kontaktliste).
 * Bewusst NICHT im Scheduler (Spec) — manueller Lauf bzw. Panel.
 *
 *   php artisan recruiting:sync-employee-contact-list --dry-run
 *   php artisan recruiting:sync-employee-contact-list --team=3
 *   php artisan recruiting:sync-employee-contact-list --team=3 --force
 */
class SyncEmployeeContactList extends Command
{
    protected $signature = 'recruiting:sync-employee-contact-list
        {--team= : Nur dieses Team syncen}
        {--dry-run : Nichts schreiben, nur Report}
        {--force : Entfernungs-Schwellen-Guard uebersteuern (leere Soll-Menge bleibt gesperrt)}';

    protected $description = 'Synct das MA-Kontaktbuch (CRM-Kontaktliste "Aktive Mitarbeiter") mit den aktiven Mitarbeitern';

    public function handle(EmployeeContactListSyncService $sync): int
    {
        // Bewusst KEIN JSON-Path-Where (settings->employee_contact_list_id):
        // verhaelt sich je nach MySQL-Version unterschiedlich und waere
        // ungetestete Flaeche. Es sind eine Handvoll Teams — alle Zeilen
        // laden und in PHP filtern.
        $teamIds = $this->option('team')
            ? [(int) $this->option('team')]
            : RecApplicantSettings::query()->get()
                ->filter(fn ($s) => $s->getSetting(EmployeeContactListSyncService::SETTING_LIST_ID))
                ->pluck('team_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        if ($teamIds === []) {
            $this->warn('Kein Team mit konfiguriertem MA-Kontaktbuch gefunden.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($teamIds as $teamId) {
            $report = $sync->syncAll(
                $teamId,
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
            );

            $rows[] = [
                $teamId,
                $report->status,
                $report->added,
                $report->removed,
                $report->normalized,
                $report->unchanged,
                $report->skipped_without_contact,
                $report->hidden_from_carddav,
                $report->ambiguous_multi_link,
            ];

            if ($report->status === 'guard_tripped') {
                $failed = true;
                $this->error("Team {$teamId}: Guard ausgeloest ({$report->removed} Entfernungen). Schwellen-Guard mit --force uebersteuerbar; leere Soll-Menge nicht.");
            } elseif ($report->status === 'list_missing') {
                $failed = true;
                $this->error("Team {$teamId}: konfigurierte Liste fehlt oder ist inaktiv — im Panel neu anlegen.");
            } elseif ($report->status === 'partial') {
                $failed = true;
                $this->warn("Team {$teamId}: Sync unvollstaendig (partial) — mindestens ein Write fehlgeschlagen, Details im Laravel-Log unter [EmployeeContactListSync]. last_sync wurde nicht aktualisiert.");
            }
        }

        $this->table(
            ['Team', 'Status', '+add', '-rem', '~norm', '=same', 'ohne Link', 'hidden', 'multi-link'],
            $rows,
        );

        if ($this->option('dry-run')) {
            $this->info('DRY-RUN — nichts geschrieben.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Command im ServiceProvider registrieren**

In `src/RecruitingServiceProvider.php` im bestehenden `$this->commands([...])`-Array (um Zeile 45–58) ergänzen:

```php
                \Platform\Recruiting\Console\Commands\SyncEmployeeContactList::class,
```

- [ ] **Step 3: Suiten laufen lassen (Regression) + Syntax-Check**

Run: `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml` und `php -l src/Console/Commands/SyncEmployeeContactList.php`
Expected: PASS / `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Console/Commands/SyncEmployeeContactList.php src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): Artisan-Command fuer MA-Kontaktbuch-Vollsync (--dry-run/--force)"
```

---

### Task 4: Livewire-Panel — `Employees\ContactBook`

**Files:**
- Create: `src/Livewire/Employees/ContactBook.php` (Livewire-Komponenten werden per Directory-Scan auto-registriert, `RecruitingServiceProvider::registerLivewireComponents()` — keine manuelle Registrierung nötig)
- Create: `resources/views/livewire/employees/contact-book.blade.php`
- Modify: `routes/web.php` — Route **VOR** der Wildcard-Route `/employees/{employee}` (Zeile ~63) einfügen, sonst fängt die Wildcard `contact-book` ab.

**Interfaces:**
- Consumes (aus Task 1, wörtlich): `EmployeeContactListSyncService::syncAll(int $teamId, bool $dryRun = false, bool $force = false): EmployeeContactListSyncReport`; `EmployeeContactListSyncService::preview(int $teamId): array` → `['report' => EmployeeContactListSyncReport, 'guard_reason' => 'empty_soll'|'threshold'|null]`; Konstanten `SETTING_LIST_ID`, `SETTING_LAST_SYNC`; Report-Felder wie in Task 3.
- Produces: Seite `/employees/contact-book` (`recruiting.employees.contact-book`).

**Test-Hinweis (Spec):** Das Panel bleibt **unverifiziert bis zum Live-Klick** — es wird nicht als getestet ausgewiesen.

- [ ] **Step 1: Livewire-Komponente implementieren**

`src/Livewire/Employees/ContactBook.php`:

```php
<?php

namespace Platform\Recruiting\Livewire\Employees;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CrmContactList;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Settings-Panel des MA-Kontaktbuchs: Liste anlegen, Status sehen,
 * zweistufiger Sync (Dry-Run -> Bestaetigen; zweiter Klick wirkt bei
 * guard_tripped als Force-Override — ausser bei leerer Soll-Menge).
 */
class ContactBook extends Component
{
    /**
     * Dry-Run des ersten Klicks: ['report' => Array der 9 Report-Props,
     * 'guard_reason' => 'empty_soll'|'threshold'|null]. null = kein Sync angestossen.
     * Die beiden Guard-Gruende MUESSEN unterschieden werden: 'threshold' ist per
     * zweitem Klick uebersteuerbar, 'empty_soll' nie (gleiche Meldung fuer beide
     * waere eine Klick-Falle).
     */
    public ?array $pendingDryRun = null;

    public function createList(): void
    {
        // Bewusst gegen die Computed-Property statt gegen das Setting geprueft:
        // ist die konfigurierte Liste geloescht/inaktiv, muss "Neu anlegen"
        // moeglich sein (Setting wird dann ueberschrieben).
        if ($this->list && $this->list->is_active) {
            return;
        }

        $settings = RecApplicantSettings::getOrCreateForTeam($this->teamId());

        $list = CrmContactList::create([
            'name' => 'Aktive Mitarbeiter',
            'description' => '⚙️ Automatisch verwaltet durch Recruiting (MA-Kontaktbuch). Manuelle Änderungen werden beim nächsten Sync überschrieben.',
            'team_id' => $this->teamId(),
            'is_active' => true,
            'requires_doi' => false,
            'owned_by_user_id' => null,
            'created_by_user_id' => auth()->id(),
        ]);

        $settings->setSetting(EmployeeContactListSyncService::SETTING_LIST_ID, $list->id);
        $settings->save();

        $report = app(EmployeeContactListSyncService::class)->syncAll($this->teamId());

        unset($this->list, $this->lastSync);
        session()->flash('message', "Kontaktbuch angelegt — {$report->added} Mitarbeiter aufgenommen"
            . ($report->skipped_without_contact > 0 ? ", {$report->skipped_without_contact} ohne CRM-Kontakt übersprungen" : '')
            . '.');
    }

    public function startSync(): void
    {
        $preview = app(EmployeeContactListSyncService::class)->preview($this->teamId());
        $this->pendingDryRun = [
            'report' => get_object_vars($preview['report']),
            'guard_reason' => $preview['guard_reason'],
        ];
    }

    public function confirmSync(): void
    {
        // 'empty_soll' ist nie uebersteuerbar — Button ist im Blade ausgeblendet,
        // das hier ist die zweite Absicherung gegen direkte Livewire-Calls.
        if (($this->pendingDryRun['guard_reason'] ?? null) === 'empty_soll') {
            return;
        }

        $force = ($this->pendingDryRun['guard_reason'] ?? null) === 'threshold';
        $report = app(EmployeeContactListSyncService::class)->syncAll($this->teamId(), force: $force);
        $this->pendingDryRun = null;

        unset($this->list, $this->lastSync);

        // partial = gelbe Warnung, nie gruen: mindestens ein Write ist fehlgeschlagen.
        if ($report->status === 'partial') {
            session()->flash('warning', "Sync unvollständig: +{$report->added} hinzugefügt, -{$report->removed} entfernt, {$report->normalized} renormalisiert — mindestens ein Schreibvorgang ist fehlgeschlagen (Details im Log). last_sync wurde nicht aktualisiert.");

            return;
        }

        session()->flash('message', match ($report->status) {
            'ok' => "Sync ausgeführt: +{$report->added} hinzugefügt, -{$report->removed} entfernt, {$report->normalized} renormalisiert.",
            'guard_tripped' => 'Sync abgebrochen: keine auslieferbaren Kontakte gefunden — deutet auf fehlende CRM-Links hin.',
            default => "Sync nicht möglich (Status: {$report->status}).",
        });
    }

    public function cancelSync(): void
    {
        $this->pendingDryRun = null;
    }

    #[Computed]
    public function list(): ?CrmContactList
    {
        $listId = RecApplicantSettings::getOrCreateForTeam($this->teamId())
            ->getSetting(EmployeeContactListSyncService::SETTING_LIST_ID);

        if (!$listId) {
            return null;
        }

        return CrmContactList::query()
            ->where('id', (int) $listId)
            ->where('team_id', $this->teamId())
            ->first();
    }

    #[Computed]
    public function lastSync(): ?string
    {
        return RecApplicantSettings::getOrCreateForTeam($this->teamId())
            ->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC);
    }

    public function render()
    {
        return view('recruiting::livewire.employees.contact-book')
            ->layout('platform::layouts.app');
    }

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->id;
    }
}
```

- [ ] **Step 2: Blade-View anlegen**

`resources/views/livewire/employees/contact-book.blade.php`:

```blade
<div class="max-w-3xl mx-auto p-6 space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900">MA-Kontaktbuch</h1>
        <p class="text-[13px] text-gray-500 mt-1">
            Eine automatisch verwaltete CRM-Kontaktliste mit genau den <strong>aktiven Mitarbeitern</strong> —
            als CardDAV-Telefonbuch abonnierbar (CRM &rarr; Kontaktliste &rarr; Tab „Adressbuch").
        </p>
    </div>

    @if (session('message'))
        <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-[13px] text-green-800">
            {{ session('message') }}
        </div>
    @endif

    @if (session('warning'))
        <div class="rounded-md bg-amber-50 border border-amber-300 px-4 py-3 text-[13px] text-amber-900">
            {{ session('warning') }}
        </div>
    @endif

    @if (!$this->list)
        <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-3">
            <p class="text-[13px] text-gray-600">
                Für dieses Team ist noch kein Kontaktbuch konfiguriert. Beim Anlegen wird eine
                CRM-Kontaktliste „Aktive Mitarbeiter" erstellt und initial befüllt.
            </p>
            <button type="button" wire:click="createList"
                class="px-4 py-2 text-[13px] font-medium rounded-md bg-[#ff7a59] text-white hover:bg-[#ff6a45] transition-colors">
                MA-Kontaktbuch anlegen
            </button>
        </section>
    @elseif (!$this->list->is_active)
        <section class="bg-white rounded-lg border border-red-200 p-4 space-y-3">
            <p class="text-[13px] text-red-700">
                Die konfigurierte Liste ist inaktiv oder wurde ersetzt. Bitte neu anlegen.
            </p>
            <button type="button" wire:click="createList"
                class="px-4 py-2 text-[13px] font-medium rounded-md bg-red-600 text-white hover:bg-red-700 transition-colors">
                Neu anlegen
            </button>
        </section>
    @else
        <section class="bg-white rounded-lg border border-gray-200 p-4 space-y-4">
            <div class="flex items-center gap-2">
                <span class="px-2 py-0.5 text-[11px] font-medium rounded bg-amber-100 text-amber-800">sync-verwaltet</span>
                <span class="text-sm font-semibold text-gray-900">{{ $this->list->name }}</span>
            </div>

            <dl class="grid grid-cols-2 gap-3 text-[13px]">
                <div><dt class="text-gray-400">Mitglieder</dt><dd class="text-gray-900 font-medium">{{ $this->list->member_count }}</dd></div>
                <div><dt class="text-gray-400">Letzter Sync</dt><dd class="text-gray-900 font-medium">{{ $this->lastSync ?? '—' }}</dd></div>
            </dl>

            <p class="text-[11px] text-gray-400">
                Manuelle Änderungen an der Liste werden beim nächsten Sync überschrieben.
                Mitarbeiter ohne CRM-Kontakt fehlen, bis der Kontakt verlinkt ist
                (<code>recruiting:zas-crm-contact-backfill</code>).
            </p>

            @if ($pendingDryRun)
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 space-y-3">
                    <p class="text-[13px] font-medium text-amber-900">
                        Sync würde <strong>{{ $pendingDryRun['report']['removed'] }}</strong> entfernen,
                        <strong>{{ $pendingDryRun['report']['added'] }}</strong> hinzufügen,
                        {{ $pendingDryRun['report']['normalized'] }} renormalisieren
                        ({{ $pendingDryRun['report']['skipped_without_contact'] }} ohne Kontakt,
                        {{ $pendingDryRun['report']['hidden_from_carddav'] }} nicht auslieferbar,
                        {{ $pendingDryRun['report']['ambiguous_multi_link'] }} mit Mehrfach-Link).
                        @if ($pendingDryRun['guard_reason'] === 'empty_soll')
                            <br><strong>Gestoppt:</strong> keine auslieferbaren Kontakte gefunden — deutet auf
                            fehlende CRM-Links hin. Nicht übersteuerbar; ggf.
                            <code>recruiting:zas-crm-contact-backfill</code> laufen lassen oder die Liste
                            manuell über die CRM-Listen-UI pflegen.
                        @elseif ($pendingDryRun['guard_reason'] === 'threshold')
                            <br><strong>Schutz ausgelöst</strong> — „Ausführen" übersteuert die Entfernungs-Schwelle.
                        @endif
                        @if ($pendingDryRun['guard_reason'] !== 'empty_soll')
                            — ausführen?
                        @endif
                    </p>
                    <div class="flex gap-2">
                        @if ($pendingDryRun['guard_reason'] !== 'empty_soll')
                            <button type="button" wire:click="confirmSync"
                                class="px-4 py-2 text-[13px] font-medium rounded-md bg-amber-600 text-white hover:bg-amber-700 transition-colors">
                                Ausführen
                            </button>
                        @endif
                        <button type="button" wire:click="cancelSync"
                            class="px-4 py-2 text-[13px] font-medium rounded-md border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors">
                            Abbrechen
                        </button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="startSync"
                    class="px-4 py-2 text-[13px] font-medium rounded-md bg-[#ff7a59] text-white hover:bg-[#ff6a45] transition-colors">
                    Jetzt synchronisieren
                </button>
            @endif
        </section>
    @endif
</div>
```

- [ ] **Step 3: Route registrieren (VOR der Wildcard!)**

In `routes/web.php` direkt nach der `payroll-changes.csv`-Route (Zeile ~62) und **vor** `/employees/{employee}` einfügen:

```php
Route::get('/employees/contact-book', \Platform\Recruiting\Livewire\Employees\ContactBook::class)
    ->name('recruiting.employees.contact-book');
```

- [ ] **Step 4: Syntax-Checks + Suiten**

Run: `php -l src/Livewire/Employees/ContactBook.php` und `vendor/bin/phpunit -c modules/platforms-recruiting/phpunit.xml`
Expected: `No syntax errors detected` / PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Employees/ContactBook.php resources/views/livewire/employees/contact-book.blade.php routes/web.php
git commit -m "feat(recruiting): Settings-Panel MA-Kontaktbuch (Anlage + zweistufiger Sync)"
```

---

### Task 5 (Nachtrag a): Scheduler-Eintrag für den Voll-Sync

**Files:**
- Modify: `src/RecruitingServiceProvider.php` (`registerSchedule()`, neben den bestehenden `Schedule::command(...)`-Einträgen um Zeile 172ff)

**Interfaces:**
- Consumes: Artisan-Command `recruiting:sync-employee-contact-list` aus Task 3 (ohne `--force`!).
- Produces: stündlicher Konvergenz-Sync — die Garantie, dass benannte Lücken (Link-Anlage ohne syncEmployee, Hard-Deletes) spätestens nach einer Stunde heilen.

- [ ] **Step 1: Scheduler-Eintrag ergänzen**

In `registerSchedule()` nach dem `recruiting:flynk-reconcile`-Block:

```php
        // MA-Kontaktbuch: Konvergenz-Garantie fuer alle Pfade, die der Observer
        // nicht sieht (Link-Anlage, Hard-Deletes). BEWUSST ohne --force —
        // Guard-Faelle sollen liegen bleiben und im Command/Panel auffallen.
        Schedule::command('recruiting:sync-employee-contact-list')
            ->hourly()
            ->withoutOverlapping(10)
            ->runInBackground();
```

- [ ] **Step 2: Syntax-Check + Suiten (Regression)**

Run: `php -l src/RecruitingServiceProvider.php` und die volle Suite.
Expected: sauber / PASS.

- [ ] **Step 3: Commit (eigener Commit!)**

```bash
git add src/RecruitingServiceProvider.php
git commit -m "feat(recruiting): MA-Kontaktbuch-Sync stuendlich im Scheduler (ohne --force)"
```

---

### Task 6 (Nachtrag b): syncEmployee() nach der Bewerber-Übernahme

**Files:**
- Modify: `src/Services/CreateEmployeeFromApplicantService.php` (direkt nach dem `$this->mirrorCrmContactLinks($applicant, $employee, $createdByUserId);`-Aufruf, Zeile ~80)

**Interfaces:**
- Consumes: `EmployeeContactListSyncService::syncEmployee(RecEmployee): void` (No-Op ohne konfigurierte Liste; wirft in definierten Flows nie).
- Produces: Neu übernommene MA landen sofort im Kontaktbuch (Regel „Link-Anleger ruft syncEmployee()" für den Bewerber-Pfad erfüllt).

**Sicherheits-Anforderung (bindend):** Der Aufruf darf die Übernahme **niemals** kippen — try/catch um den Sync, `Log::error`, kein Rethrow. Eigener Commit + eigenes Review + eigener Live-Klick (Einstellungs-Strecke!).

- [ ] **Step 1: Sync-Aufruf ergänzen**

Direkt nach `$this->mirrorCrmContactLinks($applicant, $employee, $createdByUserId);`:

```php
            // MA-Kontaktbuch: Link-Anlage feuert keinen Observer (Regel aus der
            // Spec, Benannte Luecken) — nach dem Spiegeln explizit syncen.
            // Darf die Uebernahme niemals kippen.
            try {
                app(\Platform\Recruiting\Services\EmployeeContactListSyncService::class)
                    ->syncEmployee($employee);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('[EmployeeContactListSync] Sync nach Bewerber-Uebernahme fehlgeschlagen', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
```

- [ ] **Step 2: Suiten (Regression) + Syntax-Check**

Run: volle Suite + `php -l src/Services/CreateEmployeeFromApplicantService.php`.
Expected: PASS / sauber. (Die syncEmployee-Semantik selbst ist durch die Task-1/2-Integrationstests abgedeckt; die Verdrahtung hier ist dünnes Glue und wird per Live-Übernahme verifiziert — dokumentierte Grenze wie Command/Panel.)

- [ ] **Step 3: Commit (eigener Commit!)**

```bash
git add src/Services/CreateEmployeeFromApplicantService.php
git commit -m "feat(recruiting): Kontaktbuch-Sync direkt nach Bewerber-Uebernahme (mirrorCrmContactLinks)"
```

---

## Pre-Flight vor dem ersten Sync auf der Instanz (manuell, kein Code)

Vor dem ersten echten `syncAll` auf der Zielinstanz (Rheingedeck) die drei Queries aus der Spec (`docs/superpowers/specs/2026-08-04-ma-kontaktbuch-design.md`, Abschnitt „Vor dem ersten Sync auf der Instanz") laufen lassen:

1. `SELECT DISTINCT linkable_type FROM crm_contact_links;` — Morph-Key-Kontrolle.
2. MA mit >1 Link zählen (→ erwartete `ambiguous_multi_link`).
3. Kontakte an >1 MA zählen (→ Observer-Fall „geteilter Kontakt" real?).
4. Aktive MA mit nicht-auslieferbaren Kontakten (→ erwartete `hidden_from_carddav`).

Danach: `php artisan recruiting:sync-employee-contact-list --team=<id> --dry-run` und den Report gegen die Query-Ergebnisse halten, erst dann Panel-Anlage bzw. echter Lauf. Prüfen, ob die Guard-Schwelle (25 / 50 %) zur realen MA-Zahl passt.

## Benannte Lücken (nicht Teil dieses Plans — nicht „mitfixen")

- `recruiting:delete-employee`: hart gelöschter MA bleibt bis zum nächsten Voll-Sync im Telefonbuch (Link-Zeilen sind beim `deleted`-Event schon weg; kein Scheduler).
- ZAS-Import legt noch keinen CRM-Link inline an (separates Design).
- Kein Scheduler-Eintrag für den Sync (bewusst).
- Kein Schreibschutz in der CRM-UI (wäre CRM-Änderung → Freigabe nötig).
