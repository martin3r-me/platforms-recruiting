# KPI-Dashboard V2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Statistik-Seite auf die vom Kunden gewünschte Anordnung umbauen — Zeilen sind Ausschreibungen, mit Bedarf, Erfüllungsquote und zwei Ampeln, darunter eine Tabelle je Schulungstermin.

**Architecture:** Der bestehende Rechenkern bleibt: `CohortAssigner` (Zuordnung, Rekonziliations-Invariante) und `CohortViewModel` (Anzeige) sind pure Klassen ohne Framework und werden erweitert, nicht ersetzt. Neu dazu kommt `TargetLight` — die komplette Ampel-Logik als pure Klasse, damit die Schwellen- und Hochrechnungs-Mathematik testbar ist. Datumsrechnung wandert in `YmdDate`, damit Assigner und Ampel dieselbe Wahrheit benutzen.

**Tech Stack:** Laravel 11 (Modul platforms-recruiting), Livewire 3, MySQL 8, PHPUnit 11 (pure Unit-Suite ohne Laravel/DB + Integration-Suite mit SQLite-Capsule).

**Spec:** `docs/superpowers/specs/2026-08-06-kpi-dashboard-v2-design.md`

## Global Constraints

- **Testrunner:** `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` aus dem Modul-Root. `--testsuite Unit` für die pure Suite. Basis vor Beginn: **665 Tests grün**.
- **Pure Klassen** (`src/Support/`, `src/Services/Statistics/`) dürfen **keine** `Illuminate\*`- oder `Carbon`-Imports haben — der Test-Bootstrap lädt kein Laravel. Keine Uhr in pure Klassen: `today` reist als `Y-m-d`-String hinein.
- **Rekonziliations-Invariante:** Jede Bewerbung steckt in genau einer Zeile, Σ Zeilen = Gesamtmenge. Tests dürfen dafür nie gelockert werden.
- **Einziger stiller Filter:** `is_test`. Alles andere wird als Zeile oder Block sichtbar.
- **Keine geratenen Werte:** Fehlt Bedarf oder Faktor, gibt es keine Ampel (grau), nie eine erfundene.
- **Blade-Fallen** (Projekt-Erfahrung): kein inline `@php(...)`, nur Block-Form; `x-ui-input-date` nie per `wire:model` an ein datetime-Cast binden (Y-m-d-String-Property nutzen); keine `:required`-`??`-Fallbacks an `x-ui-*`; `x-ui-page-container` immer mit `width="full"`.
- **Blade-Prüfung:** `php -l` prüft `.blade.php` NICHT. Kompilieren mit dem echten BladeCompiler und das Kompilat prüfen (Skript-Vorlage in Task 8).
- Jede Eloquent-Query auf Team-Daten braucht `forTeam()`/`where('team_id', …)`.
- Commits: Prefix `feat(recruiting):` / `fix(recruiting):` / `test(recruiting):`, Umlaute in Messages als ae/oe/ue.

## Entscheidungen, die die Spec ergänzen

Beim Durchdenken der Zeilen aufgekommen, hier verbindlich festgelegt:

1. **Der Trichter rechnet netto.** Die Zeile einer Ausschreibung zählt nur Bewerbungen, die noch im Rennen sind. Geparkte, Abgesagte, Dubletten, nicht Zugeordnete und Bewerbungen ohne Datum stehen in einem eigenen Block darunter — für die KPIs nicht direkt relevant, aber sichtbar, damit die Differenz zur Gesamtmenge benannt ist (User-Entscheidung 2026-08-17). Damit bleibt es eine echte Partition wie in V1.
2. **Stufe 1 wird eigenständig deployt.** Felder und Formulare gehen live, bevor die Seite umgebaut ist — HR kann Bedarf, Faktor und Laufzeitende pflegen, während Stufe 2 entsteht. Das ist keine Kosmetik: ohne gepflegte Werte kann Stufe 2 nicht sinnvoll abgenommen werden.

## Dateien im Überblick

**Stufe 1 — Datenbasis**
- Create: `database/migrations/2026_08_17_000001_add_bedarf_and_faktor_to_rec_postings.php`
- Create: `database/migrations/2026_08_17_000002_add_rec_posting_id_to_rec_interviews.php`
- Create: `src/Support/YmdDate.php` — Datumsrechnung für pure Klassen
- Create: `src/Services/Statistics/TargetLight.php` — Ampel-Logik
- Modify: `src/Models/RecPosting.php`, `src/Models/RecInterview.php` — fillable, casts, Relation
- Modify: `src/Livewire/Posting/Show.php` + `resources/views/livewire/posting/show.blade.php`
- Modify: `src/Livewire/InterviewSchedule/Index.php` + `resources/views/livewire/interview-schedule/index.blade.php`
- Modify: `src/Services/Statistics/CohortViewModel.php` — Datums-Helfer auf `YmdDate` umstellen

**Stufe 2 — Die Seite**
- Modify: `src/Services/Statistics/CohortAssigner.php` — Zeilen nach Ausschreibung, „Phase N erreicht", netto-Trichter
- Modify: `src/Services/Statistics/CohortViewModel.php` — Sortierung nach Ausschreibung, Gesamt-Arithmetik
- Modify: `src/Livewire/Statistics/Index.php` — Filter, Queries, Termin-Daten
- Create: `resources/views/livewire/statistics/postings-table.blade.php` — Tabelle 1
- Create: `resources/views/livewire/statistics/interviews-table.blade.php` — Tabelle 2
- Create: `resources/views/livewire/statistics/light.blade.php` — Ampel-Darstellung
- Modify: `resources/views/livewire/statistics/index.blade.php` — setzt die Teile zusammen
- Reuse unverändert: `cells.blade.php`, `conversion.blade.php`, `markers.blade.php`, `meter.blade.php`

---

# Stufe 1 — Datenbasis

### Task 1: Messung des Stellen-Wechsel-Effekts

Keine Codeänderung. Liefert eine Zahl und eine Entscheidung: Beim Buchen einer Schulung an einer **anderen** Stelle hängt `switchToPosition()` den Bewerber auf die *erste aktive* Ausschreibung der Zielstelle um, nicht auf eine passende (`src/Models/RecApplicant.php`, Suche nach `postings()->where('is_active', true)->first()`). In V2 ist die Ausschreibung die **Zeile** — betroffene Bewerber landen in einer Zeile, auf die sie sich nie beworben haben, und zählen gegen einen fremden Bedarf.

**Files:** keine

- [ ] **Step 1: Häufigkeit messen**

Auf dem Server (read-only):

```sql
SELECT COUNT(*) AS wechsel_gesamt,
       COUNT(DISTINCT l.rec_applicant_id) AS betroffene_bewerber,
       MIN(l.created_at) AS erster,
       MAX(l.created_at) AS letzter
FROM rec_auto_pilot_logs l
JOIN rec_applicants a ON a.id = l.rec_applicant_id
WHERE a.team_id = 3 AND l.type = 'position_switched';
```

- [ ] **Step 2: Entscheiden und festhalten**

- Unter ~5 Fällen insgesamt: nichts tun. Stattdessen in Task 8 an der betroffenen Zeile den bestehenden „Zuordnung unklar"-Marker nutzen (existiert schon als `uneindeutig_ids`).
- Mehr als das: den notierten Mini-Fix (`project_switch_position_posting_bug`: Log + `matched_via` setzen, alte Verknüpfung NICHT behalten) als Task 1b vor Stufe 2 einschieben.

Ergebnis (Zahl + Entscheidung) als Kommentar in `docs/superpowers/plans/2026-08-17-kpi-dashboard-v2.md` unter diesen Task schreiben und committen:

```bash
git add docs/superpowers/plans/2026-08-17-kpi-dashboard-v2.md
git commit -m "docs(recruiting): Messung Stellen-Wechsel-Effekt — Ergebnis und Entscheidung"
```

---

### Task 2: Migrationen + Model-Felder

**Files:**
- Create: `database/migrations/2026_08_17_000001_add_bedarf_and_faktor_to_rec_postings.php`
- Create: `database/migrations/2026_08_17_000002_add_rec_posting_id_to_rec_interviews.php`
- Modify: `src/Models/RecPosting.php` (fillable + casts)
- Modify: `src/Models/RecInterview.php` (fillable + `posting()`-Relation)

