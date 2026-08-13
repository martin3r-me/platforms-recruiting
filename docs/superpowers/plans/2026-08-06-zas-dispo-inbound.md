# ZAS Dispo-Inbound Phase 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Push-Endpunkt für ZAS-Dispositionsdaten (Veranstaltungen + eingebuchtes Personal als CSV), Roh-Persistenz ohne Verarbeitung, Sichtungs-UI unter neuer Sidebar-Gruppe „Disposition".

**Architecture:** Neuer Bearer-geschützter POST-Endpunkt neben dem bestehenden Mitarbeiter-Inbound (`routes/zas.php`), Metadaten-Tabelle analog `rec_zas_inbound_files`, Rohdatei 1:1 auf Storage-Disk. Encoding-Normalisierung und Struktur-Erkennung als pure Klassen (unit-testbar ohne Laravel), konsumiert von Controller und Livewire-Sichtung. Spec: `docs/superpowers/specs/2026-08-06-zas-dispo-inbound-design.md`.

**Tech Stack:** Laravel-Modul (platforms-recruiting), Livewire 3, reines PHPUnit 11 (kein Laravel-Bootstrap in Unit-Tests).

## Global Constraints

- **Test-Konvention:** Nur reines PHPUnit ohne Laravel/DB (`tests/bootstrap.php`-Autoloader). Runner: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml` aus dem Modul-Root.
- **Kein Edit außerhalb platforms-recruiting** ohne explizite User-Erlaubnis.
- **Rohdatei bleibt 1:1 erhalten** — Encoding-Normalisierung nur in-memory für Parsing/Anzeige.
- **Antwortverhalten wie `ZasInboundController`:** 201 + JSON-Quittung, `?dry_run=true` → `is_test`, 422 nur bei leerem Body. Kein App-seitiges Größenlimit.
- **Livewire:** geparste Zeilen nie als public property (serialisierter Component-State); `->get()` ohne Paginierung für die Datei-Liste; Detailtabelle Row-Cap 200.
- **Blade:** `@php` nur in Block-Form (`@php ... @endphp`), keine inline `@if` in Component-Attributen.
- **Commits:** Migration+Model als eigener erster Commit (Deploy: Migration-Push zuerst).
- **Branch:** `feat/zas-dispo-inbound` — vorher `git fetch` und Basis == `origin/main` prüfen.

---

### Task 1: Migration + Model `RecZasDispoInboundFile`

**Files:**
- Create: `database/migrations/2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php`
- Create: `src/Models/RecZasDispoInboundFile.php`

**Interfaces:**
- Consumes: nichts (erster Task)
- Produces: Eloquent-Model `Platform\Recruiting\Models\RecZasDispoInboundFile` mit fillable-Feldern `uuid, source, original_filename, disk, stored_path, mime_type, size_bytes, detected_format, delimiter, header_columns (array-Cast), row_count, is_test (bool-Cast), parse_status, notes, received_ip` — Task 4 (Controller) erzeugt Records, Task 5 (UI) liest sie.

Kein Test-Zyklus: Migration/Eloquent sind ohne DB nicht testbar (Modul-Konvention). Reviewer-Gate ist der Diff.

- [ ] **Step 1: Migration schreiben**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadaten-Log fuer von ZAS eingehende Dispo-Dateien (Veranstaltungen +
     * eingebuchtes Personal), POST /recruiting/zas/dispo-inbound.
     *
     * Analog rec_zas_inbound_files (Mitarbeiter-Inbound): Phase 1 nimmt nur an
     * und legt die Rohdatei weg — Verarbeitung (VA-/Einsatz-Models, Matching)
     * kommt in Phase 2, sobald klar ist welche Spalten ZAS liefert.
     *
     * Bewusst team-los: das Bearer-Token traegt keinen Team-Kontext. Nebeneffekt:
     * jeder eingeloggte User jedes Teams sieht die Sichtung — akzeptiert bis zum
     * Rheingedeck-Disponenten-Zugang (siehe Spec, Zielbild Punkt 6).
     *
     * parse_status: Phase 1 schreibt nur viewable | unparseable.
     * `pending` ist Reserve fuer die Verarbeitungs-Pipeline in Phase 2.
     */
    public function up(): void
    {
        Schema::create('rec_zas_dispo_inbound_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Quelle (fix 'zas' — vorbereitet auf weitere Quellen im Staffing-Modul)
            $table->string('source', 32)->default('zas');

            // Herkunft / Roh-Ablage (Rohdatei 1:1 auf dem Disk, hier nur Metadaten)
            $table->string('original_filename')->nullable();
            $table->string('disk');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Erkannte Struktur (Best-Effort beim Empfang)
            $table->string('detected_format', 16)->nullable(); // csv | json | null = unbekannt
            $table->string('delimiter', 8)->nullable();
            $table->json('header_columns')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            // Klassifikation + Status
            $table->boolean('is_test')->default(false);
            $table->string('parse_status', 16)->default('unparseable');
            $table->text('notes')->nullable();

            // Diagnostik
            $table->string('received_ip', 45)->nullable();

            $table->timestamps();

            $table->index('created_at', 'idx_rec_zas_dispo_inbound_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_zas_dispo_inbound_files');
    }
};
```

- [ ] **Step 2: Model schreiben**

