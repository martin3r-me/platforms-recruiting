# RG + MA über die Person — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Personenangaben gelten über alle aktiven Anstellungen eines Menschen: das neue Portal liest sie personenweit, Ersthelfer wird gespiegelt, der Bestand wird einmalig ohne ZAS-Marker aufgefüllt.

**Architecture:** Eine reine Regel-Klasse `PersonDatensatz` entscheidet je Feld „eigener Wert, sonst jüngste aktive Schwester“; `PortalShell` holt die Schwestern einmal je Request und liest an seinen drei Lesestellen über diese Regel. Ersthelfer wird über die bestehende Liste `PersonenFelder::SPIEGELN` gespiegelt. Das Auffüllen ist eine neue Option am bestehenden Kommando `recruiting:personendaten-abgleich` und schreibt per Query Builder.

**Tech Stack:** Laravel 12 / Livewire 3.8 (Modul ohne eigenes vendor), PHPUnit 11 über `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml`, Capsule/SQLite-Harnesse.

**Spec:** `docs/superpowers/specs/2026-10-22-person-pflichtangaben-design.md`

## Global Constraints

- Arbeitsverzeichnis: `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting-portal`, Branch `feat/person-pflichten`. Nichts außerhalb dieses Moduls ändern.
- Tests: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml [--filter X]`. Vor jedem Commit muss die Ausgabe der vollen Suite mit `OK` beginnen.
- Nur **aktive** (`is_active = 1`) und **nicht-Test** (`is_test = false`) Anstellungen zählen als Quelle oder Ziel (Spec §3, §5).
- `PersonenFelder::JE_GESELLSCHAFT` (`tax_class`, `is_main_employer`, `other_employer`, `health_insurance`) wird nie personenweit gelesen oder aufgefüllt. Datei-Spalten (`*_file_id`) ebenso nicht. `phone` steht nicht in `SPIEGELN` und bleibt draußen.
- „Leer“ heißt `null`, `''` oder `[]`. `false`/`0` ist gefüllt.
- Auffüllen: Query Builder, **kein** `zas_changed_at`, **kein** `updated_at`, kein Observer (Spec §5, Sebastian 22.10. „A“).
- Commit-Trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` und `Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda`.
- Kein Push, kein Merge.

## Review Focus

1. Schwester mit `false` (z. B. `is_eu_citizen = false`, `is_first_aider = false`) bei eigener Akte `null` → Portal und Auffüllen übernehmen `false`, nicht „leer“ (Test in Task 2 und Task 3).
2. Drei aktive Akten (ZAS-Dublette) mit zwei verschiedenen gefüllten Werten → Portal nimmt die jüngste Schwester, Auffüllen schreibt nichts und meldet Widerspruch (Tests in Task 2 und Task 3).
3. Eigene Akte inaktiv geöffnet ist unmöglich (Portal verlangt aktiv) — aber Schwester inaktiv → wird ignoriert (Test Task 2).
4. Person nur über `person_key` + Telefon verbunden (ohne `rec_person_id`) → personenweites Lesen funktioniert genauso (Test Task 2).
5. Auffüllen mit `--felder` und `--person` begrenzt wirklich (Test Task 3).

---

### Task 1: Ersthelfer am Menschen (Spiegel)

**Files:**
- Modify: `src/Support/PersonenFelder.php` (Konstanten `SPIEGELN`, `DATUM`, `BOOL`)
- Modify: `tests/Integration/PersonenSpiegelTest.php` (setUpBeforeClass + neue Tests)

**Interfaces:**
- Produces: `PersonenFelder::SPIEGELN` enthält zusätzlich `'is_first_aider'` und `'first_aider_valid_until'`; `PersonenFelder::normalisiere('is_first_aider', false) === '0'`, `normalisiere('first_aider_valid_until', '2027-05-01 00:00:00') === '2027-05-01'`.

- [ ] **Step 1: Harness um die Arbeitsschutz-Spalten erweitern**

In `tests/Integration/PersonenSpiegelTest.php`:

```php
    public static function setUpBeforeClass(): void
    {
        self::baueWelt(false, ['2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees']);
    }
```

