# Die Stelle als eigenes Feld an der Bewerbung — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Stellenwechsel bei der Schulungsbuchung soll die Ausschreibungs-Verknüpfung der Bewerbung nicht mehr umschreiben, damit die KPI-Zahlen der Statistik-Seite stabil und richtig sind — ohne dass Mehrfach-Orte, Umbuchen oder die Warteliste anders funktionieren als heute.

**Architecture:** Die Stelle bekommt ein eigenes Feld (`rec_applicants.rec_position_id`). `primaryPosition()` bleibt die einzige Fassade und liest künftig dieses Feld, mit dem heutigen Pivot-Weg als dauerhaftem Fallback. Die zehn Stellen, die die Stelle heute selbst aus dem Pivot raten, rufen die Fassade. `switchToPosition()` setzt nur noch Feld und Phase; `detach()`/`attach()` fallen weg. Der Pivot bedeutet danach ausschließlich „woher kam die Bewerbung" und ist die Achse der Statistik.

**Tech Stack:** Laravel 11, Livewire 3, MySQL 8 (Tests: SQLite in-memory via Capsule), PHPUnit 11.

Spec: `docs/superpowers/specs/2026-08-18-stelle-an-der-bewerbung-design.md`

## Global Constraints

- Testrunner (das Modul hat kein eigenes `vendor/`, aus dem Repo-Root aufrufen): `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
- Zwei Test-Suiten: `tests/Unit` (kein Laravel-Bootstrap, keine `Illuminate\*`- oder `Carbon`-Imports in den geprüften Klassen) und `tests/Integration` (Container + Capsule von Hand, echte Migrationen per glob, `auth()` als Attrappe, feste Uhr). Muster für Integrationstests: `tests/Integration/InterviewPostingTeamScopeTest.php`.
- `phpunit.xml` hat `failOnWarning="true"` — ein `Undefined array key` dreht die Suite.
- Stand vor Task 1: **967 Tests grün**.
- Blades prüfen mit `php tools/blade-check.php <dateien>` — `php -l` prüft `.blade.php` **nicht**.
- Blade-Fallen: kein inline `@php(...)` (nur Block-Form), Hilfetext-Attribut heißt `hint` statt `help`, freier Nutzertext nie unescaped in einen `wire:click` (dafür `@js(...)`), keine `??`-Fallbacks an `:required` von `x-ui-*`.
- Der Marker-Wert für eine durch einen Stellenwechsel entstandene Verknüpfung heißt **genau** `'position_switch'` — identisch in Task 1 und Task 10.
- „Festgelegt" heißt: aktive (nicht storniert) Buchung **oder** Phase-`order` ≥ 3. Nur über `istFestgelegt()` prüfen, nie neu herleiten.
- Commit-Prefix `feat(recruiting):` / `fix(recruiting):` / `docs(recruiting):`, Umlaute in Commit-Messages als ae/oe/ue.
- Der Wächtertest `test_rekonziliation_jeder_genau_einmal` in `tests/Unit/Statistics/CohortAssignerTest.php` bleibt in jedem Task unangetastet.

## Dateien

**Stufe 0**
- Modify: `src/Models/RecApplicant.php` (`switchToPosition`, neuer privater Helfer)
- Modify: `src/Livewire/Public/InterviewBooking.php:530` (Termin mitgeben)
- Test: `tests/Integration/PositionSwitchPostingChoiceTest.php` (neu)

**Stufe 1**
- Create: `database/migrations/2026_08_18_000001_add_rec_position_id_to_rec_applicants.php`
- Modify: `src/Models/RecApplicant.php` (fillable, `position()`, `istFestgelegt()`, `primaryPosition()`, `switchToPosition()`, `reconcilePositionState()`)
- Modify die fünf Anlege-Wege: `src/Services/IncomingApplicationService.php`, `src/Services/ImportApplicantsCsvService.php`, `src/Livewire/Applicant/Index.php`, `src/Tools/CreateApplicantTool.php`, `src/Tools/BulkCreateApplicantsTool.php`
- Modify die zehn Leser: `src/Livewire/Public/InterviewBooking.php` (5×), `src/Livewire/DirectHire/Index.php` (3×), `src/Console/Commands/FixApplicantPhase.php`, `src/Console/Commands/SyncPhases.php`
- Modify: `src/Console/Commands/ReconcileApplicantPositions.php` (Festlegungs-Gate)
- Create: `src/Console/Commands/BackfillApplicantPosition.php`
- Test: `tests/Integration/ApplicantPositionFieldTest.php`, `tests/Integration/WaitlistUnchangedBySwitchTest.php`, `tests/Integration/BackfillApplicantPositionTest.php` (alle neu)

**Stufe 2**
- Modify: `src/Livewire/Statistics/Index.php` (Herkunft-unbekannt-Ablage, Bezifferung)
- Modify: `resources/views/livewire/statistics/index.blade.php` (fünfter Block, Fußnote)
- Test: `tests/Integration/StatisticsPageReconciliationTest.php` (erweitern)

---

# Stufe 0 — Sofort-Fix, eigener Merge

Diese Stufe ist allein lauffähig und wird sofort deployt. Sie wird durch Stufe 1 obsolet — bewusst, weil täglich etwa zwei Wechsel stattfinden und die Statistik-Seite schon live ist.

### Task 1: Der Stellenwechsel wählt die Ausschreibung des gebuchten Termins

**Files:**
- Modify: `src/Models/RecApplicant.php:1876-1928` (`switchToPosition`)
- Modify: `src/Livewire/Public/InterviewBooking.php:530`
- Test: `tests/Integration/PositionSwitchPostingChoiceTest.php`

**Interfaces:**
- Produces: `RecApplicant::switchToPosition(RecPosition $newPosition, ?RecInterview $interview = null): void` — der zweite Parameter ist optional, damit bestehende Aufrufer (und Tests) unverändert funktionieren.
- Produces: Pivot-Spalte `matched_via` trägt bei so entstandenen Verknüpfungen den Wert `'position_switch'`.

- [ ] **Step 1: Failing Test schreiben**

Neue Datei `tests/Integration/PositionSwitchPostingChoiceTest.php`. Aufbau exakt wie `tests/Integration/InterviewPostingTeamScopeTest.php` (Container + Capsule von Hand, echte Migrationen per glob, `auth()`-Attrappe, `Carbon::setTestNow`) — den Kopf dieser Datei als Vorlage kopieren, nur den Bestand austauschen.

Bestand: Team 8; Stelle 81 („Duesseldorf") mit Ausschreibung 810; Stelle 82 („Moenchengladbach") mit **drei** aktiven Ausschreibungen 820, 821, 822; ein Termin 830 an Stelle 82 mit `rec_posting_id = 821`; ein zweiter Termin 831 an Stelle 82 **ohne** `rec_posting_id`; Phase 101 (Stelle 81, `order` 1) und Phase 102 (Stelle 82, `order` 1); Bewerber 1010 mit `rec_phase_id = 101` und Pivot auf 810.

```php
    public function test_der_wechsel_nimmt_die_ausschreibung_des_gebuchten_termins(): void
    {
        $applicant = RecApplicant::find(1010);
        $interview = RecInterview::find(830);

        $applicant->switchToPosition(RecPosition::find(82), $interview);

        $applicant->load('postings');
        $this->assertSame([821], $applicant->postings->pluck('id')->all(),
            'die Ausschreibung des Termins gewinnt, nicht eine beliebige der Stelle');
    }

    public function test_ohne_ausschreibung_am_termin_ist_der_fallback_stabil(): void
    {
        // Zweimal derselbe Ausgangszustand muss dieselbe Ausschreibung ergeben —
        // vorher entschied die Reihenfolge, in der die Datenbank die Zeilen liefert.
        $ersteWahl = $this->wechselMitTermin(831);
        $zweiteWahl = $this->wechselMitTermin(831);

        $this->assertSame($ersteWahl, $zweiteWahl, 'der Fallback muss reproduzierbar sein');
        $this->assertContains($ersteWahl, [820, 821, 822]);
    }

    public function test_die_verknuepfung_ist_als_wechsel_markiert(): void
    {
        $applicant = RecApplicant::find(1010);
        $applicant->switchToPosition(RecPosition::find(82), RecInterview::find(830));

        $pivot = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1010)->first();

        $this->assertSame('position_switch', $pivot->matched_via,
            'ohne Marker kann die Statistik sie nicht von einer echten Bewerbung unterscheiden');
    }

    public function test_der_log_nennt_die_alte_stelle_und_anzeige(): void
    {
        $applicant = RecApplicant::find(1010);
        $applicant->switchToPosition(RecPosition::find(82), RecInterview::find(830));

        $log = Capsule::table('rec_auto_pilot_logs')
            ->where('rec_applicant_id', 1010)
            ->where('type', 'position_switched')
            ->orderByDesc('id')->first();

        $this->assertStringContainsString('Duesseldorf', $log->summary, 'alte Stelle');
        $this->assertStringContainsString('Kellner (m/w/d)', $log->summary, 'alte Anzeige');
    }
