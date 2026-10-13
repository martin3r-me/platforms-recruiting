# Jobs anbieten Runde 2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Seiten von „Jobs anbieten“ schneller bedienbar machen und dem Disponenten die Fakten geben, die er zum Aussuchen und Eintragen braucht — acht Punkte (A–H) der Spec.

**Architecture:** Jede Verbesserung bekommt einen kleinen Baustein unter `src/Services/Zas/Dispo/Jobs/` (rein rechnend oder dünner DB-Rand), die Livewire-Komponenten rufen ihn nur auf. Eine Migration (Endzeit an der Schicht, Rücknahme-Stempel an der Zusage). Die Mitarbeiter-Seite bekommt einen Rücknahme-Weg mit derselben Sperr-Disziplin wie die Zusage.

**Tech Stack:** Laravel 11, Livewire 3 (`#[Computed]` nur per Property-Zugriff, `#[Locked]`, `#[Url]`), Alpine für die Zwischenablage, PHPUnit mit Capsule/SQLite (kein Testbench), `php tools/blade-check.php`.

**Spec:** `docs/superpowers/specs/2026-10-14-dispo-jobs-anbieten-runde2-design.md` (Runde 1: `docs/superpowers/specs/2026-10-12-dispo-jobs-anbieten-design.md`)

## Global Constraints

- Worktree `.claude/worktrees/dispo-jobs-runde2`, Branch `feat/dispo-jobs-runde2` ab origin/main `b12641a9`. Nur Dateien in platforms-recruiting.
- Kundentexte in Du-Form, nie „buchen“, keine technischen Begriffe. Eine Zusage ist nie „gebucht“.
- Keine Vorbelegung von Zahlen, keine Farblogik, die etwas empfiehlt. Bewertungen als fünf Einzelwerte, kein Gesamtschnitt.
- Livewire: `#[Computed]` cached nur bei Property-Zugriff (`$this->schichten`, nie `$this->schichten()`). Zahlenfelder als String-Properties. Keine inline-`@if` in `x-ui`-Attributen, Direktiven nie an Wortzeichen geklebt (Block-Form, vorberechnen).
- Tests: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`. Integrationstests mit Capsule/SQLite und ECHTEN Migrationen (jede angefasste Tabelle muss in der Liste stehen, sonst wertet SQLite den Bezeichner als String-Literal). Blade-Prüfung: `php tools/blade-check.php <datei>`.
- Commits mit Trailer `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Kein Merge, kein Push auf main, kein Bump ohne Freigabe.
- Keine Änderung an Versand (`DispoJobOfferSender`), Wellen-Job (`SendDispoJobWave`), Planner, ZAS-Abgleich.

## Review Focus

1. Rücknahme und ZAS-Haken im selben Moment: die Zusage darf nicht verschwinden, nachdem der Disponent sie als eingetragen markiert hat → bedingtes Update, Test in Task 8.
2. Rücknahme bei „Wer zuerst kommt“ aus einer vollen Schicht: der Platz muss wieder frei werden (`closed_at` zurück auf null), sonst bleibt die Schicht für immer „voll“ → Test in Task 8.
3. Fortschritt mit `gesucht = 0` oder ohne Schichten: keine Division durch null, 0 % → Test in Task 3.
4. Badge in der Sidebar auf JEDER Seite: fehlt die Tabelle oder fällt die DB aus, muss 0 kommen, nie eine Exception → Test in Task 7.
5. Endzeit vor Beginn bei einer freien Schicht („Ende 10:00, Beginn 16:00“): Meldung statt gespeicherter Unsinn → Test in Task 1 (`DispoJobZeit::problem`).

---

### Task 1: Migration, Modelle, Endzeit-Bausteine

**Files:**
- Create: `database/migrations/2026_10_14_000001_add_bis_and_withdrawn_at_to_rec_dispo_job_tables.php`
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobZeit.php`
- Modify: `src/Models/RecDispoJobShift.php` (fillable `bis`)
- Modify: `src/Models/RecDispoJobOfferShift.php` (fillable/cast `withdrawn_at`)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobSchichten.php` (`bis` je Gruppe)
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobAnfrageAnlegen.php:93-115` (`bis` speichern)
- Test: `tests/Unit/Dispo/Jobs/DispoJobZeitTest.php` (neu), `tests/Unit/Dispo/Jobs/DispoJobSchichtenTest.php` (anpassen), `tests/Integration/DispoJobCreateFlowTest.php` (anpassen)

**Interfaces:**
- Produces: `DispoJobZeit::spanne(?string $von, ?string $bis): string` („16:00–23:00“, „16:00“, „“); `DispoJobZeit::problem(string $von, string $bis): ?string` (Meldung oder null); `DispoJobSchichten::optionen()` liefert je Schicht zusätzlich `bis` (zwischen `von` und `taetigkeit`); `RecDispoJobShift->bis`; `RecDispoJobOfferShift->withdrawn_at` (Carbon|null); `DispoJobAnfrageAnlegen::anlegen()` nimmt in `schichten[]` optional `bis`.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runde 2 „Jobs anbieten“ (Spec 2026-10-14): Endzeit an der Schicht (nur
 * Anzeige, NICHT Teil des Unique-Index) und Ruecknahme-Stempel an der Zusage.
 * Bestand: bis = '' zeigt weiter nur den Beginn; withdrawn_at bleibt null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->string('bis', 8)->default('')->after('von');
        });
        Schema::table('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->timestamp('withdrawn_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_shifts', fn (Blueprint $t) => $t->dropColumn('bis'));
        Schema::table('rec_dispo_job_offer_shifts', fn (Blueprint $t) => $t->dropColumn('withdrawn_at'));
    }
};
```

- [ ] **Step 2: Modelle**

`RecDispoJobShift`: `$fillable = ['request_id', 'rec_dispo_event_id', 'datum', 'von', 'bis', 'taetigkeit', 'gesucht', 'closed_at'];`

`RecDispoJobOfferShift`: `$fillable = ['offer_id', 'shift_id', 'accepted_at', 'withdrawn_at', 'zas_entered_at', 'zas_entered_by_user_id', 'zas_assignment_id'];` und `$casts` um `'withdrawn_at' => 'datetime'` ergänzen.

- [ ] **Step 3: Failing test für DispoJobZeit**

`tests/Unit/Dispo/Jobs/DispoJobZeitTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit;

final class DispoJobZeitTest extends TestCase
{
    public function testSpanneZeigtBeginnBisEndeOderNurBeginn(): void
    {
        $this->assertSame('16:00–23:00', DispoJobZeit::spanne('16:00', '23:00'));
        $this->assertSame('16:00', DispoJobZeit::spanne('16:00', ''));
        $this->assertSame('16:00', DispoJobZeit::spanne(' 16:00 ', null));
        $this->assertSame('', DispoJobZeit::spanne('', '23:00'), 'ohne Beginn keine Spanne');
        $this->assertSame('', DispoJobZeit::spanne(null, null));
    }

    public function testProblemBeiUngueltigerOderVerdrehterZeit(): void
    {
        $this->assertNull(DispoJobZeit::problem('16:00', '23:00'));
        $this->assertNull(DispoJobZeit::problem('16:00', ''), 'Ende ist optional');
        $this->assertNull(DispoJobZeit::problem('', ''), 'beides leer ist erlaubt');
        $this->assertSame('Der Beginn muss als Uhrzeit (HH:MM) angegeben werden.', DispoJobZeit::problem('16h', ''));
        $this->assertSame('Das Ende muss als Uhrzeit (HH:MM) angegeben werden.', DispoJobZeit::problem('16:00', '23'));
        $this->assertSame('Das Ende muss nach dem Beginn liegen.', DispoJobZeit::problem('16:00', '10:00'));
        $this->assertSame('Das Ende muss nach dem Beginn liegen.', DispoJobZeit::problem('16:00', '16:00'));
        $this->assertNull(DispoJobZeit::problem('', '23:00'), 'Ende ohne Beginn: kein Vergleich moeglich, kein Fehler');
    }
}
```

- [ ] **Step 4: Test laufen lassen — FAIL**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoJobZeitTest`
Expected: FAIL mit „Class … DispoJobZeit not found“

- [ ] **Step 5: DispoJobZeit schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Zeitspanne einer Schicht fuer die Anzeige („16:00–23:00“) und die Pruefung
 * einer von Hand eingegebenen Schicht (Runde 2, Spec §8). Rein rechnend.
 */
final class DispoJobZeit
{
    public const MUSTER = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function spanne(?string $von, ?string $bis): string
    {
        $von = trim((string) $von);
        $bis = trim((string) $bis);
        if ($von === '') {
            return '';
        }

        return $bis !== '' ? $von . '–' . $bis : $von;
    }

    /** Meldung fuer den Disponenten oder null. Beide Felder duerfen leer sein. */
    public static function problem(string $von, string $bis): ?string
    {
        $von = trim($von);
        $bis = trim($bis);
        if ($von !== '' && preg_match(self::MUSTER, $von) !== 1) {
            return 'Der Beginn muss als Uhrzeit (HH:MM) angegeben werden.';
        }
        if ($bis !== '' && preg_match(self::MUSTER, $bis) !== 1) {
            return 'Das Ende muss als Uhrzeit (HH:MM) angegeben werden.';
        }
        if ($von !== '' && $bis !== '' && $bis <= $von) {
            return 'Das Ende muss nach dem Beginn liegen.';
        }

        return null;
    }
}
```

- [ ] **Step 6: Test laufen lassen — PASS**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoJobZeitTest`
Expected: PASS (2 Tests)

- [ ] **Step 7: Failing test für `bis` in DispoJobSchichten**

In `tests/Unit/Dispo/Jobs/DispoJobSchichtenTest.php` den Helfer und die Erwartungen umstellen. `zeile()` bekommt einen `bis`-Parameter mit Standard `null`:

```php
    private static function zeile(string $datum, ?string $von, string $taet, array $extra = [], ?string $bis = null): array
    {
        return $extra + ['datum' => $datum, 'von' => $von, 'bis' => $bis, 'taetigkeit' => $taet, 'pnr_raw' => 'RG100',
            'missing_since' => null, 'deletion_marked_at' => null];
    }
```

In allen bestehenden Erwartungen `'von' => '…'` um `'bis' => ''` direkt dahinter ergänzen, z. B. `['von' => '12:00', 'bis' => '', 'taetigkeit' => 'Service', 'zeilen' => 1, 'platzhalter' => 0]`. Neuer Test:

```php
    public function testEndzeitIstDieHaeufigsteBeiGleichstandDieFrueheste(): void
    {
        $rows = [
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], '23:00'),
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], '23:00'),
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], '22:00'),
            self::zeile('2026-10-24', '17:00', 'Zapfer', [], null),
            self::zeile('2026-10-24', '12:00', 'Service', [], '20:00'),
            self::zeile('2026-10-24', '12:00', 'Service', [], '18:00'),
            self::zeile('2026-10-24', '19:00', 'Logistik', [], ''),
        ];
        $out = DispoJobSchichten::optionen($rows, ['2026-10-24']);

        $this->assertSame('18:00', $out['2026-10-24'][0]['bis'], 'Gleichstand: die fruehere');
        $this->assertSame('23:00', $out['2026-10-24'][1]['bis'], 'die haeufigste; leere zaehlen nicht');
        $this->assertSame('', $out['2026-10-24'][2]['bis'], 'nirgends eine Endzeit');
        $this->assertSame(['von', 'bis', 'taetigkeit', 'zeilen', 'platzhalter'], array_keys($out['2026-10-24'][0]));
    }
```

- [ ] **Step 8: Test laufen lassen — FAIL**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoJobSchichtenTest`
Expected: FAIL (fehlender Schlüssel `bis`)

- [ ] **Step 9: DispoJobSchichten::optionen um `bis` ergänzen**

In der Schleife nach `$row ??= …`:

```php
            $row ??= ['von' => $von, 'bis' => '', 'taetigkeit' => $taet, 'zeilen' => 0, 'platzhalter' => 0, '_bis' => []];
            $row['zeilen']++;
            $bis = trim((string) self::feld($a, 'bis'));
            if ($bis !== '') {
                $row['_bis'][$bis] = ($row['_bis'][$bis] ?? 0) + 1;
            }
```

Nach der Schleife, in der Sortier-Schleife je Zeile die Endzeit bestimmen und `_bis` entfernen:

```php
        foreach ($out as $datum => $rows) {
            $rows = array_values(array_map(function (array $r) {
                $zaehler = $r['_bis'];
                unset($r['_bis']);
                if ($zaehler !== []) {
                    ksort($zaehler, SORT_STRING);          // bei Gleichstand gewinnt die fruehere
                    arsort($zaehler, SORT_NUMERIC);        // stabil: haeufigste zuerst
                    $r['bis'] = (string) array_key_first($zaehler);
                }

                return $r;
            }, $rows));
            usort($rows, fn ($x, $y) => [$x['von'], mb_strtolower($x['taetigkeit'])] <=> [$y['von'], mb_strtolower($y['taetigkeit'])]);
            $out[$datum] = $rows;
        }
```

Docblock-Rückgabetyp auf `list<array{von:string, bis:string, taetigkeit:string, zeilen:int, platzhalter:int}>` ändern. Hinweis: `arsort` in PHP 8 ist stabil, deshalb bleibt nach `ksort` bei gleicher Häufigkeit die frühere Zeit vorn.

