# Bewerber-Dedup-Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Auto-Pilot sendet pro Telefonnummer nur noch über EINEN Bewerber-Datensatz; juniore Dubletten werden geflaggt (`duplicate_of_applicant_id` + review_needed) statt denselben WhatsApp-Chat doppelt zu bespielen.

**Architecture:** Pure Guard-Klasse `DuplicateApplicantGuard` (Support-Namespace) mit kanonischer Nummern-Normalisierung, flacher Match-Query (JOIN, 3 Spalten, keine Hydration) und Senior-Regel als Totalordnung. Zwei Aufrufpunkte in `ProcessAutoPilotApplicants` (Erstkontakt + Reminder), reines Anzeige-Banner in der Bewerber-Ansicht.

**Tech Stack:** Laravel-Modul (platforms-recruiting), reines PHPUnit (Runner: `meingedeck/vendor/bin/phpunit -c phpunit.xml`), Integrations-Tests via Capsule/SQLite gegen echte Migrationen.

**Spec:** `docs/superpowers/specs/2026-07-20-applicant-dedup-design.md` — bei Detailfragen gilt die Spec.

## Global Constraints

- Test-Konvention: Entscheidungslogik pure ohne Laravel/DB (`tests/Unit`); DB-berührende Tests nur via Capsule/SQLite gegen ECHTE Migrationen (`tests/Integration`), kein Testbench.
- Kein Edit/Write außerhalb von platforms-recruiting (platform-crm, platform-core etc. sind tabu).
- Commit-Messages: deutsch, Präfix `feat(recruiting):` / `fix(recruiting):` / `test(recruiting):`, Abschluss-Zeile `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Kein `gh` CLI, keine PRs — Feature-Branch, nach Freigabe ff-Merge auf main (macht der Maintainer, NICHT dieser Plan).
- Blade-Pitfall: niemals inline `@php($x)` — immer Block-Form `@php … @endphp` und Werte vorberechnen.
- Kanonischer Nummernvergleich NUR über `DuplicateApplicantGuard::canonicalDigits()` — nirgends eine zweite Strip-Implementierung einführen.
- Nach jedem Task: komplette Suite grün (`/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`), Stand vor Task 1 (Basis `14f669f`, inkl. der 13 uncommitteten Guard-Tests): 235 Tests / 622 Assertions.

## Vorhandener (uncommitteter) Stand

Bereits im Working Tree, wird in Task 1 committet — NICHT neu schreiben:
- `src/Support/DuplicateApplicantGuard.php` — `canonicalDigits()` + `matchesFor()`
- `tests/Unit/DuplicateApplicantGuardCanonicalTest.php` — 6 Tests
- `tests/Integration/DuplicateMatchQueryTest.php` — 7 Tests, Schema aus echten Migrationen
- `phpunit.xml` — Testsuite „Integration" ergänzt

---

### Task 1: Feature-Branch anlegen und vorhandenen Guard-Stand committen

**Files:**
- Commit (existierend): `src/Support/DuplicateApplicantGuard.php`, `tests/Unit/DuplicateApplicantGuardCanonicalTest.php`, `tests/Integration/DuplicateMatchQueryTest.php`, `phpunit.xml`

**Interfaces:**
- Produces: Branch `feat/applicant-dedup-guard` mit Basis `origin/main`; committete Klasse `Platform\Recruiting\Support\DuplicateApplicantGuard` mit `canonicalDigits(?string): ?string` und `matchesFor(RecApplicant, ?string): Collection` (Rows: `object{id: int, auto_pilot_last_reminder_at: ?string}`).

- [ ] **Step 1: Fetch und Basis prüfen**

```bash
cd /Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting
git fetch origin
git status --short   # erwartete Änderungen: phpunit.xml (M), Guard + 2 Tests (??), docs/ (??)
git log --oneline -1 origin/main   # Stand bei Planerstellung: 14f669f; wenn origin/main neuer als lokaler main: erst lokalen main aktualisieren
```

- [ ] **Step 2: Branch anlegen**

```bash
git checkout -b feat/applicant-dedup-guard origin/main
```

Erwartung: Branch basiert auf origin/main; die uncommitteten Dateien wandern mit.

- [ ] **Step 3: Suite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: `OK (235 tests, 622 assertions)`

- [ ] **Step 4: Commit**

```bash
git add src/Support/DuplicateApplicantGuard.php tests/Unit/DuplicateApplicantGuardCanonicalTest.php tests/Integration/DuplicateMatchQueryTest.php phpunit.xml
git commit -m "feat(recruiting): DuplicateApplicantGuard — kanonische Nummern-Form + Match-Query inkl. Unit-/Integrations-Tests

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

