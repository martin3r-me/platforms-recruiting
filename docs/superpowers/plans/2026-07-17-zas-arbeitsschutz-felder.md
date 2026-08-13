# ZAS-Arbeitsschutz-Felder (Ersthelfer / Sicherheitsbeauftragter) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Dispatch-Regel:** Die Konstanten-Tabelle aus den Global Constraints MUSS verbatim in JEDEN Task-Prompt kopiert werden (sie steht zusätzlich in jedem Task). Spec: `docs/superpowers/specs/2026-07-17-zas-arbeitsschutz-felder-design.md`.

**Goal:** Drei neue Mitarbeiter-Felder (Ersthelfer-Haken, Ersthelfer-Schein gültig bis, Sicherheitsbeauftragter-Haken) in Datenmodell, ZAS-CSV-Export, ZAS-Inbound-Import (Neuanlagen) und HR-Ansicht inkl. Datumspflicht-Guard.

**Architecture:** Copy des bestehenden Infektionsschutz-Musters (bool + date auf `rec_employees`): Migration + Model-Casts, drei Spalten am Ende von `ZasEmployeeFieldResolver::COLUMNS`, header-basiertes Inbound-Mapping in `ZasInboundRowMapper`, Delta-Trigger im `RecEmployeeExportObserver`, neue HR-only-Feldgruppe in der Livewire-Maske. Einzige Neu-Logik: pure Guard-Klasse `FirstAiderDateGuard` (Datum Pflicht sobald Ersthelfer=Ja) + rote Fehlerbox im Blade.

**Tech Stack:** Laravel-Modul (Namespace `Platform\Recruiting`), Livewire, PHPUnit 11 (pure Unit, kein Laravel-Bootstrap, keine DB).

## Global Constraints

- **Konstanten-Tabelle (verbindlich, deckungsgleich über Migration, Model, Resolver, Observer, Mapper, fieldGroups):**

  | Fachlich | CSV-Header (ZAS) | Model-Attribut / DB-Spalte |
  |---|---|---|
  | Ersthelfer (Haken) | `Ersthelfer` | `is_first_aider` |
  | Ersthelfer gültig bis | `ErsthelferBis` | `first_aider_valid_until` |
  | Sicherheitsbeauftragter (Haken) | `Sicherheitsbeauftragter` | `is_safety_officer` |

- **Branch:** Umsetzung auf `feature/zas-arbeitsschutz-felder`, frisch von `origin/main` (vorher `git fetch`). NICHT auf `feature/nicht-eu-nach-schulung` arbeiten.
- **Nur dieses Modul anfassen:** Alle Pfade relativ zu `/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting`. Keine Edits außerhalb (Core/CRM/HCM tabu).
- **Test-Runner:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (vom Modul-Root; das Modul hat kein eigenes vendor/). Tests sind pure Unit: `tests/bootstrap.php` lädt nur den Modul-Autoloader, es gibt KEINEN Laravel-Container und KEINE DB.
- **String-Konvention:** Deutsche Strings in Code/Labels ASCII-transliteriert (`ue`/`ae`/`oe`), wie bestehend („Aenderungen gespeichert.", Label „Gueltig bis").
- **Blade-Falle:** Keine `@if`/`@php`-Inline-Konstrukte in Komponenten-Attributen; `@php … @endphp` nur in Block-Form. Die neuen UI-Teile nutzen ausschließlich handgeschriebenes Markup (keine x-ui-Komponenten).
- **Commits:** Konvention `feat(recruiting): …`, jede Commit-Message endet mit `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

### Task 1: Branch, Migration, Model

**Konstanten-Tabelle:** `Ersthelfer`→`is_first_aider` (bool) · `ErsthelferBis`→`first_aider_valid_until` (date) · `Sicherheitsbeauftragter`→`is_safety_officer` (bool)

**Files:**
- Create: `database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php`
- Modify: `src/Models/RecEmployee.php` (`$fillable` nach `'work_permit_valid_until',` ~Zeile 90; `$casts` nach `'work_permit_valid_until' => 'date',` ~Zeile 138)

**Interfaces:**
- Consumes: —
- Produces: Model-Attribute `is_first_aider` (boolean-Cast), `first_aider_valid_until` (date-Cast), `is_safety_officer` (boolean-Cast) auf `RecEmployee` — alle späteren Tasks lesen/schreiben diese Attribute.

- [ ] **Step 1: Branch anlegen**

```bash
git fetch origin
git checkout -b feature/zas-arbeitsschutz-felder origin/main
```

Expected: neuer Branch, Basis = origin/main.

- [ ] **Step 2: Migration schreiben**

Datei `database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php` (Vorbild: `2026_05_21_000003_...`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arbeitsschutz-Felder (Kundenwunsch ZAS, 2026-07-17):
     * Ersthelfer (Haken + Schein gueltig bis) und Sicherheitsbeauftragter
     * (nur Haken). Keine Pflichtfelder — alle nullable. Gehen in den
     * ZAS-Export und werden beim Inbound neuer MA uebernommen.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'is_first_aider')) {
                $table->boolean('is_first_aider')->nullable()->comment('Ersthelfer (ZAS: Ersthelfer)');
            }
            if (!Schema::hasColumn('rec_employees', 'first_aider_valid_until')) {
                $table->date('first_aider_valid_until')->nullable()->comment('Ersthelfer-Schein gueltig bis (ZAS: ErsthelferBis)');
            }
            if (!Schema::hasColumn('rec_employees', 'is_safety_officer')) {
                $table->boolean('is_safety_officer')->nullable()->comment('Sicherheitsbeauftragter (ZAS: Sicherheitsbeauftragter)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            foreach (['is_first_aider', 'first_aider_valid_until', 'is_safety_officer'] as $col) {
                if (Schema::hasColumn('rec_employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
```