- [ ] **Step 10: Test laufen lassen — PASS**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoJobSchichtenTest`
Expected: PASS

- [ ] **Step 11: `bis` in DispoJobAnfrageAnlegen speichern + Test**

In `tests/Integration/DispoJobCreateFlowTest.php` die Migration `database/migrations/2026_10_14_000001_add_bis_and_withdrawn_at_to_rec_dispo_job_tables.php` in die Liste (nach `…000002_add_anlass…`) aufnehmen. In `test_legt_anfrage_schichten_und_welle_1_an` die erste Schicht in `daten()` um `'bis' => '23:00'` ergänzen und prüfen:

```php
        $this->assertSame('23:00', $shifts[0]->bis);
        $this->assertSame('', $shifts[1]->bis, 'ohne Ende: Leerstring, nie null');
```

Run: Expected FAIL (Spalte `bis` bleibt '' / unknown key). Dann in `DispoJobAnfrageAnlegen::schichten()`:

```php
            $von = trim((string) ($r['von'] ?? ''));
            $bis = trim((string) ($r['bis'] ?? ''));
            if (preg_match(DispoJobZeit::MUSTER, $bis) !== 1) {
                $bis = '';
            }
            …
            $out[$key] = ['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => $von, 'bis' => $bis, 'taetigkeit' => $taet, 'gesucht' => $gesucht];
```

Docblock der Methode und von `anlegen()` um `bis?:string` ergänzen. Run: Expected PASS.

- [ ] **Step 12: Gesamte Suite + Commit**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml > /tmp/r2-t1.txt 2>&1; tail -3 /tmp/r2-t1.txt`
Expected: OK (nur PHPUnit-Deprecations). Andere Tests, die Jobs-Tabellen migrieren (`JobOffersPageTest`, `DispoJobZasAbgleichTest`, `SendDispoJobWaveJobTest`, `DispoJobOfferSenderTest`, `DispoJobTablesTest`), brauchen die neue Migration NICHT, solange sie `bis`/`withdrawn_at` nicht anfassen — aber `DispoJobTablesTest` prüft evtl. Spaltenlisten: anpassen, falls er rot wird.

```bash
git add database/migrations/2026_10_14_000001_add_bis_and_withdrawn_at_to_rec_dispo_job_tables.php src/Models/RecDispoJobShift.php src/Models/RecDispoJobOfferShift.php src/Services/Zas/Dispo/Jobs/DispoJobZeit.php src/Services/Zas/Dispo/Jobs/DispoJobSchichten.php src/Services/Zas/Dispo/Jobs/DispoJobAnfrageAnlegen.php tests/Unit/Dispo/Jobs/DispoJobZeitTest.php tests/Unit/Dispo/Jobs/DispoJobSchichtenTest.php tests/Integration/DispoJobCreateFlowTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Endzeit an der Schicht, Ruecknahme-Stempel, DispoJobZeit

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: B — Entscheidungshilfe je Zusage

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobZusagenInfo.php`
- Modify: `src/Livewire/Dispo/Jobs/Show.php:153-173` (`rueckmeldungen()`)
- Modify: `resources/views/livewire/dispo/jobs/show.blade.php` (Tabelle Rückmeldungen)
- Test: `tests/Integration/DispoJobZusagenInfoTest.php` (neu), `tests/Unit/DispoJobsRunde2WiringTest.php` (neu, wird in späteren Tasks erweitert)

**Interfaces:**
- Consumes: `DispoIdentityResolver::groupsFor(list<int>): array<int,list<int>>`; `DispoEmployeeGateway::contacts(list<int>)` (name, first_name, phone, …); `DispoEmployeeGateway::profileCards(list<int>)` (name, personnel_number, ratings: array<label,int>, qualifications: list<string>); `DispoEinsatzZaehler::proMonat(list<array>, array<int,int>, string)`; `DispoTagesPruefung`-Zeilenform.
- Produces: `DispoJobZusagenInfo::laden(array $employeeIds, string $monat): array<int, array{name:string, personnel_number:string, phone:string, einsaetze:int, taetigkeiten:list<string>, ratings:array<string,int>}>`; `DispoJobZusagenInfo::passt(string $taetigkeit, array $taetigkeiten): bool`; `DispoJobZusagenInfo::bewertung(array $ratings): array{text:string, title:string}`.

- [ ] **Step 1: Failing test**

`tests/Integration/DispoJobZusagenInfoTest.php` — Aufbau wie `DispoJobKandidatenTest` (Capsule, Migrationen aus dessen `runMigrations()` 1:1 übernehmen **plus** `database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php`), Konfiguration `recruiting.zas.inbound_team_id => 3` für den Gruppen-Test.

```php
    public function test_eine_zeile_je_mitarbeiter_mit_einsaetzen_taetigkeiten_und_fuenf_bewertungen(): void
    {
        $a = $this->employee(['first_name' => 'Anna', 'last_name' => 'Ast', 'phone' => '0176 12345678', 'personnel_number' => 'RG1']);
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $a,
            'dispo_taetigkeiten' => json_encode(['Zapfer', 'Logistiker']),
            'rating_erscheinungsbild' => 4, 'rating_fachkompetenz' => 5, 'rating_auffassungsgabe' => 3, 'rating_auftreten' => 4, 'rating_teamintegration' => 4,
            'created_at' => now(), 'updated_at' => now()]);
        $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
        foreach (['2026-10-02', '2026-10-20', '2026-09-30'] as $d) {
            RecDispoAssignment::create(['ds_ref' => 'd' . $d, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => $d, 'status_id' => 1]);
        }
        RecDispoAssignment::create(['ds_ref' => 'abgesagt', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $a, 'datum' => '2026-10-21', 'status_id' => 1, 'declined_at' => now()]);

        $info = DispoJobZusagenInfo::laden([$a, 999999], '2026-10');

        $this->assertSame([$a], array_keys($info), 'unbekannte IDs fallen raus');
        $this->assertSame('Anna Ast', $info[$a]['name']);
        $this->assertSame('RG1', $info[$a]['personnel_number']);
        $this->assertStringStartsWith('+49', $info[$a]['phone']);
        $this->assertSame(2, $info[$a]['einsaetze'], 'nur Oktober, abgesagte zaehlen nicht');
        $this->assertSame(['Zapfer', 'Logistiker'], $info[$a]['taetigkeiten']);
        $this->assertSame([4, 5, 3, 4, 4], array_values($info[$a]['ratings']));
    }

    public function test_gruppe_rg_und_ma_zaehlt_einsaetze_zusammen_und_fuellt_luecken(): void
    {
        // Gruppierung braucht den Team-Anker (Muster DispoJobKandidatenTest) — am Ende zuruecksetzen.
        config(['recruiting.zas.inbound_team_id' => 3]);
        try {
            $this->gruppenFall();
        } finally {
            config(['recruiting.zas.inbound_team_id' => 0]);
        }
    }

    private function gruppenFall(): void
    {
        $rg = $this->employee(['first_name' => 'Alt', 'personnel_number' => 'RG7', 'phone' => null]);
        $ma = $this->employee(['first_name' => 'Neu', 'personnel_number' => 'MA7', 'phone' => '0176 12345678']);
        foreach ([$rg, $ma] as $id) {
            Capsule::table('crm_contact_links')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'contact_id' => 77, 'team_id' => 3, 'created_by_user_id' => 1,
                'linkable_id' => $id, 'linkable_type' => (new RecEmployee())->getMorphClass(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $rg,
            'dispo_taetigkeiten' => json_encode(['Zapfer']), 'star_rating' => 4, 'created_at' => now(), 'updated_at' => now()]);
        Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'rec_employee_id' => $ma,
            'dispo_taetigkeiten' => json_encode(['Koch']), 'created_at' => now(), 'updated_at' => now()]);
        $event = RecDispoEvent::create(['einsatz_ref' => 'E2']);
        RecDispoAssignment::create(['ds_ref' => 'g1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $rg, 'datum' => '2026-10-03', 'status_id' => 1]);
        RecDispoAssignment::create(['ds_ref' => 'g2', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $ma, 'datum' => '2026-10-04', 'status_id' => 1]);

        $info = DispoJobZusagenInfo::laden([$ma], '2026-10');

        $this->assertSame(2, $info[$ma]['einsaetze'], 'beide Datensaetze derselben Person');
        $this->assertSame(['Koch', 'Zapfer'], $info[$ma]['taetigkeiten'], 'Vereinigung, sortiert');
        $this->assertSame(['Sternebewertung (Altbestand)' => 4], $info[$ma]['ratings'], 'Luecke aus dem RG-Datensatz gefuellt');
    }

    public function test_passt_und_bewertungstext(): void
    {
        $this->assertTrue(DispoJobZusagenInfo::passt(' zapfer ', ['Zapfer']));
        $this->assertFalse(DispoJobZusagenInfo::passt('Koch', ['Zapfer']));
        $this->assertFalse(DispoJobZusagenInfo::passt('Koch', []));

        $this->assertSame(['text' => '4 · 5 · 3 · 4 · 4', 'title' => 'Erscheinungsbild & Hygiene 4, Fachliche Grundkompetenz 5, Auffassungsgabe & Lernbereitschaft 3, Auftreten & Kommunikation 4, Teamintegration & Verhalten 4'],
            DispoJobZusagenInfo::bewertung(['Erscheinungsbild & Hygiene' => 4, 'Fachliche Grundkompetenz' => 5, 'Auffassungsgabe & Lernbereitschaft' => 3, 'Auftreten & Kommunikation' => 4, 'Teamintegration & Verhalten' => 4]));
        $this->assertSame(['text' => '★ 4', 'title' => 'Sternebewertung (Altbestand) 4'], DispoJobZusagenInfo::bewertung(['Sternebewertung (Altbestand)' => 4]));
        $this->assertSame(['text' => '–', 'title' => 'keine Bewertung'], DispoJobZusagenInfo::bewertung([]));
    }

    public function test_leere_liste_fragt_nichts(): void
    {
        $this->assertSame([], DispoJobZusagenInfo::laden([], '2026-10'));
    }
```

`employee()`-Helfer wie in `DispoJobKandidatenTest` (`personnel_number` kommt aus der Migration `2026_05_22_000001_add_personnel_number_to_rec_employees.php`). `setUp()` leert zusätzlich nichts Neues.

- [ ] **Step 2: Test — FAIL**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter DispoJobZusagenInfoTest`
Expected: FAIL „Class … DispoJobZusagenInfo not found“

- [ ] **Step 3: Baustein schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Entscheidungshilfe je Zusage (Runde 2, Spec §4): Einsaetze im laufenden
 * Monat, ZAS-Taetigkeiten und die fuenf Termin-Bewertungen je Person — fuer
 * den Disponenten, der im Modus „sammeln“ aussucht. Ein Durchlauf fuer alle
 * Zeilen, nie pro Zeile. Fakten, keine Empfehlung: keine Sortierung, kein Schnitt.
 */
final class DispoJobZusagenInfo
{
    /**
     * @param list<int> $employeeIds rec_employee_id der Angebots-Zeilen
     * @return array<int, array{name:string, personnel_number:string, phone:string, einsaetze:int, taetigkeiten:list<string>, ratings:array<string,int>}>
     */
    public static function laden(array $employeeIds, string $monat): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), fn ($v) => $v > 0)));
        if ($ids === []) {
            return [];
        }

        $groups = app(DispoIdentityResolver::class)->groupsFor($ids);
        $anchorByEmployee = [];
        foreach ($groups as $id => $group) {
            foreach ($group as $member) {
                $anchorByEmployee[(int) $member] = min($group);
            }
        }
        $alle = array_values(array_unique(array_merge($ids, array_keys($anchorByEmployee))));

        $gateway = app(DispoEmployeeGateway::class);
        $contacts = $gateway->contacts($ids);
        $cards = $gateway->profileCards($alle);

        $ende = \Carbon\Carbon::parse($monat . '-01')->endOfMonth()->toDateString();
        $assignments = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $alle)
            ->whereBetween('datum', [$monat . '-01', $ende])
            ->get(['rec_employee_id', 'datum', 'status_id', 'missing_since', 'deletion_marked_at', 'declined_at'])
            ->map(fn ($a) => ['employee_id' => (int) $a->rec_employee_id, 'datum' => $a->datum?->format('Y-m-d') ?? '',
                'status_id' => (int) $a->status_id, 'missing_since' => $a->missing_since, 'deletion_marked_at' => $a->deletion_marked_at, 'declined_at' => $a->declined_at])
            ->all();
        $einsaetze = DispoEinsatzZaehler::proMonat($assignments, $anchorByEmployee, $monat);

        $out = [];
        foreach ($ids as $id) {
            if (!isset($cards[$id]) && !isset($contacts[$id])) {
                continue;
            }
            $group = $groups[$id] ?? [$id];
            $ratings = [];
            $taetigkeiten = [];
            foreach (array_merge([$id], $group) as $member) {
                $card = $cards[(int) $member] ?? null;
                if ($card === null) {
                    continue;
                }
                if ($ratings === [] && $card['ratings'] !== []) {
                    $ratings = $card['ratings'];
                }
                $taetigkeiten = array_merge($taetigkeiten, $card['qualifications']);
            }
            $taetigkeiten = array_values(array_unique(array_map('strval', $taetigkeiten)));
            sort($taetigkeiten, SORT_NATURAL | SORT_FLAG_CASE);

            $out[$id] = [
                'name'             => (string) ($contacts[$id]['name'] ?? $cards[$id]['name'] ?? ('#' . $id)),
                'personnel_number' => (string) ($cards[$id]['personnel_number'] ?? ''),
                'phone'            => (string) ($contacts[$id]['phone'] ?? ''),
                'einsaetze'        => (int) ($einsaetze[$anchorByEmployee[$id] ?? $id] ?? 0),
                'taetigkeiten'     => $taetigkeiten,
                'ratings'          => $ratings,
            ];
        }

        return $out;
    }

    /** Vergleich wie DispoJobRecipientPlanner: kleingeschrieben, getrimmt. */
    public static function passt(string $taetigkeit, array $taetigkeiten): bool
    {
        $eigene = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $taetigkeiten);

        return in_array(mb_strtolower(trim($taetigkeit)), $eigene, true);
    }

    /** @param array<string,int> $ratings @return array{text:string, title:string} */
    public static function bewertung(array $ratings): array
    {
        if ($ratings === []) {
            return ['text' => '–', 'title' => 'keine Bewertung'];
        }
        $title = implode(', ', array_map(fn ($label, $wert) => $label . ' ' . $wert, array_keys($ratings), $ratings));
        if (count($ratings) === 1 && str_starts_with((string) array_key_first($ratings), 'Sternebewertung')) {
            return ['text' => '★ ' . reset($ratings), 'title' => $title];
        }

        return ['text' => implode(' · ', array_values($ratings)), 'title' => $title];
    }
}
```

Hinweis für den Gruppen-Test: `profileCards` liest `hrData` über die Relation; `qualifications` sind die ZAS-Tätigkeiten.

- [ ] **Step 4: Test — PASS**

Run: `… --filter DispoJobZusagenInfoTest` → PASS (4 Tests). Scheitert `groupsFor` an fehlender `crm_contact_links`-Tabelle: Migration aus `DispoJobKandidatenTest` übernommen? Prüfen.

- [ ] **Step 5: Show::rueckmeldungen() umstellen**

```php
    /** @return array{zeilen: array<int, list<RecDispoJobOfferShift>>, info: array<int, array<string,mixed>>} */
    #[Computed]
    public function rueckmeldungen(): array
    {
        $shiftIds = $this->schichten->pluck('id')->all();
        if ($shiftIds === []) {
            return ['zeilen' => [], 'info' => []];
        }
        $zeilen = RecDispoJobOfferShift::query()
            ->whereIn('shift_id', $shiftIds)
            ->whereNotNull('accepted_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', $this->requestId))
            ->with('offer:id,rec_employee_id,anchor_employee_id')
            ->orderBy('accepted_at')
            ->get();
        $ids = $zeilen->map(fn ($z) => (int) $z->offer?->rec_employee_id)->filter()->unique()->values()->all();

        return [
            'zeilen' => $zeilen->groupBy('shift_id')->map(fn ($g) => $g->all())->all(),
            'info'   => DispoJobZusagenInfo::laden($ids, now()->format('Y-m')),
        ];
    }
```

`use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo;` ergänzen; der `DispoEmployeeGateway`-Import bleibt nur, wenn er noch anderswo in der Datei gebraucht wird (prüfen, sonst entfernen).

- [ ] **Step 6: Tabelle in show.blade.php**

Kopfzeile der Rückmeldungs-Tabelle ersetzen:

```blade
                            <tr class="text-left text-xs text-gray-500">
                                <th class="py-1 pr-3 font-medium">Name</th>
                                <th class="py-1 pr-3 font-medium">Zusage</th>
                                <th class="py-1 pr-3 font-medium">Handynummer</th>
                                <th class="py-1 pr-3 font-medium" title="Auftrags-Einsatztage im laufenden Monat">Einsätze</th>
                                <th class="py-1 pr-3 font-medium">Tätigkeit</th>
                                <th class="py-1 pr-3 font-medium">Bewertung</th>
                                <th class="py-1 font-medium">ZAS</th>
                            </tr>
```

Im Zeilen-`@php` ersetzen:

```blade
                                @php
                                    $maId = (int) ($z->offer?->rec_employee_id ?? 0);
                                    $info = $rueck['info'][$maId] ?? null;
                                    $maName = $info['name'] ?? ('#' . $maId);
                                    $taetPasst = $info !== null && \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo::passt((string) $s->taetigkeit, $info['taetigkeiten']);
                                    $taetListe = $info !== null && $info['taetigkeiten'] !== [] ? implode(', ', $info['taetigkeiten']) : 'keine Tätigkeiten aus ZAS';
                                    $bewertung = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZusagenInfo::bewertung($info['ratings'] ?? []);
                                @endphp
```

Nach der Handynummer-Zelle (`{{ $kontakt['phone'] ?? '–' }}` → `{{ ($info['phone'] ?? '') !== '' ? $info['phone'] : '–' }}`) drei Zellen einfügen:

```blade
                                    <td class="py-1 pr-3 tabular-nums text-gray-700">{{ $info['einsaetze'] ?? 0 }}</td>
                                    <td class="py-1 pr-3">
                                        @if ($info === null || $info['taetigkeiten'] === [])
                                            <span class="rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-400">keine aus ZAS</span>
                                        @elseif ($taetPasst)
                                            <span class="rounded-full border border-green-200 bg-green-50 px-2 py-0.5 text-xs text-green-700" title="{{ $taetListe }}">passt</span>
                                        @else
                                            <span class="rounded-full border border-gray-200 px-2 py-0.5 text-xs text-gray-600" title="{{ $taetListe }}">andere</span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-3 tabular-nums text-gray-700" title="{{ $bewertung['title'] }}">{{ $bewertung['text'] }}</td>
```

Alle `$rueck['kontakte']`-Verweise in der Datei entfernen (grep). Der Kommentar oben in der Datei bleibt.

- [ ] **Step 7: Wiring-Test anlegen**

`tests/Unit/DispoJobsRunde2WiringTest.php` (Muster `IchBinNachbereitungWiringTest`):

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Platzierungs-Vertraege Runde 2 „Jobs anbieten“ — die Livewire-Komponenten
 * booten in dieser Suite nicht; das Verhalten steht in den Bausteinen-Tests.
 */
final class DispoJobsRunde2WiringTest extends TestCase
{
    private static function datei(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function test_rueckmeldungen_laden_die_entscheidungshilfe_einmal_fuer_alle(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobZusagenInfo::laden(', $src);
        $this->assertStringNotContainsString("'kontakte' =>", $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        foreach (['>Einsätze</th>', '>Tätigkeit</th>', '>Bewertung</th>', 'DispoJobZusagenInfo::passt(', 'DispoJobZusagenInfo::bewertung('] as $s) {
            $this->assertStringContainsString($s, $view, $s);
        }
        $this->assertStringNotContainsString("\$rueck['kontakte']", $view);
    }
}
```

- [ ] **Step 8: Blade-Check, Suite, Commit**

Run: `php tools/blade-check.php resources/views/livewire/dispo/jobs/show.blade.php` → OK.
Run: Suite → OK.

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobZusagenInfo.php src/Livewire/Dispo/Jobs/Show.php resources/views/livewire/dispo/jobs/show.blade.php tests/Integration/DispoJobZusagenInfoTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Entscheidungshilfe je Zusage (Einsaetze, Taetigkeit passt, fuenf Bewertungen)

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: C — Fortschritt oben, offene Schichten zuerst

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobFortschritt.php`
- Modify: `src/Livewire/Dispo/Jobs/Show.php` (Computed `fortschritt`)
- Modify: `resources/views/livewire/dispo/jobs/show.blade.php` (Kopfzeile + Reihenfolge)
- Test: `tests/Unit/Dispo/Jobs/DispoJobFortschrittTest.php` (neu), Wiring-Test erweitern

**Interfaces:**
- Consumes: `Show::schichten` (Collection von `RecDispoJobShift` mit `zugesagt`, `eingetragen`, `angekommen`).
- Produces: `DispoJobFortschritt::berechnen(iterable $schichten): array{gesucht:int, zugesagt:int, eingetragen:int, angekommen:int, prozent:int, offen:list, voll:list}`.

- [ ] **Step 1: Failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFortschritt;

final class DispoJobFortschrittTest extends TestCase
{
    private static function s(string $datum, string $von, string $taet, int $gesucht, int $zugesagt, int $eingetragen = 0, int $angekommen = 0): object
    {
        return (object) compact('datum', 'von', 'taet', 'gesucht', 'zugesagt', 'eingetragen', 'angekommen') + ['taetigkeit' => $taet];
    }

    public function testSummenProzentUndReihenfolgeOffenVorVoll(): void
    {
        $voll1 = self::s('2026-10-20', '10:00', 'Zapfer', 2, 2, 1, 1);
        $offen1 = self::s('2026-10-21', '17:00', 'Service', 5, 3, 2);
        $offen2 = self::s('2026-10-20', '17:00', 'Zapfer', 3, 0);
        $voll2 = self::s('2026-10-19', '09:00', 'Koch', 1, 4);

        $f = DispoJobFortschritt::berechnen([$voll1, $offen1, $offen2, $voll2]);

        $this->assertSame(['gesucht' => 11, 'zugesagt' => 9, 'eingetragen' => 3, 'angekommen' => 1, 'prozent' => 81], array_intersect_key($f, array_flip(['gesucht', 'zugesagt', 'eingetragen', 'angekommen', 'prozent'])));
        $this->assertSame([$offen2, $offen1], $f['offen'], 'offene nach Datum, Beginn, Taetigkeit');
        $this->assertSame([$voll2, $voll1], $f['voll']);
    }

    public function testOhneSchichtenUndMitGesuchtNull(): void
    {
        $f = DispoJobFortschritt::berechnen([]);
        $this->assertSame(0, $f['prozent']);
        $this->assertSame([], $f['offen']);

        $null = self::s('2026-10-20', '10:00', 'Zapfer', 0, 0);
        $f = DispoJobFortschritt::berechnen([$null]);
        $this->assertSame(0, $f['prozent'], 'keine Division durch null');
        $this->assertSame([$null], $f['voll'], 'gesucht 0 gilt als voll');
    }

    public function testProzentIstBeiHundertGedeckelt(): void
    {
        $f = DispoJobFortschritt::berechnen([self::s('2026-10-20', '10:00', 'Zapfer', 2, 5)]);
        $this->assertSame(100, $f['prozent']);
    }
}
```

- [ ] **Step 2: Test — FAIL** (Klasse fehlt)

- [ ] **Step 3: Baustein**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Fortschritt einer Anfrage (Runde 2, Spec §5): Summen ueber alle Schichten
 * und die Reihenfolge fuer die Seite — offene zuerst, volle dahinter, jeweils
 * nach Datum, Beginn, Taetigkeit. Rein rechnend; nimmt Modelle oder Arrays.
 */
final class DispoJobFortschritt
{
    /**
     * @param iterable<object|array<string,mixed>> $schichten mit gesucht, zugesagt, eingetragen, angekommen, datum, von, taetigkeit
     * @return array{gesucht:int, zugesagt:int, eingetragen:int, angekommen:int, prozent:int, offen:list<mixed>, voll:list<mixed>}
     */
    public static function berechnen(iterable $schichten): array
    {
        $sum = ['gesucht' => 0, 'zugesagt' => 0, 'eingetragen' => 0, 'angekommen' => 0];
        $offen = [];
        $voll = [];
        foreach ($schichten as $s) {
            foreach (array_keys($sum) as $k) {
                $sum[$k] += max(0, (int) self::feld($s, $k));
            }
            $istVoll = (int) self::feld($s, 'gesucht') <= 0 || (int) self::feld($s, 'zugesagt') >= (int) self::feld($s, 'gesucht');
            $istVoll ? $voll[] = $s : $offen[] = $s;
        }
        $sortierer = fn ($a, $b) => [self::datum(self::feld($a, 'datum')), (string) self::feld($a, 'von'), mb_strtolower((string) self::feld($a, 'taetigkeit'))]
            <=> [self::datum(self::feld($b, 'datum')), (string) self::feld($b, 'von'), mb_strtolower((string) self::feld($b, 'taetigkeit'))];
        usort($offen, $sortierer);
        usort($voll, $sortierer);

        return $sum + [
            'prozent' => $sum['gesucht'] > 0 ? min(100, intdiv($sum['zugesagt'] * 100, $sum['gesucht'])) : 0,
            'offen'   => $offen,
            'voll'    => $voll,
        ];
    }

    private static function feld(mixed $s, string $name): mixed
    {
        return is_array($s) ? ($s[$name] ?? null) : ($s->{$name} ?? null);
    }

    private static function datum(mixed $v): string
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : substr(trim((string) $v), 0, 10);
    }
}
```

- [ ] **Step 4: Test — PASS**

- [ ] **Step 5: Show + View**

In `Show.php` nach `schichten()`:

```php
    /** @return array{gesucht:int, zugesagt:int, eingetragen:int, angekommen:int, prozent:int, offen:list<RecDispoJobShift>, voll:list<RecDispoJobShift>} */
    #[Computed]
    public function fortschritt(): array
    {
        return DispoJobFortschritt::berechnen($this->schichten);
    }
```

Import ergänzen. In `show.blade.php` oben im `@php`: `$fortschritt = $this->fortschritt;` und die Schleife `@foreach ($schichten as $s)` durch `@foreach (array_merge($fortschritt['offen'], $fortschritt['voll']) as $s)` ersetzen. Zwischen `<div class="font-medium text-gray-700">Schichten und Rückmeldungen</div>` und der Schleife einfügen:

```blade
        @php
            $plaetze = $fortschritt['gesucht'] === 1 ? 'Platz' : 'Plätzen';
            $balkenKlasse = $fortschritt['prozent'] >= 100 ? 'bg-green-500' : 'bg-blue-500';
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-2">
            <div class="text-sm text-gray-800">
                <strong>{{ $fortschritt['zugesagt'] }}</strong> von {{ $fortschritt['gesucht'] }} {{ $plaetze }} zugesagt
                · {{ $fortschritt['eingetragen'] }} in ZAS eingetragen
                · {{ $fortschritt['angekommen'] }} angekommen
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div class="h-2 rounded-full {{ $balkenKlasse }}" style="width: {{ $fortschritt['prozent'] }}%"></div>
            </div>
        </div>
```

Kopf der vollen Schicht-Kachel grün: in der Kachel `$voll` bleibt wie bisher berechnet; die Kachel-Klasse wird `class="rounded-lg border bg-white p-4 space-y-2 {{ $voll ? 'border-green-200' : 'border-gray-200' }}"` und der Titel-`div` bei voll `text-green-800`.

- [ ] **Step 6: Wiring-Test erweitern**

```php
    public function test_fortschritt_steht_ueber_den_schichten_und_offene_kommen_zuerst(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobFortschritt::berechnen($this->schichten)', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        $kopf = strpos($view, '{{ $fortschritt[\'zugesagt\'] }}</strong> von');
        $liste = strpos($view, 'array_merge($fortschritt[\'offen\'], $fortschritt[\'voll\'])');
        $this->assertNotFalse($kopf);
        $this->assertNotFalse($liste);
        $this->assertLessThan($liste, $kopf, 'Fortschritt vor der Liste');
    }
```

- [ ] **Step 7: Blade-Check, Suite, Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobFortschritt.php src/Livewire/Dispo/Jobs/Show.php resources/views/livewire/dispo/jobs/show.blade.php tests/Unit/Dispo/Jobs/DispoJobFortschrittTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Fortschritt ueber den Schichten, offene zuerst

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: D — Weniger Klicks beim Eintragen

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobZasListe.php`
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobZasHaken.php`
- Modify: `src/Livewire/Dispo/Jobs/Show.php` (`alleEintragen`, `zasHaken` nutzt den Baustein)
- Modify: `resources/views/livewire/dispo/jobs/show.blade.php` (zwei Knöpfe je Schicht)
- Test: `tests/Unit/Dispo/Jobs/DispoJobZasListeTest.php`, `tests/Integration/DispoJobZasHakenTest.php`, Wiring-Test

**Interfaces:**
- Consumes: `DispoJobZusagenInfo::laden()`-Zeilen (name, personnel_number, phone).
- Produces: `DispoJobZasListe::text(list<array{name:string, personnel_number:string, phone:string}>): string`; `DispoJobZasHaken::umschalten(RecDispoJobRequest $request, int $offerShiftId, ?int $userId): bool`; `DispoJobZasHaken::alle(RecDispoJobRequest $request, int $shiftId, ?int $userId): int`.

- [ ] **Step 1: Failing tests**

`tests/Unit/Dispo/Jobs/DispoJobZasListeTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasListe;

final class DispoJobZasListeTest extends TestCase
{
    public function testEineZeileJeZusageInReihenfolge(): void
    {
        $text = DispoJobZasListe::text([
            ['name' => 'Anna Ast', 'personnel_number' => 'RG1', 'phone' => '+4917612345678'],
            ['name' => 'Ben Birke', 'personnel_number' => '', 'phone' => ''],
        ]);

        $this->assertSame("Anna Ast · RG1 · +4917612345678\nBen Birke · – · –", $text);
        $this->assertSame('', DispoJobZasListe::text([]));
    }
}
```

`tests/Integration/DispoJobZasHakenTest.php` — Aufbau wie `JobOffersPageTest` (gleiche vier Migrationen plus `2026_10_14_000001_…`), Helfer `aufbau()` legt eine Anfrage mit zwei Schichten und je zwei angenommenen Zeilen an sowie eine ZWEITE Anfrage mit einer eigenen Schicht und Zeile:

```php
    public function test_alle_setzt_nur_unverhakte_zeilen_der_eigenen_schicht_und_anfrage(): void
    {
        [$request, $s1, $s2, $fremd] = $this->aufbau();
        $schonVerhakt = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->first();
        $schonVerhakt->update(['zas_entered_at' => '2026-10-01 10:00:00', 'zas_entered_by_user_id' => 7]);

        $n = DispoJobZasHaken::alle($request, $s1->id, 42);

        $this->assertSame(1, $n);
        $zeilen = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->get();
        $this->assertSame('2026-10-01 10:00:00', $zeilen[0]->zas_entered_at->toDateTimeString(), 'bestehender Haken bleibt');
        $this->assertSame(7, (int) $zeilen[0]->zas_entered_by_user_id);
        $this->assertNotNull($zeilen[1]->zas_entered_at);
        $this->assertSame(42, (int) $zeilen[1]->zas_entered_by_user_id);
        $this->assertSame(0, RecDispoJobOfferShift::where('shift_id', $s2->id)->whereNotNull('zas_entered_at')->count(), 'andere Schicht unberuehrt');
        $this->assertSame(0, DispoJobZasHaken::alle($request, $fremd->id, 42), 'Schicht einer fremden Anfrage: nichts');
    }

    public function test_alle_ueberspringt_nicht_angenommene_und_zurueckgezogene(): void
    {
        [$request, $s1] = $this->aufbau();
        RecDispoJobOfferShift::where('shift_id', $s1->id)->update(['accepted_at' => null, 'withdrawn_at' => now()]);

        $this->assertSame(0, DispoJobZasHaken::alle($request, $s1->id, 42));
    }

    public function test_umschalten_setzt_und_loescht_den_haken(): void
    {
        [$request, $s1] = $this->aufbau();
        $zeile = RecDispoJobOfferShift::where('shift_id', $s1->id)->orderBy('id')->first();

        $this->assertTrue(DispoJobZasHaken::umschalten($request, $zeile->id, 42));
        $this->assertNotNull($zeile->fresh()->zas_entered_at);
        $this->assertTrue(DispoJobZasHaken::umschalten($request, $zeile->id, 42));
        $this->assertNull($zeile->fresh()->zas_entered_at);
        $this->assertFalse(DispoJobZasHaken::umschalten($request, 999999, 42));
    }
```

- [ ] **Step 2: Tests — FAIL** (Klassen fehlen)

- [ ] **Step 3: Bausteine**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/** Textliste der Zusagen einer Schicht fuer die Zwischenablage (Runde 2, Spec §6). Rein. */
final class DispoJobZasListe
{
    /** @param list<array{name:string, personnel_number:string, phone:string}> $zeilen */
    public static function text(array $zeilen): string
    {
        $out = [];
        foreach ($zeilen as $z) {
            $out[] = implode(' · ', [
                trim((string) ($z['name'] ?? '')) !== '' ? trim((string) $z['name']) : '–',
                trim((string) ($z['personnel_number'] ?? '')) !== '' ? trim((string) $z['personnel_number']) : '–',
                trim((string) ($z['phone'] ?? '')) !== '' ? trim((string) $z['phone']) : '–',
            ]);
        }

        return implode("\n", $out);
    }
}
```

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * Haken „in ZAS eingetragen“ (Runde 1 §4.7, Runde 2 §6): einzeln umschalten
 * oder alle offenen Zusagen einer Schicht auf einmal. IDs aus dem Browser
 * werden nur innerhalb der uebergebenen Anfrage gesucht.
 */
final class DispoJobZasHaken
{
    /** @return bool false, wenn die Zeile nicht zur Anfrage gehoert */
    public static function umschalten(RecDispoJobRequest $request, int $offerShiftId, ?int $userId): bool
    {
        $zeile = RecDispoJobOfferShift::query()
            ->whereKey($offerShiftId)
            ->whereNotNull('accepted_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
            ->first();
        if ($zeile === null) {
            return false;
        }
        $an = $zeile->zas_entered_at === null;
        $zeile->update([
            'zas_entered_at'         => $an ? now() : null,
            'zas_entered_by_user_id' => $an ? $userId : null,
        ]);

        return true;
    }

    /** @return int Anzahl neu gesetzter Haken */
    public static function alle(RecDispoJobRequest $request, int $shiftId, ?int $userId): int
    {
        $shift = $request->shifts()->whereKey($shiftId)->first();
        if ($shift === null) {
            return 0;
        }

        return RecDispoJobOfferShift::query()
            ->where('shift_id', $shift->id)
            ->whereNotNull('accepted_at')
            ->whereNull('zas_entered_at')
            ->whereHas('offer', fn ($q) => $q->where('request_id', $request->id))
            ->update(['zas_entered_at' => now(), 'zas_entered_by_user_id' => $userId]);
    }
}
```

- [ ] **Step 4: Tests — PASS**

- [ ] **Step 5: Show.php**

`zasHaken()` durch den Baustein ersetzen und `alleEintragen` ergänzen:

```php
    public function zasHaken(int $offerShiftId): void
    {
        DispoJobZasHaken::umschalten($this->anfrage(), $offerShiftId, auth()->id() !== null ? (int) auth()->id() : null);
    }

    public function alleEintragen(int $shiftId): void
    {
        $this->fehler = '';
        $n = DispoJobZasHaken::alle($this->anfrage(), $shiftId, auth()->id() !== null ? (int) auth()->id() : null);
        $this->hinweis = $n > 0 ? $n . ' ' . ($n === 1 ? 'Zusage' : 'Zusagen') . ' als in ZAS eingetragen markiert.' : '';
    }
```

Import ergänzen; `RecDispoJobOfferShift`-Import bleibt (rueckmeldungen).

- [ ] **Step 6: Knöpfe in show.blade.php**

Im Kachel-`@php` ergänzen:

```blade
                $offeneHaken = count(array_filter($zeilen, fn ($z) => $z->zas_entered_at === null));
                $listeText = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasListe::text(array_map(
                    fn ($z) => $rueck['info'][(int) ($z->offer?->rec_employee_id ?? 0)] ?? ['name' => '#' . (int) ($z->offer?->rec_employee_id ?? 0), 'personnel_number' => '', 'phone' => ''],
                    $zeilen
                ));
                $alleText = $offeneHaken . ' ' . ($offeneHaken === 1 ? 'Zusage' : 'Zusagen') . ' als in ZAS eingetragen markieren?';
```

Rechts im Kachel-Kopf (nach den Chips, innerhalb des `flex`-Containers) einfügen:

```blade
                    @if ($offen && $zeilen !== [])
                        <div class="flex items-center gap-2" x-data="{ ok: false, zeigen: false, kopieren() {
                                const text = $refs.liste.value;
                                if (navigator.clipboard && window.isSecureContext) {
                                    navigator.clipboard.writeText(text).then(() => { this.ok = true; setTimeout(() => this.ok = false, 2000); });
                                } else {
                                    this.zeigen = !this.zeigen;
                                }
                            } }">
                            <button type="button" x-on:click="kopieren()" class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">
                                <span x-show="!ok">Liste kopieren</span><span x-show="ok" x-cloak>kopiert</span>
                            </button>
                            <textarea x-ref="liste" x-show="zeigen" x-cloak readonly rows="3" class="w-72 rounded border border-gray-300 px-2 py-1 text-xs">{{ $listeText }}</textarea>
                            @if ($offeneHaken > 0)
                                <button type="button" wire:click="alleEintragen({{ (int) $s->id }})" wire:confirm="{{ $alleText }}"
                                        class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">Alle als in ZAS eingetragen markieren</button>
                            @endif
                        </div>
                    @endif
```

Hinweis: Der `textarea`-Inhalt ist escaped (`{{ }}`), Zeilenumbrüche bleiben erhalten.

- [ ] **Step 7: Wiring-Test**

```php
    public function test_eintragen_knoepfe_haengen_am_baustein(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Show.php');
        $this->assertStringContainsString('DispoJobZasHaken::alle($this->anfrage(), $shiftId', $src);
        $this->assertStringContainsString('DispoJobZasHaken::umschalten($this->anfrage(), $offerShiftId', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/show.blade.php');
        $this->assertStringContainsString('wire:click="alleEintragen({{ (int) $s->id }})"', $view);
        $this->assertStringContainsString('DispoJobZasListe::text(', $view);
        $this->assertStringContainsString('navigator.clipboard.writeText', $view);
    }
```

- [ ] **Step 8: Blade-Check, Suite, Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobZasListe.php src/Services/Zas/Dispo/Jobs/DispoJobZasHaken.php src/Livewire/Dispo/Jobs/Show.php resources/views/livewire/dispo/jobs/show.blade.php tests/Unit/Dispo/Jobs/DispoJobZasListeTest.php tests/Integration/DispoJobZasHakenTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — alle Zusagen einer Schicht abhaken, Liste kopieren

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: A — Drei Schritte statt fünf

**Files:**
- Modify: `src/Services/Zas/Dispo/Jobs/DispoJobCreateState.php`
- Modify: `src/Livewire/Dispo/Jobs/Create.php`
- Modify: `resources/views/livewire/dispo/jobs/create.blade.php`
- Modify: `docs/anleitungen/dispo-jobs-anbieten.md`
- Test: `tests/Unit/Dispo/Jobs/DispoJobCreateStateTest.php` (umbauen), Wiring-Test

**Interfaces:**
- Produces: `DispoJobCreateState::schritte(): array{1:string,2:string,3:string}`; `kannWeiter(int $schritt, array $state)` mit Schritt 1 = Filiale+Zeitraum+Veranstaltungen, 2 = gesucht, 3 = Empfänger; neu `DispoJobCreateState::zeitraumProblem(array $state): ?string` (öffentlich, für die Live-Liste).

- [ ] **Step 1: Tests umbauen (FAIL zuerst)**

In `DispoJobCreateStateTest`:

```php
    public function testSchritteHabenDreiNamen(): void
    {
        $this->assertSame([1 => 'Was suchst du?', 2 => 'Schichten', 3 => 'Senden'], DispoJobCreateState::schritte());
    }

    public function testGueltigerZustandLaesstJedenSchrittDurch(): void
    {
        foreach ([1, 2, 3] as $s) {
            $this->assertNull(DispoJobCreateState::kannWeiter($s, self::state()), "Schritt $s");
        }
    }

    public function testSchritt1PrueftFilialeZeitraumUndVeranstaltungenInDieserReihenfolge(): void
    {
        $this->assertSame('Bitte wähle eine Filiale.', DispoJobCreateState::kannWeiter(1, self::state(['filial_nr' => '', 'von' => '', 'event_ids' => []])));
        $this->assertSame('Bitte wähle eine Filiale.', DispoJobCreateState::kannWeiter(1, self::state(['filial_nr' => 'abc'])));
        $this->assertSame('Bitte gib ein gültiges Von- und Bis-Datum an.', DispoJobCreateState::kannWeiter(1, self::state(['von' => '', 'event_ids' => []])));
        $this->assertSame('Der Zeitraum darf nicht in der Vergangenheit beginnen.', DispoJobCreateState::kannWeiter(1, self::state(['von' => '2026-10-11'])));
        $this->assertSame('Das Bis-Datum liegt vor dem Von-Datum.', DispoJobCreateState::kannWeiter(1, self::state(['bis' => '2026-10-12'])));
        $this->assertNull(DispoJobCreateState::kannWeiter(1, self::state(['von' => '2026-10-12', 'bis' => '2026-10-12'])), 'heute ist erlaubt');
        $this->assertSame('Bitte wähle mindestens eine Veranstaltung.', DispoJobCreateState::kannWeiter(1, self::state(['event_ids' => []])));
        $this->assertSame('Bitte wähle mindestens eine Veranstaltung.', DispoJobCreateState::kannWeiter(1, self::state(['event_ids' => ['0', 'x']])));
    }

    public function testZeitraumProblemIstOeffentlichFuerDieLiveListe(): void
    {
        $this->assertNull(DispoJobCreateState::zeitraumProblem(self::state()));
        $this->assertNotNull(DispoJobCreateState::zeitraumProblem(self::state(['von' => '2026-02-30'])));
    }
```

Die bisherigen Tests `testSchritt1BrauchtEineFiliale`, `testSchritt2PrueftDenZeitraum`, `testSchritt3BrauchtEineVeranstaltung` entfernen (ihre Fälle stecken oben). `testSchritt4…` → `kannWeiter(2, …)`, umbenennen in `testSchritt2BrauchtEineGesuchteSchicht`; alle `testSchritt5…` → `kannWeiter(3, …)`, umbenennen in `testSchritt3…`. `testAllesPrueftAlleSchritteDerReiheNach`: `kannWeiter(1, self::state(['event_ids' => []]))` als Erwartung.

Run: `… --filter DispoJobCreateStateTest` → FAIL.

- [ ] **Step 2: DispoJobCreateState umbauen**

```php
    /** @return array<int,string> */
    public static function schritte(): array
    {
        return [1 => 'Was suchst du?', 2 => 'Schichten', 3 => 'Senden'];
    }

    public static function kannWeiter(int $schritt, array $state): ?string
    {
        return match ($schritt) {
            1 => self::wasSuchstDu($state),
            2 => self::summeGesucht($state) > 0 ? null : 'Trag bei mindestens einer Schicht ein, wie viele Leute du suchst.',
            3 => self::empfaenger($state),
            default => null,
        };
    }

    /** Filiale, Zeitraum, Veranstaltungen — die erste Meldung gewinnt. */
    private static function wasSuchstDu(array $state): ?string
    {
        if (!self::istZahl($state['filial_nr'] ?? null)) {
            return 'Bitte wähle eine Filiale.';
        }
        if (($z = self::zeitraumProblem($state)) !== null) {
            return $z;
        }

        return array_filter((array) ($state['event_ids'] ?? []), fn ($id) => (int) $id > 0) !== []
            ? null : 'Bitte wähle mindestens eine Veranstaltung.';
    }

    /** Oeffentlich: die Veranstaltungsliste im Schritt 1 filtert live und braucht nur den Zeitraum. */
    public static function zeitraumProblem(array $state): ?string
    {
        … (bisheriger Rumpf von zeitraum())
    }
```

`zeitraum()` entfernen, Docblock der Klasse auf „drei Schritte“ anpassen (Schlüssel-Liste bleibt).

- [ ] **Step 3: Test — PASS**

- [ ] **Step 4: Create.php umstellen**

Änderungen (Zeilennummern Stand Runde 1):
- Klassen-Docblock: „in drei Schritten (Spec 2026-10-14 §3)“.
- `mount()`: bei `?event=` → `$this->schritt = 2;`.
- `weiter()`: `if ($this->schritt >= 3) return;` … `if ($this->schritt === 2) { $this->tageVorbelegen(); } if ($this->schritt === 3) { $this->bloeckeVorbelegen(); $this->zahlenRechnen(); }`.
- `zeitraumGueltig()`: `return DispoJobCreateState::zeitraumProblem($this->state(1)) === null;`
- `updated()`: `$this->schritt === 3`.
- `state(int $bisSchritt = 3)`: Basis + `event_ids` immer (`$state['event_ids'] = $this->gewaehlteEvents->keys()->all();`), `gesucht`/`andere` ab `>= 2`, Empfänger-Block ab `>= 3`. ACHTUNG: `state(1)` wird jetzt auch aus `zeitraumGueltig()` gerufen, das `events()` speist — `gewaehlteEvents` ruft `events()` → Rekursion. Deshalb in `state()` die Events nur laden, wenn `$bisSchritt >= 1 && $fuerListe === false`: einfacher: `zeitraumGueltig()` baut sein Array selbst: `DispoJobCreateState::zeitraumProblem(['heute' => now()->toDateString(), 'von' => $this->von, 'bis' => $this->bis]) === null`.
- `anlegenUndSenden()`: `if ($this->schritt !== 3) return;`
- Kommentare „Schritt 5“ → „Schritt 3“, „Schritt 4“ → „Schritt 2“.

- [ ] **Step 5: create.blade.php**

Blöcke 1–3 durch einen Block ersetzen:

```blade
    {{-- ============================ 1 Was suchst du? ============================ --}}
    @if ($schritt === 1)
        @php
            $heute = now()->toDateString();
            $vaListe = $this->events;
            $zeitraumOk = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobCreateState::zeitraumProblem(['heute' => $heute, 'von' => $von, 'bis' => $bis]) === null;
        @endphp
        <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <div class="font-medium text-gray-700">Für welche Filiale suchst du Leute?</div>
                    @if ($filialen === [])
                        <p class="text-sm text-gray-500">Es sind noch keine Veranstaltungen aus ZAS da.</p>
                    @else
                        <select wire:model.live="filialNr" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="">– Filiale wählen –</option>
                            @foreach ($filialen as $nr => $label)
                                <option value="{{ $nr }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div class="space-y-2">
                    <div class="font-medium text-gray-700">In welchem Zeitraum?</div>
                    <p class="text-xs text-gray-500">Vorgeschlagen sind die nächsten vier Wochen. Vergangene Tage gehen nicht.</p>
                    <div class="flex flex-wrap items-end gap-4">
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs font-medium text-gray-500">von</span>
                            <input type="date" min="{{ $heute }}" wire:model.live="von" class="rounded border border-gray-300 px-2 py-1 text-sm">
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="text-xs font-medium text-gray-500">bis</span>
                            <input type="date" min="{{ $heute }}" wire:model.live="bis" class="rounded border border-gray-300 px-2 py-1 text-sm">
                        </label>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-100 pt-3 space-y-2">
                <div class="font-medium text-gray-700">Für welche Veranstaltungen?</div>
                @if (!ctype_digit($filialNr))
                    <p class="text-sm text-gray-500">Wähle zuerst eine Filiale.</p>
                @elseif (!$zeitraumOk)
                    <p class="text-sm text-gray-500">Gib einen gültigen Zeitraum an.</p>
                @elseif ($vaListe->isEmpty())
                    <p class="text-sm text-gray-500">In diesem Zeitraum hat die Filiale keine Veranstaltung mit Einsatztagen.</p>
                @else
                    <div class="divide-y divide-gray-100">
                        … (bestehende VA-Schleife unverändert) …
                    </div>
                @endif
            </div>
        </div>
    @endif
```

Die Blöcke `@if ($schritt === 4)` → `@if ($schritt === 2)`, `@if ($schritt === 5)` → `@if ($schritt === 3)`; die Überschriften-Kommentare entsprechend („2 Schichten“, „3 Senden“). Die Fußleiste: `@if ($schritt < 5)` → `@if ($schritt < 3)` (Button „Weiter“), der Anlegen-Knopf bei `$schritt === 3`. Text „Keine Veranstaltung gewählt — geh einen Schritt zurück.“ bleibt (ist jetzt Schritt 1).

- [ ] **Step 6: Anleitung**

In `docs/anleitungen/dispo-jobs-anbieten.md` die Folie „Anfrage anlegen in fünf Schritten“ ersetzen durch „Anfrage anlegen in drei Schritten“:

```markdown
## Dispo · Jobs anbieten | Anfrage anlegen in drei Schritten

![Neue Anfrage mit der Schrittleiste oben](schrittleiste.png)
<!-- shot: seite=/recruiting/dispo-jobs/neu ausschnitt="Neue Anfrage" -->

1. **Was suchst du?** Wähle die Filiale und den Zeitraum. Darunter erscheinen die Veranstaltungen mit Einsatztagen in diesem Zeitraum. Hake an, für welche du Leute suchst.
2. **Schichten:** Trag bei jeder Schicht ein, wie viele Leute du suchst (Spalte **„gesucht“**). Leer heißt: Diese Schicht wird nicht angeboten. Fehlt eine Schicht, fügst du sie unter **„Andere Schicht hinzufügen“** dazu.
3. **Senden:** Wähle die **Mitarbeiterfiliale** und die Gruppen nach Einsätzen in diesem Monat. … (Rest des bisherigen Punkts 5 unverändert)

> Vor dem Senden siehst du, an wie viele Personen die Nachricht geht.
```

`stand:` im Kopf auf `2026-10-14`.

- [ ] **Step 7: Wiring-Test**

```php
    public function test_neue_anfrage_hat_drei_schritte(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Create.php');
        $this->assertStringContainsString('if ($this->schritt >= 3) {', $src);
        $this->assertStringContainsString("if (\$this->schritt !== 3) {", $src);
        $this->assertStringContainsString('$this->schritt = 2;', $src, '?event= springt zu den Schichten');
        $this->assertStringNotContainsString('$this->schritt === 5', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/create.blade.php');
        $this->assertStringContainsString('@if ($schritt === 3)', $view);
        $this->assertStringNotContainsString('@if ($schritt === 4)', $view);
        $this->assertStringContainsString('wire:model.live="filialNr"', $view);
        $this->assertStringContainsString('Wähle zuerst eine Filiale.', $view);

        $doku = self::datei('docs/anleitungen/dispo-jobs-anbieten.md');
        $this->assertStringContainsString('in drei Schritten', $doku);
        $this->assertStringNotContainsString('fünf Schritten', $doku);
    }
```

- [ ] **Step 8: Blade-Check, Suite, Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobCreateState.php src/Livewire/Dispo/Jobs/Create.php resources/views/livewire/dispo/jobs/create.blade.php docs/anleitungen/dispo-jobs-anbieten.md tests/Unit/Dispo/Jobs/DispoJobCreateStateTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Neue Anfrage in drei Schritten

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: F — Endzeit anzeigen und bei freien Schichten eingeben

**Files:**
- Modify: `src/Livewire/Dispo/Jobs/Create.php` (`neuBis`, `andereHinzufuegen`, `gewaehlteSchichten`)
- Modify: `resources/views/livewire/dispo/jobs/create.blade.php` (Spalte Ende, Feld Ende)
- Modify: `src/Livewire/Dispo/Jobs/Show.php` nichts; `resources/views/livewire/dispo/jobs/show.blade.php` (Schichttitel)
- Modify: `src/Livewire/Public/JobOffers.php` (`zeit` je Schicht), `resources/views/livewire/public/job-offers.blade.php`
- Test: Wiring-Test; `tests/Integration/JobOffersPageTest.php` unverändert (nur Zusage-Logik)

**Interfaces:**
- Consumes: `DispoJobZeit::spanne`, `DispoJobZeit::problem`, `DispoJobSchichten`-`bis`, `RecDispoJobShift->bis`.

- [ ] **Step 1: Create.php**

Property `public string $neuBis = '';`. In `andereHinzufuegen()` die Beginn-Prüfung durch den Baustein ersetzen:

```php
        $von = trim($this->neuVon);
        $bis = trim($this->neuBis);
        …
        if (($zeitProblem = DispoJobZeit::problem($von, $bis)) !== null) {
            $this->fehler = $zeitProblem;

            return;
        }
        …
        $this->andere[] = ['event' => $eventId, 'datum' => $datum, 'von' => $von, 'bis' => $bis, 'taetigkeit' => $taet, 'gesucht' => (string) $gesucht];
        $this->neuVon = '';
        $this->neuBis = '';
```

In `gewaehlteSchichten()`: ZAS-Schichten `'bis' => $s['bis']`, freie Schichten `$bis = trim((string) ($a['bis'] ?? ''));` mit `DispoJobZeit::problem($von, $bis) !== null → continue` statt der Regex-Prüfung, und `'bis' => $bis` im Ergebnis. Docblock-Typ ergänzen. `zahlenRechnen()` braucht `bis` nicht.

- [ ] **Step 2: create.blade.php (Schritt 2)**

Spalte „Beginn“ in der Schicht-Tabelle zeigt die Spanne: `{{ \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($s['von'], $s['bis']) !== '' ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($s['von'], $s['bis']) : '–' }}` → vorher im `@php` je Zeile nicht möglich (Schleife), deshalb `@php $spanne = …; @endphp` als eigene Zeile in der Schleife vor dem `<tr>`. Kopf: „Beginn“ → „Zeit“. In der Tabelle der freien Schichten dieselbe Anzeige (`$a['von']`, `$a['bis'] ?? ''`). Im Formular nach „Beginn“:

```blade
                    <label class="flex flex-col gap-1 text-sm">
                        <span class="text-xs font-medium text-gray-500">Ende <span class="font-normal">(optional)</span></span>
                        <input type="time" wire:model="neuBis" class="rounded border border-gray-300 px-2 py-1 text-sm">
                    </label>
```

- [ ] **Step 3: show.blade.php**

Im Schichttitel: `$s->von !== '' ? \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeit::spanne($s->von, $s->bis) . ' Uhr' : null`.

- [ ] **Step 4: JobOffers.php + Blade**

In `angebote()` je Zeile `'zeit' => DispoJobZeit::spanne($shift->von, $shift->bis),` (zusätzlich zu `von`, das die Sortierung braucht) und in `$groups[…]['days'][…][]` mitgeben. Blade: `$zeile = ($shift['zeit'] !== '' ? $shift['zeit'] . ' · ' : '') . ($shift['taetigkeit'] ?: 'Einsatz');`.

- [ ] **Step 5: Wiring-Test**

```php
    public function test_endzeit_wird_ueberall_als_spanne_gezeigt(): void
    {
        $this->assertStringContainsString('DispoJobZeit::problem($von, $bis)', self::datei('src/Livewire/Dispo/Jobs/Create.php'));
        $this->assertStringContainsString('wire:model="neuBis"', self::datei('resources/views/livewire/dispo/jobs/create.blade.php'));
        $this->assertStringContainsString('DispoJobZeit::spanne($s->von, $s->bis)', self::datei('resources/views/livewire/dispo/jobs/show.blade.php'));
        $this->assertStringContainsString('DispoJobZeit::spanne($shift->von, $shift->bis)', self::datei('src/Livewire/Public/JobOffers.php'));
        $this->assertStringContainsString("\$shift['zeit']", self::datei('resources/views/livewire/public/job-offers.blade.php'));
    }
```

- [ ] **Step 6: Blade-Checks (create, show, job-offers), Suite, Commit**

```bash
git add src/Livewire/Dispo/Jobs/Create.php resources/views/livewire/dispo/jobs/create.blade.php resources/views/livewire/dispo/jobs/show.blade.php src/Livewire/Public/JobOffers.php resources/views/livewire/public/job-offers.blade.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Endzeit der Schicht fuer Disponent und Mitarbeiter

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: E — Zähler am Menüpunkt

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/DispoJobOffeneZusagen.php`
- Modify: `resources/views/livewire/sidebar.blade.php:166-169`
- Modify: `src/Livewire/Dispo/Jobs/Index.php` + `resources/views/livewire/dispo/jobs/index.blade.php` (Kopfzeile)
- Test: `tests/Integration/DispoJobOffeneZusagenTest.php`, Wiring-Test

**Interfaces:**
- Produces: `DispoJobOffeneZusagen::count(?int $teamId = null): int` (wirft nie; `null` = Team aus `recruiting.zas.inbound_team_id`, sonst aktuelles Team des Users, sonst 0 → zählt teamübergreifend NICHT, sondern liefert 0).

- [ ] **Step 1: Failing test**

Aufbau wie `JobOffersPageTest` (plus Migration `2026_10_14_000001_…`):

```php
    public function test_zaehlt_nur_zusagen_ohne_haken_in_offenen_anfragen_mit_zukuenftigen_schichten(): void
    {
        $offen = $this->anfrage(RecDispoJobRequest::STATUS_LAEUFT, datum: now()->addDays(3)->toDateString());
        $this->zusage($offen, zas: false);                      // zaehlt
        $this->zusage($offen, zas: true);                       // Haken gesetzt
        $this->zusage($offen, zas: false, angekommen: true);    // in ZAS angekommen
        $this->zusage($offen, zas: false, angenommen: false);   // nie zugesagt
        $zu = $this->anfrage(RecDispoJobRequest::STATUS_ABGESCHLOSSEN, datum: now()->addDays(3)->toDateString());
        $this->zusage($zu, zas: false);
        $vorbei = $this->anfrage(RecDispoJobRequest::STATUS_LAEUFT, datum: now()->subDay()->toDateString());
        $this->zusage($vorbei, zas: false);
        $fremd = $this->anfrage(RecDispoJobRequest::STATUS_LAEUFT, datum: now()->addDays(3)->toDateString(), team: 9);
        $this->zusage($fremd, zas: false);

        $this->assertSame(1, DispoJobOffeneZusagen::count(3));
        $this->assertSame(1, DispoJobOffeneZusagen::count(9));
    }

    public function test_ohne_team_oder_bei_db_fehler_null(): void
    {
        $this->assertSame(0, DispoJobOffeneZusagen::count(0));
        Capsule::schema()->rename('rec_dispo_job_offer_shifts', 'weg');
        try {
            $this->assertSame(0, DispoJobOffeneZusagen::count(3), 'wirft nie — die Sidebar rendert auf jeder Seite');
        } finally {
            Capsule::schema()->rename('weg', 'rec_dispo_job_offer_shifts');
        }
    }
```

Helfer `anfrage(string $status, string $datum, int $team = 3)` legt Request + eine Schicht + eine Welle an; `zusage($request, bool $zas, bool $angekommen = false, bool $angenommen = true)` legt Offer + OfferShift an (`accepted_at` = now bei angenommen, `zas_entered_at` bei zas, `zas_assignment_id` = 5 bei angekommen).

- [ ] **Step 2: Test — FAIL**

- [ ] **Step 3: Baustein**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;

/**
 * Badge „Jobs anbieten“ in der Sidebar (Runde 2, Spec §7): Zusagen, die der
 * Disponent noch in ZAS eintragen muss — angenommen, ohne Haken, nicht
 * angekommen, Anfrage offen, Schicht nicht vorbei. Wirft nie (Sidebar).
 */
final class DispoJobOffeneZusagen
{
    public static function count(?int $teamId = null): int
    {
        try {
            $teamId ??= (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));
            if ($teamId <= 0) {
                return 0;
            }

            return (int) RecDispoJobOfferShift::query()
                ->join('rec_dispo_job_shifts as s', 's.id', '=', 'rec_dispo_job_offer_shifts.shift_id')
                ->join('rec_dispo_job_requests as r', 'r.id', '=', 's.request_id')
                ->where('r.team_id', $teamId)
                ->whereNull('r.closed_at')
                ->where('r.status', '!=', RecDispoJobRequest::STATUS_ABGESCHLOSSEN)
                ->whereDate('s.datum', '>=', now()->toDateString())
                ->whereNotNull('rec_dispo_job_offer_shifts.accepted_at')
                ->whereNull('rec_dispo_job_offer_shifts.zas_entered_at')
                ->whereNull('rec_dispo_job_offer_shifts.zas_assignment_id')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
```

- [ ] **Step 4: Test — PASS**

- [ ] **Step 5: Sidebar + Index**

`sidebar.blade.php`, Eintrag „Jobs anbieten“:

```blade
        @php
            $dispoJobsOffen = \Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOffeneZusagen::count();
        @endphp
        <x-ui-sidebar-item :href="route('recruiting.dispo.jobs.index')">
            @svg('heroicon-o-megaphone', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Jobs anbieten</span>
            @if ($dispoJobsOffen > 0)
                <span class="ml-auto flex-shrink-0 inline-flex items-center justify-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-orange-50 text-orange-600">{{ $dispoJobsOffen }}</span>
            @endif
        </x-ui-sidebar-item>
```

`Index::render()` gibt zusätzlich `'offeneZusagen' => DispoJobOffeneZusagen::count($this->teamId())` an die View; `index.blade.php` unter der Überschrift (links neben dem Hilfe-Fragezeichen):

```blade
            @if ($offeneZusagen > 0)
                <span class="rounded-full border border-orange-200 bg-orange-50 px-2 py-0.5 text-xs text-orange-700">{{ $offeneZusagen }} {{ $offeneZusagen === 1 ? 'Zusage wartet' : 'Zusagen warten' }} auf ZAS</span>
            @endif
```

- [ ] **Step 6: Wiring-Test**

```php
    public function test_badge_in_der_sidebar_haengt_am_zaehler(): void
    {
        $sidebar = self::datei('resources/views/livewire/sidebar.blade.php');
        $this->assertStringContainsString('DispoJobOffeneZusagen::count()', $sidebar);
        $this->assertStringContainsString('{{ $dispoJobsOffen }}', $sidebar);
        $this->assertStringContainsString("DispoJobOffeneZusagen::count(\$this->teamId())", self::datei('src/Livewire/Dispo/Jobs/Index.php'));
    }
```

- [ ] **Step 7: Blade-Checks, Suite, Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobOffeneZusagen.php resources/views/livewire/sidebar.blade.php src/Livewire/Dispo/Jobs/Index.php resources/views/livewire/dispo/jobs/index.blade.php tests/Integration/DispoJobOffeneZusagenTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Zaehler offener Zusagen am Menuepunkt

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 8: G — „Doch nicht“

**Files:**
- Create: `src/Services/Zas/Dispo/Jobs/JobOffersSchwestern.php`
- Create: `src/Services/Zas/Dispo/Jobs/JobOffersRuecknahme.php`
- Modify: `src/Services/Zas/Dispo/Jobs/JobOffersZusage.php` (Schwestern auslagern, `withdrawn_at = null`)
- Modify: `src/Livewire/Public/JobOffers.php` (`zuruecknehmen`, Zeilen-Felder)
- Modify: `resources/views/livewire/public/job-offers.blade.php`, `resources/views/livewire/public/_crew-stil.blade.php`
- Modify: `src/Livewire/Dispo/Jobs/Show.php` (`zurueckgezogen`-Zähler), `resources/views/livewire/dispo/jobs/show.blade.php` (Chip)
- Test: `tests/Integration/JobOffersRuecknahmeTest.php`, `tests/Integration/JobOffersPageTest.php` (Migration + ein Test), Wiring-Test

**Interfaces:**
- Consumes: `JobOffersZusage`-Sperrmuster; `DispoJobRecipientPlanner::schichtSchluessel`.
- Produces: `JobOffersSchwestern::finden(RecDispoJobShift $shift, array $employeeIds, int $ohneId, bool $angenommen): list<RecDispoJobOfferShift>`; `JobOffersRuecknahme::ausfuehren(int $offerShiftId, array $employeeIds): 'ok'|'eingetragen'|'weg'`.

**Ruling (weicht vom Spec-Wortlaut §9.1 ab):** `closed_at` der Schicht sperrt die Rücknahme NICHT — bei „Wer zuerst kommt“ ist eine volle Schicht geschlossen, und genau dort muss die Rücknahme den Platz wieder freigeben (`closed_at` zurück auf null, wenn Zusagen < gesucht). Sonst bliebe die Schicht für immer voll. Spec §9.1 ist entsprechend zu korrigieren (im Task mit erledigen).

- [ ] **Step 1: Failing tests**

`tests/Integration/JobOffersRuecknahmeTest.php` — Aufbau wie `JobOffersPageTest` plus Migration `2026_10_14_000001_…`, Helfer `aufbau(string $modus, int $gesucht)` wie dort (zwei Personen 5 und 6, eine Schicht), zusätzlich `zweiteAnfrageMitGleicherSchicht($shift, int $emp)` für die Schwester.

```php
    public function test_ruecknahme_leert_die_zusage_und_stempelt(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));

        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $z = RecDispoJobOfferShift::find($os5);
        $this->assertNull($z->accepted_at);
        $this->assertNotNull($z->withdrawn_at);
        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]), 'idempotent');
    }

    public function test_erneute_zusage_loescht_den_ruecknahme_stempel(): void
    {
        [, , $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        JobOffersZusage::ausfuehren($os5, [5]);
        JobOffersRuecknahme::ausfuehren($os5, [5]);

        $this->assertSame('ok', JobOffersZusage::ausfuehren($os5, [5]));
        $z = RecDispoJobOfferShift::find($os5);
        $this->assertNotNull($z->accepted_at);
        $this->assertNull($z->withdrawn_at);
    }

    public function test_ruecknahme_bei_wer_zuerst_kommt_oeffnet_die_volle_schicht_wieder(): void
    {
        [, $shift, $os5, $os6] = $this->aufbau(RecDispoJobRequest::MODUS_ZUERST, 1);
        JobOffersZusage::ausfuehren($os5, [5]);
        $this->assertNotNull($shift->fresh()->closed_at, 'Testannahme: voll');

        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertNull($shift->fresh()->closed_at, 'Platz wieder frei');
        $this->assertSame('ok', JobOffersZusage::ausfuehren($os6, [6]), 'die naechste Person kann zusagen');
    }

    public function test_eingetragen_oder_angekommen_blockt(): void
    {
        [, , $os5, $os6] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        JobOffersZusage::ausfuehren($os5, [5]);
        JobOffersZusage::ausfuehren($os6, [6]);
        RecDispoJobOfferShift::whereKey($os5)->update(['zas_entered_at' => now()]);
        RecDispoJobOfferShift::whereKey($os6)->update(['zas_assignment_id' => 77]);

        $this->assertSame('eingetragen', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertSame('eingetragen', JobOffersRuecknahme::ausfuehren($os6, [6]));
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at);
    }

    public function test_weg_bei_fremder_person_nie_zugesagt_abgeschlossen_oder_vorbei(): void
    {
        [$request, $shift, $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [5]), 'nie zugesagt');
        JobOffersZusage::ausfuehren($os5, [5]);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [6]), 'fremde Person');
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren(999999, [5]));
        $shift->update(['datum' => now()->subDay()->toDateString()]);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [5]), 'vorbei');
        $shift->update(['datum' => now()->addDays(5)->toDateString()]);
        $request->update(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN, 'closed_at' => now()]);
        $this->assertSame('weg', JobOffersRuecknahme::ausfuehren($os5, [5]), 'abgeschlossen');
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at, 'nichts angefasst');
    }

    public function test_schwester_in_anderer_anfrage_wird_mit_zurueckgenommen_oder_blockt(): void
    {
        [, $shift, $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        $schwester = $this->zweiteAnfrageMitGleicherSchicht($shift, 5);
        JobOffersZusage::ausfuehren($os5, [5]);
        $this->assertNotNull(RecDispoJobOfferShift::find($schwester)->accepted_at, 'Testannahme: Zusage gilt fuer beide');

        $this->assertSame('ok', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertNull(RecDispoJobOfferShift::find($schwester)->accepted_at);

        JobOffersZusage::ausfuehren($os5, [5]);
        RecDispoJobOfferShift::whereKey($schwester)->update(['zas_entered_at' => now()]);
        $this->assertSame('eingetragen', JobOffersRuecknahme::ausfuehren($os5, [5]));
        $this->assertNotNull(RecDispoJobOfferShift::find($os5)->accepted_at, 'bleibt stehen, weil die Schwester eingetragen ist');
    }

    public function test_zurueckgezogene_zaehlen_nirgends_mehr(): void
    {
        [$request, $shift, $os5] = $this->aufbau(RecDispoJobRequest::MODUS_SAMMELN, 3);
        JobOffersZusage::ausfuehren($os5, [5]);
        JobOffersRuecknahme::ausfuehren($os5, [5]);

        $this->assertSame([], DispoJobSchonZugesagt::laden(3));
        $this->assertSame(0, DispoJobOffeneZusagen::count(3));
        $this->assertSame(0, RecDispoJobOfferShift::where('shift_id', $shift->id)->whereNotNull('accepted_at')->count());
    }
```

In `JobOffersPageTest` die neue Migration ergänzen und einen Test, dass `JobOffersZusage` `withdrawn_at` leert (steht oben schon in der Rücknahme-Suite — hier reicht die Migration, damit `withdrawn_at`-Updates nicht an einer fehlenden Spalte scheitern).

- [ ] **Step 2: Tests — FAIL**

- [ ] **Step 3: Schwestern auslagern**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * Spec Runde 1 §9: Angebots-Zeilen derselben Person fuer DIESELBE Schicht
 * (VA, Tag, Beginn, Taetigkeit) in anderen laufenden Anfragen. Zusage und
 * Ruecknahme behandeln sie mit.
 */
final class JobOffersSchwestern
{
    /**
     * @param list<int> $employeeIds
     * @param bool $angenommen true = nur angenommene (Ruecknahme), false = nur offene (Zusage)
     * @return list<RecDispoJobOfferShift>
     */
    public static function finden(RecDispoJobShift $shift, array $employeeIds, int $ohneId, bool $angenommen): array
    {
        $schluessel = DispoJobRecipientPlanner::schichtSchluessel($shift->rec_dispo_event_id, $shift->datum->format('Y-m-d'), $shift->von, $shift->taetigkeit);

        return RecDispoJobOfferShift::query()
            ->with('shift')
            ->where('id', '!=', $ohneId)
            ->when($angenommen, fn ($q) => $q->whereNotNull('accepted_at'), fn ($q) => $q->whereNull('accepted_at'))
            ->whereHas('offer', fn ($q) => $q->whereIn('rec_employee_id', $employeeIds))
            ->whereHas('shift', fn ($q) => $q->where('rec_dispo_event_id', $shift->rec_dispo_event_id)
                ->whereDate('datum', $shift->datum->format('Y-m-d'))
                ->where('id', '!=', $shift->id)
                ->whereHas('request', fn ($r) => $r->where('status', RecDispoJobRequest::STATUS_LAEUFT)->whereNull('closed_at')))
            ->get()
            ->filter(fn ($s) => $s->shift !== null && DispoJobRecipientPlanner::schichtSchluessel(
                $s->shift->rec_dispo_event_id, $s->shift->datum->format('Y-m-d'), $s->shift->von, $s->shift->taetigkeit
            ) === $schluessel)
            ->values()->all();
    }
}
```

In `JobOffersZusage`: `$schwestern = JobOffersSchwestern::finden($vorab->shift, $employeeIds, (int) $vorab->id, false);`, die private `schwestern()` entfernen, in `annehmen()` `$os->update(['accepted_at' => now(), 'withdrawn_at' => null]);`.

- [ ] **Step 4: JobOffersRuecknahme**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;

/**
 * „Doch nicht“ (Runde 2, Spec §9): die eigene Zusage zuruecknehmen, solange
 * der Disponent sie nicht in ZAS eingetragen hat. Gleiche Sperr-Disziplin wie
 * JobOffersZusage: Schicht-Zeilen aufsteigend sperren, alles Veraenderliche
 * danach sperrend lesen. Das Update selbst ist bedingt — setzt der Disponent
 * im selben Moment den Haken, gewinnt der Haken.
 *
 * Eine volle Schicht im Modus „zuerst“ wird wieder geoeffnet, wenn nach der
 * Ruecknahme Zusagen < gesucht sind. Schwestern (dieselbe Schicht aus einer
 * anderen Anfrage) werden mit zurueckgenommen; ist eine davon schon
 * eingetragen, bleibt alles stehen.
 */
final class JobOffersRuecknahme
{
    /** @return 'ok'|'eingetragen'|'weg' */
    public static function ausfuehren(int $offerShiftId, array $employeeIds): string
    {
        return DB::transaction(function () use ($offerShiftId, $employeeIds) {
            $vorab = RecDispoJobOfferShift::query()->with('shift')->find($offerShiftId);
            if ($vorab === null || $vorab->shift === null) {
                return 'weg';
            }
            $schwestern = JobOffersSchwestern::finden($vorab->shift, $employeeIds, (int) $vorab->id, true);

            $shiftIds = array_values(array_unique(array_merge([(int) $vorab->shift_id], array_map(fn ($s) => (int) $s->shift_id, $schwestern))));
            sort($shiftIds);
            $shifts = [];
            foreach ($shiftIds as $id) {
                $shift = RecDispoJobShift::query()->whereKey($id)->lockForUpdate()->first();
                if ($shift !== null) {
                    $shifts[$id] = $shift;
                }
            }
            $shift = $shifts[(int) $vorab->shift_id] ?? null;
            if ($shift === null) {
                return 'weg';
            }

            $os = RecDispoJobOfferShift::query()->whereKey($offerShiftId)->with('offer')->lockForUpdate()->first();
            if ($os === null || $os->offer === null || !in_array((int) $os->offer->rec_employee_id, $employeeIds, true)) {
                return 'weg';
            }
            if ($os->accepted_at === null) {
                return $os->withdrawn_at !== null ? 'ok' : 'weg';
            }
            $request = RecDispoJobRequest::query()->whereKey($shift->request_id)->sharedLock()->first();
            if ($request === null || !$request->laeuft() || $shift->datum->lt(now()->startOfDay())) {
                return 'weg';
            }
            if ($os->zas_entered_at !== null || $os->zas_assignment_id !== null) {
                return 'eingetragen';
            }

            $frischeSchwestern = [];
            foreach ($schwestern as $s) {
                $frisch = RecDispoJobOfferShift::query()->whereKey($s->id)->lockForUpdate()->first();
                if ($frisch === null || $frisch->accepted_at === null) {
                    continue;
                }
                if ($frisch->zas_entered_at !== null || $frisch->zas_assignment_id !== null) {
                    return 'eingetragen';
                }
                $frischeSchwestern[] = $frisch;
            }

            if (self::zuruecknehmen($os) === 0) {
                return 'eingetragen';
            }
            self::wiederOeffnen($shift, $request);
            foreach ($frischeSchwestern as $frisch) {
                if (self::zuruecknehmen($frisch) > 0 && isset($shifts[(int) $frisch->shift_id])) {
                    $schwesterShift = $shifts[(int) $frisch->shift_id];
                    $schwesterRequest = RecDispoJobRequest::query()->whereKey($schwesterShift->request_id)->sharedLock()->first();
                    if ($schwesterRequest !== null) {
                        self::wiederOeffnen($schwesterShift, $schwesterRequest);
                    }
                }
            }

            return 'ok';
        }, 3);
    }

    /** Bedingtes Update: trifft nichts, wenn inzwischen ein Haken gesetzt wurde. */
    private static function zuruecknehmen(RecDispoJobOfferShift $os): int
    {
        return RecDispoJobOfferShift::query()
            ->whereKey($os->id)
            ->whereNotNull('accepted_at')
            ->whereNull('zas_entered_at')
            ->whereNull('zas_assignment_id')
            ->update(['accepted_at' => null, 'withdrawn_at' => now()]);
    }

    private static function wiederOeffnen(RecDispoJobShift $shift, RecDispoJobRequest $request): void
    {
        if ($request->modus !== RecDispoJobRequest::MODUS_ZUERST || $shift->closed_at === null) {
            return;
        }
        $zusagen = RecDispoJobOfferShift::query()->where('shift_id', $shift->id)->whereNotNull('accepted_at')->lockForUpdate()->count();
        if ($zusagen < (int) $shift->gesucht) {
            $shift->update(['closed_at' => null]);
        }
    }
}
```

- [ ] **Step 5: Tests — PASS** (Rücknahme 7, JobOffersPage weiterhin grün)

- [ ] **Step 6: JobOffers-Komponente + Blade + Stil**

`JobOffers::angebote()`: je Zeile zusätzlich

```php
                'zurueck'        => $os->accepted_at !== null && $os->zas_entered_at === null && $os->zas_assignment_id === null,
```

und in `$groups[…]['days'][…][]` `'zurueck' => $row['zurueck']` mitgeben (Sichtbarkeit/Bündelung reichen Zeilen unverändert durch — prüfen, dass `JobOffersBuendelung` die ganze Zeile behält). Neue Methode:

```php
    public function zuruecknehmen(int $offerShiftId): void
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return;
        }
        if ($this->gesperrt()) {
            $this->portalLocked = true;
            return;
        }

        $ergebnis = JobOffersRuecknahme::ausfuehren($offerShiftId, $this->employeeIds);
        $this->hinweisOk = $ergebnis === 'ok';
        $this->hinweis = match ($ergebnis) {
            'ok'          => 'Alles klar, deine Zusage ist zurückgenommen.',
            'eingetragen' => 'Du bist schon eingetragen — bitte melde dich bei deiner Dispo.',
            default       => 'Dieses Angebot gibt es nicht mehr.',
        };

        unset($this->angebote);
    }