Hinweis: `docs/superpowers/**` bleibt untracked (Repo-Konvention), `.DS_Store`/`.phpunit.result.cache` NICHT adden.

---

### Task 2: Senior-Regel `decide()` (pure, TDD)

**Files:**
- Modify: `src/Support/DuplicateApplicantGuard.php`
- Test (Create): `tests/Unit/DuplicateApplicantGuardDecideTest.php`

**Interfaces:**
- Consumes: Match-Rows aus `matchesFor()` (`object{id: int, auto_pilot_last_reminder_at: ?string}`).
- Produces: `DuplicateApplicantGuard::decide(int $candidateId, ?string $candidateLastReminderAt, iterable $matches): ?int` — `null` = senden ok; sonst Original-ID, auf die geflaggt wird. Task 4 ruft genau diese Signatur auf.

Semantik (Spec §2, Totalordnung): Ein Match ist senior, wenn (a) Match kontaktiert (`auto_pilot_last_reminder_at` nicht null/leer) und Kandidat nicht, ODER (b) beide gleicher Kontakt-Status UND Match-ID kleiner. Kein seniorer Match → null. Sonst Original = kontaktierte Seniors vor unkontaktierten, innerhalb dessen kleinste ID. Eigene ID im Match-Set ignorieren.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Unit/DuplicateApplicantGuardDecideTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DuplicateApplicantGuard;

class DuplicateApplicantGuardDecideTest extends TestCase
{
    private function m(int $id, ?string $contactedAt = null): object
    {
        return (object) ['id' => $id, 'auto_pilot_last_reminder_at' => $contactedAt];
    }

    public function test_kein_match_sendet(): void
    {
        $this->assertNull(DuplicateApplicantGuard::decide(5, null, []));
    }

    public function test_ein_kontaktierter_match_flaggt_auf_dessen_id(): void
    {
        $this->assertSame(9, DuplicateApplicantGuard::decide(5, null, [$this->m(9, '2026-07-15 13:21:20')]));
    }

    public function test_mehrere_matches_einer_kontaktiert_flaggt_auf_den_kontaktierten(): void
    {
        $matches = [$this->m(2), $this->m(9, '2026-07-15 13:21:20'), $this->m(11)];
        $this->assertSame(9, DuplicateApplicantGuard::decide(5, null, $matches));
    }

    public function test_mehrere_kontaktierte_flaggt_auf_kleinste_kontaktierte_id(): void
    {
        $matches = [$this->m(9, '2026-07-15 13:21:20'), $this->m(4, '2026-07-15 13:22:19')];
        $this->assertSame(4, DuplicateApplicantGuard::decide(5, null, $matches));
    }

    public function test_alle_unkontaktiert_kandidat_kleinste_id_sendet(): void
    {
        $this->assertNull(DuplicateApplicantGuard::decide(3, null, [$this->m(7), $this->m(9)]));
    }

    public function test_alle_unkontaktiert_kleinere_match_id_flaggt(): void
    {
        $this->assertSame(3, DuplicateApplicantGuard::decide(7, null, [$this->m(3), $this->m(9)]));
    }

    public function test_senior_regel_ordnungsunabhaengig_genau_einer_sendet(): void
    {
        // Zwei frische Dubletten (IDs 10 und 20) — egal wer zuerst verarbeitet wird:
        // 10 sendet, 20 flaggt auf 10. Kein Doppel-Flag.
        $this->assertNull(DuplicateApplicantGuard::decide(10, null, [$this->m(20)]));
        $this->assertSame(10, DuplicateApplicantGuard::decide(20, null, [$this->m(10)]));
    }

