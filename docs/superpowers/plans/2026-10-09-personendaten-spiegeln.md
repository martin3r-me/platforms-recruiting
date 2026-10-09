# Personendaten spiegeln Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Jede Änderung eines Personenfelds (Adresse, Bank, Steuer …) an einer Akte landet in allen Akten derselben Person, mit ZAS-Marker und Lohn-Eintrag je Akte; ZAS-Eingang und ZAS-Paarung spiegeln mit; ein Prüfkommando listet Bestands-Abweichungen.

**Architecture:** Eine Feldliste (`PersonenFelder`) und ein einziger Schreiber (`PersonenSpiegel`), der Geschwister-Akten ausschließlich per Query-Builder beschreibt und Marker/Lohn-Eintrag ausdrücklich setzt. Angestoßen wird er von einem dritten Listener am bestehenden `RecEmployee::updated` (deckt alle Eloquent-Schreibwege), ausdrücklich vom ZAS-Importer (Query-Builder-Weg) und bei der automatischen ZAS-Paarung. Lohn-Verfolgung wird aus dem Observer in eine gemeinsame statische Methode gezogen.

**Tech Stack:** Laravel 12, Livewire 3, PHPUnit 11 (Integration: Capsule + SQLite in-memory, echte Migrationen).

**Spec:** `docs/superpowers/specs/2026-10-09-personendaten-spiegeln-design.md`

## Global Constraints

- Arbeitsverzeichnis: `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting-portal`, Branch `feat/ma-konto`. Kein Edit außerhalb dieses Moduls.
- Tests: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (kein eigenes vendor/). Blade: `php tools/blade-check.php <datei>`.
- Geschwister-Akten werden NIE über Eloquent beschrieben, nur `DB::table('rec_employees')->…->update()`.
- Keine Migration, keine neue Spalte (`MassenzuweisungGeschlosseneWeltTest` muss grün bleiben).
- Kommentare/Texte deutsch, ASCII-Umschrift in Code-Kommentaren wie im Bestand (ae/oe/ue), Oberflächentexte mit Umlauten.
- Commit-Trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Feldliste verbatim aus Spec §2.1: first_name, last_name, birth_name, birth_date, birth_place, gender, email, street, house_number, zip, city, country_code, birth_country, nationality, is_eu_citizen, identity_card_number, identity_card_valid_until, drivers_license_class, marital_status, number_of_children, religion, employment_type, iban, bic, bank_institute, account_holder, tax_class, steuer_id, sozialversicherungsnummer, health_insurance, is_main_employer, other_employer.
- Team-Einstellung `tax_class_per_company` (Standard `false`): steht sie an, wird `tax_class` weder gespiegelt noch im Prüfkommando gemeldet.

## Review Focus

1. Datumsfelder (`birth_date`, `identity_card_valid_until`): Eloquent liefert `1990-01-01 00:00:00`, MySQL-Spalte `1990-01-01` — darf NICHT als Abweichung zählen, sonst setzt jedes Speichern beide Marker. Test in Task 1.
2. Boolesche Felder (`is_main_employer`, `is_eu_citizen`): `true` vs. `1` vs. `'1'` sind gleich. Test in Task 1.
3. Ein Feld wird geleert (z. B. `other_employer` → null, wenn Hauptarbeitgeber ja): Geschwister wird ebenfalls geleert. Test in Task 2.
4. Akte ohne Personen-Zeile und ohne `person_key` (Einzelperson): Spiegel tut nichts, kein Fehler. Test in Task 1.
5. ZAS-Paarung mit leerem eigenem Feld: ZAS-Wert bleibt in der neuen Akte und wird in die bestehende nachgetragen, nie überschrieben. Test in Task 3.

---

### Task 1: Feldliste, Lohn-Helfer, PersonenSpiegel

**Files:**
- Create: `src/Support/PersonenFelder.php`
- Create: `src/Services/PersonenSpiegel.php`
- Modify: `src/Observers/RecEmployeeExportObserver.php` (trackPayrollChanges → gemeinsamer Helfer)
- Modify: `src/Models/RecApplicantSettings.php` (DEFAULT_SETTINGS: `'tax_class_per_company' => false`)
- Test: `tests/Integration/PersonenSpiegelTest.php`, `tests/Integration/SpiegelHarness.php` (Trait)

**Interfaces:**
- Produces:
  - `PersonenFelder::SPIEGELN` (list<string>), `PersonenFelder::DATUM` = `['birth_date','identity_card_valid_until']`, `PersonenFelder::BOOL` = `['is_main_employer','is_eu_citizen']`
  - `PersonenFelder::fuerTeam(?int $teamId): list<string>` — SPIEGELN ohne `tax_class`, wenn Einstellung an
  - `PersonenFelder::normalisiere(string $feld, mixed $wert): ?string` — null/'' → null; Datum → erste 10 Zeichen; Bool → '1'/'0'; sonst getrimmter String
  - `RecEmployeeExportObserver::verfolgeLohn(int $employeeId, ?int $teamId, array $aenderungen): void` — `$aenderungen` = `[feld => ['old'=>mixed,'new'=>mixed]]`; filtert selbst auf `employee_payroll_tracked_fields`, überspringt old===null und gleiche Werte (nach `normalizePayrollValue`)
  - `PersonenSpiegel::spiegele(RecEmployee $quelle, array $werte, bool $markerSetzen, bool $lohnVerfolgen): list<int>`
  - `PersonenSpiegel::geschwister(RecEmployee $quelle): list<int>`