```

Blade (`job-offers.blade.php`), der `@if ($shift['accepted'])`-Zweig:

```blade
                            @if ($shift['accepted'])
                                <div class="jobact">
                                    <div class="btn-done">Du bist dabei — wir melden uns.</div>
                                    @if ($shift['zurueck'])
                                        <button type="button" class="link-undo" wire:click="zuruecknehmen({{ $shift['offer_shift_id'] }})" wire:confirm="Deine Zusage für diese Schicht zurücknehmen?" wire:loading.attr="disabled">Doch nicht?</button>
                                    @else
                                        <div class="l2">Du bist eingetragen — wenn etwas dazwischenkommt, melde dich bei deiner Dispo.</div>
                                    @endif
                                </div>
                            @elseif (!$shift['voll'])
```

`_crew-stil.blade.php` ergänzen:

```css
.rg-crew .jobact{display:flex; flex-direction:column; align-items:flex-end; gap:4px; max-width:60%}
.rg-crew .link-undo{background:none; border:none; padding:2px 0; color:var(--ink-2); font-size:12.5px; text-decoration:underline; cursor:pointer}
.rg-crew .jobact .l2{text-align:right}
```

- [ ] **Step 7: Show-Chip „zurückgezogen“**

`Show::schichten()` `withCount` ergänzen: `'offerShifts as zurueckgezogen' => fn ($q) => $q->whereNull('accepted_at')->whereNotNull('withdrawn_at'),`. In `show.blade.php` nach dem Chip „angekommen“:

```blade
                        @if ((int) $s->zurueckgezogen > 0)
                            <span class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 text-gray-500">{{ $s->zurueckgezogen }} zurückgezogen</span>
                        @endif