    public function test_eigene_id_im_match_set_wird_ignoriert(): void
    {
        $this->assertNull(DuplicateApplicantGuard::decide(5, null, [$this->m(5)]));
    }

    public function test_kandidat_kontaktiert_unkontaktierter_match_kleinerer_id_sendet(): void
    {
        // Reminder des Originals darf nicht durch später angelegten Datensatz blockieren:
        // kontaktiert schlaegt ID-Vergleich.
        $this->assertNull(DuplicateApplicantGuard::decide(8, '2026-07-15 13:21:20', [$this->m(2)]));
    }

    public function test_beide_kontaktiert_kleinere_id_remindert_weiter_groessere_flaggt(): void
    {
        // Bestandsfall #2378/#2379 im Reminder-Zweig.
        $this->assertNull(DuplicateApplicantGuard::decide(2378, '2026-07-16 01:22:19', [$this->m(2379, '2026-07-16 01:22:20')]));
        $this->assertSame(2378, DuplicateApplicantGuard::decide(2379, '2026-07-16 01:22:20', [$this->m(2378, '2026-07-16 01:22:19')]));
    }

    public function test_carbon_instanz_als_kandidat_kontakt_signal(): void
    {
        // Im Command kommt candidateLastReminderAt als Carbon (datetime-Cast,
        // RecApplicant $casts Zeile 65), Match-Rows aus dem JOIN als String —
        // beide müssen via !empty() äquivalent als "kontaktiert" fallen.
        $carbon = new \Carbon\Carbon('2026-07-15 13:21:20');
        $this->assertNull(DuplicateApplicantGuard::decide(8, $carbon, [$this->m(2)]));
        $this->assertSame(2, DuplicateApplicantGuard::decide(8, $carbon, [$this->m(2, '2026-07-15 13:00:00')]));
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DuplicateApplicantGuardDecideTest`
Expected: ERRORS — `Call to undefined method …::decide()`

- [ ] **Step 3: `decide()` implementieren**

In `src/Support/DuplicateApplicantGuard.php` ergänzen (nach `canonicalDigits()`):

```php
    /**
     * Senior-Regel (Totalordnung, ordnungsunabhängig): entscheidet, ob der
     * Kandidat senden darf oder auf ein Original geflaggt wird.
     *
     * Ein Match ist senior, wenn er kontaktiert ist und der Kandidat nicht,
     * ODER beide denselben Kontakt-Status haben und der Match die kleinere ID
     * hat. Kein seniorer Match → null (senden ok). Sonst: Original = ranghöchster
     * Senior (kontaktierte vor unkontaktierten, innerhalb dessen kleinste ID).
     *
     * @param iterable<object{id: int, auto_pilot_last_reminder_at: mixed}> $matches
     * @return int|null Original-ID zum Flaggen, oder null = senden ok
     */
    public static function decide(int $candidateId, mixed $candidateLastReminderAt, iterable $matches): ?int
    {
        $candidateContacted = !empty($candidateLastReminderAt);

        $seniors = [];
        foreach ($matches as $match) {
            $id = (int) $match->id;
            if ($id === $candidateId) {
                continue;
            }
            $contacted = !empty($match->auto_pilot_last_reminder_at);

            $isSenior = ($contacted && !$candidateContacted)
                || ($contacted === $candidateContacted && $id < $candidateId);

            if ($isSenior) {
                $seniors[] = ['id' => $id, 'contacted' => $contacted];
            }
        }

        if ($seniors === []) {
            return null;
        }

        // Kontaktierte zuerst (desc), innerhalb dessen kleinste ID (asc)
        usort($seniors, fn (array $a, array $b) =>
            [$b['contacted'], $a['id']] <=> [$a['contacted'], $b['id']]);

        return $seniors[0]['id'];
    }
```

- [ ] **Step 4: Tests laufen lassen — müssen bestehen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: `OK (246 tests, …)` — alle 11 neuen Decide-Tests grün, Rest unverändert.

- [ ] **Step 5: Commit**

```bash
git add src/Support/DuplicateApplicantGuard.php tests/Unit/DuplicateApplicantGuardDecideTest.php
git commit -m "feat(recruiting): Dedup-Guard Senior-Regel decide() — Totalordnung, kein Doppel-Flag

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Migration `duplicate_of_applicant_id` + Model-Anbindung

**Files:**
- Create: `database/migrations/2026_07_24_000001_add_duplicate_of_to_rec_applicants.php`
- Modify: `src/Models/RecApplicant.php` (fillable, Relation)
- Modify: `tests/Integration/DuplicateMatchQueryTest.php` (Migrationsliste + Spalten-Assertion)

**Interfaces:**
- Produces: Spalte `rec_applicants.duplicate_of_applicant_id` (nullable, FK typgleich `unsignedBigInteger`, `nullOnDelete`); `RecApplicant::duplicateOf(): BelongsTo`. Task 4 schreibt das Feld, Task 5 liest es.

- [ ] **Step 1: Failing Test — Spalten-Assertion ergänzen**

In `tests/Integration/DuplicateMatchQueryTest.php` als neue Test-Methode ans Klassenende:

```php
    public function test_duplicate_of_spalte_existiert_im_echten_schema(): void
    {
        $this->assertTrue(
            Capsule::schema()->hasColumn('rec_applicants', 'duplicate_of_applicant_id'),
            'Migration add_duplicate_of_to_rec_applicants fehlt in runRealMigrations() oder ist nicht angelegt'
        );
    }
```

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter test_duplicate_of_spalte_existiert`
Expected: FAIL (Spalte existiert nicht)

- [ ] **Step 2: Migration schreiben**

`database/migrations/2026_07_24_000001_add_duplicate_of_to_rec_applicants.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            // Mögliche Dublette: zeigt auf den Bewerber, der den Chat "besitzt".
            // Gesetzt vom Auto-Pilot-Dedup-Guard; manuell leeren = Freigabe.
            $table->foreignId('duplicate_of_applicant_id')
                ->nullable()
                ->constrained('rec_applicants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_applicant_id');
        });
    }
};
```

- [ ] **Step 3: Migrationsliste im Integration-Test ergänzen**

In `runRealMigrations()` ans ENDE des `$files`-Arrays:

```php
            'platforms-recruiting/database/migrations/2026_07_24_000001_add_duplicate_of_to_rec_applicants.php',