- [ ] **Step 2: Failing tests schreiben** (ans Ende der Klasse)

```php
    /** Spec 2026-10-22 §4: Ersthelfer gehoert dem Menschen. */
    public function test_ersthelfer_wird_gespiegelt_mit_marker(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'is_first_aider' => 1, 'first_aider_valid_until' => '2027-05-01']);
        $ma = $this->akte(['rec_person_id' => $p, 'is_first_aider' => null, 'first_aider_valid_until' => null]);

        $ids = (new PersonenSpiegel())->spiegele(RecEmployee::find($rg), ['is_first_aider' => true, 'first_aider_valid_until' => '2027-05-01'], true, true);

        $this->assertSame([$ma], $ids);
        $this->assertSame(1, (int) $this->zeile($ma)->is_first_aider);
        $this->assertSame('2027-05-01', substr((string) $this->zeile($ma)->first_aider_valid_until, 0, 10));
        $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'echte Aenderung: Schwester geht an ZAS');
    }

    public function test_ersthelfer_normalisierung(): void
    {
        $this->assertSame('0', \Platform\Recruiting\Support\PersonenFelder::normalisiere('is_first_aider', false));
        $this->assertSame('1', \Platform\Recruiting\Support\PersonenFelder::normalisiere('is_first_aider', '1'));
        $this->assertNull(\Platform\Recruiting\Support\PersonenFelder::normalisiere('is_first_aider', null));
        $this->assertSame('2027-05-01', \Platform\Recruiting\Support\PersonenFelder::normalisiere('first_aider_valid_until', '2027-05-01 00:00:00'));
    }
```

- [ ] **Step 3: Tests laufen lassen — müssen scheitern**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'test_ersthelfer_'`
Expected: FAIL (`spiegele` liefert `[]`, Normalisierung liefert `'1'`/Datum mit Uhrzeit nicht wie erwartet).

- [ ] **Step 4: Implementieren** in `src/Support/PersonenFelder.php`

```php
    public const SPIEGELN = [
        'first_name', 'last_name', 'birth_name', 'birth_date', 'birth_place', 'gender',
        'email',
        'street', 'house_number', 'zip', 'city', 'country_code', 'birth_country', 'nationality', 'is_eu_citizen',
        'identity_card_number', 'identity_card_valid_until', 'drivers_license_class',
        'marital_status', 'number_of_children', 'religion', 'employment_type',
        'iban', 'bic', 'bank_institute', 'account_holder',
        'steuer_id', 'sozialversicherungsnummer',
        // Spec 2026-10-22 §4 (Sebastian 21.10.): Ersthelfer gehoert dem
        // Menschen, nicht der Gesellschaft. Der Schein laeuft als Nachweis
        // (ProofWriter spiegelt die Altspalte schon selbst).
        'is_first_aider', 'first_aider_valid_until',
    ];

    public const DATUM = ['birth_date', 'identity_card_valid_until', 'first_aider_valid_until'];
    public const BOOL  = ['is_main_employer', 'is_eu_citizen', 'is_first_aider'];
```

- [ ] **Step 5: Gezielt und voll testen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'PersonenSpiegel|PersonendatenAbgleich|ZasInbound'` → alles grün.
Run: volle Suite → beginnt mit `OK`. Bricht ein Test, weil ein Harness die Spalten `is_first_aider`/`first_aider_valid_until` nicht kennt und der Spiegel sie jetzt schreibt: in diesem Harness die Migration `2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees` ergänzen (nicht den Produktionscode anpassen). Bricht ein Test, der bisher „Ersthelfer wird nicht gespiegelt“ festschrieb: Erwartung auf das neue Verhalten ändern und im Testkommentar „Spec 2026-10-22 §4“ nennen.

- [ ] **Step 6: Commit**

```bash
git add src/Support/PersonenFelder.php tests/
git commit -m "feat(recruiting): Person — Ersthelfer wird ueber die Person gespiegelt"
```
(mit den beiden Trailer-Zeilen)

---

### Task 2: Personenweite Pflichtangaben im neuen Portal