```

- [ ] **Step 8: Spec korrigieren**

In `docs/superpowers/specs/2026-10-14-dispo-jobs-anbieten-runde2-design.md` §9.1 „Schicht nicht geschlossen“ streichen und §9.2 um den Satz ergänzen: „Eine volle Schicht im Modus ‚Wer zuerst kommt‘ wird nach der Rücknahme wieder geöffnet, wenn Zusagen < gesucht.“ (Ruling aus diesem Task.)

- [ ] **Step 9: Wiring-Test**

```php
    public function test_doch_nicht_nur_bei_ruecknehmbaren_zeilen(): void
    {
        $src = self::datei('src/Livewire/Public/JobOffers.php');
        $this->assertStringContainsString('JobOffersRuecknahme::ausfuehren($offerShiftId, $this->employeeIds)', $src);
        $this->assertStringContainsString("'zurueck'", $src);

        $view = self::datei('resources/views/livewire/public/job-offers.blade.php');
        $this->assertStringContainsString("@if (\$shift['zurueck'])", $view);
        $this->assertStringContainsString('wire:click="zuruecknehmen({{ $shift[\'offer_shift_id\'] }})"', $view);
        $this->assertStringContainsString('Du bist eingetragen', $view);
        $this->assertStringContainsString('zurückgezogen</span>', self::datei('resources/views/livewire/dispo/jobs/show.blade.php'));
    }