```

Helfer in derselben Testklasse — setzt den Bestand zurück und führt einen Wechsel aus:

```php
    private function wechselMitTermin(int $interviewId): int
    {
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', 1010)->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => 1010, 'rec_posting_id' => 810,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_phase_id' => 101]);

        $applicant = RecApplicant::find(1010);
        $applicant->switchToPosition(RecPosition::find(82), RecInterview::find($interviewId));

        return (int) Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1010)->value('rec_posting_id');
    }
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PositionSwitchPostingChoiceTest`
Expected: FAIL — `switchToPosition()` nimmt den zweiten Parameter noch nicht an (`ArgumentCountError` bzw. der Marker fehlt).

- [ ] **Step 3: Implementieren**

In `src/Models/RecApplicant.php` die Signatur erweitern und die Auswahl in einen Helfer ziehen:

```php
    public function switchToPosition(RecPosition $newPosition, ?RecInterview $interview = null): void
    {
        DB::transaction(function () use ($newPosition, $interview) {
            $currentOrder = $this->phase?->order;

            // VOR dem Loesen festhalten: danach ist die Herkunft nicht mehr lesbar.
            $this->loadMissing('postings.position');
            $alteAnzeigen = $this->postings->pluck('title')->filter()->implode(', ');
            $alteStellen = $this->postings
                ->map(fn ($p) => $p->position?->title)->filter()->unique()->implode(', ');

            $this->postings()->detach();

            $newPosting = $this->postingFuerStellenwechsel($newPosition, $interview);
            if (!$newPosting) {
                throw new \RuntimeException(
                    "Stelle '{$newPosition->title}' hat keine aktive Ausschreibung — Switch nicht möglich."
                );
            }

            $this->postings()->attach($newPosting->id, [
                'applied_at' => now()->toDateString(),
                // Diese Verknuepfung ist KEINE Bewerbung auf diese Anzeige. Der Marker
                // ist die einzige Spur davon, sobald die alte Verknuepfung geloescht
                // ist — die Statistik zaehlt sie damit nicht als Bewerbung mit.
                'matched_via' => 'position_switch',
            ]);
```

Der Rest der Methode (Phasen-Mapping, `PhaseTransitionTrigger`, `save()`, `remapExtraFieldValuesToPosition`) bleibt unverändert. Nur der Log-Eintrag am Ende wird erweitert:

```php
            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'position_switched',
                    'summary' => "Stelle gewechselt zu \"{$newPosition->title}\" durch Schulungs-Buchung"
                        . ($alteStellen !== '' ? " (vorher Stelle: {$alteStellen})" : '')
                        . ($alteAnzeigen !== '' ? ", Anzeige: {$alteAnzeigen}" : '')
                        . '.',
                ]);
            } catch (\Throwable) {}
```

Neuer privater Helfer direkt unter `switchToPosition()`:

```php
    /**
     * Welche Ausschreibung der neuen Stelle bekommt die Verknuepfung?
     *
     * Vorher stand hier `->where('is_active', true)->first()` OHNE Sortierung: die
     * Auswahl haing davon ab, in welcher Reihenfolge die Datenbank die Zeilen
     * liefert, und war damit nicht reproduzierbar. Gemessen wurden 15 Wechsel,
     * davon 11 in sechs Tagen — jeder verschob eine Bewerbung in eine
     * Statistik-Zeile, in die sie nicht gehoert.
     *
     * 1. Die Ausschreibung des GEBUCHTEN TERMINS ist die richtige Antwort: die
     *    Person geht zu genau dieser Schulung. Sie muss zur neuen Stelle gehoeren,
     *    sonst haetten wir das Problem nur verschoben.
     * 2. Ist am Termin keine gepflegt (das Feld ist neu), entscheidet die kleinste
     *    ID — beliebig, aber stabil. Reproduzierbar zu sein ist hier mehr wert als
     *    klug zu sein.
     */
    private function postingFuerStellenwechsel(RecPosition $newPosition, ?RecInterview $interview): ?RecPosting
    {
        if ($interview?->rec_posting_id !== null) {
            $ausTermin = $newPosition->postings()
                ->where('rec_postings.id', $interview->rec_posting_id)
                ->first();

            if ($ausTermin) {
                return $ausTermin;
            }
        }

        return $newPosition->postings()
            ->where('is_active', true)
            ->orderBy('rec_postings.id')
            ->first();
    }
```

Import ergänzen, falls nicht vorhanden: `use Platform\Recruiting\Models\RecInterview;` (im selben Namespace — dann kein Import nötig; prüfen).

In `src/Livewire/Public/InterviewBooking.php:530` den Termin mitgeben:

```php
        $applicant->switchToPosition($bookedPosition, $interview);
```

- [ ] **Step 4: Tests grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PositionSwitchPostingChoiceTest`
Expected: PASS (4 Tests)

Dann die volle Suite:
Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle grün (967 + 4)

- [ ] **Step 5: Mutationsprobe**