**Files:**
- Create: `src/Support/PersonDatensatz.php`
- Modify: `src/Livewire/Public/PortalShell.php` (`datensatzWerte` ~Z. 1406, `formularwert` ~Z. 1423, `anzeigewert` ~Z. 1454, neue private Helfer)
- Create: `tests/Unit/PersonDatensatzTest.php`
- Create: `tests/Integration/PortalShellPersonDatensatzTest.php`

**Interfaces:**
- Consumes: `PersonenFelder::SPIEGELN` (Task 1), `PersonScopeResolver::forEmployee(RecEmployee): array{ids: list<int>, abweichend: list<int>}`.
- Produces: `PersonDatensatz::istLeer(mixed $wert): bool`; `PersonDatensatz::wert(string $feld, mixed $eigen, iterable $schwesternWerte): mixed` — `$schwesternWerte` = Werte desselben Felds der aktiven Schwestern, jüngste zuerst.

- [ ] **Step 1: Unit-Test für die Regel**

`tests/Unit/PersonDatensatzTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonDatensatz;

final class PersonDatensatzTest extends TestCase
{
    public function test_eigener_wert_gewinnt(): void
    {
        $this->assertSame('deutsch', PersonDatensatz::wert('nationality', 'deutsch', ['polnisch']));
    }

    public function test_leer_nimmt_die_erste_gefuellte_schwester(): void
    {
        $this->assertSame('polnisch', PersonDatensatz::wert('nationality', null, [null, '', 'polnisch', 'italienisch']));
    }

    public function test_false_ist_gefuellt(): void
    {
        $this->assertFalse(PersonDatensatz::wert('is_eu_citizen', null, [false, true]));
        $this->assertFalse(PersonDatensatz::wert('is_first_aider', false, [true]));
    }

    public function test_je_gesellschaft_wird_nie_ergaenzt(): void
    {
        $this->assertNull(PersonDatensatz::wert('is_main_employer', null, [true]));
        $this->assertNull(PersonDatensatz::wert('tax_class', null, ['1']));
    }

    public function test_feld_ausserhalb_der_personenfelder_wird_nie_ergaenzt(): void
    {
        $this->assertNull(PersonDatensatz::wert('phone', null, ['+49151']));
        $this->assertNull(PersonDatensatz::wert('first_aider_certificate_file_id', null, [55]));
    }

    public function test_keine_schwester_laesst_den_eigenen_wert(): void
    {
        $this->assertSame('', PersonDatensatz::wert('nationality', '', []));
    }
}
```

- [ ] **Step 2: Laufen lassen — FAIL** (Klasse fehlt)

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonDatensatzTest`

- [ ] **Step 3: Regel-Klasse** `src/Support/PersonDatensatz.php`

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Personenweiter Wert eines Feldes (Spec 2026-10-22 §3): eigener Wert, wenn
 * gefuellt; sonst der erste gefuellte Wert der AKTIVEN Schwestern derselben
 * Person, juengste zuerst (Reihenfolge liefert der Aufrufer). Nur fuer
 * PersonenFelder::SPIEGELN — was je Gesellschaft gilt, bleibt je Akte.
 *
 * Reine Regel, kein Framework/DB.
 */
final class PersonDatensatz
{
    /** Leer = null, '' oder []. false/0 ist gefuellt (Spec §6). */
    public static function istLeer(mixed $wert): bool
    {
        return $wert === null || $wert === '' || $wert === [];
    }

    /** @param iterable<mixed> $schwesternWerte */
    public static function wert(string $feld, mixed $eigen, iterable $schwesternWerte): mixed
    {
        if (!self::istLeer($eigen) || !in_array($feld, PersonenFelder::SPIEGELN, true)) {
            return $eigen;
        }
        foreach ($schwesternWerte as $w) {
            if (!self::istLeer($w)) {
                return $w;
            }
        }

        return $eigen;
    }
}
```