- [ ] **Step 1: Harness-Trait schreiben** (`tests/Integration/SpiegelHarness.php`)

Muster `EmployerFieldsExportMarkerTest::setUpBeforeClass` (Log-Alias, config/log-Attrappe, Capsule, Dispatcher, `Model::unguard()`, `Model::clearBootedModels()`). Migrationen in dieser Reihenfolge per `(require …)->up()`:
`2026_05_20_000001_create_rec_employees_table`, `2026_05_21_000001_add_full_field_set_to_rec_employees`, `2026_05_21_000002_create_rec_employee_hr_data_table`, `2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data`, `2026_05_21_000005_add_zas_export_markers_to_rec_employees`, `2026_06_05_000001_add_payroll_tracking_to_rec_employees`, `2026_09_10_000001_add_person_key_to_rec_employees`, `2026_09_23_000002_add_employer_fields_to_rec_employees`, `2026_09_28_000001_create_rec_persons_table`, `2026_09_28_000002_add_rec_person_id_to_rec_employees`. Scheitert eine an fehlender Tabelle/Spalte, die davor nötige Migration ergänzen (nicht die Migration ändern). Dazu `rec_applicant_settings` wie im Muster. `RecEmployeeExportObserver::register()` nur, wenn `$mitObserver` (Trait-Methode `baueWelt(bool $mitObserver)`). Helfer:

```php
protected function akte(array $attr = []): int
{
    return (int) Capsule::table('rec_employees')->insertGetId(array_merge([
        'uuid' => uniqid('u', true), 'team_id' => 614, 'first_name' => 'Max', 'last_name' => 'Muster',
        'portal_token' => uniqid('t', true), 'is_active' => 1,
    ], $attr));
}
protected function person(): int
{
    return (int) Capsule::table('rec_persons')->insertGetId(['uuid' => uniqid('p', true), 'team_id' => 614, 'created_at' => now(), 'updated_at' => now()]);
}
protected function zeile(int $id): object { return Capsule::table('rec_employees')->find($id); }
protected function lohn(int $id): array { return json_decode((string) $this->zeile($id)->payroll_data_changed_fields, true) ?: []; }
protected function setzeEinstellung(array $s): void
{
    Capsule::table('rec_applicant_settings')->updateOrInsert(['team_id' => 614], ['settings' => json_encode($s)]);
}
```

- [ ] **Step 2: Failing tests schreiben** (`tests/Integration/PersonenSpiegelTest.php`, `baueWelt(false)` — Spiegel direkt, ohne Observer)

```php
public function test_schreibt_abweichende_felder_auf_das_geschwister_mit_marker_und_lohn(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'street' => 'Neu 1', 'iban' => 'DE02']);
    $ma = $this->akte(['rec_person_id' => $p, 'street' => 'Alt 9', 'iban' => 'DE01']);
    $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['iban', 'street']]);

    $ids = (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'Neu 1', 'iban' => 'DE02'], true, true);

    $this->assertSame([$ma], $ids);
    $this->assertSame('Neu 1', $this->zeile($ma)->street);
    $this->assertNotNull($this->zeile($ma)->zas_changed_at);
    $this->assertNull($this->zeile($rg)->zas_changed_at, 'Quelle fasst der Spiegel nie an');
    $felder = array_column($this->lohn($ma), 'old', 'field');
    $this->assertSame(['street' => 'Alt 9', 'iban' => 'DE01'], $felder);
}

public function test_gleiche_werte_beruehren_nichts(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'street' => 'A 1']);
    $ma = $this->akte(['rec_person_id' => $p, 'street' => ' A 1 ']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'A 1'], true, true));
    $this->assertNull($this->zeile($ma)->zas_changed_at);
}

public function test_datum_mit_uhrzeit_ist_kein_unterschied(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'birth_date' => '1990-01-01']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['birth_date' => '1990-01-01 00:00:00'], true, true));
}

public function test_bool_true_und_eins_sind_gleich(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'is_main_employer' => 1]);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['is_main_employer' => true], true, true));
}

public function test_gesellschaftsfelder_werden_ignoriert(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'cost_center' => 'K1', 'personnel_number' => 'MA1']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['cost_center' => 'K9', 'personnel_number' => 'RG1'], true, true));
    $this->assertSame('K1', $this->zeile($ma)->cost_center);
}

public function test_steuerklasse_je_gesellschaft_wird_nicht_gespiegelt(): void
{
    $this->setzeEinstellung(['tax_class_per_company' => true]);
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'tax_class' => '1', 'city' => 'Alt']);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['tax_class' => '6', 'city' => 'Neu'], true, true);
    $this->assertSame('1', (string) $this->zeile($ma)->tax_class);
    $this->assertSame('Neu', $this->zeile($ma)->city);
}

public function test_einzelperson_ohne_klammer_tut_nichts(): void
{
    $rg = $this->akte(['street' => 'X']);
    $this->assertSame([], (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['street' => 'Y'], true, true));
}

public function test_abweichende_nummer_unter_gleichem_marker_wird_nicht_beschrieben(): void
{
    $rg = $this->akte(['person_key' => 'k1', 'phone' => '+491701111111', 'city' => 'A']);
    $gleich = $this->akte(['person_key' => 'k1', 'phone' => '01701111111', 'city' => 'B']);
    $fremd = $this->akte(['person_key' => 'k1', 'phone' => '+491709999999', 'city' => 'C']);
    $ids = (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['city' => 'A'], true, true);
    $this->assertSame([$gleich], $ids);
    $this->assertSame('C', $this->zeile($fremd)->city);
}

public function test_ohne_marker_und_ohne_lohn(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'iban' => 'DE01']);
    $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['iban']]);
    (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['iban' => 'DE02'], false, false);
    $this->assertSame('DE02', $this->zeile($ma)->iban);
    $this->assertNull($this->zeile($ma)->zas_changed_at);
    $this->assertSame([], $this->lohn($ma));
}
```