- [ ] **Step 3: Model erweitern**

In `src/Models/RecEmployee.php` im `$fillable`-Array direkt nach `'work_permit_valid_until',` einfügen:

```php
        // Arbeitsschutz
        'is_first_aider',
        'first_aider_valid_until',
        'is_safety_officer',
```

Im `$casts`-Array direkt nach `'work_permit_valid_until'               => 'date',` einfügen:

```php
        // Arbeitsschutz
        'is_first_aider'                        => 'boolean',
        'first_aider_valid_until'               => 'date',
        'is_safety_officer'                     => 'boolean',
```

- [ ] **Step 4: Syntax prüfen + bestehende Tests grün**

```bash
php -l database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php
php -l src/Models/RecEmployee.php
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected` (2×), alle Tests PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php src/Models/RecEmployee.php
git commit -m "feat(recruiting): Arbeitsschutz-Felder am Mitarbeiter (Ersthelfer, ErsthelferBis, Sicherheitsbeauftragter)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: FirstAiderDateGuard (pure Datumspflicht-Prüfung)

**Konstanten-Tabelle:** `Ersthelfer`→`is_first_aider` (bool) · `ErsthelferBis`→`first_aider_valid_until` (date) · `Sicherheitsbeauftragter`→`is_safety_officer` (bool)

**Files:**
- Create: `src/Support/FirstAiderDateGuard.php`
- Test: `tests/Unit/FirstAiderDateGuardTest.php`

**Interfaces:**
- Consumes: —
- Produces: `FirstAiderDateGuard::error(mixed $isFirstAider, mixed $validUntil): ?string` — nimmt die ROHEN Formular-Strings aus wire:model (Bool-Select liefert `''`/`'1'`/`'0'`), liefert Fehlertext oder `null` wenn ok. Task 5 ruft genau diese Signatur in `saveAll()` auf.

- [ ] **Step 1: Failing Test schreiben**

Datei `tests/Unit/FirstAiderDateGuardTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\FirstAiderDateGuard;

class FirstAiderDateGuardTest extends TestCase
{
    public function test_blocks_truthy_flag_without_date(): void
    {
        foreach (['1', 'true', 'ja', 'Ja', ' 1 '] as $flag) {
            $error = FirstAiderDateGuard::error($flag, '');
            $this->assertNotNull($error, "Flag '$flag' ohne Datum muss blocken");
            $this->assertStringContainsString('Ersthelfer', $error);
        }
    }

    public function test_blocks_whitespace_only_date(): void
    {
        $this->assertNotNull(FirstAiderDateGuard::error('1', '   '));
    }

    public function test_passes_with_date(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('1', '2027-03-01'));
        $this->assertNull(FirstAiderDateGuard::error('ja', '2027-03-01'));
    }

    public function test_passes_when_not_set(): void
    {
        $this->assertNull(FirstAiderDateGuard::error('0', ''));
        $this->assertNull(FirstAiderDateGuard::error('nein', ''));
        $this->assertNull(FirstAiderDateGuard::error('', ''));
        $this->assertNull(FirstAiderDateGuard::error(null, null));
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss failen**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FirstAiderDateGuardTest
```