- [ ] **Step 4: Unit-Test grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonDatensatzTest` → PASS.

- [ ] **Step 5: Integrations-Test fürs Portal (failing)**

`tests/Integration/PortalShellPersonDatensatzTest.php` — Harness 1:1 nach `PortalShellProfilDatenTest` (gleiche `setUpBeforeClass`-Migrationsliste, **zusätzlich** `'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php'` und `'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php'`; gleiche `tearDownAfterClass`, `tearDown` mit `TestPortalSitzung::aufraeumen()`, `setUp` löscht `rec_employees`; gleiche Helfer `shell()` und `profilDaten()` über `ReflectionMethod`). Dazu:

```php
    private function akte(array $set): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => 913, 'first_name' => 'Tanja', 'last_name' => 'Test',
            'portal_token' => 'tok-' . uniqid(), 'is_active' => true,
            'portal_v2_since' => '2026-09-24 08:00:00',
            'is_eu_citizen' => true, 'is_main_employer' => true, 'rec_person_id' => 77,
        ], $set));
    }

    /** @return list<string> */
    private function offenePflichtfelder(RecEmployee $e): array
    {
        return array_column($this->profilDaten($this->shell($e), $e)['pflicht'], 'feld');
    }

    /** Fall Tanja (Spec §1): Staatsangehoerigkeit nur an der MA-Akte. */
    public function test_staatsangehoerigkeit_der_aktiven_schwester_zaehlt(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);

        $this->assertNotContains('nationality', $this->offenePflichtfelder($rg));
    }

    public function test_blatt_ist_mit_dem_wert_der_schwester_vorbelegt(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Persoenliches');

        $this->assertSame('deutsch', $shell->profilWerte['nationality'] ?? null);
    }

    public function test_inaktive_schwester_zaehlt_nicht(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_active' => false]);

        $this->assertContains('nationality', $this->offenePflichtfelder($rg));
    }

    public function test_test_schwester_zaehlt_nicht(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_test' => true]);

        $this->assertContains('nationality', $this->offenePflichtfelder($rg));
    }

    public function test_hauptarbeitgeber_bleibt_je_akte(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'is_main_employer' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);

        $this->assertContains('is_main_employer', $this->offenePflichtfelder($rg));
    }

    public function test_eigener_wert_gewinnt_bei_widerspruch(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch']);
        $this->akte(['company' => 'MA', 'nationality' => 'polnisch', 'is_main_employer' => false]);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Persoenliches');

        $this->assertSame('deutsch', $shell->profilWerte['nationality'] ?? null);
    }

    /** Review Focus 2: drei aktive Akten — die juengste gefuellte Schwester gewinnt. */
    public function test_juengste_schwester_gewinnt(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $alt = $this->akte(['company' => 'MA', 'nationality' => 'polnisch', 'is_main_employer' => false]);
        $neu = $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);
        Capsule::table('rec_employees')->where('id', $alt->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        Capsule::table('rec_employees')->where('id', $neu->id)->update(['updated_at' => '2026-10-01 00:00:00']);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Persoenliches');

        $this->assertSame('deutsch', $shell->profilWerte['nationality'] ?? null);
    }

    /** Review Focus 4: Verbindung nur ueber person_key + gleiche Nummer. */
    public function test_person_key_verbindung_reicht(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null, 'rec_person_id' => null, 'person_key' => 'k-9', 'phone' => '+4915112345678']);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'rec_person_id' => null, 'person_key' => 'k-9', 'phone' => '+4915112345678', 'is_main_employer' => false]);

        $this->assertNotContains('nationality', $this->offenePflichtfelder($rg));
    }

    /** Review Focus 1: false der Schwester ist ein Wert. */
    public function test_ersthelfer_nein_der_schwester_ist_beantwortet(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'is_first_aider' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_first_aider' => false, 'is_main_employer' => false]);

        $this->assertNotContains('is_first_aider', $this->offenePflichtfelder($rg));
    }