```

- [ ] **Step 10: Blade-Checks (job-offers, _crew-stil, show), Suite, Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/JobOffersSchwestern.php src/Services/Zas/Dispo/Jobs/JobOffersRuecknahme.php src/Services/Zas/Dispo/Jobs/JobOffersZusage.php src/Livewire/Public/JobOffers.php resources/views/livewire/public/job-offers.blade.php resources/views/livewire/public/_crew-stil.blade.php src/Livewire/Dispo/Jobs/Show.php resources/views/livewire/dispo/jobs/show.blade.php docs/superpowers/specs/2026-10-14-dispo-jobs-anbieten-runde2-design.md tests/Integration/JobOffersRuecknahmeTest.php tests/Integration/JobOffersPageTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — „Doch nicht“: Zusage zuruecknehmen, solange nicht in ZAS eingetragen

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 9: H — Übersicht mit Quote, „Noch in ZAS“ und Filter

**Files:**
- Modify: `src/Livewire/Dispo/Jobs/Index.php`
- Modify: `resources/views/livewire/dispo/jobs/index.blade.php`
- Test: Wiring-Test; `tests/Integration/DispoJobUebersichtZahlenTest.php` (neu) für den Zahlen-Baustein

**Interfaces:**
- Produces: `DispoJobUebersichtZahlen::laden(list<int> $requestIds): array<int, array{zugesagt:int, gesucht:int, noch_zas:int}>` (neu, `src/Services/Zas/Dispo/Jobs/DispoJobUebersichtZahlen.php`); `Index::$filter` (`#[Url]`, `offen|zu|alle`).