- [ ] **Step 3: Run — FAIL** (`--filter PersonenSpiegelTest`, Klasse fehlt)

- [ ] **Step 4: Implementieren**

`src/Support/PersonenFelder.php`:

```php
<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die Felder, die dem MENSCHEN gehoeren und deshalb auf allen Akten (RG, MA)
 * gleich sein muessen (Spec 2026-10-09 §2.1). EINE Liste — Spiegel und
 * Pruefkommando lesen nur hier.
 */
final class PersonenFelder
{
    public const SPIEGELN = [
        'first_name', 'last_name', 'birth_name', 'birth_date', 'birth_place', 'gender',
        'email',
        'street', 'house_number', 'zip', 'city', 'country_code', 'birth_country', 'nationality', 'is_eu_citizen',
        'identity_card_number', 'identity_card_valid_until', 'drivers_license_class',
        'marital_status', 'number_of_children', 'religion', 'employment_type',
        'iban', 'bic', 'bank_institute', 'account_holder',
        'tax_class', 'steuer_id', 'sozialversicherungsnummer', 'health_insurance',
        'is_main_employer', 'other_employer',
    ];

    public const DATUM = ['birth_date', 'identity_card_valid_until'];
    public const BOOL  = ['is_main_employer', 'is_eu_citizen'];

    /** @return list<string> */
    public static function fuerTeam(?int $teamId): array
    {
        if ($teamId !== null && (bool) RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('tax_class_per_company', false)) {
            return array_values(array_diff(self::SPIEGELN, ['tax_class']));
        }

        return self::SPIEGELN;
    }

    /** Vergleichsform: leer = null, Datum ohne Uhrzeit, Bool als '1'/'0'. */
    public static function normalisiere(string $feld, mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }
        if (in_array($feld, self::BOOL, true)) {
            return ((bool) (is_string($wert) ? trim($wert) : $wert)) ? '1' : '0';
        }
        $text = trim((string) $wert);
        if ($text === '') {
            return null;
        }

        return in_array($feld, self::DATUM, true) ? substr($text, 0, 10) : $text;
    }
}
```

`src/Services/PersonenSpiegel.php`:

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Support\PersonenFelder;

/**
 * Der EINZIGE Schreiber von Personenfeldern auf Geschwister-Akten (Spec
 * 2026-10-09 §4). Geschwister werden NUR per Query-Builder beschrieben: kein
 * updated-Ereignis, keine Rueckkopplung. Marker und Lohn-Eintrag setzt der
 * Spiegel deshalb ausdruecklich, nicht als Nebenwirkung.
 */
final class PersonenSpiegel
{
    private static bool $laeuft = false;

    /** @return list<int> */
    public function geschwister(RecEmployee $quelle): array
    {
        $ids = (new PersonScopeResolver())->forEmployee($quelle)['ids'];

        return array_values(array_filter($ids, fn (int $id) => $id !== (int) $quelle->id));
    }