Expected: FAIL/Error — `Class "Platform\Recruiting\Support\FirstAiderDateGuard" not found`.

- [ ] **Step 3: Implementierung**

Datei `src/Support/FirstAiderDateGuard.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Datumspflicht-Kopplung Ersthelfer: der Endzustand "Ersthelfer=Ja ohne
 * Bis-Datum" darf die HR-Maske nicht verlassen (Spec 2026-07-17).
 * Pure Funktion auf den rohen Formularwerten (Strings aus wire:model,
 * Bool-Select liefert ''/'1'/'0') — kein Laravel, unit-testbar.
 *
 * Endzustands-Pruefung, bewusst: blockt auch Saves, bei denen nur ein
 * anderes Feld geaendert wurde — so wird ein per lenientem ZAS-Import
 * entstandener "Ja ohne Datum"-MA beim naechsten Edit repariert.
 */
class FirstAiderDateGuard
{
    /** Fehlertext oder null wenn der Zustand konsistent ist. */
    public static function error(mixed $isFirstAider, mixed $validUntil): ?string
    {
        $flag = mb_strtolower(trim((string) ($isFirstAider ?? '')));
        $isSet = in_array($flag, ['1', 'true', 'ja'], true);
        $date = trim((string) ($validUntil ?? ''));

        if (!$isSet || $date !== '') {
            return null;
        }

        return 'Ersthelfer-Datum fehlt: "Ersthelfer-Schein gueltig bis" ist Pflicht, sobald Ersthelfer auf Ja steht. Es wurde nichts gespeichert.';
    }
}
```