Ersetze im Helfer `->orderBy('rec_postings.id')` durch nichts (also zurück auf `first()` ohne Sortierung) und lass `--filter PositionSwitchPostingChoiceTest` laufen. Der Stabilitäts-Test kann dabei zufällig grün bleiben — deshalb zusätzlich die erste Bedingung (`$interview?->rec_posting_id !== null`) auskommentieren: `test_der_wechsel_nimmt_die_ausschreibung_des_gebuchten_termins` **muss** rot werden. Beides zurücksetzen und die Suite erneut grün laufen lassen.

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecApplicant.php src/Livewire/Public/InterviewBooking.php tests/Integration/PositionSwitchPostingChoiceTest.php
git commit -m "fix(recruiting): Stellenwechsel nimmt die Ausschreibung des gebuchten Termins"
```

- [ ] **Step 7: Deploy Stufe 0**

1. ff auf `main` mergen (nach Freigabe), meingedeck `composer.lock` bumpen und pushen
2. **Kein `migrate`** (keine Migration), **kein `queue:restart`** — `switchToPosition()` wird ausschließlich von der Buchungsseite gerufen (`InterviewBooking.php:530`), nicht aus Jobs
3. Live-Prüfung: eine Testbuchung in einer fremden Filiale, danach im Log des Bewerbers prüfen, dass die alte Stelle und Anzeige genannt sind

---

# Stufe 1 — Das Feld

### Task 2: Migration, Feld und Festlegungs-Regel am Model

**Files:**
- Create: `database/migrations/2026_08_18_000001_add_rec_position_id_to_rec_applicants.php`
- Modify: `src/Models/RecApplicant.php` (`$fillable`, neue Relation, `istFestgelegt()`)
- Test: `tests/Integration/ApplicantPositionFieldTest.php`

**Interfaces:**
- Produces: Spalte `rec_applicants.rec_position_id` (`?int`, FK auf `rec_positions`, `nullOnDelete`)
- Produces: `RecApplicant::position()` — `BelongsTo` auf `RecPosition`
- Produces: `RecApplicant::istFestgelegt(): bool` — Tasks 3, 6 und 7 lesen sie

- [ ] **Step 1: Failing Test schreiben**

Neue Datei `tests/Integration/ApplicantPositionFieldTest.php`, Aufbau wie `InterviewPostingTeamScopeTest`. Bestand: Team 8; Stellen 81, 82; Phasen 101 (Stelle 81, `order` 1), 103 (Stelle 81, `order` 3); Termin 830 an Stelle 82; Bewerber 1010 (Phase 101), 1011 (Phase 103), 1012 (Phase 101, mit aktiver Buchung auf 830), 1013 (Phase 101, mit **storniertem** Booking auf 830).

```php
    public function test_die_stelle_ist_ein_eigenes_feld_mit_relation(): void
    {
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);

        $applicant = RecApplicant::find(1010);

        $this->assertSame(82, (int) $applicant->rec_position_id);
        $this->assertSame('Moenchengladbach', $applicant->position?->title);
    }

    public function test_eine_geloeschte_stelle_nimmt_die_bewerbung_nicht_mit(): void
    {
        // nullOnDelete statt cascadeOnDelete: eine Stelle zu loeschen darf keine
        // Bewerbung vernichten. (Und Model-Events feuern bei DB-Kaskaden nicht.)
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);
        Capsule::table('rec_positions')->where('id', 82)->delete();

        $applicant = RecApplicant::find(1010);

        $this->assertNotNull($applicant, 'die Bewerbung bleibt');
        $this->assertNull($applicant->rec_position_id, 'die Stelle wird geleert');
    }

    public function test_festgelegt_gilt_ab_phase_drei_oder_aktiver_buchung(): void
    {
        $this->assertFalse(RecApplicant::find(1010)->istFestgelegt(), 'Phase 1, keine Buchung');
        $this->assertTrue(RecApplicant::find(1011)->istFestgelegt(), 'Phase 3 ohne Buchung');
        $this->assertTrue(RecApplicant::find(1012)->istFestgelegt(), 'Phase 1 mit aktiver Buchung');
        $this->assertFalse(RecApplicant::find(1013)->istFestgelegt(), 'eine STORNIERTE Buchung zaehlt nicht');
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ApplicantPositionFieldTest`
Expected: FAIL — die Spalte existiert nicht, `position()` und `istFestgelegt()` fehlen.

- [ ] **Step 3: Migration schreiben**

`database/migrations/2026_08_18_000001_add_rec_position_id_to_rec_applicants.php`:

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
            // nullOnDelete, NICHT cascadeOnDelete: eine geloeschte Stelle darf keine
            // Bewerbung mitnehmen. Model-Events feuern bei DB-Kaskaden ausserdem
            // nicht (daher gibt es im Modul den Kaskaden-Observer fuer Phasen).
            $table->foreignId('rec_position_id')->nullable()->after('rec_phase_id')
                ->constrained('rec_positions')->nullOnDelete();
            $table->index(['team_id', 'rec_position_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'rec_position_id']);
            $table->dropConstrainedForeignId('rec_position_id');
        });
    }
};
```

- [ ] **Step 4: Model ergänzen**

In `src/Models/RecApplicant.php` `'rec_position_id'` ins `$fillable` aufnehmen (neben `'rec_phase_id'`), und ergänzen:

```php
    /**
     * DIE Stelle der Bewerbung — wo die Person bearbeitet wird.
     *
     * Nicht verwechseln mit positions(): das liefert die Stellen der verknuepften
     * ANZEIGEN, also woher die Bewerbung kam. Beides war bis hierher dasselbe Feld,
     * und genau daran hat sich der Stellenwechsel die KPI-Zahlen verdorben.
     */
    public function position()
    {
        return $this->belongsTo(RecPosition::class, 'rec_position_id');
    }

    /**
     * Hat sich die Person auf einen Schulungsort festgelegt?
     *
     * Die Regel war bisher an drei Stellen abgeleitet (u. a.
     * InterviewBooking::resolvePositionIdsForApplicant) — hier steht sie einmal.
     * Eine STORNIERTE Buchung zaehlt nicht: wer storniert, waehlt neu und soll
     * wieder die Termine aller Wunschorte sehen.
     */
    public function istFestgelegt(): bool
    {
        if (($this->phase?->order ?? 0) >= 3) {
            return true;
        }

        return $this->interviewBookings()
            ->whereNotIn('status', ['cancelled'])
            ->exists();
    }
```

- [ ] **Step 5: Tests grün + volle Suite**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ApplicantPositionFieldTest`
Expected: PASS (3 Tests)

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle grün

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_18_000001_add_rec_position_id_to_rec_applicants.php src/Models/RecApplicant.php tests/Integration/ApplicantPositionFieldTest.php
git commit -m "feat(recruiting): Stelle als eigenes Feld an der Bewerbung, plus Festlegungs-Regel"
```

### Task 3: `primaryPosition()` liest das Feld

**Files:**
- Modify: `src/Models/RecApplicant.php:1855-1866` (`primaryPosition`)
- Test: `tests/Integration/ApplicantPositionFieldTest.php` (erweitern)

**Interfaces:**
- Consumes: `rec_position_id` und `position()` aus Task 2
- Produces: `primaryPosition(): ?RecPosition` mit unveränderter Signatur — die acht bestehenden Aufrufer bleiben unberührt

- [ ] **Step 1: Failing Tests anhängen**

```php
    public function test_die_fassade_liest_das_feld(): void
    {
        // Pivot zeigt auf Stelle 81, Feld auf 82 — das FELD gewinnt.
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);

        $this->assertSame(82, RecApplicant::find(1010)->primaryPosition()?->id);
    }

    public function test_ohne_feld_gilt_der_bisherige_weg(): void
    {
        // Bestandsdaten vor dem Backfill: das Feld ist leer, die Antwort muss
        // exakt die von heute sein (Stelle der fruehesten Anzeige).
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => null]);

        $this->assertSame(81, RecApplicant::find(1010)->primaryPosition()?->id);
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter ApplicantPositionFieldTest`
Expected: `test_die_fassade_liest_das_feld` FAIL (liefert 81, erwartet 82)

- [ ] **Step 3: Implementieren**

```php
    /**
     * DIE Stelle der Bewerbung.
     *
     * Liest das Feld rec_position_id. Der frueher hier stehende Weg (Stelle der
     * fruehesten verknuepften Anzeige) bleibt als Fallback, solange das Feld leer
     * ist — und zwar DAUERHAFT, nicht nur bis zum Backfill: entstehen Bewerbungen
     * ueber einen Weg, an dem das Setzen vergessen wurde, ist ein veralteter Wert
     * besser als gar keiner. Ein entfernter Fallback machte daraus einen stillen
     * Datenfehler.
     *
     * Wer die Stelle braucht, ruft diese Methode — nicht postings->first(). Genau
     * das Raten an zehn Stellen war der Grund, warum der Stellenwechsel den Pivot
     * umschreiben musste.
     */
    public function primaryPosition(): ?RecPosition
    {
        if ($this->rec_position_id !== null) {
            return $this->position;
        }

        return $this->postings
            ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
            ->first()
            ?->position;
    }
```

- [ ] **Step 4: Tests grün + volle Suite**

Run: `… --filter ApplicantPositionFieldTest` → PASS (5 Tests)
Run: volle Suite → alle grün. **Besonders hinsehen:** Tests rund um MA-Anlage (`CreateEmployeeFromApplicantService`) und HR-Desk-Routing hängen an dieser Methode.

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecApplicant.php tests/Integration/ApplicantPositionFieldTest.php
git commit -m "feat(recruiting): primaryPosition liest die Stelle aus dem Feld, Pivot bleibt Fallback"
```

### Task 4: Die fünf Anlege-Wege setzen die Stelle

**Files:**
- Modify: `src/Services/IncomingApplicationService.php:114`
- Modify: `src/Services/ImportApplicantsCsvService.php:232`
- Modify: `src/Livewire/Applicant/Index.php:265`
- Modify: `src/Tools/CreateApplicantTool.php:113`
- Modify: `src/Tools/BulkCreateApplicantsTool.php:141`
- Test: `tests/Integration/ApplicantPositionFieldTest.php` (erweitern)

**Interfaces:**
- Consumes: `rec_position_id` aus Task 2

- [ ] **Step 1: Failing Test anhängen**

```php
    public function test_beim_verknuepfen_einer_anzeige_wird_die_stelle_gesetzt(): void
    {
        // Der gemeinsame Nenner aller fuenf Anlege-Wege: sie haengen eine Anzeige
        // an. Geprueft wird der Effekt, nicht jeder Weg einzeln — die Wege selbst
        // brauchen eine gebootete App.
        $applicant = RecApplicant::create([
            'uuid' => 'apf-neu-1', 'team_id' => self::TEAM, 'applied_at' => '2026-08-01',
        ]);
        $applicant->postings()->attach(810, ['applied_at' => '2026-08-01']);
        $applicant->refresh()->stelleAusAnzeigeUebernehmen();

        $this->assertSame(81, (int) $applicant->fresh()->rec_position_id);
    }

    public function test_ohne_anzeige_bleibt_die_stelle_leer(): void
    {
        // Import ohne Bindung, Inbound ohne Match: kein Raten, kein Default.
        // "Leer heisst nicht gepflegt" — die Statistik benennt diese Faelle.
        $applicant = RecApplicant::create([
            'uuid' => 'apf-neu-2', 'team_id' => self::TEAM, 'applied_at' => '2026-08-01',
        ]);
        $applicant->stelleAusAnzeigeUebernehmen();

        $this->assertNull($applicant->fresh()->rec_position_id);
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter ApplicantPositionFieldTest`
Expected: FAIL — `stelleAusAnzeigeUebernehmen()` existiert nicht.

- [ ] **Step 3: Eine Methode für alle fünf Wege**

In `src/Models/RecApplicant.php`:

```php
    /**
     * Setzt die Stelle aus der verknuepften Anzeige — EINE Stelle fuer alle fuenf
     * Anlege-Wege (Inbound, CSV-Import, manuelle Anlage, zwei MCP-Werkzeuge).
     *
     * Fuenf Kopien derselben Zuweisung waeren fuenf Stellen, an denen sie beim
     * naechsten neuen Anlege-Weg vergessen wird — dieses Modul hat das mit der
     * Taetigkeit und mit der Ausschreibung am Termin schon zweimal erlebt.
     *
     * Ueberschreibt NICHT: ist die Stelle schon gesetzt, gilt sie. Sonst wuerde ein
     * nachtraeglich verknuepftes Posting eine Festlegung zurueckdrehen.
     */
    public function stelleAusAnzeigeUebernehmen(): void
    {
        if ($this->rec_position_id !== null) {
            return;
        }

        $this->loadMissing('postings');
        $positionId = $this->postings
            ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
            ->first()
            ?->rec_position_id;

        if ($positionId === null) {
            return;
        }

        $this->rec_position_id = (int) $positionId;
        $this->save();
    }
```

Danach in allen fünf Anlege-Wegen **nach** dem `attach()` der Anzeige aufrufen. Beispiel `IncomingApplicationService.php` (nach dem Block um Zeile 149, in dem `matched_via` gesetzt wird):

```php
            $applicant->stelleAusAnzeigeUebernehmen();
```

Die anderen vier Stellen genauso: `ImportApplicantsCsvService.php` (nach dem Attach im Import-Zweig), `Applicant/Index.php` (nach dem Attach in der manuellen Anlage), `CreateApplicantTool.php:175`, `BulkCreateApplicantsTool.php:196`. Den Aufruf **nur** dort setzen, wo tatsächlich eine Anzeige angehängt wird — ein wirkungsloser Aufruf an einem Weg ohne Anzeige wäre toter Code. Gibt es einen Anlege-Weg ohne Anzeigen-Verknüpfung, im Bericht benennen statt ihn zu dekorieren.

- [ ] **Step 4: Tests grün + volle Suite**

Run: `… --filter ApplicantPositionFieldTest` → PASS (7 Tests)
Run: volle Suite → alle grün

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecApplicant.php src/Services/IncomingApplicationService.php src/Services/ImportApplicantsCsvService.php src/Livewire/Applicant/Index.php src/Tools/CreateApplicantTool.php src/Tools/BulkCreateApplicantsTool.php tests/Integration/ApplicantPositionFieldTest.php
git commit -m "feat(recruiting): alle fuenf Anlege-Wege setzen die Stelle der Bewerbung"
```

### Task 5: Der Stellenwechsel lässt den Pivot in Ruhe

**Files:**
- Modify: `src/Models/RecApplicant.php` (`switchToPosition`, `postingFuerStellenwechsel` entfällt)
- Test: `tests/Integration/PositionSwitchPostingChoiceTest.php` (umschreiben)

**Interfaces:**
- Consumes: `rec_position_id` (Task 2), `primaryPosition()` (Task 3)
- Produces: `switchToPosition(RecPosition $newPosition, ?RecInterview $interview = null): void` — Signatur bleibt, der zweite Parameter wird nicht mehr gebraucht und entfällt

- [ ] **Step 1: Tests umschreiben**

Die vier Tests aus Task 1 prüfen die Auswahl einer Ausschreibung, die es nicht mehr gibt. Sie werden **ersetzt**, nicht gelöscht — der Testgegenstand wandert von „welche Anzeige wird gewählt" auf „keine Anzeige wird angefasst":

```php
    public function test_der_wechsel_setzt_die_stelle(): void
    {
        $applicant = RecApplicant::find(1010);

        $applicant->switchToPosition(RecPosition::find(82));

        $this->assertSame(82, (int) $applicant->fresh()->rec_position_id);
        $this->assertSame(82, $applicant->fresh()->primaryPosition()?->id);
    }

    public function test_der_wechsel_laesst_die_herkunft_unberuehrt(): void
    {
        // Das ist der Kern des ganzen Umbaus: die Bewerbung bleibt bei der Anzeige,
        // die sie gebracht hat. Vorher wurde sie geloescht und durch eine beliebige
        // Anzeige der neuen Stelle ersetzt.
        $vorher = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1010)->get()->toArray();

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $nachher = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1010)->get()->toArray();

        $this->assertEquals($vorher, $nachher, 'kein detach, kein attach, kein neues applied_at');
    }

    public function test_die_phase_wandert_weiter_mit(): void
    {
        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $phase = RecPhase::find(RecApplicant::find(1010)->rec_phase_id);

        $this->assertSame(82, (int) $phase->rec_position_id, 'Phase gehoert jetzt zur neuen Stelle');
        $this->assertSame(1, (int) $phase->order, 'dieselbe order wie vorher');
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter PositionSwitchPostingChoiceTest`
Expected: `test_der_wechsel_setzt_die_stelle` und `test_der_wechsel_laesst_die_herkunft_unberuehrt` FAIL.

- [ ] **Step 3: Implementieren**

`switchToPosition()` verliert die drei Pivot-Zeilen und den Helfer `postingFuerStellenwechsel()` (aus Task 1 — er wird ersatzlos gelöscht). Neu:

```php
    /**
     * Legt die Bewerbung auf eine Stelle fest: setzt die Stelle, mappt die Phase
     * auf dieselbe `order` der neuen Stelle und haengt die Extra-Feld-Werte um.
     *
     * Der Pivod wird NICHT angefasst — er sagt, woher die Bewerbung kam, und das
     * aendert sich durch eine Festlegung nicht. Vorher loeschte diese Methode die
     * Verknuepfung und haengte eine beliebige Anzeige der neuen Stelle an; die
     * Statistik zaehlte die Bewerbung danach bei einer Anzeige, auf die sich
     * niemand beworben hatte.
     *
     * $interview wird nicht mehr gebraucht (bis Stufe 0 waehlte es die Anzeige) und
     * bleibt nur als Parameter erhalten, solange Aufrufer es uebergeben.
     */
    public function switchToPosition(RecPosition $newPosition, ?RecInterview $interview = null): void
    {
        DB::transaction(function () use ($newPosition) {
            $currentOrder = $this->phase?->order;
            $alteStelle = $this->primaryPosition()?->title;

            $this->rec_position_id = $newPosition->id;

            if ($currentOrder !== null) {
                $newPhase = RecPhase::where('rec_position_id', $newPosition->id)
                    ->where('order', $currentOrder)
                    ->where('is_active', true)
                    ->first();
                if ($newPhase) {
                    $this->rec_phase_id = $newPhase->id;
                }
            }

            PhaseTransitionTrigger::set($this->id, PhaseTransitionTrigger::POSITION_SWITCH);
            try {
                $this->save();
            } finally {
                PhaseTransitionTrigger::forget($this->id);
            }

            $this->remapExtraFieldValuesToPosition($newPosition);

            try {
                RecAutoPilotLog::create([
                    'rec_applicant_id' => $this->id,
                    'type' => 'position_switched',
                    'summary' => "Stelle gewechselt zu \"{$newPosition->title}\" durch Schulungs-Buchung"
                        . ($alteStelle !== null ? " (vorher: {$alteStelle})" : '') . '.',
                ]);
            } catch (\Throwable) {}
        });
    }
```

Der `RuntimeException`-Zweig („Stelle hat keine aktive Ausschreibung") entfällt — er war nur nötig, weil eine Anzeige gebraucht wurde. Prüfen, dass kein Aufrufer ihn fängt: `grep -rn "Switch nicht möglich" src/`.

- [ ] **Step 4: Tests grün + volle Suite**

Run: `… --filter PositionSwitchPostingChoiceTest` → PASS
Run: volle Suite → alle grün

- [ ] **Step 5: Mutationsprobe**

Baue `$this->postings()->detach();` wieder an den Anfang der Transaktion ein. `test_der_wechsel_laesst_die_herkunft_unberuehrt` **muss** rot werden. Zurücksetzen, Suite grün.

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecApplicant.php tests/Integration/PositionSwitchPostingChoiceTest.php
git commit -m "feat(recruiting): Stellenwechsel setzt nur die Stelle, die Herkunft bleibt unberuehrt"
```

### Task 6: HR-Korrektur zieht die Stelle nur mit, solange nicht festgelegt

**Files:**
- Modify: `src/Models/RecApplicant.php:1950-2020` (`reconcilePositionState`)
- Modify: `src/Console/Commands/ReconcileApplicantPositions.php:72-101`
- Test: `tests/Integration/ApplicantPositionFieldTest.php` (erweitern)

**Interfaces:**
- Consumes: `istFestgelegt()` (Task 2), `primaryPosition()` (Task 3)

- [ ] **Step 1: Failing Tests anhängen**

```php
    public function test_vor_der_festlegung_zieht_die_stelle_mit(): void
    {
        // HR korrigiert eine falsch zugeordnete Anzeige: Stelle 81 -> 82.
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 81]);
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', 1010)->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => 1010, 'rec_posting_id' => 820,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        RecApplicant::find(1010)->reconcilePositionState();

        $this->assertSame(82, (int) RecApplicant::find(1010)->rec_position_id);
    }

    public function test_nach_der_festlegung_bleibt_die_stelle_stehen(): void
    {
        // 1012 hat eine aktive Buchung. Eine Korrektur der Anzeige darf ihn nicht
        // aus der Filiale ziehen, in der er zur Schulung angemeldet ist.
        Capsule::table('rec_applicants')->where('id', 1012)->update(['rec_position_id' => 81]);
        Capsule::table('rec_applicant_posting')->where('rec_applicant_id', 1012)->delete();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => 1012, 'rec_posting_id' => 820,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        RecApplicant::find(1012)->reconcilePositionState();

        $this->assertSame(81, (int) RecApplicant::find(1012)->rec_position_id,
            'die Festlegung gewinnt gegen die Anzeigen-Korrektur');
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter ApplicantPositionFieldTest`
Expected: `test_vor_der_festlegung_zieht_die_stelle_mit` FAIL (bleibt auf 81).

- [ ] **Step 3: Implementieren**

In `reconcilePositionState()` **vor** dem bestehenden `$primaryPosition = $this->primaryPosition();` die Stelle nachziehen — sonst liest die Methode gleich wieder den alten Wert:

```php
        $this->loadMissing(['postings.position', 'phase', 'team']);

        // Die Stelle folgt der korrigierten Anzeige — aber nur, solange sich die
        // Person nicht festgelegt hat. Ab aktiver Buchung oder Phase 3 gewinnt die
        // Festlegung: eine Anzeigen-Korrektur darf niemanden aus der Filiale
        // ziehen, in der er zur Schulung angemeldet ist.
        if (! $this->istFestgelegt()) {
            $ausAnzeige = $this->postings
                ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
                ->first()
                ?->rec_position_id;

            if ($ausAnzeige !== null && (int) $ausAnzeige !== (int) $this->rec_position_id) {
                $this->rec_position_id = (int) $ausAnzeige;
                $this->save();
            }
        }

        $primaryPosition = $this->primaryPosition();
```

Im Heil-Command `ReconcileApplicantPositions.php` dieselbe Regel: es ruft `primaryPosition()` (`:72`) und gleicht die Phase ab. Da es `reconcilePositionState()` nicht selbst benutzt, den Gate-Aufruf dort ergänzen — im Bericht des Kommandos ausweisen, wie viele Bewerbungen wegen Festlegung übersprungen wurden.

- [ ] **Step 4: Tests grün + volle Suite**

Run: `… --filter ApplicantPositionFieldTest` → PASS (9 Tests)
Run: volle Suite → alle grün

- [ ] **Step 5: Commit**

```bash
git add src/Models/RecApplicant.php src/Console/Commands/ReconcileApplicantPositions.php tests/Integration/ApplicantPositionFieldTest.php
git commit -m "feat(recruiting): Anzeigen-Korrektur zieht die Stelle nur vor der Festlegung mit"
```

### Task 7: Die zehn Leser rufen die Fassade — mit Wartelisten-Regression

**Files:**
- Modify: `src/Livewire/Public/InterviewBooking.php:145`, `:219`, `:242`, `:373`, `:464`
- Modify: `src/Livewire/DirectHire/Index.php:81`, `:95`, `:272`
- Modify: `src/Console/Commands/FixApplicantPhase.php:75`
- Modify: `src/Console/Commands/SyncPhases.php:68`
- Test: `tests/Integration/WaitlistUnchangedBySwitchTest.php`

**Interfaces:**
- Consumes: `primaryPosition()` (Task 3), `istFestgelegt()` (Task 2)

- [ ] **Step 1: Failing Tests schreiben**

Neue Datei `tests/Integration/WaitlistUnchangedBySwitchTest.php`. Bestand: Team 8; Stellen 81 (`beschaftigungsort_lookup_value = 'essen'`) und 82 (`'koeln'`); Ausschreibungen 810 (Stelle 81) und 820 (Stelle 82); Termin 830 an Stelle 82; Bewerber 1010 mit Pivot auf 810, Phase 101, **ohne** gepflegte Wunschorte; ein offener Wartelisten-Eintrag 900 für 1010 mit `wunschorte = ['essen']`, `armed = true`, `notified_at = null`.

```php
    public function test_ein_stellenwechsel_veraendert_keinen_wartelisten_eintrag(): void
    {
        $vorher = Capsule::table('rec_interview_waitlist')->where('id', 900)->first();

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $nachher = Capsule::table('rec_interview_waitlist')->where('id', 900)->first();

        $this->assertEquals($vorher, $nachher,
            'wunschorte, armed und notified_at duerfen sich nicht bewegen');
    }

    public function test_der_fallback_ort_folgt_der_stelle(): void
    {
        // Ohne gepflegte Wunschorte faellt das Eintragen auf den Ort der Stelle
        // zurueck. Nach der Festlegung ist das die NEUE Stelle — vorher kam
        // derselbe Wert aus dem umgeschriebenen Pivot.
        $applicant = RecApplicant::find(1010);
        $applicant->switchToPosition(RecPosition::find(82));

        $orte = WaitlistEnrollmentPlanner::resolveWunschorte(
            $applicant->fresh()->getExtraField('beschaftigungsort'),
            $applicant->fresh()->primaryPosition()?->beschaftigungsort_lookup_value,
        );

        $this->assertSame(['koeln'], $orte);
    }

    public function test_die_benachrichtigung_trifft_dieselbe_menge(): void
    {
        // Der Trigger-Pfad liest den Ort des frei gewordenen Termins und vergleicht
        // ihn gegen den Schnappschuss im Eintrag — die Stelle des Bewerbers kommt
        // darin nicht vor. Ein Wechsel darf daran nichts aendern.
        $treffer = fn () => RecInterviewWaitlist::query()
            ->where('team_id', self::TEAM)->whereNull('notified_at')
            ->whereNull('rec_interview_id')
            ->whereJsonContains('wunschorte', 'essen')
            ->pluck('rec_applicant_id')->all();

        $vorher = $treffer();
        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $this->assertSame($vorher, $treffer());
        $this->assertSame([1010], $vorher, 'der Eintrag bleibt fuer essen zustaendig');
    }

    public function test_ein_termin_abo_gewinnt_weiter_gegen_das_ort_abo(): void
    {
        // Skip-Logik aus NotifyWaitlistForInterview: wer ein offenes Termin-Abo fuer
        // genau diesen Termin hat, wird vom Ort-Zweig uebersprungen.
        Capsule::table('rec_interview_waitlist')->insert([
            'id' => 901, 'uuid' => 'wl-901', 'team_id' => self::TEAM,
            'rec_applicant_id' => 1010, 'rec_interview_id' => 830,
            'wunschorte' => json_encode([]), 'armed' => 1,
            'enrolled_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $terminAbo = RecInterviewWaitlist::query()
            ->where('rec_interview_id', 830)->whereNull('notified_at')
            ->pluck('rec_applicant_id')->all();

        $this->assertSame([1010], $terminAbo, 'das Termin-Abo bleibt unberuehrt bestehen');
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter WaitlistUnchangedBySwitchTest`
Expected: `test_der_fallback_ort_folgt_der_stelle` FAIL, solange die Aufrufstellen noch `postings->first()` lesen (liefert `essen`).

- [ ] **Step 3: Die zehn Stellen umstellen**

Muster für alle: statt `$applicant->postings->first()?->position?->X` künftig `$applicant->primaryPosition()?->X`, und statt `$applicant->postings->first()?->rec_position_id` künftig `$applicant->primaryPosition()?->id`.

`InterviewBooking.php:145` (Ort-Button der Warteliste):

```php
        return WaitlistEnrollmentPlanner::resolveWunschorte(
            $applicant->getExtraField('beschaftigungsort'),
            $applicant->primaryPosition()?->beschaftigungsort_lookup_value,
        ) !== [];
```

`:373` und `:464` (Eintragen und Re-Enrollment): dieselbe Ersetzung des zweiten Arguments.

`:219` (welche Termine sichtbar sind): `$primaryId = $applicant->primaryPosition()?->id;` — und die Herleitung von `$isCommitted` (`:220-224`) durch `$applicant->istFestgelegt()` ersetzen, damit die Regel nur an einer Stelle steht.

`:242` (Cut-Over-Schutz): `$primaryTitle = $applicant->primaryPosition()?->title ?? '';`

`DirectHire/Index.php:81`, `:95`, `:272`: `$a->primaryPosition()?->id` bzw. `$applicant?->primaryPosition()`. **Achtung Query-Budget:** `:81` gruppiert eine Sammlung — `with('position')` im vorgelagerten Laden ergänzen, sonst entsteht ein N+1.

`FixApplicantPhase.php:75` und `SyncPhases.php:68`: `$primaryPosition = $applicant->primaryPosition();` und die nachfolgenden Zugriffe auf die Stelle statt auf das Posting umstellen.

- [ ] **Step 4: Tests grün + volle Suite**

Run: `… --filter WaitlistUnchangedBySwitchTest` → PASS (4 Tests)
Run: volle Suite → alle grün

- [ ] **Step 5: Query-Budget prüfen**

Miss die Zahl der Queries der Buchungsseite und der Direkteinstellung vor und nach der Änderung (Query-Log im Test aktivieren, wie in `StatisticsCohortWiringTest`). Erwartung: gleich viele oder weniger — `primaryPosition()` liest über die `position`-Relation und ist mit `with('position')` eager-ladbar. Wird es mehr, fehlt ein Eager Load; im Bericht die Zahlen nennen.

- [ ] **Step 6: Commit**

```bash
git add src/Livewire/Public/InterviewBooking.php src/Livewire/DirectHire/Index.php src/Console/Commands/FixApplicantPhase.php src/Console/Commands/SyncPhases.php tests/Integration/WaitlistUnchangedBySwitchTest.php
git commit -m "feat(recruiting): alle Leser der Stelle rufen die Fassade statt den Pivot zu raten"
```

### Task 8: Backfill-Kommando

**Files:**
- Create: `src/Console/Commands/BackfillApplicantPosition.php`
- Modify: `src/RecruitingServiceProvider.php` (Kommando registrieren — prüfen, wie die anderen dort eingetragen sind)
- Test: `tests/Integration/BackfillApplicantPositionTest.php`

**Interfaces:**
- Consumes: `rec_position_id` (Task 2)
- Produces: `php artisan recruiting:backfill-applicant-position [--dry-run] [--team-id=]`

- [ ] **Step 1: Failing Test schreiben**

```php
    public function test_der_backfill_setzt_die_stelle_aus_der_fruehesten_anzeige(): void
    {
        // 1010 hat Pivot auf 810 (Stelle 81) und ein leeres Feld.
        $this->runBackfill();

        $this->assertSame(81, (int) RecApplicant::find(1010)->rec_position_id);
    }

    public function test_der_backfill_ueberschreibt_nichts(): void
    {
        Capsule::table('rec_applicants')->where('id', 1010)->update(['rec_position_id' => 82]);

        $this->runBackfill();

        $this->assertSame(82, (int) RecApplicant::find(1010)->rec_position_id,
            'ein gepflegter Wert bleibt — der Backfill fuellt nur Luecken');
    }

    public function test_dry_run_schreibt_nicht(): void
    {
        $this->runBackfill(dryRun: true);

        $this->assertNull(RecApplicant::find(1010)->rec_position_id);
    }

    public function test_altfaelle_werden_als_wechsel_markiert(): void
    {
        // Bewerber 1014: seine Verknuepfung entstand durch einen Stellenwechsel
        // (erkennbar am Transition-Log mit trigger position_switch). Die
        // urspruengliche Anzeige ist geloescht und nicht rekonstruierbar — die
        // vorhandene wird markiert, damit die Statistik sie nicht als Bewerbung
        // dieser Anzeige zaehlt.
        $this->runBackfill();

        $pivot = Capsule::table('rec_applicant_posting')
            ->where('rec_applicant_id', 1014)->first();

        $this->assertSame('position_switch', $pivot->matched_via);
        $this->assertSame(82, (int) RecApplicant::find(1014)->rec_position_id,
            'die Stelle bleibt die, auf die er sich festgelegt hat');
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter BackfillApplicantPositionTest`
Expected: FAIL — das Kommando existiert nicht.

- [ ] **Step 3: Kommando schreiben**

`src/Console/Commands/BackfillApplicantPosition.php`, Aufbau wie `BackfillEmployeeFieldsFromApplicant` (Docblock mit Hintergrund, `--dry-run`, `--team-id`, Tabellen-Ausgabe, idempotent):

```php
    protected $signature = 'recruiting:backfill-applicant-position
        {--dry-run : Nur anzeigen, was gesetzt wuerde}
        {--team-id= : Auf ein Team beschraenken}';

    protected $description = 'Fuellt rec_applicants.rec_position_id aus der fruehesten verknuepften Anzeige (nie ueberschreibend)';
```

Kern:

```php
        RecApplicant::query()
            ->whereNull('rec_position_id')
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->with('postings')
            ->chunkById(500, function ($applicants) use ($dryRun, &$gesetzt, &$ohneAnzeige, &$markiert) {
                foreach ($applicants as $applicant) {
                    // Der Backfill ist EXAKT: bis zu diesem Umbau war die Stelle
                    // definitionsgemaess die der fruehesten Anzeige (primaryPosition).
                    $positionId = $applicant->postings
                        ->sortBy(fn ($p) => $p->pivot?->applied_at ?? $p->pivot?->created_at)
                        ->first()
                        ?->rec_position_id;

                    if ($positionId === null) {
                        $ohneAnzeige++;   // bleibt leer, kein Raten
                        continue;
                    }

                    if (! $dryRun) {
                        $applicant->rec_position_id = (int) $positionId;
                        $applicant->save();
                    }
                    $gesetzt++;
                }
            });
```

Danach die Altfälle markieren — erkennbar am Transition-Log:

```php
        $altfaelle = DB::table('rec_phase_transitions')
            ->where('trigger', PhaseTransitionTrigger::POSITION_SWITCH)
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->distinct()
            ->pluck('rec_applicant_id');

        if (! $dryRun && $altfaelle->isNotEmpty()) {
            DB::table('rec_applicant_posting')
                ->whereIn('rec_applicant_id', $altfaelle)
                ->whereNull('matched_via')
                ->update(['matched_via' => 'position_switch']);
        }
        $markiert = $altfaelle->count();
```

`whereNull('matched_via')` ist wichtig: eine echte Match-Information (aus dem Inbound-Matching) darf nicht überschrieben werden.

`PhaseTransitionTrigger::POSITION_SWITCH` ist derselbe String `'position_switch'` wie der Pivot-Marker — zwei verschiedene Spalten, ein Vokabular. Die Konstante importieren (`use Platform\Recruiting\Support\PhaseTransitionTrigger;`), nicht das Literal tippen.

- [ ] **Step 4: Tests grün + volle Suite**

Run: `… --filter BackfillApplicantPositionTest` → PASS (4 Tests)
Run: volle Suite → alle grün

- [ ] **Step 5: Commit**

```bash
git add src/Console/Commands/BackfillApplicantPosition.php src/RecruitingServiceProvider.php tests/Integration/BackfillApplicantPositionTest.php
git commit -m "feat(recruiting): Backfill-Kommando fuer die Stelle der Bewerbung"
```

### Task 9: Deploy Stufe 1

**Files:** keine

- [ ] **Step 1:** Volle Suite grün, `php tools/blade-check.php resources/views/livewire/**/*.blade.php` ohne Funde
- [ ] **Step 2:** ff auf `main` mergen (nach Freigabe), meingedeck `composer.lock` bumpen und pushen
- [ ] **Step 3:** `php artisan migrate`
- [ ] **Step 4:** `php artisan recruiting:backfill-applicant-position --dry-run`, Zahlen prüfen (erwartet: fast alle Bewerbungen bekommen eine Stelle, „ohne Anzeige" nur die bekannten Fälle, ~15 markierte Altfälle), dann ohne `--dry-run`
- [ ] **Step 5:** **`php artisan queue:restart`** — `HrDeskRoutingService` liest die Fassade und wird über den Buchungs-Observer (`Observers/RecInterviewBookingComplianceObserver.php:52`) auch in Worker-Prozessen ausgelöst
- [ ] **Step 6: Live-Prüfungen**

1. Ein Bewerber in Phase 1 mit zwei Wunschorten sieht die Termine **beider** Orte
2. Nach einer Buchung sieht er nur noch die Termine des gebuchten Ortes
3. Nach der Buchung zeigt die Bewerber-Detailseite die neue Stelle, und die Statistik zählt ihn weiter bei der **alten** Anzeige
4. Ein Wartelisten-Eintrag ohne gepflegte Wunschorte trägt nach der Buchung den Ort der gebuchten Stelle
5. Eine Einstellung aus einer festgelegten Bewerbung erzeugt einen Mitarbeiter mit der Stelle der Festlegung

---

# Stufe 2 — Die Seite

### Task 10: Block „Herkunft unbekannt"

**Files:**
- Modify: `src/Livewire/Statistics/Index.php` (`cohort()`: eigene Ablage)
- Modify: `resources/views/livewire/statistics/index.blade.php` (fünfter Block)
- Test: `tests/Integration/StatisticsPageReconciliationTest.php`

**Interfaces:**
- Consumes: Pivot-Marker `matched_via = 'position_switch'` (Tasks 1 und 8)

- [ ] **Step 1: Failing Test anhängen**

```php
    public function test_gewanderte_bewerbungen_zaehlen_in_keiner_anzeigen_zeile(): void
    {
        // Bewerber 2010: seine Verknuepfung ist als Wechsel markiert, die echte
        // Herkunft ist nicht mehr bekannt. Sie darf keiner Anzeige zugeschlagen
        // werden — sonst zaehlt eine Anzeige eine Bewerbung, die sie nie erhielt.
        $cohort = $this->cohortFuerOrt('Essen');

        $inZeilen = collect($cohort['rows'])->flatMap(fn ($r) => $r['ids'])->all();
        $this->assertNotContains(2010, $inZeilen);

        $this->assertContains(2010, collect($cohort['unknown_origin_rows'])
            ->flatMap(fn ($r) => $r['ids'])->all(), 'aber sie wird benannt');
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `… --filter StatisticsPageReconciliationTest`
Expected: FAIL — `unknown_origin_rows` existiert nicht.

- [ ] **Step 3: Implementieren**

In `cohort()` beim Aufbau der Pivot-Liste (`$pivots[$a->id]`) den Marker mitlesen und markierte Verknüpfungen **nicht** in die Pivot-Liste geben, sondern die Bewerbung in eine eigene Ablage legen — analog zu `unreachable_rows` (vierter Block). Der Assigner sieht sie dann als „ohne Ausschreibung", die Ablage benennt sie getrennt:

```php
            // Verknuepfungen aus einem Stellenwechsel sind KEINE Bewerbung auf diese
            // Anzeige (Marker aus switchToPosition bzw. dem Backfill). Sie zaehlen in
            // keiner Anzeigen-Zeile mit — sonst bekaeme die Anzeige eine Bewerbung,
            // die sie nie erhalten hat. Benannt werden sie im eigenen Block.
            $pivots[$a->id] = $a->postings
                ->filter(fn ($p) => $p->pivot?->matched_via !== 'position_switch')
                ->filter(fn ($p) => $postingId === null || (int) $p->id === $postingId)
                ->map(fn ($p) => [ /* unveraendert */ ])->all();
```

Im Blade der fünfte Block, Aufbau wie die vier vorhandenen (aufklappbar, Zahl im Kopf, Drill-down über den vorhandenen `posting`-Scope), mit einem Text, der sagt, was der Fall ist: dass diese Bewerbungen vor der Umstellung die Stelle gewechselt haben und ihre ursprüngliche Anzeige nicht mehr bekannt ist.

- [ ] **Step 4: Tests grün + blade-check + volle Suite**

Run: `php tools/blade-check.php resources/views/livewire/statistics/index.blade.php` → OK
Run: volle Suite → alle grün

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Statistics/Index.php resources/views/livewire/statistics/index.blade.php tests/Integration/StatisticsPageReconciliationTest.php
git commit -m "feat(recruiting): Block benennt Bewerbungen mit unbekannter Herkunft"
```

### Task 11: „In einer anderen Filiale eingestellt" beziffern

**Files:**
- Modify: `src/Livewire/Statistics/Index.php`
- Modify: `resources/views/livewire/statistics/postings-table.blade.php` (Fußnote)
- Test: `tests/Integration/StatisticsPageReconciliationTest.php`

**Interfaces:**
- Consumes: `rec_position_id` (Task 2), Row-Shape der Statistik

- [ ] **Step 1: Failing Test anhängen**

```php
    public function test_einstellungen_in_anderer_filiale_werden_beziffert(): void
    {
        // 2011 kam ueber die Essener Anzeige, hat sich auf Koeln festgelegt und
        // unterschrieben. Die Unterschrift zaehlt bei der Essener Anzeige
        // (Entscheidung der Spec) — dass die Person woanders arbeitet, wird genannt.
        $zahlen = $this->fremdeFilialeZahlen('Essen');

        $this->assertSame(1, $zahlen['count']);
        $this->assertStringContainsString('anderen Filiale', $zahlen['reason']);
    }
```

- [ ] **Step 2: FAIL verifizieren** — Run: `… --filter StatisticsPageReconciliationTest`, Expected: FAIL

- [ ] **Step 3: Implementieren**

Je Zeile die Unterschriften zählen, deren Bewerbung eine `rec_position_id` **ungleich** der Stelle der Anzeige trägt, und die Summe als Fußnote unter Tabelle 1 ausgeben — im selben Muster wie die vorhandenen Fußnoten („NICHT im Zähler: …"), also mit Zahl und Erklärung in einem Satz. Kein neuer Rechenweg: die Zahlen kommen aus den vorhandenen Zeilen.

- [ ] **Step 4: Tests grün + blade-check + volle Suite**
- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Statistics/Index.php resources/views/livewire/statistics/postings-table.blade.php tests/Integration/StatisticsPageReconciliationTest.php
git commit -m "feat(recruiting): Einstellungen in anderer Filiale werden beziffert"
```

### Task 12: Deploy Stufe 2

**Files:** keine

- [ ] **Step 1:** ff auf `main`, meingedeck-Bump. Kein `migrate`, **kein** `queue:restart` (nur Views und Lese-Logik)
- [ ] **Step 2: Sichtprüfungen**

1. Der Rekonziliations-Hinweis erscheint **nicht**
2. Der Block „Herkunft unbekannt" nennt eine Zahl in der Größenordnung 15
3. Die Fußnote „in einer anderen Filiale eingestellt" nennt eine plausible Zahl (≤ die Zahl aus 2)
4. Eine Ausschreibung, über die jemand kam, der inzwischen woanders arbeitet, zeigt ihn weiter in „Bewerbungen"

---

## Self-Review

**Spec-Coverage:** §4 Datenmodell → Task 2; §5.1 Anlage → Task 4; §5.2 Festlegung → Task 5 (Sofort-Variante in Task 1); §5.3 HR-Korrektur → Task 6; §6 Lesewege → Tasks 3 und 7; §7 Warteliste (vier Pflichttests) → Task 7 Step 1; §8 Statistik → Tasks 10 und 11; §9 Migration und Backfill → Tasks 2 und 8; §10 Kanten → Task 2 (Storno via `istFestgelegt`, gelöschte Stelle) und Task 4 (Bewerbung ohne Stelle); §11 Tests → über alle Tasks verteilt; §12 Reihenfolge → Stufen 0/1/2 mit Deploy-Tasks 1 Step 7, 9 und 12.

**Nicht abgedeckt und bewusst so:** die Umbenennung `primaryPosition()` → `stelle()` (Nicht-Ziel der Spec) und die drei Tickets aus §13.

**Platzhalter:** keine — jeder Code-Schritt trägt seinen Code, jeder Test seine Assertions. Die Blade-Schritte (Tasks 10, 11) beschreiben Struktur und Anker statt vollständiges Markup, weil die Vorlage die vier bestehenden Blöcke in derselben Datei sind; abgetippte 80 Zeilen Markup veralten schneller als sie helfen.

**Typ-Konsistenz:** `rec_position_id` (`?int`), `position()` (`BelongsTo`), `istFestgelegt(): bool`, `stelleAusAnzeigeUebernehmen(): void`, `primaryPosition(): ?RecPosition` — in allen Tasks unter genau diesen Namen. Der Marker-String `'position_switch'` ist in Tasks 1, 8 und 10 identisch. `switchToPosition()` behält seine Signatur über Task 1 und Task 5 hinweg, damit kein Aufrufer zweimal angefasst werden muss.