    /**
     * @param  array<string,mixed> $werte  Feld => Wert, wie er in der Quelle steht
     * @return list<int> beschriebene Geschwister
     */
    public function spiegele(RecEmployee $quelle, array $werte, bool $markerSetzen, bool $lohnVerfolgen): array
    {
        if (self::$laeuft) {
            return [];
        }

        $teamId = $quelle->team_id !== null ? (int) $quelle->team_id : null;
        $werte = array_intersect_key($werte, array_flip(PersonenFelder::fuerTeam($teamId)));
        if ($werte === []) {
            return [];
        }

        $geschwister = $this->geschwister($quelle);
        if ($geschwister === []) {
            return [];
        }

        self::$laeuft = true;
        try {
            return DB::transaction(fn () => $this->schreibe($geschwister, $werte, $markerSetzen, $lohnVerfolgen));
        } finally {
            self::$laeuft = false;
        }
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $werte
     * @return list<int>
     */
    private function schreibe(array $ids, array $werte, bool $markerSetzen, bool $lohnVerfolgen): array
    {
        $beschrieben = [];
        $zeilen = DB::table('rec_employees')->whereIn('id', $ids)->orderBy('id')
            ->get(array_merge(['id', 'team_id'], array_keys($werte)));

        foreach ($zeilen as $zeile) {
            $update = [];
            $aenderungen = [];
            foreach ($werte as $feld => $neu) {
                $alt = $zeile->{$feld};
                if (PersonenFelder::normalisiere($feld, $alt) === PersonenFelder::normalisiere($feld, $neu)) {
                    continue;
                }
                $update[$feld] = is_bool($neu) ? (int) $neu : $neu;
                $aenderungen[$feld] = ['old' => $alt, 'new' => $neu];
            }
            if ($update === []) {
                continue;
            }

            $update['updated_at'] = now();
            if ($markerSetzen && array_intersect(array_keys($aenderungen), RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS) !== []) {
                $update['zas_changed_at'] = now();
            }
            DB::table('rec_employees')->where('id', $zeile->id)->update($update);

            if ($lohnVerfolgen) {
                RecEmployeeExportObserver::verfolgeLohn((int) $zeile->id, $zeile->team_id !== null ? (int) $zeile->team_id : null, $aenderungen);
            }
            $beschrieben[] = (int) $zeile->id;
        }

        return $beschrieben;
    }
}
```

`RecEmployeeExportObserver`: `trackPayrollChanges` baut `$aenderungen` aus `getChanges()` (`['old' => getOriginal($f), 'new' => getAttribute($f)]`) und ruft die neue `public static function verfolgeLohn(int $employeeId, ?int $teamId, array $aenderungen): void`. In `verfolgeLohn` steckt der bisherige Rumpf ab „tracked fields lesen" (Team-Einstellung, Schnitt, Erstbefüllung/gleich überspringen über `normalizePayrollValue`, JSON anhängen, `payroll_data_changed_at`). Bei `$teamId === null` die Standardliste aus `DEFAULT_SETTINGS` nehmen. Verhalten für die Quelle bleibt identisch — bestehende Tests müssen grün bleiben.

`RecApplicantSettings::DEFAULT_SETTINGS`: `'tax_class_per_company' => false,` neben `employee_payroll_tracked_fields`.

- [ ] **Step 5: Run — PASS**, dazu `--filter "EmployerFieldsExportMarkerTest|PayrollMainEmployerTest|PortalProfileWriterTest"` grün.

- [ ] **Step 6: Commit** — `feat(recruiting): PersonenSpiegel — Personenfelder auf Geschwister-Akten (Query-Builder, Marker + Lohn ausdruecklich)`

---

### Task 2: Spiegel am Speichern (Observer-Listener)

**Files:**
- Modify: `src/Observers/RecEmployeeExportObserver.php` (`register()`: dritter Listener)
- Test: `tests/Integration/PersonenSpiegelBeimSpeichernTest.php` (Trait `SpiegelHarness`, `baueWelt(true)`)

**Interfaces:**
- Consumes: `PersonenSpiegel::spiegele` (Task 1)

- [ ] **Step 1: Failing tests**

```php
public function test_portal_speichern_landet_in_beiden_akten_je_mit_eigenem_lohn_eintrag(): void
{
    $this->setzeEinstellung(['employee_payroll_tracked_fields' => ['street']]);
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'street' => 'Alt RG']);
    $ma = $this->akte(['rec_person_id' => $p, 'street' => 'Alt MA']);

    RecEmployee::find($rg)->update(['street' => 'Neu 5']);

    $this->assertSame('Neu 5', $this->zeile($ma)->street);
    $this->assertNotNull($this->zeile($rg)->zas_changed_at);
    $this->assertNotNull($this->zeile($ma)->zas_changed_at);
    $this->assertCount(1, $this->lohn($rg), 'genau ein Eintrag — keine Rueckkopplung');
    $this->assertCount(1, $this->lohn($ma));
    $this->assertSame('Alt MA', $this->lohn($ma)[0]['old']);
}

public function test_geleertes_feld_wird_auch_beim_geschwister_geleert(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'other_employer' => 'X GmbH']);
    $ma = $this->akte(['rec_person_id' => $p, 'other_employer' => 'X GmbH']);
    RecEmployee::find($rg)->update(['is_main_employer' => true, 'other_employer' => null]);
    $this->assertNull($this->zeile($ma)->other_employer);
    $this->assertSame(1, (int) $this->zeile($ma)->is_main_employer);
}