- [ ] **Step 4: Test laufen lassen — muss passen**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter FirstAiderDateGuardTest
```

Expected: PASS (4 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/FirstAiderDateGuard.php tests/Unit/FirstAiderDateGuardTest.php
git commit -m "feat(recruiting): FirstAiderDateGuard — pure Datumspflicht-Pruefung fuer Ersthelfer

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: ZAS-Export (Resolver + Delta-Trigger)

**Konstanten-Tabelle:** `Ersthelfer`→`is_first_aider` (bool) · `ErsthelferBis`→`first_aider_valid_until` (date) · `Sicherheitsbeauftragter`→`is_safety_officer` (bool)

**Files:**
- Modify: `src/Services/Zas/ZasEmployeeFieldResolver.php` (`COLUMNS` ~Zeile 101, `resolveColumn()` ~Zeile 250)
- Modify: `src/Observers/RecEmployeeExportObserver.php` (`RELEVANT_EMPLOYEE_FIELDS` ~Zeile 85-91)
- Test: `tests/Unit/ZasArbeitsschutzExportTest.php`

**Interfaces:**
- Consumes: Model-Attribute aus Task 1 (`is_first_aider` bool-Cast, `first_aider_valid_until` date-Cast, `is_safety_officer` bool-Cast).
- Produces: CSV-Spalten `Ersthelfer`, `ErsthelferBis`, `Sicherheitsbeauftragter` als letzte drei Einträge von `ZasEmployeeFieldResolver::COLUMNS`; Werte via bestehender Helper `boolLabel()` („Ja"/„Nein", nie leer) und `formatDate()` (`d.m.Y`, null→leer). Task 4 spiegelt exakt diese Header.

- [ ] **Step 1: Failing Test schreiben**

Datei `tests/Unit/ZasArbeitsschutzExportTest.php`. Zwei Mechanik-Zwänge (Spec): `resolveColumn()` ist protected und `resolve()` zieht DB (`loadMissing`) → Test-Subklasse; Attribute via `setRawAttributes()` setzen — `fill()`/Konstruktor würde beim date-Cast `getDateFormat()` → `getConnection()` aufrufen und ohne Connection-Resolver fatalen. Der Resolver-Konstruktor verlangt einen `ZasSignedUrlGenerator`, der für Arbeitsschutz-Spalten ungenutzt ist → leerer Override-Konstruktor.

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

class ZasArbeitsschutzExportTest extends TestCase
{
    /**
     * Testbare Resolver-Variante: resolveColumn public; leerer Konstruktor,
     * weil ZasSignedUrlGenerator fuer Arbeitsschutz-Spalten ungenutzt ist.
     */
    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}
            public function col(RecEmployee $e, string $column): ?string
            {
                return $this->resolveColumn($e, null, $column);
            }
        };
    }

    /** setRawAttributes statt fill(): der Schreib-Cast braeuchte eine DB-Connection. */
    private function employee(array $attributes): RecEmployee
    {
        $e = new RecEmployee();
        $e->setRawAttributes($attributes);
        return $e;
    }

    public function test_columns_end_with_arbeitsschutz_headers(): void
    {
        $this->assertSame(
            ['Ersthelfer', 'ErsthelferBis', 'Sicherheitsbeauftragter'],
            array_slice(ZasEmployeeFieldResolver::COLUMNS, -3),
        );
    }

    public function test_bool_columns_render_ja_nein(): void
    {
        $r = $this->resolver();
        $this->assertSame('Ja', $r->col($this->employee(['is_first_aider' => 1]), 'Ersthelfer'));
        $this->assertSame('Nein', $r->col($this->employee(['is_first_aider' => 0]), 'Ersthelfer'));
        $this->assertSame('Nein', $r->col($this->employee([]), 'Ersthelfer'));
        $this->assertSame('Ja', $r->col($this->employee(['is_safety_officer' => 1]), 'Sicherheitsbeauftragter'));
        $this->assertSame('Nein', $r->col($this->employee([]), 'Sicherheitsbeauftragter'));
    }

    public function test_date_column_renders_dmy_or_empty(): void
    {
        $r = $this->resolver();
        $this->assertSame('01.03.2027', $r->col($this->employee(['first_aider_valid_until' => '2027-03-01']), 'ErsthelferBis'));
        // null ist korrekt: resolve() koalesziert jede Spalte mit
        // `(string) (resolveColumn(...) ?? '')` (Resolver:138), bevor der
        // ZasCsvBuilder implodiert — null erreicht den CSV-Pfad nie.
        $this->assertNull($r->col($this->employee([]), 'ErsthelferBis'));
    }

    public function test_observer_triggers_on_arbeitsschutz_fields(): void
    {
        foreach (['is_first_aider', 'first_aider_valid_until', 'is_safety_officer'] as $field) {
            $this->assertContains($field, RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss failen**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasArbeitsschutzExportTest
```

Expected: FAIL — `test_columns_end_with_arbeitsschutz_headers` (Array-Diff), `test_bool_columns...`/`test_date_column...` mit `UnhandledMatchError`, `test_observer...` (assertContains).

- [ ] **Step 3: Implementierung**

In `src/Services/Zas/ZasEmployeeFieldResolver.php`, Konstante `COLUMNS`: nach `'UUID', 'ZasPersonalNr',` (letzte Zeile vor `];`) einfügen — verifiziert: `ZasPersonalNr` IST der letzte Eintrag, Anhängen = echtes Ende = abwärtskompatibel falls ZAS positionsbasiert parst:

```php
        // Arbeitsschutz (ans Ende — ZAS parst ggf. positionsbasiert,
        // Einschub in der Mitte wuerde Folgespalten verschieben)
        'Ersthelfer', 'ErsthelferBis', 'Sicherheitsbeauftragter',
```

In `resolveColumn()`: nach der Zeile `'ZasPersonalNr' => $employee->personnel_number,` (vor `};`) einfügen:

```php
            // Arbeitsschutz
            'Ersthelfer'              => $this->boolLabel($employee->is_first_aider),
            'ErsthelferBis'           => $this->formatDate($employee->first_aider_valid_until),
            'Sicherheitsbeauftragter' => $this->boolLabel($employee->is_safety_officer),
```

In `src/Observers/RecEmployeeExportObserver.php`, Konstante `RELEVANT_EMPLOYEE_FIELDS`: nach dem Block `// Gesundheit` (`'infection_protection_first_issued_at',`) einfügen — Attributnamen, NICHT CSV-Header (Konstante wird gegen `getChanges()`-Keys geschnitten):