```php
<?php

namespace Platform\Recruiting\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine von ZAS eingegangene Dispo-Datei (POST /recruiting/zas/dispo-inbound).
 *
 * Haelt nur Metadaten + erkannte Struktur — die Rohdatei selbst liegt auf
 * dem Storage-Disk unter `disk`/`stored_path`. Siehe ZasDispoInboundController
 * und die Migration create_rec_zas_dispo_inbound_files_table fuer Details.
 *
 * Phase 1: nur annehmen + wegspeichern + sichten. Verarbeitung folgt, sobald
 * klar ist welche Spalten ZAS tatsaechlich liefert.
 */
class RecZasDispoInboundFile extends Model
{
    protected $table = 'rec_zas_dispo_inbound_files';

    protected $fillable = [
        'uuid',
        'source',
        'original_filename',
        'disk',
        'stored_path',
        'mime_type',
        'size_bytes',
        'detected_format',
        'delimiter',
        'header_columns',
        'row_count',
        'is_test',
        'parse_status',
        'notes',
        'received_ip',
    ];

    protected $casts = [
        'header_columns' => 'array',
        'size_bytes'     => 'integer',
        'row_count'      => 'integer',
        'is_test'        => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }
}
```

- [ ] **Step 3: Unit-Suite laufen lassen (Regression, nichts darf brechen)**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS (unverändert zur Basis)

- [ ] **Step 4: Commit (eigener Migration-Commit — wird beim Deploy zuerst gepusht)**

```bash
git add database/migrations/2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php src/Models/RecZasDispoInboundFile.php
git commit -m "feat(recruiting): rec_zas_dispo_inbound_files Tabelle + Model fuer ZAS-Dispo-Inbound"
```

---

### Task 2: `CsvEncodingNormalizer` (pure) + Umstellung `ImportApplicantsCsvService`

**Files:**
- Create: `src/Support/CsvEncodingNormalizer.php`
- Modify: `src/Services/ImportApplicantsCsvService.php:291-295` (Encoding-Block in `readCsv()`)
- Test: `tests/Unit/CsvEncodingNormalizerTest.php`

**Interfaces:**
- Consumes: nichts
- Produces: `Platform\Recruiting\Support\CsvEncodingNormalizer::toUtf8(string $raw): string` — Task 4 (Controller) und Task 5 (UI) rufen sie vor jedem Parsen/Anzeigen auf.

- [ ] **Step 1: Failing Test schreiben**

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\CsvEncodingNormalizer;

class CsvEncodingNormalizerTest extends TestCase
{
    public function test_utf8_passthrough(): void
    {
        $this->assertSame("Müller;Köln\n", CsvEncodingNormalizer::toUtf8("Müller;Köln\n"));
    }

    public function test_strips_utf8_bom(): void
    {
        $this->assertSame('Name;Ort', CsvEncodingNormalizer::toUtf8("\xEF\xBB\xBFName;Ort"));
    }

    public function test_converts_windows_1252(): void
    {
        // "Müller;Köln" in Windows-1252-Bytes (ü=0xFC, ö=0xF6)
        $this->assertSame('Müller;Köln', CsvEncodingNormalizer::toUtf8("M\xFCller;K\xF6ln"));
    }

    public function test_windows_1252_euro_sign(): void
    {
        // 0x80 ist € in Windows-1252 (in ISO-8859-1 nicht belegt)
        $this->assertSame('32 €', CsvEncodingNormalizer::toUtf8("32 \x80"));
    }

    public function test_empty_string(): void
    {
        $this->assertSame('', CsvEncodingNormalizer::toUtf8(''));
    }

    public function test_bom_only(): void
    {
        $this->assertSame('', CsvEncodingNormalizer::toUtf8("\xEF\xBB\xBF"));
    }