public function test_gesellschaftsfeld_bleibt_getrennt(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $ma = $this->akte(['rec_person_id' => $p, 'cost_center' => 'MA-K']);
    RecEmployee::find($rg)->update(['cost_center' => 'RG-K']);
    $this->assertSame('MA-K', $this->zeile($ma)->cost_center);
    $this->assertNull($this->zeile($ma)->zas_changed_at);
}

public function test_fehler_im_spiegel_laesst_die_quelle_gespeichert(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p]);
    $this->akte(['rec_person_id' => $p]);
    Capsule::schema()->table('rec_employees', fn ($t) => $t->dropColumn('rec_person_id')); // Resolver wirft
    try {
        RecEmployee::find($rg)->update(['city' => 'Neu']);
    } finally {
        Capsule::schema()->table('rec_employees', fn ($t) => $t->unsignedBigInteger('rec_person_id')->nullable());
    }
    $this->assertSame('Neu', $this->zeile($rg)->city);
}
```

(Hinweis Implementierer: bricht der vierte Test an SQLite-`dropColumn`, statt dessen den Resolver über einen Container-Binding-Ersatz werfen lassen — `PersonenSpiegel` dann über `app(PersonenSpiegel::class)` auflösen und im Test eine Unterklasse mit werfendem `geschwister()` binden; `PersonenSpiegel` dafür nicht `final` machen, sondern `class`. Wähle die Variante, die läuft; Ziel: Quelle gespeichert, kein Durchschlag der Ausnahme.)

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implementieren** — in `register()` innerhalb des bestehenden `RecEmployee::updated`-Callbacks, NACH dem Payroll-`safelyRun`:

```php
            // Personendaten spiegeln (Spec 2026-10-09 §5.1): Geschwister-Akten
            // derselben Person bekommen geaenderte Personenfelder, per
            // Query-Builder (keine Rueckkopplung), Marker + Lohn ausdruecklich.
            self::safelyRun(function () use ($employee): void {
                $geaendert = array_intersect_key($employee->getAttributes(), $employee->getChanges());
                app(\Platform\Recruiting\Services\PersonenSpiegel::class)
                    ->spiegele($employee, $geaendert, markerSetzen: true, lohnVerfolgen: true);
            }, 'rec_employee.updated.spiegel', $employee->id);
```

Im Harness muss `app()` funktionieren: `Container::getInstance()` ist die Facade-App; falls `app(PersonenSpiegel::class)` nicht auflöst, im Harness nichts binden — der Container baut die Klasse ohne Abhängigkeiten selbst.

- [ ] **Step 4: Run — PASS**, plus `--filter "RecEmployeeExportObserver|EmployerFieldsExportMarker|PortalProfileWriter|ZasExportMarkerGate"` grün.

- [ ] **Step 5: Commit** — `feat(recruiting): Personenfelder beim Speichern auf alle Akten der Person spiegeln`

---

### Task 3: ZAS-Eingang und ZAS-Paarung

**Files:**
- Modify: `src/Services/Zas/ZasInboundEmployeeImporter.php` (`syncMatchedFields` + Paarungs-Zweig nach `pairIfExact`)
- Modify: `src/Services/PersonenSpiegel.php` (neue Methode `uebernimmBeiPaarung`)
- Test: `tests/Integration/ZasInboundSpiegelTest.php`

**Interfaces:**
- Consumes: `PersonenSpiegel::spiegele`, `PersonenFelder`
- Produces: `PersonenSpiegel::uebernimmBeiPaarung(int $bestehendeId, int $neueId): void`

- [ ] **Step 1: Failing tests** — Harness wie `ZasInboundOverwriteTest` (Importer-Fabrik `importer()`, `run_()`), Schema dort von Hand gebaut: die Spalten `person_key`, `rec_person_id`, `phone`, `payroll_data_changed_at`, `payroll_data_changed_fields`, alle Felder aus `PersonenFelder::SPIEGELN` ergänzen sowie `rec_persons` und `rec_applicant_settings` anlegen. Lieber: neue Testklasse mit eigenem Harness aus den echten Migrationen (Trait `SpiegelHarness`) plus `rec_zas_inbound_files`-freiem Aufruf `import([$row], (object) ['id' => 99], false)`; Lookup-Attrappe aus `ZasInboundOverwriteTest::importer()` übernehmen.

```php
public function test_zas_ueberschreibt_ma_akte_und_rg_bekommt_wert_und_marker(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'street' => 'Alt', 'personnel_number' => 'RG1']);
    $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'street' => 'Alt', 'personnel_number' => 'MA1', 'company' => 'MA']);

    $this->run_($this->row(['ZasPersonalNr' => 'MA1', 'Strasse' => 'Neu 3']));

    $this->assertSame('Neu 3', $this->zeile($ma)->street);
    $this->assertNull($this->zeile($ma)->zas_changed_at, 'kein Echo');
    $this->assertSame('Neu 3', $this->zeile($rg)->street);
    $this->assertNotNull($this->zeile($rg)->zas_changed_at);
    $this->assertSame([], $this->lohn($rg));
}