```

Hinweis für den Implementierer: Ist `company` keine Spalte im Harness, ergänze `'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'` in der Migrationsliste. Ist `is_first_aider = null` an der eigenen Akte heute **keine** offene Pflicht (der Wächter `ersthelfer` blockt evtl. erst bei Ja ohne Datum/Schein), ist `test_ersthelfer_nein_der_schwester_ist_beantwortet` nicht rot zu bekommen — dann die Prüfung auf `oeffneGruppe('Arbeitsschutz')` + `assertSame('0', $shell->profilWerte['is_first_aider'])` umstellen. Prüfe vor Step 6, dass `test_staatsangehoerigkeit_der_aktiven_schwester_zaehlt`, `test_blatt_ist_mit_dem_wert_der_schwester_vorbelegt`, `test_juengste_schwester_gewinnt`, `test_person_key_verbindung_reicht` und `test_ersthelfer_nein_der_schwester_ist_beantwortet` **rot** sind und die übrigen grün (sie beschreiben das heutige Verhalten, das bleiben muss).

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PortalShellPersonDatensatzTest`

- [ ] **Step 6: PortalShell verdrahten**

In `src/Livewire/Public/PortalShell.php` `use Platform\Recruiting\Support\PersonDatensatz;` und `use Platform\Recruiting\Services\PersonScopeResolver;` ergänzen (falls nicht vorhanden). Neue private Helfer unterhalb von `datensatzWerte`:

```php
    /**
     * Aktive, nicht-Test-Schwestern derselben Person, juengste zuerst
     * (Spec 2026-10-22 §3). Je Request einmal geladen — privat, also kein
     * Livewire-Zustand.
     *
     * @var array<int, list<RecEmployee>>
     */
    private array $schwesternJeAkte = [];

    /** @return list<RecEmployee> */
    private function aktiveSchwestern(RecEmployee $employee): array
    {
        $id = (int) $employee->id;
        if (!array_key_exists($id, $this->schwesternJeAkte)) {
            $ids = array_values(array_diff((new PersonScopeResolver())->forEmployee($employee)['ids'], [$id]));
            $this->schwesternJeAkte[$id] = $ids === [] ? [] : RecEmployee::query()
                ->whereIn('id', $ids)
                ->where('is_active', true)
                ->where('is_test', false)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
                ->all();
        }

        return $this->schwesternJeAkte[$id];
    }

    /** Personenweiter Feldwert (PersonDatensatz) — eine Stelle fuer alle Lesewege. */
    private function personWert(RecEmployee $employee, string $feld): mixed
    {
        $eigen = $employee->getAttribute($feld);
        if (!PersonDatensatz::istLeer($eigen)) {
            return $eigen;
        }

        return PersonDatensatz::wert(
            $feld,
            $eigen,
            array_map(fn (RecEmployee $s) => $s->getAttribute($feld), $this->aktiveSchwestern($employee)),
        );
    }
```

Dann die drei Lesestellen umstellen:
- `datensatzWerte`: `$werte[$schluessel] = $employee->getAttribute($schluessel);` → `$werte[$schluessel] = $this->personWert($employee, $schluessel);`
- `formularwert`: `$roh = $employee->getAttribute($feld);` → `$roh = $this->personWert($employee, $feld);`
- `anzeigewert`: `$wert = $employee->getAttribute($feld);` → `$wert = $this->personWert($employee, $feld);`

Keine weiteren Stellen ändern (Schreibweg `PortalProfileWriter` bleibt: er speichert die Formularwerte in die geöffnete Akte — so heilt sie sich).

Wenn `anzeigedatum()` einen String statt Carbon bekommt (Schwesterwerte kommen über `getAttribute` mit Casts, also Carbon für Datumsfelder): kein Handlungsbedarf; nur falls ein Test an einem Datum scheitert, dort `instanceof`-Zweig prüfen.