- [ ] **Step 1: Failing test**

Aufbau wie `JobOffersPageTest` plus Migration `2026_10_14_000001_…`:

```php
    public function test_zugesagt_gesucht_und_noch_in_zas_je_anfrage(): void
    {
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17', 'modus' => 'sammeln', 'status' => 'laeuft', 'created_by_user_id' => 1]);
        $s1 = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2099-10-20', 'von' => '', 'taetigkeit' => 'A', 'gesucht' => 3]);
        $s2 = $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => '2099-10-21', 'von' => '', 'taetigkeit' => 'B', 'gesucht' => 2]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0'], 'nur_passende_taetigkeit' => true]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => 5, 'anchor_employee_id' => 5]);
        $offer->shifts()->create(['shift_id' => $s1->id, 'accepted_at' => now()]);
        $offer->shifts()->create(['shift_id' => $s2->id, 'accepted_at' => now(), 'zas_entered_at' => now()]);
        // Unique (offer_id, shift_id): die zurueckgezogene Zeile braucht ein zweites Angebot.
        $offer2 = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => 6, 'anchor_employee_id' => 6]);
        $offer2->shifts()->create(['shift_id' => $s2->id, 'accepted_at' => null, 'withdrawn_at' => now()]);
        $leer = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17', 'modus' => 'sammeln', 'status' => 'laeuft', 'created_by_user_id' => 1]);

        $z = DispoJobUebersichtZahlen::laden([$request->id, $leer->id, 999999]);

        $this->assertSame(['zugesagt' => 2, 'gesucht' => 5, 'noch_zas' => 1], $z[$request->id]);
        $this->assertSame(['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0], $z[$leer->id]);
        $this->assertArrayNotHasKey(999999, $z);
        $this->assertSame([], DispoJobUebersichtZahlen::laden([]));
    }
```