```

- [ ] **Step 4: Model anbinden**

`src/Models/RecApplicant.php` — im `$fillable`-Array nach `'auto_pilot_reminder_count', 'auto_pilot_last_reminder_at',` ergänzen:

```php
        'duplicate_of_applicant_id',
```

Und als Relation (in die Nähe der anderen BelongsTo-Relationen):

```php
    public function duplicateOf()
    {
        return $this->belongsTo(self::class, 'duplicate_of_applicant_id');
    }
```

- [ ] **Step 5: Suite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: `OK (247 tests, …)`

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_24_000001_add_duplicate_of_to_rec_applicants.php src/Models/RecApplicant.php tests/Integration/DuplicateMatchQueryTest.php
git commit -m "feat(recruiting): duplicate_of_applicant_id Spalte + Relation fuer Dedup-Flag

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Guard-Aufrufe in ProcessAutoPilotApplicants (Erstkontakt + Reminder)

**Files:**
- Modify: `src/Console/Commands/ProcessAutoPilotApplicants.php` (Erstkontakt-Zweig ~Zeile 206, Reminder-Zweig ~Zeile 244, neue private Methode; Basis 14f669f)

**Interfaces:**
- Consumes: `DuplicateApplicantGuard::matchesFor(RecApplicant, ?string): Collection` und `::decide(int, mixed, iterable): ?int` (Task 1/2); Spalte `duplicate_of_applicant_id` (Task 3); vorhandene Helfer `findPrimaryPhoneNumber()`, `logAutoPilot()`, `$this->reviewNeededStateId`.
- Produces: Log-Typ `duplicate_detected` in `rec_auto_pilot_logs`; geflaggter Bewerber (`duplicate_of_applicant_id` + `auto_pilot_state_id = review_needed`), der von `nextAutoPilotApplicant` automatisch ausgeschlossen wird.

Hinweis: Kein Unit-Test möglich (Command hängt an Laravel/DB, Modul-Konvention). Absicherung: Logik steckt vollständig in den bereits getesteten Guard-Methoden; der Command-Anteil ist reine Verdrahtung. Verifikation: Live-Check nach Forge-Deploy (Task 6 Step 4).

Nummer-Identität (verifiziert): Guard und WA-Send-Pfad nutzen DENSELBEN Resolver
— `findPrimaryPhoneNumber($applicant)` hier und in
`sendWhatsAppTemplateWithOverrides` (Zeile ~376), gesendet wird an
`$phoneNumber->international`. Der Guard prüft also exakt die Nummer, an die
gesendet würde. Diese Kopplung beim Refactoring erhalten.

- [ ] **Step 1: Use-Import ergänzen**

Oben in `src/Console/Commands/ProcessAutoPilotApplicants.php` bei den Imports:

```php
use Platform\Recruiting\Support\DuplicateApplicantGuard;
```

- [ ] **Step 2: Private Guard-Methode einfügen**

In `ProcessAutoPilotApplicants` (z. B. direkt nach `processApplicant()`):

```php
    /**
     * Dedup-Guard: true = Versand stoppen (Bewerber wurde als mögliche
     * Dublette geflaggt und auf review_needed gesetzt).
     */
    private function duplicateGuardBlocks(RecApplicant $applicant): bool
    {
        $sendNumber = $this->findPrimaryPhoneNumber($applicant)?->international;

        $matches = DuplicateApplicantGuard::matchesFor($applicant, $sendNumber);
        $originalId = DuplicateApplicantGuard::decide(
            (int) $applicant->id,
            $applicant->auto_pilot_last_reminder_at,
            $matches,
        );

        if ($originalId === null) {
            return false;
        }

        $applicant->duplicate_of_applicant_id = $originalId;
        $applicant->auto_pilot_state_id = $this->reviewNeededStateId;
        $applicant->save();

        $this->logAutoPilot($applicant, 'duplicate_detected', "Mögliche Dublette von #{$originalId} (gleiche Telefonnummer) — Versand gestoppt.");
        $this->info("  Mögliche Dublette von #{$originalId} — Versand gestoppt.");

        return true;
    }