```php
        // Arbeitsschutz
        'is_first_aider', 'first_aider_valid_until', 'is_safety_officer',
```

- [ ] **Step 4: Test laufen lassen — muss passen**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasArbeitsschutzExportTest
```

Expected: PASS (4 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/ZasEmployeeFieldResolver.php src/Observers/RecEmployeeExportObserver.php tests/Unit/ZasArbeitsschutzExportTest.php
git commit -m "feat(recruiting): ZAS-Export Arbeitsschutz-Spalten (Ersthelfer/ErsthelferBis/Sicherheitsbeauftragter) + Delta-Trigger

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: ZAS-Inbound-Mapping (Neuanlagen)

**Konstanten-Tabelle:** `Ersthelfer`→`is_first_aider` (bool) · `ErsthelferBis`→`first_aider_valid_until` (date) · `Sicherheitsbeauftragter`→`is_safety_officer` (bool)

**Files:**
- Modify: `src/Services/Zas/ZasInboundRowMapper.php` (`DATES` ~Zeile 29-36, `BOOLS` ~Zeile 44-46, Cross-Field-Block in `map()` nach der BOOLS-Schleife ~Zeile 100)
- Test: `tests/Unit/ZasArbeitsschutzInboundTest.php`

**Interfaces:**
- Consumes: CSV-Header aus Task 3 (`Ersthelfer`, `ErsthelferBis`, `Sicherheitsbeauftragter`); Model-Attribute aus Task 1.
- Produces: `map()` liefert in `['employee']` die Keys `is_first_aider` (bool), `first_aider_valid_until` (`Y-m-d`-String), `is_safety_officer` (bool) — nur wenn die CSV-Zelle nicht leer ist; plus Kopplungs-Warnung in `['warnings']` bei „Ja ohne gültiges Datum". Der `ZasInboundEmployeeImporter` konsumiert das unverändert (kein Edit dort nötig).

- [ ] **Step 1: Failing Test schreiben**

Datei `tests/Unit/ZasArbeitsschutzInboundTest.php`. `ZasLookupReverseResolver` hat keinen Konstruktor und trifft die DB nur bei `resolve()`-Aufrufen — Arbeitsschutz-Spalten berühren keine Lookups, reale Instanz ist daher safe:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

class ZasArbeitsschutzInboundTest extends TestCase
{
    private function map(array $row): array
    {
        return (new ZasInboundRowMapper(new ZasLookupReverseResolver()))->map($row);
    }

    public function test_maps_bools_and_date(): void
    {
        $res = $this->map([
            'Ersthelfer'              => 'Ja',
            'ErsthelferBis'           => '01.03.2027',
            'Sicherheitsbeauftragter' => 'Nein',
        ]);
        $this->assertTrue($res['employee']['is_first_aider']);
        $this->assertSame('2027-03-01', $res['employee']['first_aider_valid_until']);
        $this->assertFalse($res['employee']['is_safety_officer']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_empty_values_stay_unset(): void
    {
        $res = $this->map(['Ersthelfer' => '', 'ErsthelferBis' => '', 'Sicherheitsbeauftragter' => '']);
        $this->assertArrayNotHasKey('is_first_aider', $res['employee']);
        $this->assertArrayNotHasKey('first_aider_valid_until', $res['employee']);
        $this->assertArrayNotHasKey('is_safety_officer', $res['employee']);
        $this->assertSame([], $res['warnings']);
    }

    public function test_warns_when_ersthelfer_ja_without_date(): void
    {
        $res = $this->map(['Ersthelfer' => 'Ja', 'ErsthelferBis' => '']);
        $this->assertTrue($res['employee']['is_first_aider']);
        $this->assertArrayNotHasKey('first_aider_valid_until', $res['employee']);
        $this->assertStringContainsString('Ersthelfer=Ja ohne', implode(' | ', $res['warnings']));
    }

    public function test_warns_when_ersthelfer_ja_with_invalid_date(): void
    {
        $res = $this->map(['Ersthelfer' => 'Ja', 'ErsthelferBis' => '31.02.2027']);
        $this->assertArrayNotHasKey('first_aider_valid_until', $res['employee']);
        $warnings = implode(' | ', $res['warnings']);
        // Bestehende Datums-Warnung UND neue Kopplungs-Warnung
        $this->assertStringContainsString('kein gueltiges Datum', $warnings);
        $this->assertStringContainsString('Ersthelfer=Ja ohne', $warnings);
    }

    public function test_no_warning_when_nein(): void
    {
        $res = $this->map(['Ersthelfer' => 'Nein']);
        $this->assertFalse($res['employee']['is_first_aider']);
        $this->assertSame([], $res['warnings']);
    }
}
```