- [ ] **Step 7: Tests grün, volle Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'PortalShell|PortalPflicht|PersonDatensatz'` → grün.
Run: volle Suite → `OK`. Scheitert ein Bestandstest der Portal-Suites, weil der Harness `is_test`/`person_key` nicht kennt: dort die Migrationen `2026_10_21_000001_add_is_test_to_rec_employees` bzw. `2026_09_10_000001_add_person_key_to_rec_employees` in die Liste aufnehmen. `php tools/blade-check.php` (keine Blade-Änderung erwartet, Funde = 0).

- [ ] **Step 8: Gegenprobe**

`personWert` vorübergehend auf `return $employee->getAttribute($feld);` setzen → die fünf in Step 5 genannten Tests müssen rot werden. Zurücksetzen, wieder grün.

- [ ] **Step 9: Commit**

```bash
git add src/Support/PersonDatensatz.php src/Livewire/Public/PortalShell.php tests/
git commit -m "feat(recruiting): Person — Pflichtangaben im neuen Portal ueber alle aktiven Anstellungen"
```

---

### Task 3: Bestand einmalig auffüllen (`--auffuellen`)

**Files:**
- Modify: `src/Console/Commands/PersonendatenAbgleich.php` (Signatur, `handle`, neue Methode `fuelleAuf`)
- Modify: `tests/Integration/PersonendatenAbgleichTest.php`

**Interfaces:**
- Consumes: `PersonenFelder::fuerTeam()`, `PersonenFelder::normalisiere()` (inkl. Task 1), bestehende `gruppen(?int $person)`.
- Produces: Option `--auffuellen` und `--dry-run` am Kommando `recruiting:personendaten-abgleich`.

- [ ] **Step 1: Harness ergänzen**

In `tests/Integration/PersonendatenAbgleichTest.php`:

```php
    public static function setUpBeforeClass(): void
    {
        self::baueWelt(false, [
            '2026_08_26_000002_add_company_to_rec_employees',
            '2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees',
            '2026_09_23_000001_add_nationality_to_rec_employees',
        ]);
    }
```

- [ ] **Step 2: Failing tests**

```php
    public function test_auffuellen_fuellt_leere_aktive_akte_ohne_marker_und_ohne_updated_at(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'nationality' => 'deutsch', 'updated_at' => '2026-10-01 00:00:00']);
        $rg = $this->akte(['rec_person_id' => $p, 'nationality' => null, 'updated_at' => '2026-09-01 00:00:00']);
        $markerVorher = Capsule::table('rec_employees')->whereNotNull('zas_changed_at')->count();

        [$code, $aus] = $this->run_(['--auffuellen' => true]);

        $this->assertSame(0, $code);
        $this->assertSame('deutsch', $this->zeile($rg)->nationality);
        $this->assertNull($this->zeile($rg)->zas_changed_at);
        $this->assertSame('2026-09-01 00:00:00', (string) $this->zeile($rg)->updated_at);
        $this->assertSame($markerVorher, Capsule::table('rec_employees')->whereNotNull('zas_changed_at')->count());
        $this->assertStringContainsString('nationality', $aus);
    }

    public function test_auffuellen_trockenlauf_schreibt_nichts(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'nationality' => 'deutsch']);
        $rg = $this->akte(['rec_person_id' => $p, 'nationality' => null]);

        [$code, $aus] = $this->run_(['--auffuellen' => true, '--dry-run' => true]);

        $this->assertSame(0, $code);
        $this->assertNull($this->zeile($rg)->nationality);
        $this->assertStringContainsString('Trockenlauf', $aus);
    }

    public function test_auffuellen_bei_widerspruch_schreibt_nichts(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'nationality' => 'deutsch']);
        $this->akte(['rec_person_id' => $p, 'nationality' => 'polnisch']);
        $leer = $this->akte(['rec_person_id' => $p, 'nationality' => null]);

        [, $aus] = $this->run_(['--auffuellen' => true]);

        $this->assertNull($this->zeile($leer)->nationality);
        $this->assertMatchesRegularExpression('/nationality\D+0\D+1/', $aus, 'Zeile: nationality, gefuellt 0, Widerspruch 1');
    }

    public function test_auffuellen_ignoriert_inaktive_und_test_akten(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'nationality' => 'deutsch', 'is_active' => 0]);
        $aktiv = $this->akte(['rec_person_id' => $p, 'nationality' => null]);
        $test = $this->akte(['rec_person_id' => $p, 'nationality' => null, 'is_test' => 1]);

        $this->run_(['--auffuellen' => true]);

        $this->assertNull($this->zeile($aktiv)->nationality, 'inaktive Quelle zaehlt nicht');
        $this->assertNull($this->zeile($test)->nationality, 'Test-Akte wird nie angefasst');
    }

    public function test_auffuellen_uebernimmt_false(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'is_eu_citizen' => 0]);
        $leer = $this->akte(['rec_person_id' => $p, 'is_eu_citizen' => null]);

        $this->run_(['--auffuellen' => true]);

        $this->assertSame(0, (int) $this->zeile($leer)->is_eu_citizen);
        $this->assertNotNull($this->zeile($leer)->is_eu_citizen);
    }

    public function test_auffuellen_laesst_je_gesellschaft_und_telefon(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'tax_class' => '1', 'phone' => '+491511']);
        $leer = $this->akte(['rec_person_id' => $p, 'tax_class' => null, 'phone' => null]);

        $this->run_(['--auffuellen' => true]);

        $this->assertNull($this->zeile($leer)->tax_class);
        $this->assertNull($this->zeile($leer)->phone);
    }

    public function test_auffuellen_ist_wiederholbar_und_begrenzbar(): void
    {
        $p = $this->person();
        $this->akte(['rec_person_id' => $p, 'nationality' => 'deutsch', 'city' => 'Koeln']);
        $leer = $this->akte(['rec_person_id' => $p, 'nationality' => null, 'city' => null]);

        $this->run_(['--auffuellen' => true, '--felder' => 'city']);
        $this->assertNull($this->zeile($leer)->nationality, '--felder begrenzt');
        $this->assertSame('Koeln', $this->zeile($leer)->city);

        $this->run_(['--auffuellen' => true]);
        [, $aus] = $this->run_(['--auffuellen' => true]);
        $this->assertStringContainsString('aufgefuellt: 0', $aus, 'zweiter Lauf findet nichts mehr');
    }

    public function test_auffuellen_geht_nicht_mit_nach_oder_je_gesellschaft(): void
    {
        [$c1] = $this->run_(['--auffuellen' => true, '--person' => '1', '--nach' => '1']);
        [$c2] = $this->run_(['--auffuellen' => true, '--je-gesellschaft' => true]);
        $this->assertSame(1, $c1);
        $this->assertSame(1, $c2);
    }