**Interfaces:**
- Produces: `rec_postings.bedarf` (`?int`), `rec_postings.bewerbungs_faktor` (`?float`), `rec_interviews.rec_posting_id` (`?int`) und `RecInterview::posting()`. Tasks 4, 5, 6, 8, 9 lesen diese Felder.

- [ ] **Step 1: Migration für die Ausschreibungs-Felder**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_postings', function (Blueprint $table) {
            // Personalziel dieser Ausschreibung. NULL = nicht gepflegt → keine
            // Erfuellungs-Ampel (Spec: nichts wird geraten).
            $table->unsignedInteger('bedarf')->nullable()->after('activity');
            // Bewerbungen pro Einstellung. Freie Zahl, KEIN Enum 1-5: der
            // gemessene Wert liegt bei 7-10 und damit ausserhalb.
            $table->decimal('bewerbungs_faktor', 4, 1)->nullable()->after('bedarf');
        });
    }

    public function down(): void
    {
        Schema::table('rec_postings', function (Blueprint $table) {
            $table->dropColumn(['bedarf', 'bewerbungs_faktor']);
        });
    }
};
```

- [ ] **Step 2: Migration für den Ausschreibungs-Bezug am Termin**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interviews', function (Blueprint $table) {
            // Fuer welche Ausschreibung diese Schulung stattfindet. Nullable,
            // damit Bestandstermine weiterlaufen — der Titel bleibt Rueckfall.
            // nullOnDelete: eine geloeschte Ausschreibung darf den Termin nicht
            // mitnehmen.
            $table->foreignId('rec_posting_id')->nullable()->after('rec_position_id')
                ->constrained('rec_postings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_interviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rec_posting_id');
        });
    }
};
```

- [ ] **Step 3: Model-Felder ergänzen**

In `src/Models/RecPosting.php` `'bedarf'` und `'bewerbungs_faktor'` ins `$fillable` aufnehmen und in `$casts` ergänzen:

```php
'bedarf' => 'integer',
'bewerbungs_faktor' => 'float',
```

In `src/Models/RecInterview.php` `'rec_posting_id'` ins `$fillable` und die Relation ergänzen:

```php
public function posting(): BelongsTo
{
    return $this->belongsTo(RecPosting::class, 'rec_posting_id');
}
```

(`BelongsTo` ist dort bereits importiert — mit `grep -n "use Illuminate" src/Models/RecInterview.php` prüfen und nur bei Bedarf ergänzen.)

- [ ] **Step 4: Syntax prüfen und committen**

Run: `php -l database/migrations/2026_08_17_000001_add_bedarf_and_faktor_to_rec_postings.php && php -l database/migrations/2026_08_17_000002_add_rec_posting_id_to_rec_interviews.php && php -l src/Models/RecPosting.php && php -l src/Models/RecInterview.php`
Expected: viermal `No syntax errors detected`

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: 665 Tests grün (die Integration-Suite baut ihr Schema aus Migrationsdateien — falls sie `rec_postings` oder `rec_interviews` anlegt, müssen die neuen Migrationen dort in der Liste ergänzt werden; die Fehlermeldung nennt die Datei).

```bash
git add database/migrations src/Models/RecPosting.php src/Models/RecInterview.php
git commit -m "feat(recruiting): Bedarf und Faktor an der Ausschreibung, Ausschreibungs-Bezug am Termin"
```

---

### Task 3: `YmdDate` — Datumsrechnung für pure Klassen

`CohortViewModel` hat private Datums-Helfer mit einem Rollover-Guard (`2026-02-30` würde sonst still auf den 2. März rutschen). `TargetLight` braucht dieselbe Rechnung. Eine Kopie wäre eine zweite Wahrheit — deshalb erst extrahieren, mit den bestehenden Tests als Netz.

**Files:**
- Create: `src/Support/YmdDate.php`
- Modify: `src/Services/Statistics/CohortViewModel.php` (private Helfer entfernen, `YmdDate` nutzen)
- Test: `tests/Unit/Statistics/YmdDateTest.php`

**Interfaces:**
- Produces: `YmdDate::isValid(string $ymd): bool`, `YmdDate::daysBetween(string $fromYmd, string $toYmd): ?int` (null = unlesbar, negativ möglich). Task 4 (`TargetLight`) und `CohortViewModel` konsumieren beides.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\YmdDate;

/**
 * Datumsrechnung fuer pure Klassen — ohne Carbon, weil der Test-Bootstrap
 * kein Laravel laedt.
 */
final class YmdDateTest extends TestCase
{
    public function test_gueltige_daten_werden_erkannt(): void
    {
        $this->assertTrue(YmdDate::isValid('2026-08-17'));
        $this->assertTrue(YmdDate::isValid('2026-02-28'));
    }

    public function test_rollover_daten_gelten_als_ungueltig(): void
    {
        // createFromFormat rollt '2026-02-30' still auf den 2. Maerz — ohne
        // Round-Trip-Pruefung waere das ein plausibel aussehender Fehlwert.
        $this->assertFalse(YmdDate::isValid('2026-02-30'));
        $this->assertFalse(YmdDate::isValid('2026-13-01'));
        $this->assertFalse(YmdDate::isValid('2026-8-4'), 'unpadded ist nicht Y-m-d');
        $this->assertFalse(YmdDate::isValid(''));
        $this->assertFalse(YmdDate::isValid('2026-08-17 10:00:00'));
    }

    public function test_tage_zwischen_zwei_daten(): void
    {
        $this->assertSame(0, YmdDate::daysBetween('2026-08-17', '2026-08-17'));
        $this->assertSame(1, YmdDate::daysBetween('2026-08-17', '2026-08-18'));
        $this->assertSame(31, YmdDate::daysBetween('2026-07-17', '2026-08-17'));
        $this->assertSame(-3, YmdDate::daysBetween('2026-08-20', '2026-08-17'), 'negativ ist erlaubt');
    }

    public function test_sommerzeit_wechsel_zaehlt_ganze_tage(): void
    {
        // 01.03. -> 31.03. ist 30 Tage, auch wenn dazwischen die Uhr springt.
        // Ohne UTC-Fixierung liefert die Differenz 29 oder 30,96 Tage.
        $this->assertSame(30, YmdDate::daysBetween('2026-03-01', '2026-03-31'));
    }