- [ ] **Step 2: Test — FAIL**

- [ ] **Step 3: Baustein**

```php
<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobShift;

/** Zahlen der Uebersicht (Runde 2, Spec §10): zugesagt / gesucht und „noch in ZAS“ je Anfrage, zwei Queries fuer alle. */
final class DispoJobUebersichtZahlen
{
    /** @param list<int> $requestIds @return array<int, array{zugesagt:int, gesucht:int, noch_zas:int}> */
    public static function laden(array $requestIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $requestIds)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (RecDispoJobShift::query()->whereIn('request_id', $ids)->groupBy('request_id')->selectRaw('request_id, SUM(gesucht) as gesucht')->get() as $r) {
            $out[(int) $r->request_id] = ['zugesagt' => 0, 'gesucht' => (int) $r->gesucht, 'noch_zas' => 0];
        }
        $zeilen = RecDispoJobOfferShift::query()
            ->join('rec_dispo_job_offers as o', 'o.id', '=', 'rec_dispo_job_offer_shifts.offer_id')
            ->whereIn('o.request_id', $ids)
            ->whereNotNull('rec_dispo_job_offer_shifts.accepted_at')
            ->groupBy('o.request_id')
            ->selectRaw('o.request_id as request_id, COUNT(*) as zugesagt, SUM(CASE WHEN rec_dispo_job_offer_shifts.zas_entered_at IS NULL AND rec_dispo_job_offer_shifts.zas_assignment_id IS NULL THEN 1 ELSE 0 END) as noch_zas')
            ->get();
        foreach ($zeilen as $r) {
            $id = (int) $r->request_id;
            $out[$id] ??= ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0];
            $out[$id]['zugesagt'] = (int) $r->zugesagt;
            $out[$id]['noch_zas'] = (int) $r->noch_zas;
        }
        // Anfragen ohne Schicht/Zusage bekommen Nullen — unbekannte IDs nicht.
        foreach (RecDispoJobRequest::query()->whereIn('id', $ids)->pluck('id') as $id) {
            $out[(int) $id] ??= ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0];
        }

        return $out;
    }
}
```

