# Bewertungssystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Schulungsleiter kann jeden Teilnehmer ab Status „Teilgenommen" bewerten — fünf Kriterien à 1–5 Sterne, Wäschepaket, Qualifikation und ein Bewertungstext, alles in einem Modal — unabhängig davon, ob schon ein Mitarbeiter existiert.

**Architecture:** Alle acht Bewertungsfelder liegen am **Bewerber** (`rec_applicants`) und werden bei der MA-Erst-Anlage einmalig auf `rec_employee_hr_data` übernommen. Danach ist hrData die einzige Lese- und Schreibseite (Phasenregel). Die gesamte Entscheidungslogik liegt in vier reinen Support-Klassen, die ohne Laravel testbar sind; Livewire und Blade sind dünne Verdrahtung darüber.

**Tech Stack:** PHP 8.4, Laravel/Livewire 3, Blade, PHPUnit 11.5 (reines PHPUnit, kein Laravel-Bootstrap), MySQL (Produktion) / sqlite (Integration-Harness).

**Spec:** `docs/superpowers/specs/2026-08-05-bewertungssystem-design.md` (Commit `316790d`). Die dort definierten Fakten **F1–F19** sind bindend und werden pro Task referenziert.

## Global Constraints

- **Testrunner:** `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` — das Modul hat **kein eigenes `vendor/`**. Aus dem Modul-Root ausführen.
- **Testkonvention:** Suite `Unit` (`tests/Unit`) ist reines PHPUnit ohne Laravel-Bootstrap (`tests/bootstrap.php` lädt nur `src/` und `tests/`). Suite `Integration` (`tests/Integration`) baut Container + Capsule/sqlite selbst auf. **Keine Tests in die Unit-Suite legen, die faktisch eine DB brauchen.**
- **Namespaces:** Produktionscode `Platform\Recruiting\…` unter `src/`; Tests `Platform\Recruiting\Tests\Unit\…` unter `tests/Unit/`.
- **Die fünf Kriterien — Spalten- und ZAS-Namen sind fix** (Spec §„Die fünf Kriterien"):
  | Spalte | Label | ZAS-Spalte |
  | --- | --- | --- |
  | `rating_erscheinungsbild` | Erscheinungsbild & Hygiene | `BewertungErscheinungsbild` |
  | `rating_fachkompetenz` | Fachliche Grundkompetenz | `BewertungFachkompetenz` |
  | `rating_auffassungsgabe` | Auffassungsgabe & Lernbereitschaft | `BewertungAuffassungsgabe` |
  | `rating_auftreten` | Auftreten & Kommunikation | `BewertungAuftreten` |
  | `rating_teamintegration` | Teamintegration & Verhalten | `BewertungTeamintegration` |
- **„leer" ist immer `NULL`, niemals `[]` oder `''`** (F7).
- **Wertebereich der Sterne: 1–5**, alles andere wird zu `NULL`.
- **Freigabe-Regel:** Bewertung nur bei Buchungsstatus **genau** `attended` (F10-Statusliste: `booked`, `registered`, `confirmed`, `attended`, `cancelled`, `no_show`).
- **`evaluation_note` wird NICHT nach ZAS exportiert** und gehört **nicht** in `RELEVANT_HR_FIELDS` (Spec §5).
- **`rec_interview_bookings` bekommt keine neuen Spalten.**
- **Deutsche Kommentare** im Code, wie im ganzen Modul. Keine Umlaute in ZAS-Spaltennamen.
- **Nach jedem Task committen.** Commit-Präfix `feat(recruiting):` bzw. `test(recruiting):`.

---

## Testing-Strategie (warum die Reihenfolge so ist)

Tasks 1–7 bauen die gesamte Logik als **reine, unit-getestete Support-Klassen**. Tasks 8–13 verdrahten sie in Livewire, Blade, Mitarbeiterkarte und ZAS-Export. Für Livewire/Blade gibt es in diesem Modul **kein Test-Harness** (kein Laravel-Bootstrap, keine Livewire-Testumgebung) — deren Verifikation ist daher: `php -l`, die Unit-Suite bleibt grün, plus die im Task benannte manuelle Sichtprüfung. Deshalb muss jede Entscheidung, die schiefgehen kann, vorher in einer Support-Klasse liegen. Wer in Tasks 8–13 neue Logik in die Komponente schreibt statt eine Support-Klasse zu rufen, hat den Plan verlassen.

---

## File Structure

**Neu:**
- `src/Support/RatingCriteria.php` — Single Source of Truth: Spalten, Labels, ZAS-Namen, Handout-Texte.
- `src/Support/EvaluationAvailability.php` — Freigabe-Regel.
- `src/Support/EvaluationValues.php` — Normalisierung, „bereits bewertet", kompakte Anzeige.
- `src/Support/ApplicantContactName.php` — deterministische Kontaktwahl + Namensformat + Sortierschlüssel.
- `src/Support/EvaluationTransfer.php` — welche Werte bei MA-Anlage nach hrData wandern.
- `database/migrations/2026_08_05_000001_add_evaluation_fields_to_rec_applicants.php`
- `database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php`
- `tests/Unit/RatingCriteriaTest.php`, `tests/Unit/EvaluationAvailabilityTest.php`, `tests/Unit/EvaluationValuesTest.php`, `tests/Unit/ApplicantContactNameTest.php`, `tests/Unit/EvaluationTransferTest.php`, `tests/Unit/EvaluationFieldDriftTest.php`, `tests/Unit/ZasRatingExportTest.php`

**Ändern:**
- `src/Models/RecApplicant.php` — `$fillable` + `$casts` um acht Felder.
- `src/Models/RecEmployeeHrData.php` — `$fillable` + `$casts` um sechs Felder.
- `src/Services/CreateEmployeeFromApplicantService.php` — Übernahme neben `:105`.
- `src/Livewire/InterviewBookings/Index.php` — Modal, Phasenweiche, Sortierung, Namensformat, Suchfeld, Selfie-Batch.
- `resources/views/livewire/interview-bookings/index.blade.php` — Modal, Anzeige in Spalte BEWERTUNG, Selfie-Spalte (beide Modi), Namensformat (beide Modi), Suchfeld.
- `src/Livewire/Employees/Show.php` — `hrFieldGroups()`.
- `src/Services/Zas/ZasEmployeeFieldResolver.php` — fünf Spalten.
- `src/Observers/RecEmployeeExportObserver.php` — `RELEVANT_HR_FIELDS`.

---

### Task 1: `RatingCriteria` — Single Source of Truth

**Files:**
- Create: `src/Support/RatingCriteria.php`
- Test: `tests/Unit/RatingCriteriaTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces: `RatingCriteria::CRITERIA` (array), `::columns(): array<int,string>`, `::labels(): array<string,string>`, `::zasColumns(): array<string,string>`, `::helpTexts(): array<string,string>`, `::isColumn(string $column): bool`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/RatingCriteriaTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\RatingCriteria;

class RatingCriteriaTest extends TestCase
{
    public function test_hat_genau_fuenf_kriterien_in_fester_reihenfolge(): void
    {
        $this->assertSame([
            'rating_erscheinungsbild',
            'rating_fachkompetenz',
            'rating_auffassungsgabe',
            'rating_auftreten',
            'rating_teamintegration',
        ], RatingCriteria::columns());
    }

    public function test_labels_entsprechen_der_spec(): void
    {
        $labels = RatingCriteria::labels();
        $this->assertSame('Erscheinungsbild & Hygiene', $labels['rating_erscheinungsbild']);
        $this->assertSame('Fachliche Grundkompetenz', $labels['rating_fachkompetenz']);
        $this->assertSame('Auffassungsgabe & Lernbereitschaft', $labels['rating_auffassungsgabe']);
        $this->assertSame('Auftreten & Kommunikation', $labels['rating_auftreten']);
        $this->assertSame('Teamintegration & Verhalten', $labels['rating_teamintegration']);
    }

    public function test_zas_spaltennamen_sind_vertragsbestandteil(): void
    {
        // Diese Namen sind mit Hr. Michel abgestimmt — Aenderung = zweite
        // Abstimmungsrunde. Test schuetzt gegen stille Umbenennung.
        $this->assertSame([
            'rating_erscheinungsbild' => 'BewertungErscheinungsbild',
            'rating_fachkompetenz'    => 'BewertungFachkompetenz',
            'rating_auffassungsgabe'  => 'BewertungAuffassungsgabe',
            'rating_auftreten'        => 'BewertungAuftreten',
            'rating_teamintegration'  => 'BewertungTeamintegration',
        ], RatingCriteria::zasColumns());
    }

    public function test_zas_namen_ohne_umlaute_und_eindeutig(): void
    {
        $zas = array_values(RatingCriteria::zasColumns());
        $this->assertSame($zas, array_unique($zas), 'ZAS-Spaltennamen muessen eindeutig sein.');
        foreach ($zas as $name) {
            $this->assertSame($name, preg_replace('/[^A-Za-z]/', '', $name), "ZAS-Spalte {$name} darf nur Buchstaben enthalten.");
        }
    }

    public function test_jedes_kriterium_hat_einen_hilfetext_schluessel(): void
    {
        // Inhalt kommt spaeter aus dem Handout-PDF; der Schluessel muss von
        // Anfang an existieren, damit das Popover nicht auf null laeuft.
        foreach (RatingCriteria::columns() as $column) {
            $this->assertArrayHasKey($column, RatingCriteria::helpTexts());
            $this->assertIsString(RatingCriteria::helpTexts()[$column]);
        }
    }

    public function test_is_column_akzeptiert_nur_bekannte_kriterien(): void
    {
        $this->assertTrue(RatingCriteria::isColumn('rating_auftreten'));
        $this->assertFalse(RatingCriteria::isColumn('status'));
        $this->assertFalse(RatingCriteria::isColumn('team_id'));
        $this->assertFalse(RatingCriteria::isColumn('evaluation_note'));
        $this->assertFalse(RatingCriteria::isColumn(''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter RatingCriteriaTest
```

Expected: FAIL — `Class "Platform\Recruiting\Support\RatingCriteria" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Support/RatingCriteria.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Single Source of Truth der fuenf Bewertungskriterien: Spaltenname (identisch
 * auf rec_applicants UND rec_employee_hr_data), Anzeige-Label, ZAS-CSV-Spalte
 * und Handout-Hilfetext.
 *
 * Die ZAS-Spaltennamen sind mit Hr. Michel abgestimmter Vertragsbestandteil —
 * Umbenennen erfordert eine neue Abstimmungsrunde (Spec F6).
 *
 * Die Hilfetexte stammen aus dem Schulungsleiter-Handout und werden
 * nachgetragen; die Schluessel existieren von Anfang an, damit das Popover
 * nicht auf null laeuft.
 */
final class RatingCriteria
{
    public const CRITERIA = [
        'rating_erscheinungsbild' => [
            'label' => 'Erscheinungsbild & Hygiene',
            'zas'   => 'BewertungErscheinungsbild',
            'help'  => '',
        ],
        'rating_fachkompetenz' => [
            'label' => 'Fachliche Grundkompetenz',
            'zas'   => 'BewertungFachkompetenz',
            'help'  => '',
        ],
        'rating_auffassungsgabe' => [
            'label' => 'Auffassungsgabe & Lernbereitschaft',
            'zas'   => 'BewertungAuffassungsgabe',
            'help'  => '',
        ],
        'rating_auftreten' => [
            'label' => 'Auftreten & Kommunikation',
            'zas'   => 'BewertungAuftreten',
            'help'  => '',
        ],
        'rating_teamintegration' => [
            'label' => 'Teamintegration & Verhalten',
            'zas'   => 'BewertungTeamintegration',
            'help'  => '',
        ],
    ];

    /** @return array<int, string> */
    public static function columns(): array
    {
        return array_keys(self::CRITERIA);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return array_map(fn (array $c) => $c['label'], self::CRITERIA);
    }

    /** @return array<string, string> */
    public static function zasColumns(): array
    {
        return array_map(fn (array $c) => $c['zas'], self::CRITERIA);
    }

    /** @return array<string, string> */
    public static function helpTexts(): array
    {
        return array_map(fn (array $c) => $c['help'], self::CRITERIA);
    }

    public static function isColumn(string $column): bool
    {
        return array_key_exists($column, self::CRITERIA);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter RatingCriteriaTest
```

Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/RatingCriteria.php tests/Unit/RatingCriteriaTest.php
git commit -m "feat(recruiting): RatingCriteria als Single Source of Truth der fuenf Bewertungskriterien"
```

---

### Task 2: `EvaluationAvailability` — Freigabe-Regel

**Files:**
- Create: `src/Support/EvaluationAvailability.php`
- Test: `tests/Unit/EvaluationAvailabilityTest.php`

**Interfaces:**
- Consumes: `Platform\Recruiting\Support\BookingStatusGroups::KNOWN` (existiert, `src/Support/BookingStatusGroups.php:14`).
- Produces: `EvaluationAvailability::isOpen(?string $bookingStatus): bool`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/EvaluationAvailabilityTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\BookingStatusGroups;
use Platform\Recruiting\Support\EvaluationAvailability;

class EvaluationAvailabilityTest extends TestCase
{
    public function test_nur_attended_ist_offen(): void
    {
        $this->assertTrue(EvaluationAvailability::isOpen('attended'));
    }

    public function test_alle_anderen_bekannten_status_sind_gesperrt(): void
    {
        foreach (BookingStatusGroups::KNOWN as $status) {
            if ($status === 'attended') {
                continue;
            }
            $this->assertFalse(
                EvaluationAvailability::isOpen($status),
                "Status {$status} darf die Bewertung nicht freischalten.",
            );
        }
    }

    public function test_die_statusliste_deckt_alle_erwarteten_werte_ab(): void
    {
        // Schuetzt gegen stille Erweiterung der Statusliste ohne Entscheidung,
        // wie der neue Status zur Bewertung steht.
        $this->assertSame(
            ['booked', 'registered', 'confirmed', 'attended', 'cancelled', 'no_show'],
            BookingStatusGroups::KNOWN,
        );
    }

    public function test_null_und_unbekannt_sind_gesperrt(): void
    {
        $this->assertFalse(EvaluationAvailability::isOpen(null));
        $this->assertFalse(EvaluationAvailability::isOpen(''));
        $this->assertFalse(EvaluationAvailability::isOpen('Attended'));
        $this->assertFalse(EvaluationAvailability::isOpen('irgendwas'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationAvailabilityTest
```

Expected: FAIL — `Class "Platform\Recruiting\Support\EvaluationAvailability" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Support/EvaluationAvailability.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Freigabe der Bewertungserfassung: genau dann, wenn die Buchung auf
 * 'attended' steht.
 *
 * BEWUSST kein ODER mit "Employee existiert" (Spec §2): der einzige Bestandsfall
 * ohne attended-Buchung hat keine Bewertung, es gibt nichts zu retten. Und ein
 * fehlender Status ist NICHT durch einen beilaeufigen Klick zu heilen — ein
 * Wechsel auf 'attended' kann ueber den Compliance-Observer einen
 * HR-Schreibtisch-Fall anlegen und auto_pilot abschalten (Spec F15).
 */
final class EvaluationAvailability
{
    public static function isOpen(?string $bookingStatus): bool
    {
        return $bookingStatus === 'attended';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationAvailabilityTest
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/EvaluationAvailability.php tests/Unit/EvaluationAvailabilityTest.php
git commit -m "feat(recruiting): EvaluationAvailability — Bewertung ab Status attended"
```

---

### Task 3: `EvaluationValues` — Normalisierung, Zustand, kompakte Anzeige

**Files:**
- Create: `src/Support/EvaluationValues.php`
- Test: `tests/Unit/EvaluationValuesTest.php`

**Interfaces:**
- Consumes: `RatingCriteria::columns()` (Task 1).
- Produces:
  - `EvaluationValues::normalizeStar(mixed $value): ?int`
  - `EvaluationValues::normalizeList(mixed $value): ?array`
  - `EvaluationValues::hasAny(array $values): bool`
  - `EvaluationValues::compactLine(array $values): string`
  - `EvaluationValues::FIELDS: array<int,string>` — alle acht Feldnamen in fester Reihenfolge.

- [ ] **Step 1: Write the failing test**

`tests/Unit/EvaluationValuesTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EvaluationValues;

class EvaluationValuesTest extends TestCase
{
    public function test_felder_sind_die_acht_bewertungsfelder(): void
    {
        $this->assertSame([
            'rating_erscheinungsbild',
            'rating_fachkompetenz',
            'rating_auffassungsgabe',
            'rating_auftreten',
            'rating_teamintegration',
            'evaluation_note',
            'linen_package_items',
            'qualifications',
        ], EvaluationValues::FIELDS);
    }

    public function test_sterne_nur_1_bis_5_sonst_null(): void
    {
        $this->assertSame(1, EvaluationValues::normalizeStar(1));
        $this->assertSame(5, EvaluationValues::normalizeStar('5'));
        $this->assertSame(3, EvaluationValues::normalizeStar('3'));
        $this->assertNull(EvaluationValues::normalizeStar(0));
        $this->assertNull(EvaluationValues::normalizeStar(6));
        $this->assertNull(EvaluationValues::normalizeStar(-1));
        $this->assertNull(EvaluationValues::normalizeStar(null));
        $this->assertNull(EvaluationValues::normalizeStar(''));
        $this->assertNull(EvaluationValues::normalizeStar('abc'));
        $this->assertNull(EvaluationValues::normalizeStar([]));
        $this->assertNull(EvaluationValues::normalizeStar(2.7));
    }

    public function test_leere_liste_wird_null_niemals_leeres_array(): void
    {
        // Spec F7: "leer" == NULL. Ein leeres Array wuerde von der
        // Uebernahme-Pruefung (=== null) als "schon gefuellt" gelesen.
        $this->assertNull(EvaluationValues::normalizeList([]));
        $this->assertNull(EvaluationValues::normalizeList(null));
        $this->assertNull(EvaluationValues::normalizeList(['', null]));
        $this->assertNull(EvaluationValues::normalizeList('nicht-array'));
    }

    public function test_liste_wird_bereinigt_und_reindiziert(): void
    {
        $this->assertSame(['hemd', 'schuerze'], EvaluationValues::normalizeList(['hemd', '', 'schuerze', null]));
        $this->assertSame(['hemd'], EvaluationValues::normalizeList([2 => 'hemd']));
    }

    public function test_hat_bewertung_wenn_mindestens_ein_feld_gesetzt_ist(): void
    {
        $leer = array_fill_keys(EvaluationValues::FIELDS, null);

        $this->assertFalse(EvaluationValues::hasAny($leer));
        $this->assertFalse(EvaluationValues::hasAny([]));

        $this->assertTrue(EvaluationValues::hasAny(['rating_auftreten' => 3] + $leer));
        $this->assertTrue(EvaluationValues::hasAny(['evaluation_note' => 'passt'] + $leer));
        $this->assertTrue(EvaluationValues::hasAny(['linen_package_items' => ['hemd']] + $leer));
        $this->assertTrue(EvaluationValues::hasAny(['qualifications' => ['service']] + $leer));
    }

    public function test_leere_listen_und_leerer_text_zaehlen_nicht_als_bewertet(): void
    {
        $leer = array_fill_keys(EvaluationValues::FIELDS, null);

        $this->assertFalse(EvaluationValues::hasAny(['linen_package_items' => []] + $leer));
        $this->assertFalse(EvaluationValues::hasAny(['evaluation_note' => ''] + $leer));
        $this->assertFalse(EvaluationValues::hasAny(['evaluation_note' => '   '] + $leer));
    }

    public function test_kompakte_zeile_zeigt_fuenf_werte_mit_mittelpunkt(): void
    {
        $this->assertSame('4·3·5·4·4', EvaluationValues::compactLine([
            'rating_erscheinungsbild' => 4,
            'rating_fachkompetenz'    => 3,
            'rating_auffassungsgabe'  => 5,
            'rating_auftreten'        => 4,
            'rating_teamintegration'  => 4,
        ]));
    }

    public function test_kompakte_zeile_zeigt_fehlende_werte_als_gedankenstrich(): void
    {
        $this->assertSame('–·–·–·–·–', EvaluationValues::compactLine([]));
        $this->assertSame('4·–·–·–·2', EvaluationValues::compactLine([
            'rating_erscheinungsbild' => 4,
            'rating_teamintegration'  => 2,
        ]));
    }

    public function test_kompakte_zeile_normalisiert_unsinnige_werte(): void
    {
        $this->assertSame('–·–·–·–·–', EvaluationValues::compactLine([
            'rating_erscheinungsbild' => 0,
            'rating_fachkompetenz'    => 9,
            'rating_auffassungsgabe'  => 'x',
            'rating_auftreten'        => '',
            'rating_teamintegration'  => null,
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationValuesTest
```

Expected: FAIL — `Class "Platform\Recruiting\Support\EvaluationValues" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Support/EvaluationValues.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Wertebehandlung der acht Bewertungsfelder: Normalisierung beim Speichern,
 * "ist ueberhaupt bewertet?" fuer die Tabellenzelle und die kompakte
 * Zahlenreihe fuer die Anzeige.
 *
 * Invariante (Spec F7): "leer" ist immer NULL — niemals [] und niemals ''.
 * Ein leeres Array wuerde die Uebernahme-Pruefung (=== null) als "schon
 * gefuellt" lesen und die Uebernahme auf hrData blockieren.
 */
final class EvaluationValues
{
    public const NOTE_FIELD = 'evaluation_note';

    public const LIST_FIELDS = ['linen_package_items', 'qualifications'];

    /** Alle acht Bewertungsfelder in fester Reihenfolge. */
    public const FIELDS = [
        'rating_erscheinungsbild',
        'rating_fachkompetenz',
        'rating_auffassungsgabe',
        'rating_auftreten',
        'rating_teamintegration',
        self::NOTE_FIELD,
        'linen_package_items',
        'qualifications',
    ];

    /** Trenner der kompakten Zahlenreihe. */
    private const GLUE = '·';

    /** Platzhalter fuer einen nicht gesetzten Stern. */
    private const EMPTY_MARK = '–';

    public static function normalizeStar(mixed $value): ?int
    {
        if (is_bool($value) || is_array($value) || $value === null || $value === '') {
            return null;
        }
        // Nur ganzzahlige Werte akzeptieren — "2.7" ist keine Sternebewertung.
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
            return null;
        }
        $int = (int) $value;

        return ($int >= 1 && $int <= 5) ? $int : null;
    }

    /** @return array<int, string>|null */
    public static function normalizeList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $clean = array_values(array_filter(
            $value,
            fn ($v) => $v !== '' && $v !== null,
        ));

        return $clean === [] ? null : $clean;
    }

    /**
     * True, wenn mindestens ein Bewertungsfeld einen echten Wert traegt.
     * Steuert "Bewerten" vs. "Bewertung bearbeiten" und die Anzeige der
     * kompakten Zeile.
     *
     * @param  array<string, mixed>  $values
     */
    public static function hasAny(array $values): bool
    {
        foreach (RatingCriteria::columns() as $column) {
            if (self::normalizeStar($values[$column] ?? null) !== null) {
                return true;
            }
        }

        if (trim((string) ($values[self::NOTE_FIELD] ?? '')) !== '') {
            return true;
        }

        foreach (self::LIST_FIELDS as $field) {
            if (self::normalizeList($values[$field] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kompakte Anzeige der fuenf Sterne, z.B. "4·3·5·4·4"; nicht gesetzte
     * Werte als Gedankenstrich. Reihenfolge = RatingCriteria::columns().
     *
     * @param  array<string, mixed>  $values
     */
    public static function compactLine(array $values): string
    {
        $parts = [];
        foreach (RatingCriteria::columns() as $column) {
            $star = self::normalizeStar($values[$column] ?? null);
            $parts[] = $star === null ? self::EMPTY_MARK : (string) $star;
        }

        return implode(self::GLUE, $parts);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationValuesTest
```

Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/EvaluationValues.php tests/Unit/EvaluationValuesTest.php
git commit -m "feat(recruiting): EvaluationValues — Normalisierung, Bewertet-Zustand, kompakte Anzeige"
```

---

### Task 4: `ApplicantContactName` — deterministische Kontaktwahl + Namensformat

**Files:**
- Create: `src/Support/ApplicantContactName.php`
- Test: `tests/Unit/ApplicantContactNameTest.php`

**Interfaces:**
- Consumes: nichts.
- Produces:
  - `ApplicantContactName::pick(array $candidates): ?array`
  - `ApplicantContactName::display(array $candidates): string`
  - `ApplicantContactName::sortKey(array $candidates): string`

  `$candidates` ist eine Liste von Arrays der Form
  `['contact_id' => int, 'first_name' => ?string, 'last_name' => ?string, 'full_name' => ?string]`.
  Der Aufrufer (Task 10) mappt `$applicant->crmContactLinks` einmal pro Zeile in diese Form — dadurch bleibt die Klasse ohne Eloquent testbar.

- [ ] **Step 1: Write the failing test**

`tests/Unit/ApplicantContactNameTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ApplicantContactName;

class ApplicantContactNameTest extends TestCase
{
    private function candidate(int $id, ?string $first, ?string $last, ?string $full = null): array
    {
        return ['contact_id' => $id, 'first_name' => $first, 'last_name' => $last, 'full_name' => $full];
    }

    public function test_kleinste_contact_id_gewinnt_unabhaengig_von_der_reihenfolge(): void
    {
        // crmContactLinks ist ein morphMany OHNE Ordering (Spec F11) — ->first()
        // ist nicht deterministisch. Ohne feste Wahl kann sich die Sortierung
        // der Liste zwischen zwei Renderings aendern.
        $a = $this->candidate(77, 'Anna', 'Zimmermann');
        $b = $this->candidate(12, 'Bernd', 'Achterberg');

        $this->assertSame(12, ApplicantContactName::pick([$a, $b])['contact_id']);
        $this->assertSame(12, ApplicantContactName::pick([$b, $a])['contact_id']);
    }

    public function test_ohne_kandidaten_null(): void
    {
        $this->assertNull(ApplicantContactName::pick([]));
    }

    public function test_anzeige_ist_nachname_komma_vorname(): void
    {
        $this->assertSame(
            'Achterberg, Bernd',
            ApplicantContactName::display([$this->candidate(1, 'Bernd', 'Achterberg')]),
        );
    }

    public function test_anzeige_faellt_auf_full_name_zurueck_wenn_teile_fehlen(): void
    {
        $this->assertSame(
            'Laith Kanjo Allahham',
            ApplicantContactName::display([$this->candidate(1, null, null, 'Laith Kanjo Allahham')]),
        );
    }

    public function test_anzeige_nutzt_vorhandenen_teil_wenn_nur_einer_fehlt(): void
    {
        $this->assertSame('Achterberg', ApplicantContactName::display([$this->candidate(1, null, 'Achterberg')]));
        $this->assertSame('Bernd', ApplicantContactName::display([$this->candidate(1, 'Bernd', null)]));
    }

    public function test_anzeige_ohne_jede_quelle_ist_unbekannt(): void
    {
        $this->assertSame('Unbekannt', ApplicantContactName::display([]));
        $this->assertSame('Unbekannt', ApplicantContactName::display([$this->candidate(1, null, null, null)]));
        $this->assertSame('Unbekannt', ApplicantContactName::display([$this->candidate(1, '  ', '  ', '  ')]));
    }

    public function test_sortierschluessel_entspricht_der_anzeige_in_kleinschreibung(): void
    {
        // Anzeige und Sortierung MUESSEN aus derselben Quelle kommen, sonst sieht
        // die Liste fuer den Nutzer unsortiert aus (Spec §3).
        $c = [$this->candidate(1, 'Bernd', 'Achterberg')];
        $this->assertSame('achterberg, bernd', ApplicantContactName::sortKey($c));
    }

    public function test_leerfaelle_sortieren_ans_ende_und_kippen_nicht(): void
    {
        $ohne = ApplicantContactName::sortKey([]);
        $mit  = ApplicantContactName::sortKey([$this->candidate(1, 'Anna', 'Zimmermann')]);

        $this->assertGreaterThan(0, strcmp($ohne, $mit), 'Bewerber ohne Namen muessen hinter benannte sortieren.');
    }

    public function test_sortierung_einer_liste_ist_alphabetisch(): void
    {
        $rows = [
            [$this->candidate(3, 'Anna', 'Zimmermann')],
            [$this->candidate(1, 'Bernd', 'Achterberg')],
            [],
            [$this->candidate(2, 'Clara', 'Meyer')],
        ];

        usort($rows, fn ($a, $b) => strcmp(ApplicantContactName::sortKey($a), ApplicantContactName::sortKey($b)));

        $this->assertSame([
            'Achterberg, Bernd',
            'Meyer, Clara',
            'Zimmermann, Anna',
            'Unbekannt',
        ], array_map(fn ($r) => ApplicantContactName::display($r), $rows));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ApplicantContactNameTest
```

Expected: FAIL — `Class "Platform\Recruiting\Support\ApplicantContactName" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Support/ApplicantContactName.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Deterministische Kontaktwahl und einheitliches Namensformat fuer die
 * Terminseite (Buchungs- UND Nachbereitungs-Modus).
 *
 * Warum deterministisch: crmContactLinks ist ein morphMany ohne Ordering
 * (Spec F11), ->first() liefert also keine stabile Reihenfolge. Ohne feste
 * Wahl (kleinste contact_id — gleiches Prinzip wie
 * EmployeeContactListSyncService::resolveDesired) kann sich die Sortierung
 * der Liste zwischen zwei Renderings aendern.
 *
 * Warum Anzeige und Sortierschluessel aus derselben Funktion: sortiert man
 * nach Nachname, zeigt aber "Vorname Nachname", sieht die Liste fuer den
 * Nutzer unsortiert aus.
 *
 * $candidates: Liste von
 *   ['contact_id' => int, 'first_name' => ?string, 'last_name' => ?string, 'full_name' => ?string]
 */
final class ApplicantContactName
{
    public const UNKNOWN = 'Unbekannt';

    /** Sortiert Namenlose hinter alle benannten Eintraege. */
    private const SORT_LAST = "\xff";

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    public static function pick(array $candidates): ?array
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if (!isset($candidate['contact_id'])) {
                continue;
            }
            if ($best === null || (int) $candidate['contact_id'] < (int) $best['contact_id']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @param  array<int, array<string, mixed>>  $candidates */
    public static function display(array $candidates): string
    {
        $contact = self::pick($candidates);
        if ($contact === null) {
            return self::UNKNOWN;
        }

        $last  = trim((string) ($contact['last_name'] ?? ''));
        $first = trim((string) ($contact['first_name'] ?? ''));

        if ($last !== '' && $first !== '') {
            return $last . ', ' . $first;
        }
        if ($last !== '') {
            return $last;
        }
        if ($first !== '') {
            return $first;
        }

        $full = trim((string) ($contact['full_name'] ?? ''));

        return $full !== '' ? $full : self::UNKNOWN;
    }

    /** @param  array<int, array<string, mixed>>  $candidates */
    public static function sortKey(array $candidates): string
    {
        $display = self::display($candidates);

        return $display === self::UNKNOWN
            ? self::SORT_LAST
            : mb_strtolower($display);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ApplicantContactNameTest
```

Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/ApplicantContactName.php tests/Unit/ApplicantContactNameTest.php
git commit -m "feat(recruiting): ApplicantContactName — deterministische Kontaktwahl und Namensformat"
```

---

### Task 5: `EvaluationTransfer` — welche Werte bei MA-Anlage wandern

**Files:**
- Create: `src/Support/EvaluationTransfer.php`
- Test: `tests/Unit/EvaluationTransferTest.php`

**Interfaces:**
- Consumes: `EvaluationValues::FIELDS` (Task 3).
- Produces: `EvaluationTransfer::valuesToCopy(array $applicantValues, array $hrDataValues): array`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/EvaluationTransferTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EvaluationTransfer;

class EvaluationTransferTest extends TestCase
{
    public function test_kopiert_alle_acht_felder_auf_eine_leere_hr_data_row(): void
    {
        $applicant = [
            'rating_erscheinungsbild' => 4,
            'rating_fachkompetenz'    => 3,
            'rating_auffassungsgabe'  => 5,
            'rating_auftreten'        => 4,
            'rating_teamintegration'  => 2,
            'evaluation_note'         => 'Sehr zuverlaessig.',
            'linen_package_items'     => ['hemd'],
            'qualifications'          => ['service'],
        ];

        $copy = EvaluationTransfer::valuesToCopy($applicant, []);

        $this->assertSame($applicant, $copy);
    }

    public function test_ueberschreibt_niemals_einen_bestehenden_hr_data_wert(): void
    {
        $copy = EvaluationTransfer::valuesToCopy(
            ['rating_auftreten' => 4, 'evaluation_note' => 'neu'],
            ['rating_auftreten' => 2, 'evaluation_note' => null],
        );

        $this->assertArrayNotHasKey('rating_auftreten', $copy);
        $this->assertSame(['evaluation_note' => 'neu'], $copy);
    }

    public function test_ueberspringt_leere_quellwerte(): void
    {
        $copy = EvaluationTransfer::valuesToCopy(
            [
                'rating_auftreten'    => null,
                'evaluation_note'     => '',
                'linen_package_items' => [],
                'qualifications'      => ['service'],
            ],
            [],
        );

        $this->assertSame(['qualifications' => ['service']], $copy);
    }

    public function test_normalisiert_beim_kopieren(): void
    {
        // Unsinnige Sterne und leere Listeneintraege duerfen nicht auf hrData
        // landen — sonst wandert Muell in den ZAS-Export.
        $copy = EvaluationTransfer::valuesToCopy(
            [
                'rating_auftreten'    => '9',
                'rating_fachkompetenz' => '3',
                'linen_package_items' => ['hemd', '', null],
            ],
            [],
        );

        $this->assertArrayNotHasKey('rating_auftreten', $copy);
        $this->assertSame(3, $copy['rating_fachkompetenz']);
        $this->assertSame(['hemd'], $copy['linen_package_items']);
    }

    public function test_leere_quelle_ergibt_leeres_ergebnis(): void
    {
        $this->assertSame([], EvaluationTransfer::valuesToCopy([], []));
    }

    public function test_ist_idempotent_bei_doppellauf(): void
    {
        $applicant = ['rating_auftreten' => 4, 'qualifications' => ['service']];

        $first = EvaluationTransfer::valuesToCopy($applicant, []);
        $this->assertNotSame([], $first);

        // Zweiter Lauf gegen die inzwischen befuellte hrData-Row.
        $second = EvaluationTransfer::valuesToCopy($applicant, $first);
        $this->assertSame([], $second);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationTransferTest
```

Expected: FAIL — `Class "Platform\Recruiting\Support\EvaluationTransfer" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Support/EvaluationTransfer.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Einmalige Uebernahme der Bewertungsfelder vom Bewerber auf die frische
 * hrData-Row bei der Mitarbeiter-Erst-Anlage (Spec §4).
 *
 * Nur in leere Ziel-Felder schreiben: im Aufrufpfad ist die hrData-Row
 * definitionsgemaess neu (ensureHrData() ist ein firstOrCreate auf einem
 * gerade erzeugten Employee, Spec F8), ein "spaeterer HR-Edit" existiert dort
 * also nicht. Die Pruefung ist Absicherung gegen kuenftige Aufrufer, nicht
 * gegen einen heute erreichbaren Fall — und macht die Uebernahme
 * doppellauf-sicher.
 */
final class EvaluationTransfer
{
    /**
     * @param  array<string, mixed>  $applicantValues
     * @param  array<string, mixed>  $hrDataValues
     * @return array<string, mixed>  nur die zu schreibenden Felder
     */
    public static function valuesToCopy(array $applicantValues, array $hrDataValues): array
    {
        $copy = [];

        foreach (EvaluationValues::FIELDS as $field) {
            if (($hrDataValues[$field] ?? null) !== null) {
                continue;
            }

            $value = self::normalize($field, $applicantValues[$field] ?? null);
            if ($value === null) {
                continue;
            }

            $copy[$field] = $value;
        }

        return $copy;
    }

    private static function normalize(string $field, mixed $value): mixed
    {
        if (RatingCriteria::isColumn($field)) {
            return EvaluationValues::normalizeStar($value);
        }

        if (in_array($field, EvaluationValues::LIST_FIELDS, true)) {
            return EvaluationValues::normalizeList($value);
        }

        // evaluation_note
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationTransferTest
```

Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/EvaluationTransfer.php tests/Unit/EvaluationTransferTest.php
git commit -m "feat(recruiting): EvaluationTransfer — Uebernahme der Bewertungsfelder auf hrData"
```

---

### Task 6: Migrationen + Model-Felder + Drift-Guard

**Files:**
- Create: `database/migrations/2026_08_05_000001_add_evaluation_fields_to_rec_applicants.php`
- Create: `database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php`
- Modify: `src/Models/RecApplicant.php` (`$fillable` ab `:46`, `$casts` ab `:56`)
- Modify: `src/Models/RecEmployeeHrData.php` (`$fillable` ab `:20`, `$casts` ab `:36`)
- Test: `tests/Unit/EvaluationFieldDriftTest.php`

**Interfaces:**
- Consumes: `RatingCriteria::columns()` (Task 1), `EvaluationValues::FIELDS` (Task 3).
- Produces: Die acht Spalten auf `rec_applicants` und die sechs neuen auf `rec_employee_hr_data` sind fillable und gecastet.

- [ ] **Step 1: Write the failing test**

`tests/Unit/EvaluationFieldDriftTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Support\EvaluationValues;
use Platform\Recruiting\Support\RatingCriteria;
use ReflectionClass;

/**
 * Drift-Guard: die Bewertungsfelder muessen auf BEIDEN Modellen fillable und
 * identisch gecastet sein. Ohne fillable schluckt Eloquent den Wert still;
 * ohne Cast kaeme ein JSON-String statt eines Arrays zurueck.
 *
 * Gelesen per Reflection ohne Konstruktor — kein DB-Zugriff, kein
 * Laravel-Bootstrap noetig.
 */
class EvaluationFieldDriftTest extends TestCase
{
    /** @return array<int, string> */
    private function protectedArray(string $class, string $property): array
    {
        $rc  = new ReflectionClass($class);
        $obj = $rc->newInstanceWithoutConstructor();
        $p   = $rc->getProperty($property);
        $p->setAccessible(true);

        return $p->getValue($obj);
    }

    public function test_alle_acht_felder_sind_am_bewerber_fillable(): void
    {
        $fillable = $this->protectedArray(RecApplicant::class, 'fillable');

        foreach (EvaluationValues::FIELDS as $field) {
            $this->assertContains($field, $fillable, "rec_applicants.{$field} fehlt in \$fillable.");
        }
    }

    public function test_alle_acht_felder_sind_an_hr_data_fillable(): void
    {
        $fillable = $this->protectedArray(RecEmployeeHrData::class, 'fillable');

        foreach (EvaluationValues::FIELDS as $field) {
            $this->assertContains($field, $fillable, "rec_employee_hr_data.{$field} fehlt in \$fillable.");
        }
    }

    public function test_casts_sind_auf_beiden_modellen_identisch(): void
    {
        $applicant = $this->protectedArray(RecApplicant::class, 'casts');
        $hrData    = $this->protectedArray(RecEmployeeHrData::class, 'casts');

        foreach (RatingCriteria::columns() as $column) {
            $this->assertSame('integer', $applicant[$column] ?? null, "rec_applicants.{$column} muss integer casten.");
            $this->assertSame('integer', $hrData[$column] ?? null, "rec_employee_hr_data.{$column} muss integer casten.");
        }

        foreach (EvaluationValues::LIST_FIELDS as $field) {
            $this->assertSame('array', $applicant[$field] ?? null, "rec_applicants.{$field} muss array casten.");
            $this->assertSame('array', $hrData[$field] ?? null, "rec_employee_hr_data.{$field} muss array casten.");
        }
    }

    public function test_freitext_wird_nicht_gecastet(): void
    {
        // evaluation_note ist ein reines Textfeld — ein Cast waere ein Fehler.
        $applicant = $this->protectedArray(RecApplicant::class, 'casts');
        $hrData    = $this->protectedArray(RecEmployeeHrData::class, 'casts');

        $this->assertArrayNotHasKey(EvaluationValues::NOTE_FIELD, $applicant);
        $this->assertArrayNotHasKey(EvaluationValues::NOTE_FIELD, $hrData);
    }

    public function test_migrationen_legen_die_spalten_an(): void
    {
        // Die Migrationen sind ohne DB nicht ausfuehrbar; geprueft wird, dass
        // jede Spalte ueberhaupt in einer Migration vorkommt (Schutz gegen
        // "Model erweitert, Migration vergessen").
        $applicantMigration = file_get_contents(__DIR__ . '/../../database/migrations/2026_08_05_000001_add_evaluation_fields_to_rec_applicants.php');
        $hrDataMigration    = file_get_contents(__DIR__ . '/../../database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php');

        foreach (EvaluationValues::FIELDS as $field) {
            $this->assertStringContainsString("'{$field}'", $applicantMigration, "Migration rec_applicants: {$field} fehlt.");
        }

        // Auf hrData existieren linen_package_items und qualifications bereits.
        foreach (array_merge(RatingCriteria::columns(), [EvaluationValues::NOTE_FIELD]) as $field) {
            $this->assertStringContainsString("'{$field}'", $hrDataMigration, "Migration hrData: {$field} fehlt.");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationFieldDriftTest
```

Expected: FAIL — die Felder fehlen in `$fillable`, und `file_get_contents` findet die Migrationsdateien nicht.

- [ ] **Step 3: Migration für `rec_applicants` anlegen**

`database/migrations/2026_08_05_000001_add_evaluation_fields_to_rec_applicants.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bewertung am Bewerber (Spec §1): fuenf Kriterien à 1-5 Sterne, Freitext,
 * Waeschepaket und Qualifikation. Erfasst wird ab Buchungsstatus 'attended';
 * bei der Mitarbeiter-Erst-Anlage wandern die Werte einmalig auf
 * rec_employee_hr_data.
 *
 * Alle Spalten nullable ohne Default — "leer" ist NULL, niemals [] (Spec F7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
            ] as $column) {
                if (!Schema::hasColumn('rec_applicants', $column)) {
                    $table->unsignedTinyInteger($column)->nullable()->comment('1-5 Sterne');
                }
            }

            if (!Schema::hasColumn('rec_applicants', 'evaluation_note')) {
                $table->text('evaluation_note')->nullable()->comment('Bewertungstext des Schulungsleiters');
            }
            if (!Schema::hasColumn('rec_applicants', 'linen_package_items')) {
                $table->json('linen_package_items')->nullable();
            }
            if (!Schema::hasColumn('rec_applicants', 'qualifications')) {
                $table->json('qualifications')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
                'evaluation_note',
                'linen_package_items',
                'qualifications',
            ] as $column) {
                if (Schema::hasColumn('rec_applicants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
```

- [ ] **Step 4: Migration für `rec_employee_hr_data` anlegen**

`database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gegenstuecke der Bewerber-Bewertungsfelder auf der HR-Schicht (Spec §1/N1).
 * linen_package_items und qualifications existieren hier bereits (Migration
 * 2026_05_21_000004) — neu sind nur die fuenf Kriterien und der Freitext.
 *
 * Das alte star_rating bleibt unangetastet: Altdaten bleiben lesbar, die
 * ZAS-Spalte Sternebewertung laeuft weiter, es wird nur nicht mehr neu
 * geschrieben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
            ] as $column) {
                if (!Schema::hasColumn('rec_employee_hr_data', $column)) {
                    $table->unsignedTinyInteger($column)->nullable()->comment('1-5 Sterne, HR-only');
                }
            }

            if (!Schema::hasColumn('rec_employee_hr_data', 'evaluation_note')) {
                $table->text('evaluation_note')->nullable()->comment('Bewertungstext, HR-only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
                'evaluation_note',
            ] as $column) {
                if (Schema::hasColumn('rec_employee_hr_data', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
```

- [ ] **Step 5: `RecApplicant` erweitern**

In `src/Models/RecApplicant.php` am Ende des `$fillable`-Arrays (vor der schließenden `];`) einfügen:

```php
        // Bewertung am Bewerber (Spec §1) — wandert bei der MA-Anlage auf hrData.
        'rating_erscheinungsbild',
        'rating_fachkompetenz',
        'rating_auffassungsgabe',
        'rating_auftreten',
        'rating_teamintegration',
        'evaluation_note',
        'linen_package_items',
        'qualifications',
```

Und am Ende des `$casts`-Arrays (nach `'is_test' => 'boolean',`, `:72`):

```php
        'rating_erscheinungsbild' => 'integer',
        'rating_fachkompetenz'    => 'integer',
        'rating_auffassungsgabe'  => 'integer',
        'rating_auftreten'        => 'integer',
        'rating_teamintegration'  => 'integer',
        'linen_package_items'     => 'array',
        'qualifications'          => 'array',
```

- [ ] **Step 6: `RecEmployeeHrData` erweitern**

In `src/Models/RecEmployeeHrData.php` am Ende des `$fillable`-Arrays einfügen:

```php
        // Iteration 5 — fuenf Kriterien + Freitext (Spec §1/N1)
        'rating_erscheinungsbild',
        'rating_fachkompetenz',
        'rating_auffassungsgabe',
        'rating_auftreten',
        'rating_teamintegration',
        'evaluation_note',
```

Und am Ende des `$casts`-Arrays:

```php
        'rating_erscheinungsbild' => 'integer',
        'rating_fachkompetenz'    => 'integer',
        'rating_auffassungsgabe'  => 'integer',
        'rating_auftreten'        => 'integer',
        'rating_teamintegration'  => 'integer',
```

- [ ] **Step 7: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter EvaluationFieldDriftTest
```

Expected: PASS (5 tests).

- [ ] **Step 8: Volle Suite grün**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: PASS, keine Fehler in den bestehenden Tests.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_05_000001_add_evaluation_fields_to_rec_applicants.php \
        database/migrations/2026_08_05_000002_add_ratings_to_rec_employee_hr_data.php \
        src/Models/RecApplicant.php src/Models/RecEmployeeHrData.php \
        tests/Unit/EvaluationFieldDriftTest.php
git commit -m "feat(recruiting): Bewertungsspalten an rec_applicants und rec_employee_hr_data"
```

---

### Task 7: Übernahme in `CreateEmployeeFromApplicantService`

**Files:**
- Modify: `src/Services/CreateEmployeeFromApplicantService.php` (neue private Methode + Aufruf neben `:105`)

**Interfaces:**
- Consumes: `EvaluationTransfer::valuesToCopy()` (Task 5), `EvaluationValues::FIELDS` (Task 3).
- Produces: nichts für Folge-Tasks.

**Kontext:** `createOrUpdate()` läuft in einer `DB::transaction` (`:43`) und steigt bei existierendem Employee vorher aus (`:38-41`) — die Übernahme feuert also nur bei der **Erst**-Anlage. Vorbild für Fehlerbehandlung ist `snapshotContractDatesToHrData()` (`:196-242`): try/catch mit `Log::warning`, damit die MA-Anlage nicht kippt.

- [ ] **Step 1: Aufruf einbauen**

In `src/Services/CreateEmployeeFromApplicantService.php` direkt nach Zeile 105 (`$this->snapshotContractDatesToHrData($applicant, $hrData);`) ergänzen:

```php
            $this->transferEvaluationToHrData($applicant, $hrData);
```

- [ ] **Step 2: Methode ergänzen**

Direkt nach `snapshotContractDatesToHrData()` (also nach `:242`) einfügen:

```php
    /**
     * Uebernimmt die acht Bewertungsfelder vom Bewerber auf die frische
     * hrData-Row (Spec §4). Ab hier ist hrData die einzige Lese- und
     * Schreibseite; die Bewerber-Spalten werden nicht mehr angefasst.
     *
     * Eigener Log-Marker (nicht der von snapshotContractDates), damit im Log
     * unterscheidbar bleibt, welcher der beiden Uebernahme-Schritte gekippt ist.
     */
    private function transferEvaluationToHrData(RecApplicant $applicant, $hrData): void
    {
        try {
            $source = [];
            $target = [];
            foreach (\Platform\Recruiting\Support\EvaluationValues::FIELDS as $field) {
                $source[$field] = $applicant->{$field};
                $target[$field] = $hrData->{$field};
            }

            $updates = \Platform\Recruiting\Support\EvaluationTransfer::valuesToCopy($source, $target);

            if (!empty($updates)) {
                $hrData->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning('[CreateEmployeeFromApplicantService] evaluationTransfer failed', [
                'applicant_id' => $applicant->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
```

- [ ] **Step 3: Syntax prüfen**

```bash
php -l src/Services/CreateEmployeeFromApplicantService.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Volle Suite grün**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/CreateEmployeeFromApplicantService.php
git commit -m "feat(recruiting): Bewertung bei MA-Erst-Anlage auf hrData uebernehmen"
```

---

### Task 8: Livewire — Modal, Phasenweiche, Freigabe

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (`$evaluation` `:53-57`, `openEvaluationModal` `:664-681`, `closeEvaluationModal` `:683-688`, `saveEvaluation` `:690-714`)

**Interfaces:**
- Consumes: `EvaluationAvailability::isOpen()` (Task 2), `EvaluationValues` (Task 3), `RatingCriteria` (Task 1), `ApplicantContactName` (Task 4).
- Produces:
  - `$this->evaluation` — Array mit den acht Feldnamen als Schlüssel.
  - `evaluationTargetFor($applicant)` — liefert das Modell, aus dem gelesen und in das geschrieben wird (hrData falls Employee existiert, sonst Applicant). **Von Task 9 mitbenutzt.**
  - `contactCandidatesFor($applicant): array` — mappt die CRM-Links in die Kandidaten-Form von `ApplicantContactName`. **Von Task 9 und Task 10 mitbenutzt.**

- [ ] **Step 1: Property-Shape umstellen**

`$evaluation` (`:51-57`) ersetzen durch:

```php
    public bool $showEvaluationModal = false;
    public ?int $evaluateBookingId = null;

    /**
     * Bewertungs-Modal: die acht Felder aus Spec §1. Ziel ist hrData, wenn ein
     * Mitarbeiter existiert, sonst der Bewerber (Phasenregel §4).
     */
    public array $evaluation = [
        'rating_erscheinungsbild' => null,
        'rating_fachkompetenz'    => null,
        'rating_auffassungsgabe'  => null,
        'rating_auftreten'        => null,
        'rating_teamintegration'  => null,
        'evaluation_note'         => null,
        'linen_package_items'     => [],
        'qualifications'          => [],
    ];
```

- [ ] **Step 2: Kontakt-Kandidaten-Helfer ergänzen**

Direkt vor `openEvaluationModal()` einfügen — wird von Task 9 (Modal-Kopf) und
Task 10 (beide Tabellen) benutzt:

```php
    /**
     * Mappt die CRM-Links eines Bewerbers in die Kandidaten-Form, die
     * ApplicantContactName erwartet. Die Relation ist bereits eager geladen
     * (Spec F12) — kein zusaetzlicher Query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contactCandidatesFor($applicant): array
    {
        $candidates = [];
        foreach (($applicant?->crmContactLinks ?? []) as $link) {
            $candidates[] = [
                'contact_id' => $link->contact_id,
                'first_name' => $link->contact?->first_name,
                'last_name'  => $link->contact?->last_name,
                'full_name'  => $link->contact?->full_name,
            ];
        }

        return $candidates;
    }
```

- [ ] **Step 3: Phasenweiche als eigene Methode ergänzen**

Direkt darunter einfügen:

```php
    /**
     * Phasenregel (Spec §4): existiert ein Mitarbeiter, ist hrData die einzige
     * Lese- UND Schreibseite; sonst der Bewerber. Gilt fuer alle drei
     * Leseseiten — Modal, Tabellenzelle und Mitarbeiterkarte.
     *
     * Kein Dual-Write: HR pflegt dieselben Felder auf der Mitarbeiterkarte und
     * schreibt dort nur hrData. Wuerden wir beide Seiten schreiben, schoebe das
     * Modal spaeter den alten Bewerber-Wert ueber HRs Korrektur.
     *
     * Beide Quellen sind bereits eager geladen (Spec F12) — die Weiche kostet
     * keine zusaetzliche Query.
     */
    public function evaluationTargetFor($applicant)
    {
        if (!$applicant) {
            return null;
        }

        $employee = $applicant->employee;

        return $employee ? $employee->ensureHrData() : $applicant;
    }
```

- [ ] **Step 4: `openEvaluationModal()` umstellen**

`:664-681` ersetzen durch:

```php
    public function openEvaluationModal(int $bookingId): void
    {
        $booking = $this->bookings->firstWhere('id', $bookingId);

        if (!\Platform\Recruiting\Support\EvaluationAvailability::isOpen($booking?->status)) {
            session()->flash('error', 'Bewertung erst möglich, wenn die Teilnahme bestätigt ist.');
            return;
        }

        $target = $this->evaluationTargetFor($booking->applicant);
        if (!$target) {
            session()->flash('error', 'Bewerber nicht gefunden.');
            return;
        }

        $this->evaluateBookingId = $bookingId;
        $this->evaluation = [
            'rating_erscheinungsbild' => $target->rating_erscheinungsbild !== null ? (string) $target->rating_erscheinungsbild : null,
            'rating_fachkompetenz'    => $target->rating_fachkompetenz !== null ? (string) $target->rating_fachkompetenz : null,
            'rating_auffassungsgabe'  => $target->rating_auffassungsgabe !== null ? (string) $target->rating_auffassungsgabe : null,
            'rating_auftreten'        => $target->rating_auftreten !== null ? (string) $target->rating_auftreten : null,
            'rating_teamintegration'  => $target->rating_teamintegration !== null ? (string) $target->rating_teamintegration : null,
            'evaluation_note'         => $target->evaluation_note,
            'linen_package_items'     => is_array($target->linen_package_items) ? $target->linen_package_items : [],
            'qualifications'          => is_array($target->qualifications) ? $target->qualifications : [],
        ];
        $this->showEvaluationModal = true;
    }
```

- [ ] **Step 5: `closeEvaluationModal()` und `saveEvaluation()` umstellen**

`:683-714` ersetzen durch:

```php
    public function closeEvaluationModal(): void
    {
        $this->showEvaluationModal = false;
        $this->evaluateBookingId = null;
        $this->evaluation = [
            'rating_erscheinungsbild' => null,
            'rating_fachkompetenz'    => null,
            'rating_auffassungsgabe'  => null,
            'rating_auftreten'        => null,
            'rating_teamintegration'  => null,
            'evaluation_note'         => null,
            'linen_package_items'     => [],
            'qualifications'          => [],
        ];
    }

    public function saveEvaluation(): void
    {
        if (!$this->evaluateBookingId) {
            return;
        }

        $booking = $this->bookings->firstWhere('id', $this->evaluateBookingId);

        // Server-seitiger Doppel-Schutz: das Modal kann nur ueber attended
        // geoeffnet werden, aber die Methode ist public und ueber das
        // Wire-Protokoll direkt aufrufbar.
        if (!\Platform\Recruiting\Support\EvaluationAvailability::isOpen($booking?->status)) {
            session()->flash('error', 'Bewertung erst möglich, wenn die Teilnahme bestätigt ist.');
            $this->closeEvaluationModal();
            return;
        }

        $target = $this->evaluationTargetFor($booking->applicant);
        if (!$target) {
            session()->flash('error', 'Bewerber nicht mehr vorhanden.');
            $this->closeEvaluationModal();
            return;
        }

        foreach (\Platform\Recruiting\Support\RatingCriteria::columns() as $column) {
            $target->{$column} = \Platform\Recruiting\Support\EvaluationValues::normalizeStar($this->evaluation[$column] ?? null);
        }

        $note = trim((string) ($this->evaluation['evaluation_note'] ?? ''));
        $target->evaluation_note = $note === '' ? null : $note;

        $target->linen_package_items = \Platform\Recruiting\Support\EvaluationValues::normalizeList($this->evaluation['linen_package_items'] ?? null);
        $target->qualifications      = \Platform\Recruiting\Support\EvaluationValues::normalizeList($this->evaluation['qualifications'] ?? null);

        $target->save();

        session()->flash('success', 'Bewertung gespeichert.');
        $this->closeEvaluationModal();
        unset($this->bookings);
    }
```

- [ ] **Step 6: Syntax prüfen und Suite laufen lassen**

```bash
php -l src/Livewire/InterviewBookings/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`, Suite PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php
git commit -m "feat(recruiting): Bewertungs-Modal auf acht Felder und Phasenregel umgestellt"
```

---

### Task 9: Blade — Modal-Inhalt und Anzeige in der Spalte BEWERTUNG

**Files:**
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Bewertungs-Zelle `:383-399`, Bewertungs-Modal `:528-594`)

**Interfaces:**
- Consumes: `evaluationTargetFor()` (Task 8), `EvaluationAvailability`, `EvaluationValues`, `RatingCriteria`, `lookupOptionsFor()` (bestehend, `:716`).
- Produces: nichts.

- [ ] **Step 1: Bewertungs-Zelle ersetzen**

`:383-399` (`<td>` mit `$employee = $applicant?->employee`) ersetzen durch:

```blade
                                        <td class="px-4 py-3">
                                            @php
                                                $evalOpen   = \Platform\Recruiting\Support\EvaluationAvailability::isOpen($booking->status);
                                                $evalTarget = $this->evaluationTargetFor($applicant);
                                                $evalValues = [];
                                                if ($evalTarget) {
                                                    foreach (\Platform\Recruiting\Support\EvaluationValues::FIELDS as $evalField) {
                                                        $evalValues[$evalField] = $evalTarget->{$evalField};
                                                    }
                                                }
                                                $hasEval = \Platform\Recruiting\Support\EvaluationValues::hasAny($evalValues);
                                            @endphp

                                            @if(!$evalOpen)
                                                <span class="text-xs text-[var(--ui-muted)]" title="Bewertung erst nach bestätigter Teilnahme">
                                                    Nach Teilnahme
                                                </span>
                                            @else
                                                @if($hasEval)
                                                    <div class="text-xs font-mono text-[var(--ui-secondary)]">
                                                        {{ \Platform\Recruiting\Support\EvaluationValues::compactLine($evalValues) }}
                                                    </div>
                                                    <div class="flex items-center gap-1.5 mt-0.5 text-[10px] text-[var(--ui-muted)]">
                                                        @if(!empty($evalValues['linen_package_items']))
                                                            <span title="Wäschepaket erfasst">@svg('heroicon-o-check-badge', 'w-3 h-3 inline-block')</span>
                                                        @endif
                                                        @if(!empty($evalValues['qualifications']))
                                                            <span title="Qualifikation erfasst">@svg('heroicon-o-academic-cap', 'w-3 h-3 inline-block')</span>
                                                        @endif
                                                        @if(trim((string) ($evalValues['evaluation_note'] ?? '')) !== '')
                                                            <span title="Bewertungstext vorhanden">@svg('heroicon-o-chat-bubble-bottom-center-text', 'w-3 h-3 inline-block')</span>
                                                        @endif
                                                    </div>
                                                @endif
                                                <button
                                                    wire:click="openEvaluationModal({{ $booking->id }})"
                                                    class="mt-1 px-2.5 py-1 text-xs font-medium rounded-md border border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]"
                                                >
                                                    @svg('heroicon-o-star', 'w-3.5 h-3.5 inline-block -mt-0.5 mr-1')
                                                    {{ $hasEval ? 'Bewertung bearbeiten' : 'Bewerten' }}
                                                </button>
                                            @endif
                                        </td>
```

- [ ] **Step 2: Modal-Inhalt ersetzen**

`:528-594` (das komplette `x-ui-modal` für `showEvaluationModal`) ersetzen durch:

```blade
    {{-- Bewertungs-Modal: acht Felder in einem Vorgang (Spec §3) --}}
    <x-ui-modal wire:model="showEvaluationModal" size="lg">
        <x-slot name="header">Bewertung</x-slot>
        @php
            $evalBooking = $evaluateBookingId ? $this->bookings->firstWhere('id', $evaluateBookingId) : null;
            $evalName = \Platform\Recruiting\Support\ApplicantContactName::display(
                $this->contactCandidatesFor($evalBooking?->applicant),
            );
            $evalCriteria = \Platform\Recruiting\Support\RatingCriteria::CRITERIA;
        @endphp
        <div class="space-y-5">
            @if($evalBooking)
                <div class="text-sm">
                    <strong class="text-[var(--ui-secondary)]">{{ $evalName }}</strong>
                </div>

                {{-- Fünf Kriterien, je 1-5 Sterne --}}
                @foreach($evalCriteria as $critKey => $crit)
                    <div>
                        <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">
                            {{ $crit['label'] }}
                            @if($crit['help'] !== '')
                                <span class="ml-1 text-[var(--ui-muted)] cursor-help" title="{{ $crit['help'] }}">
                                    @svg('heroicon-o-information-circle', 'w-3.5 h-3.5 inline-block -mt-0.5')
                                </span>
                            @endif
                        </label>
                        <div class="flex gap-2">
                            @foreach(['1','2','3','4','5'] as $star)
                                <label class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 text-sm rounded-md border cursor-pointer {{ ($evaluation[$critKey] ?? null) === $star ? 'border-amber-500 bg-amber-50 text-amber-700' : 'border-[var(--ui-border)] hover:bg-[var(--ui-muted-5)]' }}">
                                    <input type="radio" wire:model.live="evaluation.{{ $critKey }}" value="{{ $star }}" class="sr-only">
                                    @svg('heroicon-m-star', 'w-4 h-4')
                                    {{ $star }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                {{-- Waeschepaket --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Wäschepaket erhalten</label>
                    <div class="border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm bg-white flex flex-wrap gap-x-4 gap-y-1.5">
                        @forelse($this->lookupOptionsFor('waeschepaket') as $optValue => $optLabel)
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox" wire:model="evaluation.linen_package_items" value="{{ $optValue }}" class="rounded border-[var(--ui-border)]">
                                <span>{{ $optLabel }}</span>
                            </label>
                        @empty
                            <span class="text-xs text-[var(--ui-muted)]">Keine Lookup-Werte konfiguriert.</span>
                        @endforelse
                    </div>
                </div>

                {{-- Qualifikation --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Qualifikation</label>
                    <div class="border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm bg-white flex flex-wrap gap-x-4 gap-y-1.5">
                        @forelse($this->lookupOptionsFor('qualifikation') as $optValue => $optLabel)
                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox" wire:model="evaluation.qualifications" value="{{ $optValue }}" class="rounded border-[var(--ui-border)]">
                                <span>{{ $optLabel }}</span>
                            </label>
                        @empty
                            <span class="text-xs text-[var(--ui-muted)]">Keine Lookup-Werte konfiguriert.</span>
                        @endforelse
                    </div>
                </div>

                {{-- Bewertungstext (NICHT die Buchungsnotiz, Spec F4) --}}
                <div>
                    <label class="block text-sm font-medium text-[var(--ui-secondary)] mb-2">Bewertungstext</label>
                    <textarea
                        wire:model="evaluation.evaluation_note"
                        rows="4"
                        placeholder="Individuelle Einschätzung zum Abschluss…"
                        class="w-full border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm"
                    ></textarea>
                </div>
            @else
                <div class="text-sm text-[var(--ui-muted)]">Buchung nicht gefunden.</div>
            @endif
        </div>
        <x-slot name="footer">
            <x-ui-button variant="secondary" wire:click="closeEvaluationModal">Abbrechen</x-ui-button>
            <x-ui-button variant="primary" wire:click="saveEvaluation">Speichern</x-ui-button>
        </x-slot>
    </x-ui-modal>
```

- [ ] **Step 3: Suite grün halten**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: PASS.

- [ ] **Step 4: Manuelle Sichtprüfung**

Terminseite → „Nach der Schulung". Erwartet:
1. Teilnehmer **nicht** auf „Teilgenommen": Spalte BEWERTUNG zeigt „Nach Teilnahme", kein Button.
2. Teilnehmer auf „Teilgenommen" setzen: Button „Bewerten" erscheint.
3. Modal zeigt fünf Kriterien mit je fünf Sternen, Wäschepaket, Qualifikation, Bewertungstext.
4. Speichern → Zeile zeigt die Zahlenreihe (`4·3·5·4·4`) und die Marker; Button heißt jetzt „Bewertung bearbeiten".
5. Modal erneut öffnen → alle Werte vorbelegt.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(recruiting): Bewertungs-Modal mit fuenf Kriterien und Anzeige in der Spalte BEWERTUNG"
```

---

### Task 10: Sortierung, Namensformat und Suchfeld

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (`bookings()` `:124-172`)
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Suchfeld `:76-96`, Name Übersicht `:135`, Name Nachbereitung `:274`)

**Interfaces:**
- Consumes: `ApplicantContactName` (Task 4), `contactCandidatesFor()` (Task 8).
- Produces: `bookingsSortedByName()` — die nach angezeigtem Namen sortierte Collection für den Nachbereitungs-Modus.

- [ ] **Step 1: Sortier-Methode in der Komponente ergänzen**

`contactCandidatesFor()` existiert bereits aus Task 8. Direkt nach `bookings()`
(`:172`) einfügen:

```php
    /**
     * Nachbereitungs-Modus: A-Z nach dem angezeigten Namen (Spec §3).
     *
     * Sortiert wird die geladene Collection, nicht per Join: die Liste
     * paginiert nicht (Spec F11), und ein Join ueber crm_contact_links wuerde
     * bei mehrfach verlinkten Bewerbern Zeilen vervielfachen.
     *
     * ACHTUNG-Kopplung: wird die Liste spaeter paginiert, sortiert das hier nur
     * die aktuelle Seite. Dann muss auf DB-Sortierung mit expliziter
     * Link-Priorisierung umgestellt werden.
     */
    public function bookingsSortedByName()
    {
        return $this->bookings
            ->sortBy(fn ($booking) => \Platform\Recruiting\Support\ApplicantContactName::sortKey(
                $this->contactCandidatesFor($booking->applicant),
            ), SORT_STRING)
            ->values();
    }
```

- [ ] **Step 2: Blade — Suchfeld auch in der Nachbereitung**

`:76-96` ersetzen: Der Status-Filter bleibt nur im Buchungs-Modus, das Suchfeld gilt für beide.

```blade
                    <div class="flex gap-2 mb-4">
                        @if($mode === 'overview')
                            <x-ui-input-select
                                name="filterStatus"
                                wire:model.live="filterStatus"
                                :options="[
                                    ['value' => 'all', 'label' => 'Alle Status'],
                                    ['value' => 'booked', 'label' => 'Gebucht'],
                                    ['value' => 'registered', 'label' => 'Registriert'],
                                    ['value' => 'confirmed', 'label' => 'Bestätigt'],
                                    ['value' => 'attended', 'label' => 'Teilgenommen'],
                                    ['value' => 'cancelled', 'label' => 'Abgesagt'],
                                    ['value' => 'rebooked', 'label' => 'Umgebucht'],
                                    ['value' => 'no_show', 'label' => 'Nicht erschienen'],
                                ]"
                                optionValue="value"
                                optionLabel="label"
                            />
                        @endif
                        <x-ui-input-text name="search" placeholder="Suchen…" wire:model.live.debounce.300ms="search" class="flex-1 max-w-xs" />
                    </div>
```

- [ ] **Step 3: Blade — Namensformat in der Übersicht**

`:135` ersetzen:

```blade
                                                    {{ \Platform\Recruiting\Support\ApplicantContactName::display($this->contactCandidatesFor($booking->applicant)) }}
```

- [ ] **Step 4: Blade — Namensformat und Sortierung in der Nachbereitung**

In `:211-215` die Zeile mit `$relevantBookings` ersetzen durch:

```blade
                        $relevantBookings = $this->bookingsSortedByName()->whereNotIn('status', ['cancelled'])->values();
```

Und `:274` ersetzen:

```blade
                                                    {{ \Platform\Recruiting\Support\ApplicantContactName::display($this->contactCandidatesFor($applicant)) }}
```

- [ ] **Step 5: Syntax und Suite**

```bash
php -l src/Livewire/InterviewBookings/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`, Suite PASS.

- [ ] **Step 6: Manuelle Sichtprüfung**

1. Übersicht: Namen als „Nachname, Vorname", Reihenfolge weiter nach Buchungsdatum.
2. Nachbereitung: Namen im gleichen Format, Reihenfolge alphabetisch A–Z.
3. Suchfeld in **beiden** Modi vorhanden; Suche nach Nachname und nach Vorname findet den Teilnehmer.

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php resources/views/livewire/interview-bookings/index.blade.php
git commit -m "feat(recruiting): Namensformat in beiden Modi, A-Z-Sortierung und Suchfeld in der Nachbereitung"
```

---

### Task 11: Selfie-Spalte in beiden Ansichten

**Files:**
- Modify: `src/Livewire/InterviewBookings/Index.php` (neue `#[Computed]`-Property)
- Modify: `resources/views/livewire/interview-bookings/index.blade.php` (Kopfzeilen + Zellen beider Tabellen)

**Interfaces:**
- Consumes: nichts aus früheren Tasks.
- Produces: `$this->selfies` — `array<int, array{url: string, is_image: bool}>`, keyed by `applicant_id`.

**Kontext:** Extra-Feld ist `selfie_upload` (F13). Wert ist eine File-ID oder ein JSON-Array von File-IDs. `bookings()` ist **eine** Query für beide Modi (F12) — die Auflösung gilt damit automatisch für beide Tabellen.

- [ ] **Step 1: Batch-Auflösung als Computed ergänzen**

Nach `contactCandidatesFor()` (Task 10) einfügen:

```php
    /**
     * Selfies aller sichtbaren Bewerber in VIER Queries (Spec §3a) — pro Zeile
     * waeren es drei, bei 25 Teilnehmern also 75 zusaetzliche Abfragen.
     *
     * Extra-Feld ist 'selfie_upload'; der Wert ist eine File-ID oder ein
     * JSON-Array von File-IDs. Angezeigt wird die Thumbnail-Variante, sonst das
     * Original. Die signierten URLs laufen nach 60 Minuten ab — die Blade hat
     * deshalb einen onerror-Fallback auf den Platzhalter.
     *
     * @return array<int, array{url: string, is_image: bool}>
     */
    #[Computed]
    public function selfies(): array
    {
        $applicantIds = $this->bookings->pluck('applicant.id')->filter()->unique()->values();
        if ($applicantIds->isEmpty()) {
            return [];
        }

        // 1) Definitions-IDs des Feldes 'selfie_upload'
        $definitionIds = DB::table('core_extra_field_definitions')
            ->where('name', 'selfie_upload')
            ->pluck('id');
        if ($definitionIds->isEmpty()) {
            return [];
        }

        // 2) Feldwerte je Bewerber. Spalten- und Morph-Namen wie im bewaehrten
        //    Pfad ZasFieldResolver::preloadExtraFields() (:447-451):
        //    fieldable_type = 'rec_applicant', fieldable_id, definition_id, value.
        //    Der Unique-Index (definition_id, fieldable_type, fieldable_id)
        //    garantiert einen Wert pro Bewerber und Definition.
        $rawValues = DB::table('core_extra_field_values')
            ->whereIn('definition_id', $definitionIds)
            ->whereIn('fieldable_id', $applicantIds)
            ->where('fieldable_type', 'rec_applicant')
            ->pluck('value', 'fieldable_id');

        $fileIdByApplicant = [];
        foreach ($rawValues as $applicantId => $raw) {
            $fileId = $this->firstFileIdFromRawValue($raw);
            if ($fileId !== null) {
                $fileIdByApplicant[(int) $applicantId] = $fileId;
            }
        }
        if ($fileIdByApplicant === []) {
            return [];
        }

        // 3) ContextFiles
        $files = \Platform\Core\Models\ContextFile::whereIn('id', array_values($fileIdByApplicant))
            ->get()
            ->keyBy('id');

        // 4) Thumbnail-Varianten
        $variants = \Platform\Core\Models\ContextFileVariant::whereIn('context_file_id', array_values($fileIdByApplicant))
            ->where('variant_type', 'like', 'thumbnail_%')
            ->get()
            ->keyBy('context_file_id');

        $result = [];
        foreach ($fileIdByApplicant as $applicantId => $fileId) {
            $file = $files->get($fileId);
            if (!$file || !$file->isImage()) {
                continue;
            }
            $variant = $variants->get($fileId);
            $result[$applicantId] = [
                'url'      => $variant?->url ?? $file->url,
                'is_image' => true,
            ];
        }

        return $result;
    }

    /**
     * Extra-Field-Werte koennen skalar (eine File-ID) oder JSON-Array
     * (Multi-File) sein — die erste ID gewinnt.
     */
    private function firstFileIdFromRawValue($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $candidate) {
                    if ((int) $candidate > 0) {
                        return (int) $candidate;
                    }
                }
            }
            return null;
        }

        return (int) $raw > 0 ? (int) $raw : null;
    }
```

- [ ] **Step 2: Blade — Selfie-Spalte im Buchungs-Modus**

Im Kopf der Übersichts-Tabelle nach `<th class="px-4 py-3">Kandidat</th>` (`:120`) einfügen:

```blade
                                    <th class="px-4 py-3">Foto</th>
```

Und in der Zeile direkt nach der Kandidaten-Zelle die neue Zelle einfügen:

```blade
                                        <td class="px-4 py-3">
                                            @include('recruiting::livewire.interview-bookings.partials.selfie', ['applicantId' => $booking->applicant?->id])
                                        </td>
```

- [ ] **Step 3: Blade — Selfie-Spalte im Nachbereitungs-Modus**

Im Kopf der Nachbereitungs-Tabelle nach `<th class="px-4 py-3">Bewerber</th>` (`:221`) einfügen:

```blade
                                    <th class="px-4 py-3">Foto</th>
```

Und in der Zeile direkt nach der Bewerber-Zelle:

```blade
                                        <td class="px-4 py-3">
                                            @include('recruiting::livewire.interview-bookings.partials.selfie', ['applicantId' => $applicant?->id])
                                        </td>
```

**Wichtig:** Beide Tabellen haben einen `colspan` in ihrer Leer-Zeile (`:200` im Buchungs-Modus, `colspan="7"`). Diesen jeweils **um 1 erhöhen**, sonst bricht die Leer-Darstellung.

- [ ] **Step 4: Partial anlegen**

`resources/views/livewire/interview-bookings/partials/selfie.blade.php`:

```blade
@php
    $selfie = $applicantId ? ($this->selfies[$applicantId] ?? null) : null;
@endphp

@if($selfie)
    <a href="{{ $selfie['url'] }}" target="_blank" rel="noopener" title="Foto vergrößern">
        <img
            src="{{ $selfie['url'] }}"
            alt="Foto"
            class="w-9 h-9 rounded-full object-cover border border-[var(--ui-border)]"
            {{-- Signierte URLs laufen nach 60 Min ab; ohne Fallback zeigt der
                 Browser ein kaputtes Bild-Icon ohne erkennbaren Grund. --}}
            onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('span'),{className:'inline-flex items-center justify-center w-9 h-9 rounded-full border border-[var(--ui-border)] text-[10px] text-[var(--ui-muted)]',textContent:'—'}));"
        >
    </a>
@else
    <span class="inline-flex items-center justify-center w-9 h-9 rounded-full border border-[var(--ui-border)] text-[10px] text-[var(--ui-muted)]" title="Kein Foto vorhanden">—</span>
@endif
```

- [ ] **Step 5: Syntax und Suite**

```bash
php -l src/Livewire/InterviewBookings/Index.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`, Suite PASS.

- [ ] **Step 6: Manuelle Sichtprüfung**

1. Beide Modi zeigen eine Spalte „Foto".
2. Bewerber mit Selfie: rundes Thumbnail; Klick öffnet das Bild groß.
3. Bewerber ohne Selfie: Platzhalter „—", kein kaputtes Bild.
4. **Netzwerk-Tab:** die Anzahl der Requests wächst nicht mit der Zeilenzahl. Alternativ mit Laravel Debugbar prüfen, dass die Query-Zahl beim Wechsel von 2 auf 20 Teilnehmer **nicht** um 3 pro Zeile steigt.

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/InterviewBookings/Index.php \
        resources/views/livewire/interview-bookings/index.blade.php \
        resources/views/livewire/interview-bookings/partials/selfie.blade.php
git commit -m "feat(recruiting): Selfie-Spalte in beiden Termin-Ansichten mit Batch-Aufloesung"
```

---

### Task 12: Mitarbeiterkarte — fünf Ratings, Freitext, Altbestand read-only

**Files:**
- Modify: `src/Livewire/Employees/Show.php` (`hrFieldGroups()` `:252-270`)

**Interfaces:**
- Consumes: nichts aus früheren Tasks (die Feldnamen sind Konstanten der Migration).
- Produces: nichts.

**Kontext:** Der Blade-Renderer (`employees/show.blade.php:195-258`) kennt die Typen `readonly`, `lookup`, `date`, `inline_select`, `multi_lookup` und Text-Fallback. `:204-205` setzt auf leeren Feldern einen **roten Rand** — deshalb muss `star_rating` `readonly` werden, sonst ist es bei jedem neuen Mitarbeiter dauerhaft rot markiert (F17).

- [ ] **Step 1: `hrFieldGroups()` erweitern**

Die Gruppe `'Bewertung & Qualifikation'` (`:265-268`) ersetzen durch:

```php
            'Bewertung (Termin)' => [
                'rating_erscheinungsbild' => ['type' => 'inline_select', 'label' => 'Erscheinungsbild & Hygiene', 'options' => ['1','2','3','4','5']],
                'rating_fachkompetenz'    => ['type' => 'inline_select', 'label' => 'Fachliche Grundkompetenz', 'options' => ['1','2','3','4','5']],
                'rating_auffassungsgabe'  => ['type' => 'inline_select', 'label' => 'Auffassungsgabe & Lernbereitschaft', 'options' => ['1','2','3','4','5']],
                'rating_auftreten'        => ['type' => 'inline_select', 'label' => 'Auftreten & Kommunikation', 'options' => ['1','2','3','4','5']],
                'rating_teamintegration'  => ['type' => 'inline_select', 'label' => 'Teamintegration & Verhalten', 'options' => ['1','2','3','4','5']],
                'evaluation_note'         => ['type' => 'text', 'label' => 'Bewertungstext'],
            ],
            'Qualifikation & Altbestand' => [
                'qualifications' => ['type' => 'multi_lookup', 'label' => 'Qualifikation', 'lookup' => 'qualifikation'],
                // star_rating wird nicht mehr geschrieben (Spec §1). readonly,
                // weil der Blade auf leeren Feldern einen roten "fehlt"-Rand
                // setzt (:204-205) — sonst waere das Feld bei jedem neuen
                // Mitarbeiter dauerhaft rot markiert.
                'star_rating'    => ['type' => 'inline_select', 'label' => 'Sternebewertung (Altbestand)', 'options' => ['1','2','3','4','5'], 'readonly' => true],
            ],
```

- [ ] **Step 2: Syntax und Suite**

```bash
php -l src/Livewire/Employees/Show.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`, Suite PASS.

- [ ] **Step 3: Manuelle Sichtprüfung**

Mitarbeiterkarte eines Mitarbeiters, dessen Bewerber bewertet war. Erwartet:
1. Gruppe „Bewertung (Termin)" mit fünf Dropdowns 1–5 und dem Bewertungstext, alle mit den übernommenen Werten gefüllt.
2. „Sternebewertung (Altbestand)" wird als Text angezeigt, **nicht** als Dropdown, und **ohne** roten Rand — auch wenn leer.
3. Einen Rating-Wert ändern und speichern → Wert bleibt nach Reload erhalten.

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Employees/Show.php
git commit -m "feat(recruiting): Mitarbeiterkarte zeigt fuenf Ratings und Bewertungstext, star_rating als Altbestand"
```

---

### Task 13: ZAS-Export — fünf Spalten und Update-Marker

**Files:**
- Modify: `src/Services/Zas/ZasEmployeeFieldResolver.php` (`COLUMNS` `:37-100`, `resolveColumn()` ab `:147`)
- Modify: `src/Observers/RecEmployeeExportObserver.php` (`RELEVANT_HR_FIELDS` `:100-104`)
- Test: `tests/Unit/ZasRatingExportTest.php`

**Interfaces:**
- Consumes: `RatingCriteria::zasColumns()` (Task 1).
- Produces: nichts.

- [ ] **Step 1: Write the failing test**

`tests/Unit/ZasRatingExportTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Support\RatingCriteria;

class ZasRatingExportTest extends TestCase
{
    /** Testbare Resolver-Variante: leerer Konstruktor, resolveColumn public. */
    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}
            public function col(RecEmployee $e, $hr, string $column): ?string
            {
                return $this->resolveColumn($e, $hr, $column);
            }
        };
    }

    /** setRawAttributes statt fill(): der Schreib-Cast braeuchte eine DB-Connection. */
    private function hrData(array $attributes): RecEmployeeHrData
    {
        $hr = new RecEmployeeHrData();
        $hr->setRawAttributes($attributes);
        return $hr;
    }

    public function test_die_fuenf_bewertungsspalten_stehen_am_ende(): void
    {
        // Konvention im Modul: neue Spalten immer ans Ende, nie dazwischen —
        // der ZAS-Importer liest positional (Spec F6).
        $this->assertSame(
            array_values(RatingCriteria::zasColumns()),
            array_slice(ZasEmployeeFieldResolver::COLUMNS, -5),
        );
    }

    public function test_spaltennamen_sind_eindeutig(): void
    {
        $columns = ZasEmployeeFieldResolver::COLUMNS;
        $this->assertSame($columns, array_unique($columns), 'ZAS-Spaltennamen muessen eindeutig sein.');
    }

    public function test_bestandsspalten_bleiben_unveraendert_vorhanden(): void
    {
        // Rueckwaertskompatibilitaet fuer Hr. Michel: die drei alten Spalten
        // duerfen nicht verschwinden oder umbenannt werden.
        foreach (['Sternebewertung', 'Waeschepaket', 'Qualifikation'] as $column) {
            $this->assertContains($column, ZasEmployeeFieldResolver::COLUMNS);
        }
    }

    public function test_freitext_wird_nicht_exportiert(): void
    {
        // Spec §5: nicht wegen eines Schema-Risikos (ZasCsvBuilder::sanitize
        // bereinigt), sondern weil der Text verstuemmelt ankaeme und ZAS ihn
        // nicht nutzt.
        foreach (ZasEmployeeFieldResolver::COLUMNS as $column) {
            $this->assertStringNotContainsStringIgnoringCase('bewertungstext', $column);
            $this->assertStringNotContainsStringIgnoringCase('note', $column);
        }
    }

    public function test_bewertungswerte_werden_aus_hr_data_gelesen(): void
    {
        $r = $this->resolver();
        $employee = new RecEmployee();
        $hr = $this->hrData([
            'rating_erscheinungsbild' => 4,
            'rating_fachkompetenz'    => 3,
            'rating_auffassungsgabe'  => 5,
            'rating_auftreten'        => 1,
            'rating_teamintegration'  => 2,
        ]);

        $this->assertSame('4', $r->col($employee, $hr, 'BewertungErscheinungsbild'));
        $this->assertSame('3', $r->col($employee, $hr, 'BewertungFachkompetenz'));
        $this->assertSame('5', $r->col($employee, $hr, 'BewertungAuffassungsgabe'));
        $this->assertSame('1', $r->col($employee, $hr, 'BewertungAuftreten'));
        $this->assertSame('2', $r->col($employee, $hr, 'BewertungTeamintegration'));
    }

    public function test_fehlende_bewertung_ist_null_nicht_null_string(): void
    {
        $r = $this->resolver();
        $employee = new RecEmployee();

        $this->assertNull($r->col($employee, $this->hrData([]), 'BewertungAuftreten'));
        $this->assertNull($r->col($employee, null, 'BewertungAuftreten'));
    }

    public function test_update_marker_kennt_die_fuenf_rating_felder(): void
    {
        // Ohne Eintrag in RELEVANT_HR_FIELDS erreicht eine HR-Korrektur den
        // ZAS-Update-Export nie (Spec F9).
        foreach (RatingCriteria::columns() as $column) {
            $this->assertContains(
                $column,
                RecEmployeeExportObserver::RELEVANT_HR_FIELDS,
                "{$column} fehlt in RELEVANT_HR_FIELDS.",
            );
        }
    }

    public function test_freitext_loest_keinen_re_export_aus(): void
    {
        // evaluation_note wird nicht exportiert — es darf deshalb auch keinen
        // Update-Marker setzen, sonst re-exportiert eine reine Notiz-Aenderung
        // ohne Inhaltsaenderung.
        $this->assertNotContains('evaluation_note', RecEmployeeExportObserver::RELEVANT_HR_FIELDS);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasRatingExportTest
```

Expected: FAIL — die fünf Spalten stehen nicht am Ende von `COLUMNS`.

- [ ] **Step 3: `COLUMNS` erweitern**

In `src/Services/Zas/ZasEmployeeFieldResolver.php` am **Ende** des `COLUMNS`-Arrays (nach dem letzten Eintrag, vor der schließenden `];`) einfügen:

```php
        // Bewertung (ans Ende, nie dazwischen) — fuenf Kriterien à 1-5 Sterne
        // aus rec_employee_hr_data. Der Bewertungstext wird bewusst NICHT
        // exportiert (Spec §5).
        'BewertungErscheinungsbild',
        'BewertungFachkompetenz',
        'BewertungAuffassungsgabe',
        'BewertungAuftreten',
        'BewertungTeamintegration',
```

- [ ] **Step 4: `resolveColumn()` erweitern**

Im `match ($column)`-Block, direkt nach der Zeile `'Sternebewertung' => …` (`:228`), einfügen:

```php
            'BewertungErscheinungsbild' => $hr?->rating_erscheinungsbild !== null ? (string) $hr->rating_erscheinungsbild : null,
            'BewertungFachkompetenz'    => $hr?->rating_fachkompetenz !== null ? (string) $hr->rating_fachkompetenz : null,
            'BewertungAuffassungsgabe'  => $hr?->rating_auffassungsgabe !== null ? (string) $hr->rating_auffassungsgabe : null,
            'BewertungAuftreten'        => $hr?->rating_auftreten !== null ? (string) $hr->rating_auftreten : null,
            'BewertungTeamintegration'  => $hr?->rating_teamintegration !== null ? (string) $hr->rating_teamintegration : null,
```

- [ ] **Step 5: `RELEVANT_HR_FIELDS` erweitern**

In `src/Observers/RecEmployeeExportObserver.php` das Array `:100-104` ersetzen durch:

```php
    public const RELEVANT_HR_FIELDS = [
        'contract_signed_at', 'contract_sent_date', 'contract_end_date',
        'export_status', 'employment_classification',
        'linen_package_items', 'star_rating', 'qualifications',
        // Bewertung (Spec §5): loest den Update-Marker aus, damit HR-Korrekturen
        // ZAS erreichen. evaluation_note fehlt hier ABSICHTLICH — es wird nicht
        // exportiert, ein Marker waere ein Re-Export ohne Inhaltsaenderung.
        'rating_erscheinungsbild', 'rating_fachkompetenz', 'rating_auffassungsgabe',
        'rating_auftreten', 'rating_teamintegration',
    ];
```

- [ ] **Step 6: Run test to verify it passes**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasRatingExportTest
```

Expected: PASS (8 tests).

- [ ] **Step 7: Volle Suite grün**

```bash
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Services/Zas/ZasEmployeeFieldResolver.php \
        src/Observers/RecEmployeeExportObserver.php \
        tests/Unit/ZasRatingExportTest.php
git commit -m "feat(recruiting): fuenf Bewertungsspalten im ZAS-Export plus Update-Marker"
```

---

## Deploy (nach Abschluss aller Tasks)

- [ ] **Zwei-Push-Struktur:** Erst die beiden Migrationen pushen und deployen, dann den Rest. Blade und Mitarbeiterkarte lesen die neuen Spalten; ein Feature-Deploy vor der Migration wirft in beiden Ansichten.
- [ ] **`composer.lock`-Bump in `meingedeck`** nach jedem Push — sonst ist der Stand nicht live.
- [ ] **`php artisan queue:restart`** auf Forge. Zwei Queue-Jobs laden `RecApplicant` — `MatchApplicantToPostingJob` (`ShouldQueue` `:20`, `RecApplicant::find` `:38`) und `NotifyWaitlistForInterview` (`ShouldQueue` `:24`, `afterCommit` `:56`) — und dieses Paket hängt dem Modell acht Spalten mit Casts an (Spec F19). Long-running Worker halten sonst die alte Klassendefinition.
- [ ] **Vor dem Deploy:** die fünf ZAS-Spaltennamen von Hr. Michel bestätigen lassen. Der Export bleibt ohne seine Änderung funktionsfähig — er sieht die neuen Spalten erst, wenn er sie aufnimmt.
- [ ] **Nach dem Deploy:** Live-Sichttest nach Spec §„Tests & Verifikation", Schritte 1–7.

## Bewusst NICHT in diesem Plan

**Spec §3b (`hasAnyContractSent` als Batch)** ist kein Task dieses Plans. Die Spec
markiert ihn ausdrücklich als optional und für das Feature nicht erforderlich: mit
dem Modal fällt ein Render pro Bewerber an, nicht pro Sternklick. Der Task berührt
`bulkSendState`, das den **Vertragsversand** steuert — ein Fehler dort trifft einen
schwerer wiegenden Pfad als die Bewertung. Er gehört in einen eigenen Plan mit
eigener Verifikation (identische `bulkSendState`-Ergebnisse für „keiner versendet",
„gemischt", „alle versendet").

## Offene Lücken (blockieren die Umsetzung nicht)

- **Handout-Texte pro Kriterium:** `RatingCriteria::CRITERIA[*]['help']` ist mit `''` vorbelegt; das Popover wird dann ausgeblendet (Task 9, `@if($crit['help'] !== '')`). Sobald die Texte vorliegen: nur die Konstante füllen, kein Code-Umbau.
- **Handout-PDF:** noch nicht im Repo. Der Link im Popover wird nachgezogen, wenn die Datei da ist.