    public function test_unlesbares_datum_liefert_null(): void
    {
        $this->assertNull(YmdDate::daysBetween('2026-02-30', '2026-08-17'));
        $this->assertNull(YmdDate::daysBetween('2026-08-17', 'kaputt'));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter YmdDateTest`
Expected: FAIL/ERROR — Klasse `YmdDate` existiert nicht

- [ ] **Step 3: Implementieren (keine Framework-Imports)**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Datumsrechnung auf Y-m-d-Strings, ohne Carbon — pure Klassen im Modul
 * laufen ohne Laravel-Bootstrap.
 *
 * Zwei Eigenheiten, die hier bewusst behandelt werden:
 *  - createFromFormat rollt ungueltige Daten still weiter ('2026-02-30' →
 *    2. Maerz). Die Round-Trip-Pruefung faengt das, damit ein Tippfehler
 *    nicht als plausibler Wert durchgeht.
 *  - Zeitzonen: alles in UTC, damit ein Sommerzeit-Wechsel keine halben Tage
 *    erzeugt.
 */
final class YmdDate
{
    public static function isValid(string $ymd): bool
    {
        return self::parse($ymd) !== null;
    }

    /** Ganze Tage von $fromYmd bis $toYmd; negativ moeglich, null = unlesbar. */
    public static function daysBetween(string $fromYmd, string $toYmd): ?int
    {
        $from = self::parse($fromYmd);
        $to = self::parse($toYmd);
        if ($from === null || $to === null) {
            return null;
        }

        return (int) $from->diff($to)->format('%r%a');
    }

    private static function parse(string $ymd): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $ymd,
            new \DateTimeZone('UTC'),
        );

        // Round-Trip: nur wenn die Rueckformatierung identisch ist, war die
        // Eingabe wirklich ein gueltiges Y-m-d.
        return ($d !== false && $d->format('Y-m-d') === $ymd) ? $d : null;
    }
}
```

- [ ] **Step 4: Test grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter YmdDateTest`
Expected: PASS

- [ ] **Step 5: `CohortViewModel` auf `YmdDate` umstellen**

In `src/Services/Statistics/CohortViewModel.php` die privaten Helfer `ageInDays()` und `parseYmd()` entfernen und die Aufrufstelle in `isCensored()` ersetzen:

```php
$age = YmdDate::daysBetween($rowMaxAppliedAt, $todayYmd);
```

Import `use Platform\Recruiting\Support\YmdDate;` ergänzen. Die bestehenden `CohortViewModelTest`-Fälle (Rollover, Zukunftsdatum, unlesbar) sind das Sicherheitsnetz — sie dürfen nicht angepasst werden.

- [ ] **Step 6: Volle Unit-Suite grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: alle Tests grün, `CohortViewModelTest` unverändert bestanden

```bash
git add src/Support/YmdDate.php src/Services/Statistics/CohortViewModel.php tests/Unit/Statistics/YmdDateTest.php
git commit -m "feat(recruiting): YmdDate als gemeinsame Datumsrechnung fuer pure Klassen"
```

---

### Task 4: `TargetLight` — die Ampel-Logik

Herzstück der neuen Funktion und vollständig pure testbar. Die Herleitung steht in Spec §7: eine absolute Schwelle beantwortet die Frage nicht, für die die Ampel gebaut wird (dieselbe Zahl heißt je nach Restlaufzeit Alarm oder Plan), deshalb rechnet die Pipeline-Ampel eine **Hochrechnung** auf das Laufzeitende. Die Erfüllungs-Ampel bleibt **absolut**, weil Unterschriften in Schüben nach jeder Schulung kommen.

**Files:**
- Create: `src/Services/Statistics/TargetLight.php`
- Test: `tests/Unit/Statistics/TargetLightTest.php`

**Interfaces:**
- Consumes: `YmdDate::daysBetween()` (Task 3)
- Produces:
  - `TargetLight::pipeline(int $bewerbungen, ?int $bedarf, ?float $faktor, ?string $publishedYmd, ?string $closesYmd, string $todayYmd): array` → `['status' => string, 'pct' => ?int, 'projected' => ?int, 'target' => ?int, 'reason' => string]`
  - `TargetLight::fulfilment(int $unterschriften, ?int $bedarf): array` → `['status' => string, 'pct' => ?int, 'reason' => string]`
  - Konstanten `TargetLight::GREY|RED|YELLOW|GREEN`, `TargetLight::MIN_DAYS`
  - Tasks 8 und 9 rendern daraus; `status` ist der Farbschlüssel, `reason` der Tooltip-Text.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\TargetLight;

/**
 * Ampel-Logik. Herleitung in Spec §7: Pipeline rechnet eine Hochrechnung aufs
 * Laufzeitende (eine absolute Schwelle steht am Kampagnenanfang immer auf Rot
 * und am Ende immer auf Gruen), Erfuellung bleibt absolut (Unterschriften
 * kommen in Schueben nach jeder Schulung).
 */
final class TargetLightTest extends TestCase
{
    // ---------- Pipeline: fehlende Pflege ----------

    public function test_ohne_bedarf_oder_faktor_keine_ampel(): void
    {
        $ohneBedarf = TargetLight::pipeline(50, null, 7.0, '2026-07-01', '2026-09-01', '2026-08-17');
        $this->assertSame(TargetLight::GREY, $ohneBedarf['status']);
        $this->assertNull($ohneBedarf['pct']);

        $ohneFaktor = TargetLight::pipeline(50, 40, null, '2026-07-01', '2026-09-01', '2026-08-17');
        $this->assertSame(TargetLight::GREY, $ohneFaktor['status']);

        // Bedarf 0 ist kein Ziel — auch grau, nicht "100 % erreicht"
        $this->assertSame(
            TargetLight::GREY,
            TargetLight::pipeline(50, 0, 7.0, '2026-07-01', '2026-09-01', '2026-08-17')['status'],
        );
    }

    // ---------- Pipeline: Hochrechnung ----------

    public function test_hochrechnung_auf_das_laufzeitende(): void
    {
        // Bedarf 40 x Faktor 7 = 280 Ziel. Tag 47 von 62, 33 Bewerbungen.
        // Hochrechnung: 33 / 47 * 62 = 43,5 -> 44 von 280 = 16 % -> rot
        $r = TargetLight::pipeline(33, 40, 7.0, '2026-07-01', '2026-09-01', '2026-08-17');
        $this->assertSame(280, $r['target']);
        $this->assertSame(44, $r['projected']);
        $this->assertSame(16, $r['pct']);
        $this->assertSame(TargetLight::RED, $r['status']);
    }

    public function test_schwellen_greifen_auf_die_hochrechnung(): void
    {
        // Halbzeit (Tag 31 von 62), Ziel 100 (Bedarf 50 x Faktor 2)
        // 30 Bewerbungen -> Hochrechnung 60 -> 60 % -> gelb (Grenze ist inklusiv)
        $gelb = TargetLight::pipeline(30, 50, 2.0, '2026-07-01', '2026-09-01', '2026-08-01');
        $this->assertSame(60, $gelb['pct']);
        $this->assertSame(TargetLight::YELLOW, $gelb['status']);

        // 29 -> 58 % -> rot
        $this->assertSame(
            TargetLight::RED,
            TargetLight::pipeline(29, 50, 2.0, '2026-07-01', '2026-09-01', '2026-08-01')['status'],
        );

        // 45 -> Hochrechnung 90 -> 90 % -> gruen (Grenze inklusiv)
        $gruen = TargetLight::pipeline(45, 50, 2.0, '2026-07-01', '2026-09-01', '2026-08-01');
        $this->assertSame(90, $gruen['pct']);
        $this->assertSame(TargetLight::GREEN, $gruen['status']);
    }

    public function test_abgelaufene_laufzeit_rechnet_nicht_mehr_hoch(): void
    {
        // Laufzeit vorbei: es kommt nichts mehr dazu, die Hochrechnung ist der Ist-Wert
        $r = TargetLight::pipeline(100, 50, 2.0, '2026-06-01', '2026-07-01', '2026-08-17');
        $this->assertSame(100, $r['projected']);
        $this->assertSame(100, $r['pct']);
        $this->assertSame(TargetLight::GREEN, $r['status']);
    }

    // ---------- Pipeline: Schutzregeln ----------

    public function test_zu_frueh_fuer_eine_aussage(): void
    {
        // Tag 2 von 62: bei 4 Bewerbungen waere jede Hochrechnung Kaffeesatz,
        // und eine falsche rote Ampel verbrennt das Vertrauen ins Feature.
        $r = TargetLight::pipeline(4, 40, 7.0, '2026-08-15', '2026-10-15', '2026-08-17');
        $this->assertSame(TargetLight::GREY, $r['status']);
        $this->assertNull($r['pct']);
        $this->assertStringContainsString('zu früh', $r['reason']);
    }

    public function test_startdatum_in_der_zukunft_ist_grau(): void
    {
        $this->assertSame(
            TargetLight::GREY,
            TargetLight::pipeline(0, 40, 7.0, '2026-09-01', '2026-10-01', '2026-08-17')['status'],
        );
    }

    public function test_ohne_laufzeit_absolute_lesart(): void
    {
        // Kein Enddatum gepflegt -> keine Hochrechnung, aber die nackte Quote
        // ist besser als gar keine Aussage. Muss im Grund benannt sein.
        $r = TargetLight::pipeline(140, 40, 7.0, '2026-07-01', null, '2026-08-17');
        $this->assertNull($r['projected']);
        $this->assertSame(50, $r['pct'], '140 von 280');
        $this->assertSame(TargetLight::RED, $r['status']);
        $this->assertStringContainsString('Laufzeitende', $r['reason']);

        // Auch ohne Startdatum: absolute Lesart, keine Division durch null
        $ohneStart = TargetLight::pipeline(140, 40, 7.0, null, '2026-09-01', '2026-08-17');
        $this->assertSame(50, $ohneStart['pct']);
        $this->assertNull($ohneStart['projected']);
    }

    public function test_kaputte_oder_verdrehte_laufzeit_faellt_auf_absolut_zurueck(): void
    {
        // Ende vor Anfang: Laufzeit 0 oder negativ -> keine Hochrechnung
        $verdreht = TargetLight::pipeline(140, 40, 7.0, '2026-09-01', '2026-07-01', '2026-08-17');
        $this->assertNull($verdreht['projected']);
        $this->assertSame(50, $verdreht['pct']);

        // Unlesbares Datum -> ebenfalls absolut, nicht abstuerzen
        $kaputt = TargetLight::pipeline(140, 40, 7.0, '2026-02-30', '2026-09-01', '2026-08-17');
        $this->assertNull($kaputt['projected']);
        $this->assertSame(50, $kaputt['pct']);
    }

    public function test_gebrochener_faktor_wird_aufgerundet(): void
    {
        // Bedarf 3 x Faktor 7,5 = 22,5 -> 23 Bewerbungen noetig, nicht 22
        $r = TargetLight::pipeline(23, 3, 7.5, '2026-06-01', '2026-07-01', '2026-08-17');
        $this->assertSame(23, $r['target']);
        $this->assertSame(100, $r['pct']);
    }

    // ---------- Erfuellung ----------

    public function test_erfuellung_rechnet_absolut(): void
    {
        $r = TargetLight::fulfilment(6, 40);
        $this->assertSame(15, $r['pct']);
        $this->assertSame(TargetLight::RED, $r['status']);

        $this->assertSame(TargetLight::YELLOW, TargetLight::fulfilment(24, 40)['status'], '60 %');
        $this->assertSame(TargetLight::GREEN, TargetLight::fulfilment(36, 40)['status'], '90 %');
        $this->assertSame(TargetLight::GREEN, TargetLight::fulfilment(50, 40)['status'], 'ueber 100 % bleibt gruen');
    }

    public function test_erfuellung_ohne_bedarf_keine_ampel(): void
    {
        $r = TargetLight::fulfilment(6, null);
        $this->assertSame(TargetLight::GREY, $r['status']);
        $this->assertNull($r['pct']);

        $this->assertSame(TargetLight::GREY, TargetLight::fulfilment(0, 0)['status']);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TargetLightTest`
Expected: FAIL/ERROR — Klasse existiert nicht

- [ ] **Step 3: Implementieren**

```php
<?php

namespace Platform\Recruiting\Services\Statistics;

use Platform\Recruiting\Support\YmdDate;

/**
 * Ampel-Logik der Ausschreibungs-Tabelle (Spec §7).
 *
 * Zwei Ampeln mit absichtlich unterschiedlichem Bezug:
 *
 *  - pipeline(): Bewerbungen gegen Bedarf x Faktor, als HOCHRECHNUNG auf das
 *    Laufzeitende. Grund: dieselbe Zahl bedeutet je nach Restlaufzeit Alarm
 *    oder Plan — 33 Bewerbungen sind bei drei Wochen Restlaufzeit ein Problem
 *    und bei sechs Monaten im Plan. Eine absolute Schwelle stuende am
 *    Kampagnenanfang immer auf Rot und am Ende immer auf Gruen und beantwortet
 *    damit die Frage nicht, fuer die die Ampel gebaut wird.
 *    Mathematisch ist die Hochrechnung identisch mit "Ist / Soll-Fortschritt";
 *    kommuniziert wird die Hochrechnung, weil "44 von 280" verstaendlich ist
 *    und "16 % des Soll-Fortschritts" nicht.
 *
 *  - fulfilment(): Unterschriften gegen Bedarf, ABSOLUT. Keine Hochrechnung,
 *    weil Unterschriften nicht gleichmaessig eintreffen, sondern schubweise
 *    nach jeder Schulung — ein linearer Verlauf waere irreführend.
 *
 * Nichts wird geraten: fehlt Bedarf oder Faktor, ist die Ampel grau.
 */
final class TargetLight
{
    public const GREY = 'grey';
    public const RED = 'red';
    public const YELLOW = 'yellow';
    public const GREEN = 'green';

    /** Unter dieser Laufzeit ist jede Hochrechnung Kaffeesatz. */
    public const MIN_DAYS = 7;

    private const THRESHOLD_YELLOW = 60;
    private const THRESHOLD_GREEN = 90;

    /**
     * @return array{status:string, pct:?int, projected:?int, target:?int, reason:string}
     */
    public static function pipeline(
        int $bewerbungen,
        ?int $bedarf,
        ?float $faktor,
        ?string $publishedYmd,
        ?string $closesYmd,
        string $todayYmd,
    ): array {
        if ($bedarf === null || $faktor === null || $bedarf <= 0 || $faktor <= 0) {
            return self::grey('Bedarf oder Faktor ist an dieser Ausschreibung nicht gepflegt.');
        }

        // Aufrunden: 22,5 noetige Bewerbungen heisst 23, nicht 22.
        $target = (int) ceil($bedarf * $faktor);

        $elapsed = ($publishedYmd !== null && $closesYmd !== null)
            ? YmdDate::daysBetween($publishedYmd, $todayYmd)
            : null;
        $total = ($publishedYmd !== null && $closesYmd !== null)
            ? YmdDate::daysBetween($publishedYmd, $closesYmd)
            : null;

        // Keine oder unbrauchbare Laufzeit -> absolute Lesart statt gar nichts.
        if ($elapsed === null || $total === null || $total <= 0) {
            return self::rate(
                $bewerbungen,
                $target,
                null,
                'Kein Start- oder Laufzeitende gepflegt — verglichen wird gegen das Gesamtziel '
                . "von {$target} Bewerbungen, ohne Hochrechnung.",
            );
        }

        if ($elapsed < 0) {
            return self::grey('Die Ausschreibung startet erst — noch keine Aussage möglich.');
        }

        if ($elapsed < self::MIN_DAYS) {
            return self::grey(
                "Läuft erst {$elapsed} von {$total} Tagen — zu früh für eine Aussage."
            );
        }

        // Laufzeit vorbei: es kommt nichts mehr dazu, der Ist-Wert IST das Ergebnis.
        $projected = $elapsed >= $total
            ? $bewerbungen
            : (int) round($bewerbungen / $elapsed * $total);

        return self::rate(
            $projected,
            $target,
            $projected,
            "{$bewerbungen} Bewerbungen an Tag {$elapsed} von {$total} — "
            . "Hochrechnung {$projected} von {$target} benötigten.",
        );
    }

    /**
     * @return array{status:string, pct:?int, reason:string}
     */
    public static function fulfilment(int $unterschriften, ?int $bedarf): array
    {
        if ($bedarf === null || $bedarf <= 0) {
            $grey = self::grey('Bedarf ist an dieser Ausschreibung nicht gepflegt.');

            return ['status' => $grey['status'], 'pct' => null, 'reason' => $grey['reason']];
        }

        $pct = (int) round($unterschriften / $bedarf * 100);

        return [
            'status' => self::statusFor($pct),
            'pct' => $pct,
            'reason' => "{$unterschriften} von {$bedarf} benötigten Einstellungen unterschrieben.",
        ];
    }

    /** @return array{status:string, pct:?int, projected:?int, target:?int, reason:string} */
    private static function rate(int $value, int $target, ?int $projected, string $reason): array
    {
        $pct = (int) round($value / $target * 100);

        return [
            'status' => self::statusFor($pct),
            'pct' => $pct,
            'projected' => $projected,
            'target' => $target,
            'reason' => $reason,
        ];
    }

    private static function statusFor(int $pct): string
    {
        if ($pct >= self::THRESHOLD_GREEN) {
            return self::GREEN;
        }

        return $pct >= self::THRESHOLD_YELLOW ? self::YELLOW : self::RED;
    }

    /** @return array{status:string, pct:?int, projected:?int, target:?int, reason:string} */
    private static function grey(string $reason): array
    {
        return [
            'status' => self::GREY,
            'pct' => null,
            'projected' => null,
            'target' => null,
            'reason' => $reason,
        ];
    }
}
```

- [ ] **Step 4: Tests grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter TargetLightTest`
Expected: PASS (alle Tests der Datei)

- [ ] **Step 5: Keine Framework-Imports prüfen und committen**

Run: `grep -nE "Illuminate|Carbon|now\(|date\(" src/Services/Statistics/TargetLight.php`
Expected: keine Treffer

```bash
git add src/Services/Statistics/TargetLight.php tests/Unit/Statistics/TargetLightTest.php
git commit -m "feat(recruiting): TargetLight — Pipeline-Ampel als Hochrechnung, Erfuellungs-Ampel absolut"
```

---

### Task 5: Bedarf und Faktor im Ausschreibungs-Formular

**Vorsicht:** `Posting/Show.php` ist ein produktiver Bildschirm, den HR täglich benutzt. Ein Blade-Fehler hier legt die Ausschreibungs-Bearbeitung lahm, nicht nur die Statistik.

**Files:**
- Modify: `src/Livewire/Posting/Show.php` (`rules()` und `save()`)
- Modify: `resources/views/livewire/posting/show.blade.php`

**Interfaces:**
- Consumes: `rec_postings.bedarf`, `rec_postings.bewerbungs_faktor` (Task 2)

- [ ] **Step 1: Validierung ergänzen**

In `src/Livewire/Posting/Show.php` in `rules()` aufnehmen (Model-Binding wie bei `posting.title`, kein Datums-Problem):

```php
'posting.bedarf' => 'nullable|integer|min:0|max:10000',
'posting.bewerbungs_faktor' => 'nullable|numeric|min:0.1|max:99.9',
```

`save()` braucht keine Änderung — `$this->posting->save()` schreibt die model-gebundenen Felder mit.

- [ ] **Step 2: Eingabefelder ins Blade**

In `resources/views/livewire/posting/show.blade.php` bei den bestehenden Feldern (dort, wo `publishedAt`/`closesAt` stehen — mit `grep -n "closesAt" resources/views/livewire/posting/show.blade.php` finden) ergänzen:

```blade
<x-ui-input-text
    name="posting.bedarf"
    label="Bedarf (Personen)"
    type="number"
    min="0"
    wire:model="posting.bedarf"
    hint="Wie viele Personen über diese Ausschreibung eingestellt werden sollen. Leer = keine Erfüllungs-Ampel."
/>
<x-ui-input-text
    name="posting.bewerbungs_faktor"
    label="Faktor (Bewerbungen pro Einstellung)"
    type="number"
    step="0.1"
    min="0.1"
    wire:model="posting.bewerbungs_faktor"
    hint="Erfahrungswert im Team: 7–10 Bewerbungen pro Einstellung. Leer = keine Pipeline-Ampel."
/>
```

`x-ui-input-text` mit `type="number"` ist bewusst — `x-ui-input-number` existiert im UI-Paket **nicht** (dokumentierte Falle). `hint` ist das richtige Attribut für Hilfetext, nicht `help`.

- [ ] **Step 3: Blade kompilieren und Suite laufen lassen**

Run (Skript aus Task 8 verwenden, sobald es existiert; bis dahin):
`php -l src/Livewire/Posting/Show.php`
Expected: `No syntax errors detected`

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle Tests grün

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Posting/Show.php resources/views/livewire/posting/show.blade.php
git commit -m "feat(recruiting): Bedarf und Faktor im Ausschreibungs-Formular"
```

---

### Task 6: Ausschreibungs-Auswahl im Termin-Formular

**Files:**
- Modify: `src/Livewire/InterviewSchedule/Index.php` (Property, `$rules`, Speicher- und Bearbeiten-Pfad)
- Modify: `resources/views/livewire/interview-schedule/index.blade.php` (beide Modale: Anlegen und Bearbeiten)

**Interfaces:**
- Consumes: `rec_interviews.rec_posting_id` (Task 2)
- Produces: gepflegte Termin↔Ausschreibung-Zuordnung, die Task 9 (Tabelle 2) statt Titel-Parsing nutzt

- [ ] **Step 1: Property und Validierung**

In `src/Livewire/InterviewSchedule/Index.php` neben `public $rec_position_id = '';` ergänzen:

```php
public $rec_posting_id = '';
```

In `protected $rules` ergänzen:

```php
'rec_posting_id' => 'nullable|integer|exists:rec_postings,id',
```

Die Stellen, die den Termin speichern und zum Bearbeiten laden, mit `grep -n "rec_position_id" src/Livewire/InterviewSchedule/Index.php` finden und `rec_posting_id` überall analog mitziehen (Anlegen, Bearbeiten-Vorbelegung, Zurücksetzen des Formulars). Leerstring auf `null` normalisieren:

```php
'rec_posting_id' => $this->rec_posting_id !== '' ? (int) $this->rec_posting_id : null,
```

- [ ] **Step 2: Auswahl-Liste als Computed, auf die Stelle eingeschränkt**

Eine Ausschreibung einer *anderen* Stelle an einen Termin zu hängen wäre stiller Unsinn — deshalb nur Ausschreibungen der gewählten Stelle anbieten:

```php
/** @return array<int,string> */
#[Computed]
public function postingOptions(): array
{
    if ($this->rec_position_id === '' || $this->rec_position_id === null) {
        return [];
    }

    return \Platform\Recruiting\Models\RecPosting::query()
        ->where('team_id', auth()->user()->currentTeam->id)
        ->where('rec_position_id', (int) $this->rec_position_id)
        ->orderBy('title')
        ->pluck('title', 'id')
        ->all();
}
```

- [ ] **Step 3: Select in beide Modale**

In `resources/views/livewire/interview-schedule/index.blade.php` in **beiden** Modalen (Anlegen und Bearbeiten — mit `grep -n "rec_position_id" resources/views/livewire/interview-schedule/index.blade.php` beide Stellen finden) direkt nach der Stellen-Auswahl:

```blade
<x-ui-input-select
    name="rec_posting_id"
    label="Ausschreibung"
    :options="$this->postingOptions"
    :nullable="true"
    nullLabel="keine Zuordnung"
    wire:model="rec_posting_id"
    hint="Für welche Ausschreibung diese Schulung stattfindet. Ohne Zuordnung wird in der Statistik der Titel angezeigt."
/>
```

- [ ] **Step 4: Syntax, Suite, Commit**

Run: `php -l src/Livewire/InterviewSchedule/Index.php`
Expected: `No syntax errors detected`

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle Tests grün

```bash
git add src/Livewire/InterviewSchedule/Index.php resources/views/livewire/interview-schedule/index.blade.php
git commit -m "feat(recruiting): Termin kann einer Ausschreibung zugeordnet werden"
```

- [ ] **Step 5: Zwischen-Deploy von Stufe 1**

Damit HR pflegen kann, während Stufe 2 entsteht:

1. Branch pushen, nach Freigabe ff auf `main` mergen (kein PR per CLI — Projekt-Konvention)
2. meingedeck `composer.lock` bumpen und pushen
3. Nach dem Deploy: `php artisan migrate`
4. **Kein `queue:restart` nötig** — Stufe 1 ändert keinen Code, der in Jobs läuft
5. HR-Aufgabe anstoßen: Bedarf, Faktor und Laufzeitende an den aktiven Ausschreibungen eintragen; die zwei Test-Termine („test nicht einbuchen", „Test Clara Nini – nicht einbuchen") löschen oder auf inaktiv setzen

---

# Stufe 2 — Die Seite

### Task 7: `CohortAssigner` — Zeilen nach Ausschreibung, Phase-Spalten, netto

Die Klasse trägt die Rekonziliations-Invariante mit ihren Tests. Die Präzedenz-Kette bleibt unverändert; was sich ändert, ist der **Zeilen-Schlüssel** (Ausschreibung statt Ort→Tätigkeit-Gruppe) und dass Trichter-Spalten nur noch laufende Bewerbungen zählen.

**Files:**
- Modify: `src/Services/Statistics/CohortAssigner.php`
- Test: `tests/Unit/Statistics/CohortAssignerTest.php`

**Interfaces:**
- Produces (Erweiterung des Row-Shapes, Task 8 und 9 lesen das):
  - `posting_id: ?int`, `posting_title: string`, `posting_closed: bool` je Zeile
  - `columns['phase_reached'][int $order]: list<int>` — Bewerbungen, die Phase mit diesem `order` erreicht haben
  - unverändert: `type`, `key`, `group`, `ids`, `hr_desk_ids`, `uneindeutig_ids`, `offen_ids`, `max_applied_at`, `tth_days`, alle bisherigen `columns`
- Input-Erweiterung: `$applicants[i]['phase_order_reached']: ?int` (höchste erreichte Phasen-`order`, aus dem Transition-Log oder der aktuellen Phase — der Aufrufer liefert sie), `$pivotsByApplicant[i][j]['posting_closed']: bool`, `['posting_title']: string`

- [ ] **Step 1: Failing Tests schreiben**

An `tests/Unit/Statistics/CohortAssignerTest.php` anhängen (die vorhandenen Helfer `applicant()`, `booking()` weiterverwenden und um die neuen Schlüssel ergänzen):

```php
    public function test_zeilen_werden_nach_ausschreibung_gebildet(): void
    {
        $rows = (new CohortAssigner())->assign(
            [
                $this->applicant(1, ['phase_position_id' => 5]),
                $this->applicant(2, ['phase_position_id' => 5]),
                $this->applicant(3, ['phase_position_id' => 5]),
            ],
            [],
            [
                1 => [['posting_id' => 48, 'position_id' => 5, 'location' => 'MGL', 'activity' => 'Catering',
                       'posting_title' => 'Cateringhilfe', 'posting_closed' => false]],
                2 => [['posting_id' => 48, 'position_id' => 5, 'location' => 'MGL', 'activity' => 'Catering',
                       'posting_title' => 'Cateringhilfe', 'posting_closed' => false]],
                3 => [['posting_id' => 46, 'position_id' => 5, 'location' => 'MGL', 'activity' => 'Zapfer',
                       'posting_title' => 'Zapfer', 'posting_closed' => false]],
            ],
            null,
            null,
        )['rows'];

        // Zwei Ausschreibungen -> zwei Zeilen, nicht eine Gruppe
        $byPosting = [];
        foreach ($rows as $row) {
            $byPosting[$row['posting_id']][] = $row;
        }
        $this->assertSame([48, 46], array_keys($byPosting));
        $this->assertSame([1, 2], $byPosting[48][0]['ids']);
        $this->assertSame('Cateringhilfe', $byPosting[48][0]['posting_title']);
        $this->assertSame([3], $byPosting[46][0]['ids']);
    }

    public function test_phase_erreicht_ist_kumulativ(): void
    {
        $rows = (new CohortAssigner())->assign(
            [
                $this->applicant(1, ['phase_order_reached' => 1]),
                $this->applicant(2, ['phase_order_reached' => 2]),
                $this->applicant(3, ['phase_order_reached' => 4]),
            ],
            [], [], null, null,
        )['rows'];

        $row = $rows[0];
        // Wer Phase 4 erreicht hat, hat auch 1, 2 und 3 erreicht
        $this->assertSame([1, 2, 3], $row['columns']['phase_reached'][1]);
        $this->assertSame([2, 3], $row['columns']['phase_reached'][2]);
        $this->assertSame([3], $row['columns']['phase_reached'][3]);
        $this->assertSame([3], $row['columns']['phase_reached'][4]);
        $this->assertArrayNotHasKey(5, $row['columns']['phase_reached']);
    }

    public function test_ausgeschiedene_zaehlen_nicht_im_trichter(): void
    {
        // Entscheidung 2026-08-17: der Trichter rechnet netto. Geparkte und
        // Abgesagte stehen in eigenen Zeilen (Praezedenz-Kette) und tauchen in
        // KEINER Trichter-Spalte einer Ausschreibungs-Zeile auf.
        $result = (new CohortAssigner())->assign(
            [
                $this->applicant(1, ['phase_order_reached' => 2]),
                $this->applicant(2, ['phase_order_reached' => 2, 'parked' => true]),
                $this->applicant(3, ['phase_order_reached' => 2, 'rejected' => true]),
            ],
            [], [], null, null,
        );

        $laufend = array_values(array_filter($result['rows'], fn ($r) => $r['type'] === 'ohne_schulung'));
        $this->assertSame([1], $laufend[0]['columns']['phase_reached'][2]);

        // aber die Rekonziliation bleibt vollstaendig: alle drei sind erfasst
        $this->assertCount(3, $result['total_ids']);
        $typen = array_column($result['rows'], 'type');
        $this->assertContains('geparkt', $typen);
        $this->assertContains('abgesagt', $typen);
    }

    public function test_geschlossene_ausschreibung_wird_markiert(): void
    {
        $rows = (new CohortAssigner())->assign(
            [$this->applicant(1, ['phase_position_id' => 5])],
            [],
            [1 => [['posting_id' => 37, 'position_id' => 5, 'location' => 'MGL', 'activity' => 'Alles',
                    'posting_title' => 'MGL allgemein', 'posting_closed' => true]]],
            null, null,
        )['rows'];

        $this->assertTrue($rows[0]['posting_closed']);
    }
```

Die privaten Helfer im Test um die neuen Schlüssel erweitern: `applicant()` bekommt `'phase_order_reached' => null` in den Defaults, und in den Pivot-Arrays kommen `posting_title` und `posting_closed` dazu.

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CohortAssignerTest`
Expected: die vier neuen Tests FAIL, die bestehenden weiter grün

- [ ] **Step 3: Implementieren**

In `src/Services/Statistics/CohortAssigner.php`:

1. `groupFor()` gibt zusätzlich `posting_id`, `posting_title` und `posting_closed` der gewählten Zuordnung zurück (die Auswahl-Logik der fünf Fälle bleibt unverändert).
2. Der Row-Key bekommt die `posting_id` als führenden Bestandteil, damit zwei Ausschreibungen derselben Gruppe getrennte Zeilen bilden.
3. Im Row-Template `'columns' => [... 'phase_reached' => []]` ergänzen.
4. Nach der Typ-Zuweisung: nur für laufende Typen (`RUNNING_TYPES`) und nur wenn `phase_order_reached !== null` für jedes `order` von 1 bis `phase_order_reached` die ID in `columns['phase_reached'][$order]` schieben.
5. Die bisherigen Trichter-Spalten (`kontaktiert`, `gebucht`, …) bleiben unverändert — sie werden ohnehin nur für Schulungszeilen bzw. laufende Zeilen gefüllt.

- [ ] **Step 4: Alle Tests grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: alle grün — insbesondere die **Rekonziliations-Invariante** (`test_rekonziliation_jeder_genau_einmal`) unverändert bestanden, nicht angepasst

- [ ] **Step 5: Commit**

```bash
git add src/Services/Statistics/CohortAssigner.php tests/Unit/Statistics/CohortAssignerTest.php
git commit -m "feat(recruiting): CohortAssigner bildet Zeilen je Ausschreibung und Phase-erreicht-Spalten"
```

---

### Task 8: Tabelle 1 — Ausschreibungen mit Bedarf, Erfüllung und Ampeln

**Files:**
- Modify: `src/Services/Statistics/CohortViewModel.php` (Sortierung nach Ausschreibung, Summen-Arithmetik)
- Modify: `src/Livewire/Statistics/Index.php` (Filter, Query liefert die neuen Felder)
- Create: `resources/views/livewire/statistics/light.blade.php`
- Create: `resources/views/livewire/statistics/postings-table.blade.php`
- Test: `tests/Unit/Statistics/CohortViewModelTest.php`

**Interfaces:**
- Consumes: `TargetLight::pipeline()`/`::fulfilment()` (Task 4), Row-Shape aus Task 7
- Produces: `CohortViewModel::sumPercent(array $rows, string $numeratorColumn, string $bedarfKey): ?int` — Prozente der Summen-Zeile, **neu gerechnet** aus absoluten Summen

- [ ] **Step 1: Failing Test für die Summen-Arithmetik**

```php
    public function test_summen_prozent_wird_neu_gerechnet_nicht_gemittelt(): void
    {
        // Der Klassiker, der Prozentzahlen kaputtmacht: Mittelwert der
        // Zeilen-Prozente statt Summe/Summe. Hier weichen beide deutlich ab.
        // Zeile A: 1 von 1 = 100 %, Zeile B: 1 von 99 = 1 %
        // Mittelwert waere 50,5 %, korrekt sind 2 von 100 = 2 %.
        $rows = [
            ['bedarf' => 1,  'columns' => ['unterschrieben' => [1]]],
            ['bedarf' => 99, 'columns' => ['unterschrieben' => [2]]],
        ];

        $this->assertSame(2, $this->vm()->sumPercent($rows, 'unterschrieben', 'bedarf'));
    }

    public function test_summen_prozent_ohne_bedarf_ist_null(): void
    {
        $this->assertSame(
            null,
            $this->vm()->sumPercent([['bedarf' => null, 'columns' => ['unterschrieben' => [1]]]], 'unterschrieben', 'bedarf'),
        );
        $this->assertSame(null, $this->vm()->sumPercent([], 'unterschrieben', 'bedarf'));
    }
```

- [ ] **Step 2: FAIL verifizieren**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CohortViewModelTest`
Expected: die zwei neuen Tests FAIL

- [ ] **Step 3: Implementieren**

```php
    /**
     * Prozentwert einer Summen-Zeile: Summe der Zaehler geteilt durch Summe der
     * Nenner — NIEMALS der Mittelwert der Zeilen-Prozente. Bei 1/1 und 1/99
     * ergaebe der Mittelwert 50 %, richtig sind 2 %.
     *
     * null = kein Nenner gepflegt, also keine Quote (nicht 0 %).
     *
     * @param  list<array>  $rows
     */
    public function sumPercent(array $rows, string $numeratorColumn, string $bedarfKey): ?int
    {
        $numerator = 0;
        $denominator = 0;
        foreach ($rows as $row) {
            $bedarf = $row[$bedarfKey] ?? null;
            if ($bedarf === null || $bedarf <= 0) {
                continue;
            }
            $denominator += (int) $bedarf;
            $numerator += count($row['columns'][$numeratorColumn] ?? []);
        }

        return $denominator > 0 ? (int) round($numerator / $denominator * 100) : null;
    }
```

- [ ] **Step 4: Tests grün**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CohortViewModelTest`
Expected: PASS

- [ ] **Step 5: Ampel-Partial anlegen**

`resources/views/livewire/statistics/light.blade.php`:

```blade
{{--
    Ampel-Darstellung. Heller Zeilen-Tint plus Statuspunkt statt Volltoenung:
    eine durchgefaerbte Zeile wuerde die Trichter-Farben erschlagen und die
    Zahlen unlesbar machen.

    Erwartet:
      $light  array{status:string, pct:?int, reason:string, ...} aus TargetLight
      $label  string  kurzer Text vor dem Prozentwert (z. B. 'Pipeline')
--}}
@php
    $dot = match ($light['status']) {
        'green' => 'bg-emerald-500',
        'yellow' => 'bg-amber-500',
        'red' => 'bg-red-500',
        default => 'bg-gray-300',
    };
    $text = match ($light['status']) {
        'green' => 'text-emerald-700',
        'yellow' => 'text-amber-700',
        'red' => 'text-red-700',
        default => 'text-[color:var(--ui-muted)]',
    };
@endphp
<span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs tabular-nums {{ $text }}"
      title="{{ $label }}: {{ $light['reason'] }}">
    <span class="h-2 w-2 shrink-0 rounded-full {{ $dot }}"></span>
    @if ($light['pct'] === null)
        <span class="cursor-help">–</span>
    @else
        {{ $light['pct'] }} %
    @endif
</span>
```

- [ ] **Step 6: Tabelle 1 bauen**

`resources/views/livewire/statistics/postings-table.blade.php` — eine Zeile pro Ausschreibung. Vorlage für Markup, Spaltengruppen, Zellen und Summenzeile ist die bestehende `index.blade.php` (Kopfzeilen mit `sticky top-0`/`top-7`, `@include`s von `cells.blade.php` und `conversion.blade.php`, Summen-Zeile mit `border-t-2`). Neu gegenüber der alten Tabelle:

- Erste Spalte: Titel der Ausschreibung, darunter klein die Tätigkeit; bei `posting_closed` ein neutrales Badge „geschlossen"
- Spaltengruppe **Trichter** enthält jetzt die Phase-erreicht-Spalten; die Überschriften kommen aus dem Phasensatz der gefilterten Filiale (siehe Task 10, `$this->phaseLabels`), **nicht** fest verdrahtet
- Spaltengruppe **Ziel**: Bedarf (Zahl), Erfüllung (`@include light` mit `TargetLight::fulfilment`), Pipeline (`@include light` mit `TargetLight::pipeline`)
- Zeilen-Tint nach dem Pipeline-Status: `bg-red-50/40`, `bg-amber-50/40`, `bg-emerald-50/40`, sonst nichts
- Gesamt-Zeile: absolute Spalten als Summe, Bedarf als Summe, **Erfüllung über `sumPercent()`**, Pipeline-Ampel aus Σ Bewerbungen gegen Σ (Bedarf × Faktor) — **kein** Faktor in der Gesamt-Zeile, der lässt sich nicht addieren
- Spalte „Erster Einsatz": zeigt „–" mit `title="kommt mit der Dispo"`

- [ ] **Step 7: Blade-Prüfskript anlegen und alle Views prüfen**

`php -l` prüft `.blade.php` nicht. Skript unter `tools/blade-check.php` (existiert laut Projekt-Notiz bereits — mit `ls tools/blade-check.php` prüfen; falls nicht, aus dieser Vorlage anlegen):

```php
<?php
// Kompiliert Blade-Dateien mit dem echten BladeCompiler und prueft das Kompilat
// mit php -l. Faengt unbalancierte @if/@foreach und kaputte @php-Bloecke.
require '/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

$c = new Container();
Container::setInstance($c);
$c->bind(\Illuminate\Contracts\View\Factory::class, fn () => new class implements \Illuminate\Contracts\View\Factory {
    public function exists($view) { return true; }
    public function file($path, $data = [], $mergeData = []) { return ''; }
    public function make($view, $data = [], $mergeData = []) { return ''; }
    public function share($key, $value = null) { return $value; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
});
$c->bind(\Illuminate\Contracts\Foundation\Application::class, fn () => new class {
    public function getNamespace() { return 'App\\'; }
});

$compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
$ok = true;
foreach (array_slice($argv, 1) as $file) {
    $out = $compiler->compileString(file_get_contents($file));
    $tmp = sys_get_temp_dir() . '/blade-' . md5($file) . '.php';
    file_put_contents($tmp, $out);
    $lines = [];
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lines, $code);
    printf("%-28s %s\n", basename($file), $code === 0 ? 'OK' : 'FEHLER: ' . implode(' ', $lines));
    if ($code !== 0) { $ok = false; }
}
exit($ok ? 0 : 1);
```

Run: `php tools/blade-check.php resources/views/livewire/statistics/*.blade.php resources/views/livewire/posting/show.blade.php resources/views/livewire/interview-schedule/index.blade.php`
Expected: alle `OK`

- [ ] **Step 8: Commit**

```bash
git add src/Services/Statistics/CohortViewModel.php tests/Unit/Statistics/CohortViewModelTest.php \
        src/Livewire/Statistics/Index.php resources/views/livewire/statistics/light.blade.php \
        resources/views/livewire/statistics/postings-table.blade.php tools/blade-check.php
git commit -m "feat(recruiting): Ausschreibungs-Tabelle mit Bedarf, Erfuellung und zwei Ampeln"
```

---

### Task 9: Tabelle 2 — Schulungstermine mit Herkunft

**Files:**
- Modify: `src/Livewire/Statistics/Index.php` (Termin-Query mit Zeitraum-Filter)
- Create: `resources/views/livewire/statistics/interviews-table.blade.php`

**Interfaces:**
- Consumes: `rec_interviews.rec_posting_id` (Task 2/6), Row-Shape aus Task 7

- [ ] **Step 1: Termin-Query**

In `src/Livewire/Statistics/Index.php` ein Computed ergänzen. Der Zeitraum-Filter gehört **hierhin** — ein Termin hat einen Zeitpunkt, eine Ausschreibung hat ein Ziel (deshalb kein Datumsfilter in Tabelle 1):

```php
/** @return \Illuminate\Support\Collection<int,\Platform\Recruiting\Models\RecInterview> */
#[Computed]
public function interviews()
{
    return RecInterview::forTeam($this->teamId())
        ->where('is_active', true)
        ->when($this->interviewFrom, fn ($q) => $q->where('starts_at', '>=', $this->interviewFrom))
        ->when($this->interviewTo, fn ($q) => $q->where('starts_at', '<=', $this->interviewTo . ' 23:59:59'))
        ->with(['interviewType:id,name', 'posting:id,title'])
        ->withCount(['bookings as seat_taking_count' => fn ($q) => $q->seatTaking()])
        ->orderByDesc('starts_at')
        ->get();
}
```

`is_active = true` filtert die Test-Termine mit heraus, sobald HR sie inaktiv gesetzt hat (Termine haben kein `is_test`-Flag — dokumentierter Befund).

Properties `public ?string $interviewFrom = null;` und `public ?string $interviewTo = null;` ergänzen, jeweils mit `updated`-Hook, der `''` auf `null` normalisiert (Leerstring-Falle: ein geleertes Datumsfeld liefert `''`, und `'2026-07-05' > ''` ist wahr).

- [ ] **Step 2: Tabelle 2 bauen**

`resources/views/livewire/statistics/interviews-table.blade.php` — eine Zeile pro Termin:

- Datum/Uhrzeit, Terminart, Ort, **Ausschreibung** (aus `posting->title`, Rückfall auf den Termin-Titel wenn nicht gesetzt)
- **IST/SOLL (+Standby)** über das bestehende `meter.blade.php`
- Trichter-Spalten der Teilnehmer dieses Termins über `cells.blade.php`
- Aufklappbar (Alpine, Muster aus der alten `index.blade.php`) die **Herkunft**: eine Unterzeile pro Ausschreibung der Teilnehmer, mit eigenem Trichter aber **ohne** Kapazität — die Plätze gehören dem Termin
- Gesamt-Zeile: Σ IST / Σ SOLL (+Σ Standby), wie im Mockup

- [ ] **Step 3: Prüfen und committen**

Run: `php tools/blade-check.php resources/views/livewire/statistics/interviews-table.blade.php`
Expected: `OK`

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle Tests grün

```bash
git add src/Livewire/Statistics/Index.php resources/views/livewire/statistics/interviews-table.blade.php
git commit -m "feat(recruiting): Termin-Tabelle mit IST/SOLL und aufklappbarer Herkunft"
```

---

### Task 10: Filterleiste, Phasen-Überschriften und die drei Blöcke

**Files:**
- Modify: `src/Livewire/Statistics/Index.php` (Ort als Pflichtauswahl, Status-Filter, `phaseLabels`)
- Modify: `resources/views/livewire/statistics/index.blade.php` (setzt die Teile zusammen)

- [ ] **Step 1: Filter umbauen**

- `ortFilter` wird **Pflichtauswahl** mit dem ersten Ort als Vorbelegung; „alle Orte" entfällt (Kunden-Entscheidung). Ist kein Ort gewählt, zeigt die Seite eine Aufforderung statt einer Tabelle.
- Neu: `postingStatusFilter` mit `'online'` (Standard) und `'alle'`. `'online'` heißt `status = 'published' AND is_active = 1`.
- Der bisherige `filterFrom`/`filterTo`-Zeitraum entfällt aus Tabelle 1 und lebt als `interviewFrom`/`interviewTo` nur noch in Tabelle 2 (Task 9).

- [ ] **Step 2: Phasen-Überschriften aus dem Phasensatz der Filiale**

```php
/** @return array<int,string> order => Name, aus dem Phasensatz der gefilterten Filiale */
#[Computed]
public function phaseLabels(): array
{
    $positionIds = RecPosition::forTeam($this->teamId())
        ->where('location', $this->ortFilter)
        ->pluck('id');

    return RecPhase::forTeam($this->teamId())
        ->whereIn('rec_position_id', $positionIds)
        ->where('is_active', true)
        ->orderBy('order')
        ->pluck('name', 'order')
        ->all();
}
```

Phasen sind pro Stelle geklont und frei benannt — deshalb aus der Auswahl lesen statt fest verdrahten. Bei mehreren Stellen am Ort gewinnt der letzte Name je `order`; das ist unkritisch, weil geklonte Phasen gleich heißen.

- [ ] **Step 3: Die drei Blöcke unter den Tabellen**

Aufklappbar, jeweils mit Anzahl im Kopf:

1. **Ausgeschieden** — Zeilentypen `geparkt`, `abgesagt`, `dublette`, `unrouted`, `ohne_datum`, `unbekannter_status`, jeder mit Zahl und Drill-down. Für die KPIs nicht direkt relevant, aber sichtbar, damit die Differenz zur Gesamtmenge benannt ist.
2. **Geschlossene Ausschreibungen** — Zeilen mit `posting_closed = true`. Hier landen auch die rund 929 Bewerbungen an Stellen ohne gepflegten Standort, die aus der ortsgefilterten Ansicht herausfallen.
3. **Rekonziliation** — der bestehende rote Hinweis, wenn Σ Zeilen ≠ Gesamtmenge. Bleibt unverändert.

- [ ] **Step 4: Prüfen und committen**

Run: `php tools/blade-check.php resources/views/livewire/statistics/*.blade.php`
Expected: alle `OK`

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`
Expected: alle Tests grün

```bash
git add src/Livewire/Statistics/Index.php resources/views/livewire/statistics/index.blade.php
git commit -m "feat(recruiting): Filterleiste nach Filiale und Status, Phasen-Ueberschriften aus dem Phasensatz, drei Bloecke"
```

---

### Task 11: Deploy und Live-Prüfung

**Files:** keine

- [ ] **Step 1:** Branch pushen, nach Freigabe ff auf `main` mergen
- [ ] **Step 2:** meingedeck `composer.lock` bumpen und pushen (Pflicht, sonst nicht live)
- [ ] **Step 3:** Nach dem Deploy `php artisan migrate` (läuft im Deploy-Script)
- [ ] **Step 4:** **Kein `queue:restart` nötig** — kein Code, der in Jobs läuft, wurde geändert
- [ ] **Step 5: Sichtprüfungen**

1. Rekonziliations-Hinweis erscheint **nicht**
2. Eine Ausschreibung ohne Bedarf/Faktor zeigt graue Ampeln mit erklärendem Tooltip, keine geratenen Werte
3. Eine Ausschreibung mit gepflegten Werten zeigt die Hochrechnung im Tooltip („X Bewerbungen an Tag N von M")
4. Eine Anzeige, die weniger als sieben Tage läuft, zeigt „zu früh für eine Aussage" statt Rot
5. Gesamt-Zeile: Erfüllung ist **nicht** der Mittelwert der Zeilen-Prozente (mit zwei Zeilen gegenrechnen)
6. Termin-Tabelle: IST/SOLL stimmt mit dem Terminbildschirm überein, Standby zählt in keiner Kapazitätszahl
7. Aufklappen der Herkunft zeigt Unterzeilen ohne eigene Kapazität
8. Ausschreibungs- und Termin-Formular funktionieren weiter (produktive Bildschirme!)

---

## Self-Review

**Spec-Coverage:** §2 neue Felder → Task 2; §3 Zustandsmodell (kumulativ) → Task 7 (`phase_reached`) und die unveränderten Buchungsspalten; §4 Filter → Task 10; §5 Tabelle 1 samt Gesamt-Arithmetik → Tasks 7, 8; §6 Tabelle 2 → Tasks 6, 9; §7 Ampel → Task 4 (Logik) und Task 8 (Darstellung); §8 Rekonziliations-Blöcke → Task 10; §9 Datenlücken → „Erster Einsatz" leer in Task 8, Test-Termine über `is_active` in Task 9; §11 Tests → Tasks 3, 4, 7, 8. **Nicht abgedeckt und bewusst so:** der automatisch gemessene Faktor-Vorschlag (in der Spec als zurückgestellt markiert, Task 5 setzt nur den statischen Hinweistext).

**Platzhalter:** Die Blade-Tasks (8 Step 6, 9 Step 2, 10 Step 3) beschreiben Struktur und Anker statt vollständigem Markup — bewusst, weil die Vorlage die bestehende `index.blade.php` im selben Verzeichnis ist und abgetippte 400 Zeilen Markup im Plan schneller veralten als sie helfen. Alle PHP- und Test-Blöcke sind vollständig.

**Typ-Konsistenz:** `TargetLight::pipeline()`/`fulfilment()`-Rückgabeschlüssel (`status`, `pct`, `projected`, `target`, `reason`) stimmen zwischen Task 4 und dem Partial in Task 8 Step 5. `YmdDate::daysBetween()` (Task 3) wird in Task 4 mit derselben Signatur benutzt. Row-Shape-Erweiterungen aus Task 7 (`posting_id`, `posting_title`, `posting_closed`, `columns['phase_reached']`) werden in Tasks 8–10 unter genau diesen Namen gelesen. `sumPercent()` (Task 8) heißt in der Gesamt-Zeile identisch.