(`use Platform\Recruiting\Models\RecDispoJobRequest;` im Kopf ergänzen.)

- [ ] **Step 4: Test — PASS**

- [ ] **Step 5: Index.php**

```php
    #[Url]
    public string $filter = 'offen';

    public function render()
    {
        $filter = in_array($this->filter, ['offen', 'zu', 'alle'], true) ? $this->filter : 'offen';
        $anfragen = RecDispoJobRequest::query()
            ->where('team_id', $this->teamId())
            ->when($filter === 'offen', fn ($q) => $q->whereNull('closed_at')->where('status', '!=', RecDispoJobRequest::STATUS_ABGESCHLOSSEN))
            ->when($filter === 'zu', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('closed_at')->orWhere('status', RecDispoJobRequest::STATUS_ABGESCHLOSSEN)))
            ->withCount('shifts')
            ->with(['waves' => fn ($q) => $q->orderByDesc('nr')])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return view('recruiting::livewire.dispo.jobs.index', [
            'anfragen'      => $anfragen,
            'zahlen'        => DispoJobUebersichtZahlen::laden($anfragen->pluck('id')->all()),
            'filter'        => $filter,
            'offeneZusagen' => DispoJobOffeneZusagen::count($this->teamId()),
        ])->layout('platform::layouts.app');
    }
```

`use Livewire\Attributes\Url;` und die Baustein-Imports ergänzen; `RecDispoJobOfferShift`-Import entfällt.

- [ ] **Step 6: index.blade.php**

Filter-Pillen über der Tabelle:

```blade
    @php $pillen = ['offen' => 'Offen', 'zu' => 'Abgeschlossen', 'alle' => 'Alle']; @endphp
    <div class="flex flex-wrap gap-2 text-sm">
        @foreach ($pillen as $wert => $label)
            @php $pilleKlasse = $filter === $wert ? 'border-blue-600 bg-blue-50 font-semibold text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'; @endphp
            <button type="button" wire:click="$set('filter', '{{ $wert }}')" class="rounded-full border px-3 py-1 {{ $pilleKlasse }}">{{ $label }}</button>
        @endforeach
    </div>
```

Leer-Text je Filter: `offen` → „Keine offene Anfrage. Mit „Neue Anfrage“ …“, sonst „Keine Anfrage in dieser Auswahl.“ Spalten: „Zusagen“ zeigt `{{ $z['zugesagt'] }} / {{ $z['gesucht'] }}` mit `$z = $zahlen[$a->id] ?? ['zugesagt' => 0, 'gesucht' => 0, 'noch_zas' => 0]` im Zeilen-`@php`, grün (`text-green-700 font-medium`) wenn `gesucht > 0 && zugesagt >= gesucht`; neue Spalte „Noch in ZAS“ nach „Zusagen“: `{{ $z['noch_zas'] > 0 ? $z['noch_zas'] : '–' }}`. Die `$zusagen[$a->id]`-Verwendung entfällt.

- [ ] **Step 7: Wiring-Test**

```php
    public function test_uebersicht_hat_filter_quote_und_noch_in_zas(): void
    {
        $src = self::datei('src/Livewire/Dispo/Jobs/Index.php');
        $this->assertMatchesRegularExpression('/#\[Url\]\s+public string \$filter = \'offen\';/', $src);
        $this->assertStringContainsString('DispoJobUebersichtZahlen::laden(', $src);

        $view = self::datei('resources/views/livewire/dispo/jobs/index.blade.php');
        $this->assertStringContainsString(">Noch in ZAS</th>", $view);
        $this->assertStringContainsString("{{ \$z['zugesagt'] }} / {{ \$z['gesucht'] }}", $view);
        $this->assertStringContainsString("\$set('filter', '{{ \$wert }}')", $view);
    }
```

- [ ] **Step 8: Blade-Check, Suite, Commit**

```bash
git add src/Services/Zas/Dispo/Jobs/DispoJobUebersichtZahlen.php src/Livewire/Dispo/Jobs/Index.php resources/views/livewire/dispo/jobs/index.blade.php tests/Integration/DispoJobUebersichtZahlenTest.php tests/Unit/DispoJobsRunde2WiringTest.php
git commit -m "feat(recruiting): Jobs anbieten R2 — Uebersicht mit Quote, „Noch in ZAS“ und Filter

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Abschluss

- Ganze Suite grün, `php tools/blade-check.php` über `create`, `show`, `index`, `job-offers`, `_crew-stil`, `sidebar`.
- Mutationsproben (Review): Sortierung in `DispoJobFortschritt` umdrehen → Test rot; bedingtes Update in `JobOffersRuecknahme::zuruecknehmen` ohne `whereNull('zas_entered_at')` → Test „eingetragen blockt“ rot; Badge-Filter `whereNull('zas_assignment_id')` entfernen → Zähler-Test rot.
- Whole-branch-Review, dann Freigabe durch S. Haustein; Merge ff auf main, Bump meingedeck, Deploy mit `migrate` + `view:clear`.