```

(`use Illuminate\Database\Capsule\Manager as Capsule;` oben ergänzen, falls nicht vorhanden. Kennt der Harness `phone` nicht, ist es ohnehin in `rec_employees` aus der Grundmigration — prüfen.)

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter test_auffuellen` → FAIL (Option unbekannt).

- [ ] **Step 3: Implementieren**

Signatur ergänzen:

```php
        {--je-gesellschaft : nur lesen — Verstoesse beide Hauptarbeitgeber / beide Steuerklasse ungleich 6 als HR-Liste}
        {--auffuellen : leere Personenfelder AKTIVER Akten aus der aktiven Schwester fuellen (eindeutiger Wert), ohne ZAS-Marker}
        {--dry-run : mit --auffuellen: nur zaehlen, nichts schreiben}';
```

In `handle()` direkt nach der Prüfung `$nach !== null && $person === null` einfügen:

```php
        if ($this->option('auffuellen')) {
            if ($nach !== null || $this->option('je-gesellschaft')) {
                $this->error('--auffuellen geht nicht zusammen mit --nach oder --je-gesellschaft. Es wird nichts geschrieben.');

                return self::FAILURE;
            }
            $nurFelder = null;
            if (($f = trim((string) $this->option('felder'))) !== '') {
                $nurFelder = array_values(array_filter(array_map('trim', explode(',', $f))));
            }

            return $this->fuelleAuf($person, $nurFelder, (bool) $this->option('dry-run'));
        }
```

Neue Methode:

```php
    /**
     * Spec 2026-10-22 §5: je Person mit mindestens zwei AKTIVEN, nicht-Test-
     * Akten; je Personenfeld: genau ein gefuellter (normierter) Wert → in die
     * leeren aktiven Akten schreiben. Zwei Werte → Widerspruch, nichts
     * schreiben. Query Builder, kein zas_changed_at, kein updated_at
     * (Sebastian 22.10.: Variante A). Wiederholbar.
     *
     * @param list<string>|null $nurFelder
     */
    private function fuelleAuf(?int $person, ?array $nurFelder, bool $trocken): int
    {
        $markerVorher = DB::table('rec_employees')->whereNotNull('zas_changed_at')->count();
        $gefuellt = [];
        $widerspruch = [];
        $personen = 0;
        $akten = [];

        foreach ($this->gruppen($person) as $g) {
            $rows = DB::table('rec_employees')
                ->whereIn('id', $g['ids'])
                ->where('is_active', 1)
                ->where('is_test', false)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();
            if ($rows->count() < 2) {
                continue;
            }
            $teamId = $rows->first()->team_id !== null ? (int) $rows->first()->team_id : null;
            $felder = PersonenFelder::fuerTeam($teamId, $nurFelder);
            $schreiben = [];   // id => [feld => roh]
            $personGeaendert = false;

            foreach ($felder as $feld) {
                $quelle = null;
                $werte = [];
                $leere = [];
                foreach ($rows as $r) {
                    $n = PersonenFelder::normalisiere($feld, $r->{$feld} ?? null);
                    if ($n === null) {
                        $leere[] = (int) $r->id;
                        continue;
                    }
                    $werte[$n] = true;
                    $quelle ??= $r->{$feld};
                }
                if ($leere === [] || $werte === []) {
                    continue;
                }
                if (count($werte) > 1) {
                    $widerspruch[$feld] = ($widerspruch[$feld] ?? 0) + 1;
                    continue;
                }
                foreach ($leere as $id) {
                    $schreiben[$id][$feld] = $quelle;
                    $gefuellt[$feld] = ($gefuellt[$feld] ?? 0) + 1;
                    $akten[$id] = true;
                }
                $personGeaendert = true;
            }

            if ($personGeaendert) {
                $personen++;
            }
            if (!$trocken) {
                foreach ($schreiben as $id => $set) {
                    DB::table('rec_employees')->where('id', $id)->update($set);
                }
            }
        }

        $alle = array_unique(array_merge(array_keys($gefuellt), array_keys($widerspruch)));
        sort($alle);
        if ($alle !== []) {
            $this->table(['Feld', 'gefuellt (Akten)', 'Widerspruch (Personen)'], array_map(
                fn ($f) => [$f, (string) ($gefuellt[$f] ?? 0), (string) ($widerspruch[$f] ?? 0)],
                $alle,
            ));
        }
        $this->line(sprintf('Personen mit Auffuellung: %d · Akten aufgefuellt: %d · Felder aufgefuellt: %d', $personen, count($akten), array_sum($gefuellt)));
        $this->line(sprintf('Marker vorher: %d, nachher: %d', $markerVorher, DB::table('rec_employees')->whereNotNull('zas_changed_at')->count()));
        $this->line($trocken ? 'Trockenlauf — nichts geschrieben.' : 'aufgefuellt: ' . array_sum($gefuellt));

        return self::SUCCESS;
    }
```

Achtung `test_auffuellen_ist_wiederholbar_und_begrenzbar` erwartet `aufgefuellt: 0` beim dritten Lauf — die letzte Zeile liefert das. `gruppen()` verlangt heute `count($ids) >= 2` über alle (auch inaktive) Akten — das ist gewollt, die Aktiv-Filterung passiert in `fuelleAuf`.

Docblock der Klasse um einen Absatz `--auffuellen` (Spec 2026-10-22 §5) ergänzen.

- [ ] **Step 4: Tests grün, volle Suite**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter PersonendatenAbgleichTest` → grün. Volle Suite → `OK`.

- [ ] **Step 5: Gegenprobe**

`->where('is_active', 1)` in `fuelleAuf` vorübergehend entfernen → `test_auffuellen_ignoriert_inaktive_und_test_akten` muss rot werden. `count($werte) > 1`-Zweig vorübergehend entfernen → `test_auffuellen_bei_widerspruch_schreibt_nichts` rot. Zurücksetzen.

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/PersonendatenAbgleich.php tests/Integration/PersonendatenAbgleichTest.php
git commit -m "feat(recruiting): Person — Bestand auffuellen (personendaten-abgleich --auffuellen), ohne ZAS-Marker"
```