- [ ] **Step 2: Test laufen lassen — muss failen**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasArbeitsschutzInboundTest
```

Expected: FAIL — `is_first_aider`-Key fehlt in `$res['employee']` (undefined array key / assertTrue auf fehlendem Key).

- [ ] **Step 3: Implementierung**

In `src/Services/Zas/ZasInboundRowMapper.php`:

Konstante `DATES` — Eintrag ergänzen (nach `'Eintritt' => 'employed_since',`):

```php
        'ErsthelferBis' => 'first_aider_valid_until',
```

Konstante `BOOLS` — Einträge ergänzen (nach `'PKW' => 'has_car', 'EUBuerger' => 'is_eu_citizen',`):

```php
        'Ersthelfer' => 'is_first_aider', 'Sicherheitsbeauftragter' => 'is_safety_officer',
```

In `map()` direkt NACH der `foreach (self::BOOLS …)`-Schleife (vor der `LOOKUPS`-Schleife) einfügen:

```php
        // Arbeitsschutz-Kopplung: Ersthelfer=Ja verlangt fachlich ein
        // Bis-Datum. Lenient: trotzdem importieren, aber warnen — der
        // Datumspflicht-Guard der HR-Maske erzwingt die Reparatur beim
        // naechsten Edit. array_key_exists ist korrekt: die DATES-Schleife
        // setzt den Ziel-Key bei leerem UND bei unparsebarem Datum gar
        // nicht (nur `if ($d !== null)` schreibt, RowMapper:82-87).
        if (($employee['is_first_aider'] ?? false) === true
            && !array_key_exists('first_aider_valid_until', $employee)) {
            $warnings[] = "first_aider_valid_until: Ersthelfer=Ja ohne gueltiges Bis-Datum — bitte in der HR-Ansicht nachpflegen";
        }
```

- [ ] **Step 4: Test laufen lassen — muss passen**

```bash
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ZasArbeitsschutzInboundTest
```

Expected: PASS (5 Tests).

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/ZasInboundRowMapper.php tests/Unit/ZasArbeitsschutzInboundTest.php
git commit -m "feat(recruiting): ZAS-Inbound mappt Arbeitsschutz-Spalten inkl. Kopplungs-Warnung (Ja ohne Datum)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: HR-Ansicht (Feldgruppe, Guard, rote Fehlerbox)

**Konstanten-Tabelle:** `Ersthelfer`→`is_first_aider` (bool) · `ErsthelferBis`→`first_aider_valid_until` (date) · `Sicherheitsbeauftragter`→`is_safety_officer` (bool)

**Files:**
- Modify: `src/Livewire/Employees/Show.php` (Property ~Zeile 29, `fieldGroups()` nach Gruppe `'ZAS / Abrechnung (HR-only)'` ~Zeile 234-237, `saveAll()` ~Zeile 330-410)
- Modify: `resources/views/livewire/employees/show.blade.php` (nach der grünen `$flash`-Box ~Zeile 61-66)

**Interfaces:**
- Consumes: `FirstAiderDateGuard::error(mixed, mixed): ?string` aus Task 2; Model-Attribute aus Task 1.
- Produces: HR-only-Gruppe „Arbeitsschutz (HR-only)" (Gelb-Rendering kommt automatisch: Blade prüft `str_contains($section, 'HR-only')`), Property `public ?string $flashError` + rote Fehlerbox.

- [ ] **Step 1: Property + Feldgruppe in Show.php**

Nach `public ?string $flash = null;` einfügen:

```php
    public ?string $flashError = null;