```

- [ ] **Step 3: Aufruf im Erstkontakt-Zweig**

In `processApplicant()`, im Block `if ($applicant->auto_pilot_last_reminder_at === null) {` (aktuell Zeile 206, Basis 14f669f) — als ERSTE Zeile im Block, VOR `$sent = $this->sendMessageWithOverrides(...)`:

```php
            if ($this->duplicateGuardBlocks($applicant)) {
                return;
            }
```

- [ ] **Step 4: Aufruf im Reminder-Zweig**

Direkt VOR `// 5b. Send reminder`s `$sent = $this->sendMessageWithOverrides(..., isReminder: true, ...)` (aktuell Zeile 244, Basis 14f669f):

```php
        if ($this->duplicateGuardBlocks($applicant)) {
            return;
        }
```

WICHTIG: NACH dem Max-Reminders-Check (5a) einfügen, nicht davor — sonst würden ausgeschöpfte Bewerber unnötig geflaggt.

BEWUSSTE ENTSCHEIDUNG (neue Standby-Logik seit 14f669f): Der Dubletten-Flag ruft
`releaseSeats()` NICHT auf — anders als der Max-Reminders-Branch. Hat ein als
Dublette geflaggter Bewerber bereits eine Buchung, bleibt der Platz gehalten,
bis ein Mensch die Dublette auflöst (bei Merge soll die Buchung ggf. erhalten
bleiben). Nicht „vergessen", sondern gewollt.

- [ ] **Step 5: Suite + Syntax-Check**

```bash
php -l src/Console/Commands/ProcessAutoPilotApplicants.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: `No syntax errors`, `OK (246 tests, …)`

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/ProcessAutoPilotApplicants.php
git commit -m "feat(recruiting): Auto-Pilot Dedup-Guard vor Erstkontakt und Reminder — flaggt statt doppelt zu senden

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: UI-Banner auf der Bewerber-Seite

**Files:**
- Modify: `resources/views/livewire/applicant/show.blade.php` (direkt nach `<x-ui-page-container spacing="space-y-8">`, VOR dem `{{-- Header --}}`-Block)

**Interfaces:**
- Consumes: `$applicant->duplicate_of_applicant_id` (Task 3); Route `recruiting.applicants.show` (existiert, `routes/web.php:50`).
- Produces: Reines Anzeige-Banner, kein neuer Livewire-State in `Show.php`.

- [ ] **Step 1: Banner einfügen**

Direkt nach der Zeile `<x-ui-page-container spacing="space-y-8">`:

```blade
        @if($applicant->duplicate_of_applicant_id)
            <div class="p-3 bg-amber-50 border border-amber-200 rounded text-sm text-amber-900 flex items-center gap-2">
                @svg('heroicon-o-exclamation-triangle', 'w-4 h-4 shrink-0')
                <span>
                    Mögliche Dublette von
                    <a href="{{ route('recruiting.applicants.show', $applicant->duplicate_of_applicant_id) }}" class="underline font-medium" wire:navigate>
                        Bewerber #{{ $applicant->duplicate_of_applicant_id }}
                    </a>
                    (gleiche Telefonnummer) — Auto-Pilot gestoppt. Zum Freigeben Feld leeren und Auto-Pilot-Status zurücksetzen.
                </span>
            </div>
        @endif
```

Styling-Referenz: identisch zur bestehenden Amber-Box in derselben Datei (~Zeile 448). Kein `@php` nötig.

- [ ] **Step 2: Blade-Syntax prüfen**

Run: `php -l resources/views/livewire/applicant/show.blade.php` läuft bei Blade ins Leere — stattdessen Sichtprüfung: `@if`/`@endif` gepaart, kein inline `@php(...)` verwendet. Danach Suite als Smoke:

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: `OK (247 tests, …)` (Blade wird nicht getestet — Sichtprüfung zählt)

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/applicant/show.blade.php
git commit -m "feat(recruiting): Dubletten-Banner auf Bewerber-Seite mit Link zum Original

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Abschluss-Verifikation und Übergabe

**Files:**
- Keine Code-Änderungen — Verifikation + Push.

- [ ] **Step 1: Gesamte Suite final**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: `OK (247 tests, …)` — 12 Tests mehr als Ausgangsstand 235.

- [ ] **Step 2: Diff-Review gegen Spec**

```bash
git log --oneline origin/main..HEAD   # 5 Commits (Task 1-5)
git diff origin/main --stat
```
Checkliste: Guard (canonicalDigits, matchesFor, decide) ✓, Migration ✓, 2 Command-Aufrufpunkte ✓, Banner ✓, keine Dateien außerhalb platforms-recruiting ✓.

- [ ] **Step 3: Branch pushen (kein Merge!)**

```bash
git push -u origin feat/applicant-dedup-guard
```

- [ ] **Step 4: Übergabe an Maintainer**

Melden: Branch `feat/applicant-dedup-guard` gepusht, Review erbeten. NICHT selbst mergen. Nach Freigabe + ff-Merge auf main: **meingedeck composer.lock bumpen (Pflicht, sonst nicht live)**; KEIN `queue:restart` nötig (Scheduler-artisan). Danach Live-Check nach Forge-Deploy: Kleinanzeigen-Doppelbewerbung simulieren (zwei Anzeigen, gleiche Nummer) und in den Auto-Pilot-Logs `duplicate_detected` + Banner prüfen; Bestandsfall #2378/#2379 manuell auflösen (einen deaktivieren).