public function test_paarung_uebernimmt_unsere_daten_in_die_neue_ma_akte(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'first_name' => 'Max', 'last_name' => 'Muster',
        'birth_date' => '1990-01-01', 'street' => 'Unsere Str 1', 'iban' => null, 'personnel_number' => 'RG1']);

    $this->run_($this->row(['ZasPersonalNr' => 'MA77', 'Vorname' => 'Max', 'Nachname' => 'Muster', 'Geburtsdatum' => '01.01.1990',
        'Strasse' => 'ZAS Str 9', 'IBAN' => 'DE99']));

    $ma = (int) Capsule::table('rec_employees')->where('personnel_number', 'MA77')->value('id');
    $this->assertSame('Unsere Str 1', $this->zeile($ma)->street, 'unsere Daten gewinnen');
    $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'ZAS bekommt unsere Werte fuer MA');
    $this->assertSame('DE99', $this->zeile($ma)->iban, 'leeres eigenes Feld: ZAS-Wert bleibt');
    $this->assertSame('DE99', $this->zeile($rg)->iban, 'und wird bei uns nachgetragen');
}
```

Die Spaltennamen der ZAS-Zeile (`Strasse`, `IBAN`, `Vorname`, `Geburtsdatum` …) aus `ZasInboundRowMapper` nehmen — die hier genannten sind Platzhalter für die echten Kopfzeilen; vor dem Schreiben im Mapper nachschlagen und exakt übernehmen.

- [ ] **Step 2: Run — FAIL**

- [ ] **Step 3: Implementieren**

`syncMatchedFields`: nach der Transaktion, nur wenn `$employeeFields !== []`:

```php
        // Spiegel (Spec 2026-10-09 §5.2): ZAS hat den Wert fuer DIESE Akte
        // geliefert; die Geschwister-Akte derselben Person bekommt ihn auch,
        // mit Marker (ZAS kennt sie unter der anderen Gesellschaft), ohne
        // Lohn-Eintrag (Werte aus ZAS loesen keinen aus).
        if ($employeeFields !== []) {
            try {
                $frisch = RecEmployee::find($existing->id);
                if ($frisch !== null) {
                    app(PersonenSpiegel::class)->spiegele(
                        $frisch,
                        array_intersect_key($frisch->getAttributes(), $employeeFields),
                        markerSetzen: true,
                        lohnVerfolgen: false,
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[zas-inbound] Spiegel fehlgeschlagen', ['employee_id' => (int) $existing->id, 'error' => $e->getMessage()]);
            }
        }
```

Paarung: im Neuanlage-Zweig nach `pairIfExact`, wenn `$paarung['status'] === 'paired'`:

```php
                    try {
                        app(PersonenSpiegel::class)->uebernimmBeiPaarung((int) $paarung['sibling_ids'][0], (int) $employee->id);
                    } catch (\Throwable $e) {
                        Log::warning('[zas-inbound] Datenuebernahme bei Paarung fehlgeschlagen', ['employee_id' => (int) $employee->id, 'error' => $e->getMessage()]);
                    }
```

`PersonenSpiegel::uebernimmBeiPaarung`:

```php
    /**
     * ZAS hat fuer einen bekannten Menschen eine neue Akte angelegt (Spec §5.2,
     * Paarung). Datenhoheit liegt bei uns: nicht-leere Werte der bestehenden
     * Akte gehen in die neue; ist bei uns ein Feld leer und ZAS hat einen Wert,
     * wird er bei uns nachgetragen. Nie wird ein nicht-leerer Wert der
     * bestehenden Akte ueberschrieben. Marker auf beiden, wenn geschrieben;
     * kein Lohn-Eintrag.
     */
    public function uebernimmBeiPaarung(int $bestehendeId, int $neueId): void
    {
        $teamId = DB::table('rec_employees')->where('id', $bestehendeId)->value('team_id');
        $felder = PersonenFelder::fuerTeam($teamId !== null ? (int) $teamId : null);
        $alt = DB::table('rec_employees')->where('id', $bestehendeId)->first($felder);
        $neu = DB::table('rec_employees')->where('id', $neueId)->first($felder);
        if ($alt === null || $neu === null) {
            return;
        }

        $fuerNeu = [];
        $fuerAlt = [];
        foreach ($felder as $feld) {
            $a = PersonenFelder::normalisiere($feld, $alt->{$feld});
            $n = PersonenFelder::normalisiere($feld, $neu->{$feld});
            if ($a !== null && $a !== $n) {
                $fuerNeu[$feld] = $alt->{$feld};
            } elseif ($a === null && $n !== null) {
                $fuerAlt[$feld] = $neu->{$feld};
            }
        }

        DB::transaction(function () use ($bestehendeId, $neueId, $fuerNeu, $fuerAlt) {
            foreach ([[$neueId, $fuerNeu], [$bestehendeId, $fuerAlt]] as [$id, $update]) {
                if ($update === []) {
                    continue;
                }
                $update['updated_at'] = now();
                if (array_intersect(array_keys($update), RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS) !== []) {
                    $update['zas_changed_at'] = now();
                }
                DB::table('rec_employees')->where('id', $id)->update($update);
            }
        });
    }
```

Achtung Importer-Neuanlage: `createEmployee` setzt danach `zas_changed_at = null` (Zeile ~502). Die Übernahme muss NACH diesem Zurücksetzen laufen — sie steht nach `pairIfExact`, das nach `createEmployee` kommt; im Test prüfen.

- [ ] **Step 4: Run — PASS**, plus `--filter "ZasInbound|PersonPairLinker"` grün.

- [ ] **Step 5: Commit** — `feat(recruiting): ZAS-Eingang spiegelt auf die Geschwister-Akte, Paarung uebernimmt unsere Personendaten`

---

### Task 4: Prüfkommando `recruiting:personendaten-abgleich`

**Files:**
- Create: `src/Console/Commands/PersonendatenAbgleich.php`
- Modify: Service-Provider, wo `SwitchPortalVersion` registriert ist (`grep -rn "SwitchPortalVersion::class" src`), dort ergänzen
- Test: `tests/Integration/PersonendatenAbgleichTest.php` (Trait `SpiegelHarness`, `baueWelt(false)`; Fake-Laravel wie `SwitchPortalVersionFakeLaravel`)

**Interfaces:**
- Consumes: `PersonenFelder`, `PersonenSpiegel::spiegele`, `PersonScopeResolver`

Signatur:
```
recruiting:personendaten-abgleich
    {--team= : Nur Akten dieses Teams als Ausgangspunkt}
    {--person= : rec_person_id — nur diese Person}
    {--nach= : rec_employee_id — Personenfelder dieser Akte auf alle Geschwister uebertragen (nur mit --person)}
    {--felder= : komma-getrennt, Ausgabe auf diese Felder begrenzen}
```

Verhalten:
- Gruppen bilden: alle Akten (ggf. `--team`, ggf. `--person` → `where rec_person_id`), je Akte `PersonScopeResolver::forEmployee()['ids']`, Gruppe = sortierte ids, nur Gruppen mit ≥ 2 ids, jede Gruppe einmal.
- Je Gruppe je Feld aus `PersonenFelder::fuerTeam(team der kleinsten id)` (∩ `--felder`): abweichend, wenn die normalisierten Werte nicht alle gleich sind. Ausgabe als `$this->table(['Person', 'Feld', 'Akte (PNr): Wert', …])`, eine Zeile je Feld, Werte als `"#<id> (<pnr>): <wert|leer>"`.
- Summe: `Geprueft: N Personen · mit Abweichung: M · abweichende Felder: K`.
- `--nach` ohne `--person` → Fehler, `FAILURE`, nichts geschrieben. `--nach`-Akte nicht in der Person → Fehler.
- `--person --nach`: Tabelle der Person zeigen, dann `spiegele(RecEmployee::find($nach), <nicht-leere Personenfelder der Akte, roh>, true, true)`; Ausgabe `Angeglichen: <ids>`; leere Felder der Quelle als Zeile `Nicht uebertragen (leer in #<nach>): <felder>`.

- [ ] **Step 1: Failing tests**

```php
public function test_listet_abweichung_und_schreibt_nichts(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'city' => 'Koeln', 'personnel_number' => 'RG1']);
    $ma = $this->akte(['rec_person_id' => $p, 'city' => 'Bonn', 'personnel_number' => 'MA1']);
    [$code, $aus] = $this->run_([]);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('city', $aus);
    $this->assertStringContainsString('Bonn', $aus);
    $this->assertStringContainsString('mit Abweichung: 1', $aus);
    $this->assertSame('Bonn', $this->zeile($ma)->city);
}

public function test_gleiche_daten_ergeben_keine_abweichung(): void
{
    $p = $this->person();
    $this->akte(['rec_person_id' => $p, 'city' => 'Koeln']);
    $this->akte(['rec_person_id' => $p, 'city' => 'Koeln ']);
    [, $aus] = $this->run_([]);
    $this->assertStringContainsString('mit Abweichung: 0', $aus);
}

public function test_nach_ohne_person_bricht_ab(): void
{
    [$code] = $this->run_(['--nach' => '1']);
    $this->assertSame(1, $code);
}

public function test_angleichen_uebertraegt_nur_nicht_leere_werte_mit_marker(): void
{
    $p = $this->person();
    $rg = $this->akte(['rec_person_id' => $p, 'city' => 'Koeln', 'iban' => null]);
    $ma = $this->akte(['rec_person_id' => $p, 'city' => 'Bonn', 'iban' => 'DE01']);
    [$code, $aus] = $this->run_(['--person' => (string) $p, '--nach' => (string) $rg]);
    $this->assertSame(0, $code);
    $this->assertSame('Koeln', $this->zeile($ma)->city);
    $this->assertSame('DE01', $this->zeile($ma)->iban, 'leer in der Quelle wird nicht uebertragen');
    $this->assertNotNull($this->zeile($ma)->zas_changed_at);
    $this->assertStringContainsString('iban', $aus);
}

public function test_steuerklasse_je_gesellschaft_wird_nicht_gemeldet(): void
{
    $this->setzeEinstellung(['tax_class_per_company' => true]);
    $p = $this->person();
    $this->akte(['rec_person_id' => $p, 'tax_class' => '1']);
    $this->akte(['rec_person_id' => $p, 'tax_class' => '6']);
    [, $aus] = $this->run_([]);
    $this->assertStringContainsString('mit Abweichung: 0', $aus);
}
```

- [ ] **Step 2: Run — FAIL**
- [ ] **Step 3: Implementieren** (Kommando wie beschrieben; Klassenkommentar: Zweck, „pauschales Ueberschreiben gibt es nicht", Verweis Spec §6)
- [ ] **Step 4: Run — PASS**
- [ ] **Step 5: Commit** — `feat(recruiting): recruiting:personendaten-abgleich — Abweichungen je Person listen, gezielt angleichen`

---

### Task 5: Schalter „Steuerklasse je Gesellschaft", Spec-Nachtrag, Gesamtlauf

**Files:**
- Modify: `resources/views/livewire/applicant/applicant-settings-modal.blade.php` (Reiter `payroll`, über der Feldliste)
- Modify: `docs/superpowers/specs/2026-10-09-personendaten-spiegeln-design.md` (§7: Reiter heißt „Lohn", nicht „Mitarbeiter")

- [ ] **Step 1: Schalter einfügen** direkt nach dem einleitenden `<p>` im `@elseif($activeTab === 'payroll')`-Block, Muster des Duzen-Schalters:

```blade
                <div class="p-4 bg-[var(--ui-muted-5)] rounded-lg border border-[var(--ui-border)]/40">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox"
                               wire:model="settings.tax_class_per_company"
                               class="w-5 h-5 text-[var(--ui-primary)] border-[var(--ui-border)] rounded focus:ring-[var(--ui-primary)]">
                        <div>
                            <span class="text-sm font-medium text-[var(--ui-secondary)]">Steuerklasse je Gesellschaft führen</span>
                            <p class="text-xs text-[var(--ui-muted)] mt-0.5">Hat jemand eine RG- und eine MA-Akte, werden Adresse, Bank und Co. automatisch in beiden gleich gehalten. Mit diesem Schalter bleibt die Steuerklasse davon ausgenommen und wird je Akte gepflegt.</p>
                        </div>
                    </label>
                </div>
```

- [ ] **Step 2:** `php tools/blade-check.php resources/views/livewire/applicant/applicant-settings-modal.blade.php` → OK
- [ ] **Step 3:** Spec §7 anpassen („Einstellungs-Fenster → Lohn").
- [ ] **Step 4: Gesamtsuite** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml 2>&1 | tail -3` → OK, keine Errors/Failures.
- [ ] **Step 5: Mutationsproben** (einzeln einbauen, Test rot sehen, zurücknehmen):

| Mutation | muss rot werden |
|---|---|
| `PersonenSpiegel::schreibe`: Query-Builder-Update durch `RecEmployee::find($zeile->id)->update($update)` ersetzen | `PersonenSpiegelBeimSpeichernTest::test_portal_speichern_…` (Lohn-Einträge ≠ 1) |
| `PersonenFelder::normalisiere`: Datums-`substr` entfernen | `PersonenSpiegelTest::test_datum_mit_uhrzeit_ist_kein_unterschied` |
| `PersonenFelder::fuerTeam`: Einstellung ignorieren | `PersonenSpiegelTest::test_steuerklasse_je_gesellschaft_…` |
| `PersonenSpiegel::geschwister`: `abweichend` mit aufnehmen | `PersonenSpiegelTest::test_abweichende_nummer_…` |
| `uebernimmBeiPaarung`: `elseif`-Zweig entfernen | `ZasInboundSpiegelTest::test_paarung_…` (DE99 in RG) |
| `syncMatchedFields`: `markerSetzen: false` | `ZasInboundSpiegelTest::test_zas_ueberschreibt_…` |

- [ ] **Step 6: Commit** — `feat(recruiting): Schalter Steuerklasse je Gesellschaft; Spec-Nachtrag`

---

## Nach dem letzten Task (Orchestrator)

- Prüf-Agent (Memory `feedback_review_agent_vor_merge`), Auftrag: (1) kann irgendein Weg ein Gesellschaftsfeld spiegeln; (2) kann der Spiegel eine `abweichend`-Akte oder eine fremde Person beschreiben; (3) Rückkopplung/Doppel-Einträge Lohn; (4) ZAS-Echo auf der getroffenen Akte; (5) Datums-/Bool-Vergleich gegen MySQL-Rohwerte.
- `git fetch && git merge origin/main`, Suite, Push, Demo pullen + Pin + Push.
- Deploy: keine Migration, `view:clear`. Nach Deploy `recruiting:personendaten-abgleich` im Lesemodus. Michel-Ankündigung.