```

In `fieldGroups()` nach der kompletten Gruppe `'ZAS / Abrechnung (HR-only)' => [ … ],` (vor dem schließenden `];`) einfügen — Labels ASCII (`gueltig`):

```php
            'Arbeitsschutz (HR-only)' => [
                'is_first_aider'          => ['type' => 'bool', 'label' => 'Ersthelfer'],
                'first_aider_valid_until' => ['type' => 'date', 'label' => 'Ersthelfer-Schein gueltig bis'],
                'is_safety_officer'       => ['type' => 'bool', 'label' => 'Sicherheitsbeauftragter'],
            ],
```

- [ ] **Step 2: Guard in saveAll()**

Import ergänzen (bei den anderen `use`-Statements der Datei):

```php
use Platform\Recruiting\Support\FirstAiderDateGuard;
```

In `saveAll()` direkt nach dem Employee-Null-Check (`if (!$employee) { return; }`) einfügen:

```php
        // Datumspflicht Ersthelfer (Endzustands-Pruefung, Spec 2026-07-17):
        // blockt JEDEN Save solange Ersthelfer=Ja ohne Datum — auch bei
        // unrelated Edits, damit lenient importierte MA repariert werden.
        // Early-Return OHNE loadFieldValues(): Eingaben bleiben stehen.
        $guardError = FirstAiderDateGuard::error(
            $this->fieldValues['is_first_aider'] ?? null,
            $this->fieldValues['first_aider_valid_until'] ?? null,
        );
        if ($guardError !== null) {
            $this->flashError = $guardError;
            $this->flash = null;
            return;
        }
        $this->flashError = null;
```

Wichtig: KEINE weiteren Änderungen an `saveAll()` — `loadFieldValues()` bleibt ausschließlich im Erfolgspfad (Zeile ~408), damit die wire:model-Werte den Fehlerfall überleben.

- [ ] **Step 3: Rote Fehlerbox im Blade**

In `resources/views/livewire/employees/show.blade.php` direkt NACH dem bestehenden `@if($flash) … @endif`-Block (grüne Box, ~Zeile 61-66) einfügen — gespiegeltes handgeschriebenes Markup, KEINE x-ui-Komponente, keine Inline-Bedingungen in Attributen:

```blade
            @if($flashError)
                <div class="mt-3 p-2 bg-red-50 border border-red-200 rounded text-xs text-red-800 inline-flex items-center gap-2">
                    @svg('heroicon-o-exclamation-circle', 'w-4 h-4')
                    {{ $flashError }}
                </div>
            @endif
```

- [ ] **Step 4: Syntax + kompletter Test-Lauf**

```bash
php -l src/Livewire/Employees/Show.php
../../../meingedeck/vendor/bin/phpunit -c phpunit.xml
```

Expected: `No syntax errors detected`; ALLE Tests PASS (inkl. der neuen aus Task 2-4). (Blade ist nicht `php -l`-prüfbar — Guard-Logik ist über `FirstAiderDateGuardTest` abgedeckt, Rendering ist Teil des Live-Checks.)

- [ ] **Step 5: Commit**

```bash
git add src/Livewire/Employees/Show.php resources/views/livewire/employees/show.blade.php
git commit -m "feat(recruiting): HR-Ansicht Arbeitsschutz-Gruppe + Ersthelfer-Datumspflicht mit roter Fehlerbox

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

## Nach der Umsetzung (nicht Teil der Tasks — Freigabe-Workflow)

1. Review durch Sebastian, dann ff-Merge auf `main` + Push (kein gh CLI / keine PRs per CLI).
2. `meingedeck` composer.lock bumpen (Pflicht, sonst nicht live) — VOR dem Live-Check.
3. Kein `queue:restart` nötig (Export/Inbound laufen synchron im Request).
4. **Live-Check laut Spec:** Gruppe „Arbeitsschutz (HR-only)" erscheint gelb; Bool-Select + Date-Input funktionieren; Ja ohne Datum → ROTE Box mit Ersthelfer-Datum-Meldung, nichts gespeichert, Eingaben bleiben stehen; Datum nachtragen → grüne Box.
5. Mail an Markus/Olaf: Spaltenüberschriften `Ersthelfer` / `ErsthelferBis` / `Sicherheitsbeauftragter` (am Datei-Ende angehängt), `ErsthelferBis` = Gültig-bis-Datum des Ersthelfer-Scheins; Phase-2-Frage (Rückfluss zu Bestands-MA) stellen; Vollzugsmeldung.