    public function test_result_is_valid_utf8(): void
    {
        $out = CsvEncodingNormalizer::toUtf8("Stra\xDFe;\xE4\xF6\xFC");
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertSame('Straße;äöü', $out);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CsvEncodingNormalizerTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\CsvEncodingNormalizer" not found`

- [ ] **Step 3: Klasse implementieren (Verhalten 1:1 aus `ImportApplicantsCsvService::readCsv()`)**

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Normalisiert CSV-/Text-Rohbytes fuer Parsing und Anzeige: erkennt das
 * Encoding (UTF-8 / Windows-1252 / ISO-8859-1 / ASCII, Fallback Windows-1252),
 * konvertiert nach UTF-8 und strippt eine fuehrende UTF-8-BOM.
 *
 * Extrahiert aus ImportApplicantsCsvService::readCsv() — identisches
 * Verhalten. Die Rohdatei auf dem Disk bleibt unveraendert; normalisiert
 * wird nur die In-Memory-Kopie (Windows-1252-Bytes wuerden sonst
 * json_encode in Livewire-Komponenten scheitern lassen → 500).
 */
class CsvEncodingNormalizer
{
    public static function toUtf8(string $raw): string
    {
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true) ?: 'Windows-1252';
        if ($encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }

        return (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss grün sein**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter CsvEncodingNormalizerTest`
Expected: PASS (7 Tests)

- [ ] **Step 5: `ImportApplicantsCsvService::readCsv()` auf die Klasse umstellen**

In `src/Services/ImportApplicantsCsvService.php` diesen Block (Zeilen 291–295):

```php
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true) ?: 'Windows-1252';
        if ($encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
```

ersetzen durch:

```php
        $raw = \Platform\Recruiting\Support\CsvEncodingNormalizer::toUtf8((string) $raw);
```

(Der Docblock von `readCsv()` bleibt korrekt — Verhalten unverändert.)

- [ ] **Step 6: Komplette Unit-Suite laufen lassen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Support/CsvEncodingNormalizer.php src/Services/ImportApplicantsCsvService.php tests/Unit/CsvEncodingNormalizerTest.php
git commit -m "feat(recruiting): CsvEncodingNormalizer als pure Klasse — Import-Service umgestellt"
```

---

### Task 3: `DispoInboundInspector` + `DispoColumnProfiler` (pure) + Tests

**Files:**
- Create: `src/Services/Zas/DispoInboundInspector.php`
- Create: `src/Services/Zas/DispoColumnProfiler.php`
- Test: `tests/Unit/DispoInboundInspectorTest.php`
- Test: `tests/Unit/DispoColumnProfilerTest.php`

**Interfaces:**
- Consumes: UTF-8-Input (Aufrufer normalisiert vorher via `CsvEncodingNormalizer::toUtf8()`)
- Produces:
  - `DispoInboundInspector::detectFormat(string $content): string` — `'csv' | 'json' | 'unknown'`
  - `DispoInboundInspector::inspectCsv(string $utf8Content): array` — `{delimiter: ?string, columns: list<string>, row_count: int, rows: list<array<string,string>>}`
  - `DispoColumnProfiler::profile(array $columns, array $rows, int $maxExamples = 3): array` — Liste von `{column: string, filled: int, fill_ratio: float, examples: list<string>}`
  - Task 4 (Controller) nutzt detectFormat + inspectCsv; Task 5 (UI) nutzt alle drei.

- [ ] **Step 1: Failing Tests schreiben**

`tests/Unit/DispoInboundInspectorTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;

class DispoInboundInspectorTest extends TestCase
{
    private DispoInboundInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new DispoInboundInspector();
    }

    // --- detectFormat ---

    public function test_detects_semicolon_csv(): void
    {
        $this->assertSame('csv', $this->inspector->detectFormat("VaNr;Kunde;Ort\n1;Broich;Koeln"));
    }

    public function test_detects_comma_csv(): void
    {
        $this->assertSame('csv', $this->inspector->detectFormat("VaNr,Kunde\n1,Broich"));
    }

    public function test_detects_json_object(): void
    {
        $this->assertSame('json', $this->inspector->detectFormat('{"events": []}'));
    }

    public function test_detects_json_array_with_leading_whitespace(): void
    {
        $this->assertSame('json', $this->inspector->detectFormat("  \n[{\"id\": 1}]"));
    }

    public function test_invalid_json_without_delimiter_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat('{kaputt'));
    }

    public function test_plain_text_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat("nur eine zeile ohne trennzeichen"));
    }

    public function test_empty_is_unknown(): void
    {
        $this->assertSame('unknown', $this->inspector->detectFormat(''));
        $this->assertSame('unknown', $this->inspector->detectFormat("   \n  "));
    }

    // --- inspectCsv ---

    public function test_inspects_semicolon_csv(): void
    {
        $result = $this->inspector->inspectCsv("VaNr;Kunde;Ort\n1;Broich;Koeln\n2;EFP;Wuppertal\n");

        $this->assertSame(';', $result['delimiter']);
        $this->assertSame(['VaNr', 'Kunde', 'Ort'], $result['columns']);
        $this->assertSame(2, $result['row_count']);
        $this->assertSame(['VaNr' => '1', 'Kunde' => 'Broich', 'Ort' => 'Koeln'], $result['rows'][0]);
    }

    public function test_inspects_crlf_and_quoted_values(): void
    {
        $result = $this->inspector->inspectCsv("Name;Bemerkung\r\n\"Meyer; Klaus\";\"Zeile \"\"zwei\"\"\"\r\n");

        $this->assertSame(1, $result['row_count']);
        $this->assertSame('Meyer; Klaus', $result['rows'][0]['Name']);
    }

    public function test_skips_empty_lines(): void
    {
        $result = $this->inspector->inspectCsv("A;B\n1;2\n\n\n3;4\n");
        $this->assertSame(2, $result['row_count']);
    }

    public function test_row_longer_than_header_gets_col_keys(): void
    {
        $result = $this->inspector->inspectCsv("A;B\n1;2;3\n");
        $this->assertSame(['A' => '1', 'B' => '2', 'col_2' => '3'], $result['rows'][0]);
    }

    public function test_header_only_means_zero_rows(): void
    {
        $result = $this->inspector->inspectCsv("A;B;C\n");
        $this->assertSame(0, $result['row_count']);
        $this->assertSame([], $result['rows']);
    }

    public function test_empty_content(): void
    {
        $result = $this->inspector->inspectCsv('');
        $this->assertNull($result['delimiter']);
        $this->assertSame([], $result['columns']);
        $this->assertSame(0, $result['row_count']);
    }

    public function test_tab_delimiter_detected(): void
    {
        $result = $this->inspector->inspectCsv("A\tB\n1\t2\n");
        $this->assertSame("\t", $result['delimiter']);
        $this->assertSame(['A', 'B'], $result['columns']);
    }
}
```

`tests/Unit/DispoColumnProfilerTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\DispoColumnProfiler;

class DispoColumnProfilerTest extends TestCase
{
    private DispoColumnProfiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new DispoColumnProfiler();
    }

    public function test_counts_filled_and_ratio(): void
    {
        $rows = [
            ['Kunde' => 'Broich', 'Ort' => ''],
            ['Kunde' => 'EFP',    'Ort' => 'Wuppertal'],
            ['Kunde' => '',       'Ort' => '   '],
            ['Kunde' => 'Broich', 'Ort' => 'Koeln'],
        ];

        $profile = $this->profiler->profile(['Kunde', 'Ort'], $rows);

        $this->assertSame('Kunde', $profile[0]['column']);
        $this->assertSame(3, $profile[0]['filled']);
        $this->assertSame(0.75, $profile[0]['fill_ratio']);
        $this->assertSame(2, $profile[1]['filled']); // Whitespace-only zaehlt als leer
    }

    public function test_examples_are_deduped_and_capped(): void
    {
        $rows = [
            ['R' => 'Koch'], ['R' => 'Koch'], ['R' => 'Service'],
            ['R' => 'Logistik'], ['R' => 'Spueler'],
        ];

        $profile = $this->profiler->profile(['R'], $rows);

        $this->assertSame(['Koch', 'Service', 'Logistik'], $profile[0]['examples']); // max 3, dedupliziert
    }

    public function test_missing_column_key_counts_as_empty(): void
    {
        $profile = $this->profiler->profile(['A', 'Fehlt'], [['A' => 'x']]);
        $this->assertSame(0, $profile[1]['filled']);
    }

    public function test_empty_rows_give_zero_ratio(): void
    {
        $profile = $this->profiler->profile(['A'], []);
        $this->assertSame(0, $profile[0]['filled']);
        $this->assertSame(0.0, $profile[0]['fill_ratio']);
    }
}
```

- [ ] **Step 2: Tests laufen lassen — müssen fehlschlagen**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'DispoInboundInspectorTest|DispoColumnProfilerTest'`
Expected: FAIL — Klassen nicht gefunden

- [ ] **Step 3: `DispoInboundInspector` implementieren**

```php
<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Best-Effort-Strukturerkennung fuer Dispo-Inbound-Payloads (Phase 1 Sichtung).
 *
 * Bewusst von ZasInboundController::inspect() dupliziert statt dort extrahiert:
 * der Mitarbeiter-Inbound ist live und bleibt unangetastet. Zusammenfuehrung
 * beim Umzug ins Staffing-Modul (siehe Spec, Zielbild).
 *
 * Erwartet UTF-8-Input — Aufrufer normalisiert vorher via
 * CsvEncodingNormalizer::toUtf8(). Darf nie werfen (Sichtung ist Best-Effort).
 */
class DispoInboundInspector
{
    private const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * Grobformat anhand des Inhalts: 'csv' | 'json' | 'unknown'.
     * Heuristik: valides JSON gewinnt; sonst gilt eine Header-Zeile mit
     * bekanntem Trennzeichen als CSV; alles andere ist unknown (Roh-Ansicht).
     */
    public function detectFormat(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return 'unknown';
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return 'json';
            }
        }

        $firstLine = (string) (preg_split('/\r\n|\r|\n/', $trimmed)[0] ?? '');
        foreach (self::DELIMITERS as $delimiter) {
            if (substr_count($firstLine, $delimiter) > 0) {
                return 'csv';
            }
        }

        return 'unknown';
    }

    /**
     * Parst CSV-Inhalt tolerant: Trennzeichen-Erkennung, Header→Wert-Maps,
     * Laengenunterschiede werden aufgefuellt (keine strikte Validierung).
     *
     * @return array{delimiter: ?string, columns: list<string>, row_count: int, rows: list<array<string, string>>}
     */
    public function inspectCsv(string $utf8Content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            return ['delimiter' => null, 'columns' => [], 'row_count' => 0, 'rows' => []];
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $columns   = array_map('trim', str_getcsv($lines[0], $delimiter, '"', ''));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $values = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            $rows[] = $this->zip($columns, $values);
        }

        return [
            'delimiter' => $delimiter,
            'columns'   => $columns,
            'row_count' => count($rows),
            'rows'      => $rows,
        ];
    }

    /** Wahrscheinlichstes Trennzeichen der Header-Zeile (Default Semikolon). */
    protected function detectDelimiter(string $line): string
    {
        $counts = [];
        foreach (self::DELIMITERS as $delimiter) {
            $counts[$delimiter] = substr_count($line, $delimiter);
        }
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    /**
     * Header-Spalten + Werte zu einer Map; Ueberhang bekommt col_N-Keys.
     *
     * @param list<string> $columns
     * @param list<string> $values
     * @return array<string, string>
     */
    protected function zip(array $columns, array $values): array
    {
        $out = [];
        $count = max(count($columns), count($values));
        for ($i = 0; $i < $count; $i++) {
            $key = ($columns[$i] ?? '') !== '' ? $columns[$i] : ('col_' . $i);
            $out[$key] = $values[$i] ?? '';
        }

        return $out;
    }
}
```

- [ ] **Step 4: `DispoColumnProfiler` implementieren**

```php
<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Spaltenuebersicht fuer die Dispo-Sichtung: Fuellgrad + Beispielwerte je
 * Spalte, gerechnet ueber die GANZE Datei (die Detailtabelle capt bei 200
 * Zeilen, die Uebersicht bewusst nicht — sie ist das Analyse-Werkzeug).
 */
class DispoColumnProfiler
{
    /**
     * @param list<string> $columns
     * @param list<array<string, string>> $rows
     * @return list<array{column: string, filled: int, fill_ratio: float, examples: list<string>}>
     */
    public function profile(array $columns, array $rows, int $maxExamples = 3): array
    {
        $total = count($rows);
        $out = [];

        foreach ($columns as $column) {
            $filled = 0;
            $examples = [];

            foreach ($rows as $row) {
                $value = trim((string) ($row[$column] ?? ''));
                if ($value === '') {
                    continue;
                }
                $filled++;
                if (count($examples) < $maxExamples && !in_array($value, $examples, true)) {
                    $examples[] = $value;
                }
            }

            $out[] = [
                'column'     => $column,
                'filled'     => $filled,
                'fill_ratio' => $total === 0 ? 0.0 : round($filled / $total, 3),
                'examples'   => $examples,
            ];
        }

        return $out;
    }
}
```

- [ ] **Step 5: Tests laufen lassen — müssen grün sein**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --filter 'DispoInboundInspectorTest|DispoColumnProfilerTest'`
Expected: PASS (19 Tests)

- [ ] **Step 6: Commit**

```bash
git add src/Services/Zas/DispoInboundInspector.php src/Services/Zas/DispoColumnProfiler.php tests/Unit/DispoInboundInspectorTest.php tests/Unit/DispoColumnProfilerTest.php
git commit -m "feat(recruiting): DispoInboundInspector + DispoColumnProfiler (pure, getestet)"
```

---

### Task 4: `ZasDispoInboundController` + Route

**Files:**
- Create: `src/Http/Controllers/ZasDispoInboundController.php`
- Modify: `routes/zas.php` (neue Route in der Bearer-Gruppe, Zeilen 26–43)

**Interfaces:**
- Consumes: `RecZasDispoInboundFile` (Task 1), `CsvEncodingNormalizer::toUtf8()` (Task 2), `DispoInboundInspector` (Task 3)
- Produces: `POST /recruiting/zas/dispo-inbound` (Route-Name `recruiting.zas.dispo-inbound`), legt Records + Rohdateien an, die Task 5 anzeigt. Storage-Pfad-Schema: `zas-dispo-inbound/Y/m/d/<uuid>.<ext>` auf Disk `config('recruiting.zas.inbound_disk', 'local')`.

Kein eigener Unit-Test: Der Controller ist bewusst dünn (Request/Storage/Eloquent — ohne Laravel nicht pure testbar); alle Logik steckt in den getesteten Klassen aus Task 2/3. Verifikation: manueller curl nach Deploy (siehe Manuelle Verifikation unten).

- [ ] **Step 1: Controller schreiben**

```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;
use Platform\Recruiting\Support\CsvEncodingNormalizer;
use Symfony\Component\Uid\UuidV7;

/**
 * Eingangs-Endpoint fuer ZAS-Dispositionsdaten: Veranstaltungen inkl. aller
 * Felder + eingebuchtes Personal (Push-Richtung, CSV oder JSON).
 *
 * Phase 1 (bewusst): nur ANNEHMEN + roh wegspeichern + Struktur Best-Effort
 * erkennen. Keine Verarbeitung, kein Matching — Sichtung unter
 * Disposition → ZAS-Eingang. Verarbeitung kommt als Phase 2, wenn klar ist
 * welche Spalten ZAS liefert. Siehe Spec 2026-08-06-zas-dispo-inbound-design.
 *
 * Auth: ZasBearerAuth (gleiches Bearer-Token wie Export + MA-Inbound).
 * ?dry_run=true → is_test, Antwort enthaelt zusaetzlich Spalten + erste Zeile.
 */
class ZasDispoInboundController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $content = $this->extractContent($request, $originalName, $mimeType);

        if ($content === null || $content === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Keine Daten empfangen. Erwartet: Multipart-Feld "file" oder CSV/JSON im Request-Body.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        $isTest = $request->boolean('dry_run');
        $uuid   = (string) UuidV7::generate();

        // Struktur-Erkennung auf der normalisierten Kopie — gespeichert wird
        // trotzdem 1:1 roh (inkl. BOM/Encoding), damit nichts verloren geht.
        $normalized = CsvEncodingNormalizer::toUtf8($content);
        $inspector  = new DispoInboundInspector();
        $format     = $inspector->detectFormat($normalized);

        $structure = ['delimiter' => null, 'columns' => [], 'row_count' => null, 'rows' => []];
        if ($format === 'csv') {
            $structure = $inspector->inspectCsv($normalized);
        }

        $extension = match ($format) {
            'csv'   => 'csv',
            'json'  => 'json',
            default => 'txt',
        };

        $disk = (string) config('recruiting.zas.inbound_disk', 'local');
        $path = 'zas-dispo-inbound/' . now()->format('Y/m/d') . '/' . $uuid . '.' . $extension;
        Storage::disk($disk)->put($path, $content);

        $record = RecZasDispoInboundFile::create([
            'uuid'              => $uuid,
            'source'            => 'zas',
            'original_filename' => $originalName,
            'disk'              => $disk,
            'stored_path'       => $path,
            'mime_type'         => $mimeType,
            'size_bytes'        => strlen($content),
            'detected_format'   => $format === 'unknown' ? null : $format,
            'delimiter'         => $structure['delimiter'],
            'header_columns'    => $structure['columns'] !== [] ? $structure['columns'] : null,
            'row_count'         => $format === 'csv' ? $structure['row_count'] : null,
            'is_test'           => $isTest,
            'parse_status'      => in_array($format, ['csv', 'json'], true) ? 'viewable' : 'unparseable',
            'received_ip'       => $request->ip(),
        ]);

        Log::info('ZAS dispo inbound empfangen', [
            'id'         => $record->id,
            'uuid'       => $record->uuid,
            'is_test'    => $isTest,
            'format'     => $format,
            'size_bytes' => $record->size_bytes,
            'row_count'  => $record->row_count,
        ]);

        // Schlanke Quittung im Echtbetrieb (keine Spaltenwerte nach aussen).
        $payload = [
            'status'      => 'received',
            'id'          => $record->id,
            'uuid'        => $record->uuid,
            'is_test'     => $isTest,
            'received_at' => $record->created_at?->toIso8601String(),
            'size_bytes'  => $record->size_bytes,
            'detected'    => [
                'format'       => $format,
                'delimiter'    => $structure['delimiter'],
                'column_count' => count($structure['columns']),
                'row_count'    => $record->row_count,
            ],
        ];

        // Volle Vorschau nur im Test-Modus (enthaelt echte Datenwerte).
        if ($isTest && $format === 'csv') {
            $payload['detected']['columns'] = $structure['columns'];
            $payload['first_data_row']      = $structure['rows'][0] ?? null;
        }

        return response()->json($payload, 201)->header('Cache-Control', 'no-store');
    }

    /**
     * Holt den Inhalt aus Multipart-Upload (`file`/`csv`) oder Raw-Body.
     * Setzt $originalName / $mimeType per Referenz.
     * (Gleiche Logik wie ZasInboundController — Zusammenfuehrung beim
     * Umzug ins Staffing-Modul.)
     */
    protected function extractContent(Request $request, ?string &$originalName, ?string &$mimeType): ?string
    {
        $originalName = null;
        $mimeType     = null;

        $uploaded = $request->file('file') ?? $request->file('csv');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            $originalName = $uploaded->getClientOriginalName();
            $mimeType     = $uploaded->getClientMimeType();
            $bytes        = file_get_contents($uploaded->getRealPath());
            return $bytes === false ? null : $bytes;
        }

        $raw = $request->getContent();
        if ($raw !== '') {
            $mimeType = $request->header('Content-Type');
        }
        return $raw;
    }
}
```

- [ ] **Step 2: Route ergänzen**

In `routes/zas.php`: Import ergänzen (`use Platform\Recruiting\Http\Controllers\ZasDispoInboundController;` alphabetisch bei den anderen `use`-Zeilen) und in der bestehenden `Route::middleware([ZasBearerAuth::class])->group(...)` nach dem `/inbound`-Eintrag:

```php
    // Dispo-Eingang: Veranstaltungen + eingebuchtes Personal aus ZAS.
    // Phase 1: nur annehmen + roh speichern (Sichtung: Disposition → ZAS-Eingang).
    Route::post('/dispo-inbound', ZasDispoInboundController::class)
        ->name('recruiting.zas.dispo-inbound');
```

- [ ] **Step 3: Unit-Suite laufen lassen (Regression)**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add src/Http/Controllers/ZasDispoInboundController.php routes/zas.php
git commit -m "feat(recruiting): POST /recruiting/zas/dispo-inbound — ZAS-Dispo-CSV annehmen + roh speichern"
```

---

### Task 5: Sichtungs-UI (Liste + Detail) + Sidebar-Gruppe „Disposition"

**Files:**
- Create: `src/Livewire/Dispo/Index.php`
- Create: `src/Livewire/Dispo/Show.php`
- Create: `resources/views/livewire/dispo/index.blade.php`
- Create: `resources/views/livewire/dispo/show.blade.php`
- Modify: `routes/web.php` (zwei Routen)
- Modify: `config/recruiting.php` (Sidebar-Gruppe nach der `Recruiting`-Gruppe, Zeilen 25–38)

**Interfaces:**
- Consumes: `RecZasDispoInboundFile` (Task 1), `CsvEncodingNormalizer::toUtf8()` (Task 2), `DispoInboundInspector` + `DispoColumnProfiler` (Task 3)
- Produces: Routen `recruiting.dispo.index` + `recruiting.dispo.show`; Sidebar-Gruppe „Disposition" → „ZAS-Eingang". Livewire-Komponenten werden über den rekursiven Scan in `registerLivewireComponents()` automatisch registriert — keine manuelle Registrierung.

Kein Unit-Test (Livewire/Eloquent/Storage — per Konvention nicht ohne Laravel testbar; die Parse-/Profil-Logik ist in Task 2/3 getestet). Verifikation: Sichttest nach Deploy.

- [ ] **Step 1: Liste (`Index`) schreiben**

```php
<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Component;
use Platform\Recruiting\Models\RecZasDispoInboundFile;

/**
 * Disposition → ZAS-Eingang: Liste der eingegangenen Dispo-Dateien.
 *
 * Bewusst ungescoped (Tabelle ist team-los, siehe Migration) und ohne
 * Paginierung (Modul-Konvention, Handvoll Dateien pro Tag).
 */
class Index extends Component
{
    public function render()
    {
        $files = RecZasDispoInboundFile::orderByDesc('created_at')->get();

        return view('recruiting::livewire.dispo.index', ['files' => $files])
            ->layout('platform::layouts.app');
    }
}
```

- [ ] **Step 2: Detail (`Show`) schreiben**

```php
<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\DispoColumnProfiler;
use Platform\Recruiting\Services\Zas\DispoInboundInspector;
use Platform\Recruiting\Support\CsvEncodingNormalizer;

/**
 * Disposition → ZAS-Eingang → Detail: eine Dispo-Datei gesichtet.
 *
 * Detailtabelle capt bei ROW_CAP Zeilen (stuendlicher Voll-Bestand mit
 * VAxPerson-Zeilen wird fuenfstellig); die Spaltenuebersicht rechnet
 * bewusst ueber die GANZE Datei.
 */
class Show extends Component
{
    public const ROW_CAP = 200;

    public int $fileId;

    public function mount(int $fileId): void
    {
        $this->fileId = $fileId;
    }

    #[Computed]
    public function file(): RecZasDispoInboundFile
    {
        return RecZasDispoInboundFile::findOrFail($this->fileId);
    }

    /**
     * Geparste Struktur — bewusst KEINE public property: das komplette
     * Zeilen-Array laege sonst bei jedem Request im serialisierten
     * Component-State. #[Computed] ist request-lokal.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function parsed(): array
    {
        $file = $this->file;
        $raw  = (string) Storage::disk($file->disk)->get($file->stored_path);
        $utf8 = CsvEncodingNormalizer::toUtf8($raw);

        $inspector = new DispoInboundInspector();
        $format    = $inspector->detectFormat($utf8);

        if ($format === 'csv') {
            $csv = $inspector->inspectCsv($utf8);

            return [
                'format'    => 'csv',
                'columns'   => $csv['columns'],
                'row_count' => $csv['row_count'],
                'rows'      => array_slice($csv['rows'], 0, self::ROW_CAP),
                'profile'   => (new DispoColumnProfiler())->profile($csv['columns'], $csv['rows']),
            ];
        }

        if ($format === 'json') {
            $pretty = json_encode(
                json_decode($utf8),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            return ['format' => 'json', 'pretty' => (string) $pretty];
        }

        return ['format' => 'unknown', 'raw_excerpt' => mb_substr($utf8, 0, 20000)];
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.show')
            ->layout('platform::layouts.app');
    }
}
```

- [ ] **Step 3: Listen-Blade schreiben**

`resources/views/livewire/dispo/index.blade.php`:

```blade
<div class="p-6 space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold">ZAS-Eingang</h1>
        <span class="text-sm text-gray-500">Eingegangene Dispo-Dateien (Veranstaltungen + eingebuchtes Personal)</span>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2 font-medium">Eingang</th>
                    <th class="px-4 py-2 font-medium">Datei</th>
                    <th class="px-4 py-2 font-medium">Format</th>
                    <th class="px-4 py-2 font-medium">Größe</th>
                    <th class="px-4 py-2 font-medium">Zeilen</th>
                    <th class="px-4 py-2 font-medium">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($files as $file)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap">{{ $file->created_at->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-2">
                            {{ $file->original_filename ?: '(Raw-Body)' }}
                            @if ($file->is_test)
                                <span class="ml-1 rounded bg-yellow-100 px-1.5 py-0.5 text-xs text-yellow-800">Test</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $file->detected_format ?: 'unbekannt' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">{{ number_format($file->size_bytes / 1024, 1, ',', '.') }} KB</td>
                        <td class="px-4 py-2">{{ $file->row_count !== null ? number_format($file->row_count, 0, ',', '.') : '—' }}</td>
                        <td class="px-4 py-2">
                            @if ($file->parse_status === 'viewable')
                                <span class="rounded bg-green-100 px-1.5 py-0.5 text-xs text-green-800">lesbar</span>
                            @else
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-800">{{ $file->parse_status }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('recruiting.dispo.show', ['fileId' => $file->id]) }}" class="text-blue-600 hover:underline">Ansehen</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                            Noch keine Dateien eingegangen. ZAS pusht an <code>POST /recruiting/zas/dispo-inbound</code>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 4: Detail-Blade schreiben**

`resources/views/livewire/dispo/show.blade.php`:

```blade
<div class="p-6 space-y-6">
    @php
        $file = $this->file;
        $parsed = $this->parsed;
    @endphp

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">{{ $file->original_filename ?: 'Dispo-Datei #' . $file->id }}</h1>
            <p class="text-sm text-gray-500">
                Eingang {{ $file->created_at->format('d.m.Y H:i') }} ·
                {{ number_format($file->size_bytes / 1024, 1, ',', '.') }} KB ·
                Format {{ $file->detected_format ?: 'unbekannt' }}
                @if ($file->is_test) · Test-Lieferung @endif
            </p>
        </div>
        <a href="{{ route('recruiting.dispo.index') }}" class="text-sm text-blue-600 hover:underline">← Zurück zur Liste</a>
    </div>

    @if ($parsed['format'] === 'csv')
        {{-- Spaltenübersicht: rechnet über die GANZE Datei --}}
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">
                Spaltenübersicht ({{ count($parsed['columns']) }} Spalten, {{ number_format($parsed['row_count'], 0, ',', '.') }} Zeilen)
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Spalte</th>
                            <th class="px-4 py-2 font-medium">Füllgrad</th>
                            <th class="px-4 py-2 font-medium">Beispielwerte</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($parsed['profile'] as $col)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs">{{ $col['column'] }}</td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ $col['filled'] }} / {{ number_format($parsed['row_count'], 0, ',', '.') }}
                                    ({{ number_format($col['fill_ratio'] * 100, 1, ',', '.') }} %)
                                </td>
                                <td class="px-4 py-2 text-gray-600">{{ implode(' · ', $col['examples']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Datentabelle: Row-Cap, damit fünfstellige Bestände die Seite nicht sprengen --}}
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">
                Daten
                @if ($parsed['row_count'] > count($parsed['rows']))
                    <span class="ml-2 text-sm font-normal text-gray-500">
                        Zeige {{ count($parsed['rows']) }} von {{ number_format($parsed['row_count'], 0, ',', '.') }} Zeilen
                    </span>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            @foreach ($parsed['columns'] as $column)
                                <th class="px-3 py-2 font-medium whitespace-nowrap">{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($parsed['rows'] as $row)
                            <tr>
                                @foreach ($parsed['columns'] as $column)
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $row[$column] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($parsed['format'] === 'json')
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">JSON-Inhalt</div>
            <pre class="overflow-x-auto p-4 text-xs">{{ $parsed['pretty'] }}</pre>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-4 py-3 font-medium">Roh-Ansicht (Format nicht erkannt, erste 20.000 Zeichen)</div>
            <pre class="overflow-x-auto p-4 text-xs whitespace-pre-wrap">{{ $parsed['raw_excerpt'] }}</pre>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Routen ergänzen**

In `routes/web.php` (nach dem `whatsapp-costs`-Block, Zeile ~89):

```php
// Disposition (Zwischenstation im Recruiting-Modul — Zielbild eigenes
// Staffing-Modul, siehe docs/superpowers/specs/2026-08-06-zas-dispo-inbound-design.md)
Route::get('/dispo-inbound', \Platform\Recruiting\Livewire\Dispo\Index::class)
    ->name('recruiting.dispo.index');
Route::get('/dispo-inbound/{fileId}', \Platform\Recruiting\Livewire\Dispo\Show::class)
    ->name('recruiting.dispo.show');
```

- [ ] **Step 6: Sidebar-Gruppe ergänzen**

In `config/recruiting.php` im `'sidebar'`-Array nach der bestehenden `Recruiting`-Gruppe (als zweites Element):

```php
        [
            'group' => 'Disposition',
            'items' => [
                ['label' => 'ZAS-Eingang', 'route' => 'recruiting.dispo.index', 'icon' => 'heroicon-o-inbox-arrow-down'],
            ],
        ],
```

- [ ] **Step 7: Unit-Suite laufen lassen (Regression)**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add src/Livewire/Dispo/ resources/views/livewire/dispo/ routes/web.php config/recruiting.php
git commit -m "feat(recruiting): Sichtungs-UI Disposition → ZAS-Eingang (Liste + Detail mit Row-Cap + Spaltenprofil)"
```

---

## Review-Gate vor Merge

- **Vor dem ff-Merge: Diff des Detail-Blade (`resources/views/livewire/dispo/show.blade.php`) dem User zeigen** (explizite User-Anforderung).
- ff-Merge auf `main` erst nach User-Freigabe (kein gh CLI, keine PRs — Modul-Workflow).

## Deploy-Reihenfolge (nach Freigabe)

1. **Migration-Push zuerst:** nur den Task-1-Commit auf `origin/main` pushen (`git push origin <task1-sha>:main`), meingedeck `composer.lock` bumpen → Forge-Deploy führt die Migration aus.
2. **Feature-Push:** restliche Commits pushen, meingedeck `composer.lock` erneut bumpen → Forge-Deploy.
3. **Kein `queue:restart`** — kein Job-/Queue-Code in diesem Feature.
4. **Manuelle Verifikation:**
   - `curl -X POST 'https://<host>/recruiting/zas/dispo-inbound?dry_run=true' -H "Authorization: Bearer <token>" -H "Content-Type: text/csv" --data-binary $'VaNr;Kunde;ZasPersonalNr\n1;Broich;4711\n'` → 201, `detected.format=csv`, `detected.columns` in der Antwort. (`Content-Type: text/csv` ist Pflicht im Test — ohne den Header schickt curl `application/x-www-form-urlencoded`, was nicht dem entspricht, was ZAS schickt.)
   - **Windows-1252-Datei** (einziger Live-Test, der den `CsvEncodingNormalizer`-Pfad abdeckt):
     ```bash
     printf 'Name;Ort\nM\xFCller;K\xF6ln\n' > /tmp/latin1.csv
     curl -X POST 'https://<host>/recruiting/zas/dispo-inbound' \
       -H "Authorization: Bearer <token>" -H "Content-Type: text/csv" \
       --data-binary @/tmp/latin1.csv
     ```
     Erwartung: 201; Detailseite zeigt „Müller"/„Köln", kein 500.
   - Ohne Token → 401; leerer Body → 422
   - `php artisan route:list --name=recruiting.dispo` (in meingedeck) → exakt `recruiting.dispo.index` + `recruiting.dispo.show`, kein doppeltes Prefix
   - Sidebar „Disposition → ZAS-Eingang": Datei sichtbar (Test-Badge), Detail zeigt Spaltenübersicht + Tabelle
5. **Danach:** Mail an Herrn Michel mit der Live-URL rausgeben (Entwurf liegt im Chat-Verlauf / bei Sebastian).
