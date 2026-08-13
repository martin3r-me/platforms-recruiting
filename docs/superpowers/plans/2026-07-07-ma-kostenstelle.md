# MA-Kostenstelle (ZAS-Import + Export-Vorrang) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kostenstelle aus der ZAS-CSV am Mitarbeiter speichern (`rec_employees.cost_center`), im HR-Backend pflegbar machen und im ZAS-Export mit Vorrang MA-Feld → Fallback Stelle ausliefern.

**Architecture:** Neue nullable String-Spalte am MA. Der Inbound-Mapper mappt `Kostenstelle` direkt (statt Warnung). Der Export-Resolver löst mit Kaskade auf: MA-Feld gewinnt (gesetzt = nicht leer), sonst `position->cost_center` wie bisher — dadurch null Verhaltensänderung für bestehende Recruiting-MA. Observer erfasst HR-Änderungen am neuen Feld für den Update-Export.

**Tech Stack:** Laravel (PHP 8.4), bestehende ZAS-Services unter `src/Services/Zas/`.

## Global Constraints

- **Keine PHPUnit-Harness** im Modul (bewusst). Verifikation = `php -l` + Standalone-PHP-Skript für die Mapper-Logik (Muster: Bootstrap via meingedeck-Autoloader `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/autoload.php`, dann `require` der Branch-Klassen).
- Arbeit auf Branch `feat/ma-kostenstelle`, am Ende Merge → main + meingedeck-Bump + Server-Migration.
- MA-Feld ist `string(32)` (NICHT integer wie an der Stelle) — externe Quelle, führende Nullen/Nicht-Numerisches dürfen nicht crashen. Export castet zu String.
- Feld erscheint NUR im HR-Backend (`fieldGroups()` in `Livewire/Employees/Show.php`), NIEMALS im MA-Portal (`editableFieldGroups()` in `RecEmployee` nicht anfassen).
- Spaltenreihenfolge/Header des ZAS-Exports bleiben unverändert (nur die Wert-Auflösung von `Kostenstelle` ändert sich).

---

### Task 1: Datenmodell — Spalte, fillable, Observer

**Files:**
- Create: `database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php`
- Modify: `src/Models/RecEmployee.php` (fillable)
- Modify: `src/Observers/RecEmployeeExportObserver.php` (RELEVANT_EMPLOYEE_FIELDS)

**Interfaces:**
- Produces: Spalte `rec_employees.cost_center` (string(32), nullable). Fillable + Export-Marker-relevant.

- [ ] **Step 1: Migration anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kostenstelle direkt am Mitarbeiter (Quelle: ZAS-Import oder HR-Pflege).
     *
     * Export-Vorrang: MA-Feld gewinnt, sonst Fallback position->cost_center
     * (siehe ZasEmployeeFieldResolver). Bewusst string statt integer (wie an
     * der Stelle): externe ZAS-Werte duerfen nicht an Typ-Zwang scheitern,
     * fuehrende Nullen bleiben erhalten.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'cost_center')) {
                $table->string('cost_center', 32)->nullable()->after('personnel_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'cost_center')) {
                $table->dropColumn('cost_center');
            }
        });
    }
};
```

- [ ] **Step 2: fillable ergänzen**

In `src/Models/RecEmployee.php` im `$fillable`-Array nach `'personnel_number',` einfügen:
```php
        'cost_center',
```

- [ ] **Step 3: Observer — Feld export-relevant machen**

In `src/Observers/RecEmployeeExportObserver.php` direkt nach der Zeile `public const RELEVANT_EMPLOYEE_FIELDS = [` einfügen:
```php
        // Kostenstelle (MA-eigenes Feld; Vorrang vor Stelle im Export)
        'cost_center',
```
(Wirkt nur bei `updated` — die Import-Anlage setzt das Feld im `create()` und triggert den Observer nicht; der bestehende Export-Schleifen-Schutz bleibt unberührt.)

- [ ] **Step 4: Lint + Commit**

Run: `php -l database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php && php -l src/Models/RecEmployee.php && php -l src/Observers/RecEmployeeExportObserver.php`
Expected: 3× „No syntax errors detected".

```bash
git add database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php src/Models/RecEmployee.php src/Observers/RecEmployeeExportObserver.php
git commit -m "feat(zas): cost_center-Spalte am MA + Export-Marker-Relevanz"
```

---

### Task 2: Import-Mapping + Export-Vorrang

**Files:**
- Modify: `src/Services/Zas/ZasInboundRowMapper.php`
- Modify: `src/Services/Zas/ZasEmployeeFieldResolver.php`

**Interfaces:**
- Consumes: Spalte `cost_center` (Task 1).
- Produces: `map()` liefert `employee['cost_center']` aus CSV-`Kostenstelle`; Export-`Kostenstelle` = MA-Feld → Fallback Stelle.

- [ ] **Step 1: Mapper — Kostenstelle mappen statt warnen**

In `src/Services/Zas/ZasInboundRowMapper.php`, in der `DIRECT`-Konstante nach `'HemdGroesse' => 'shirt_size',` einfügen:
```php
        'Kostenstelle' => 'cost_center',
```
Und den Warnungs-Block ersatzlos ENTFERNEN:
```php
        // Ignorierte Felder mit Inhalt vermerken (keine Ziel-Spalte)
        if ($get('Kostenstelle') !== '') {
            $warnings[] = "Kostenstelle '{$get('Kostenstelle')}' ignoriert (keine Positions-Zuordnung)";
        }
```

- [ ] **Step 2: Export — Vorrang-Kaskade**

In `src/Services/Zas/ZasEmployeeFieldResolver.php` die Zeile
```php
            'Kostenstelle'        => $employee->position?->cost_center !== null ? (string) $employee->position->cost_center : null,
```
ersetzen durch:
```php
            // Vorrang: MA-eigenes Feld (ZAS-Import / HR-Pflege) — sonst Stelle.
            'Kostenstelle'        => ($employee->cost_center !== null && $employee->cost_center !== '')
                ? (string) $employee->cost_center
                : ($employee->position?->cost_center !== null ? (string) $employee->position->cost_center : null),
```

- [ ] **Step 3: Lint**

Run: `php -l src/Services/Zas/ZasInboundRowMapper.php && php -l src/Services/Zas/ZasEmployeeFieldResolver.php`
Expected: 2× „No syntax errors detected".

- [ ] **Step 4: Mapper-Verifikation (Standalone)**

`/tmp/verify_cost_center.php` anlegen und ausführen:

```php
<?php
require '/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/autoload.php';
$src = '/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/src/Services/Zas/';
require $src.'ZasLookupReverseResolver.php';
require $src.'ZasInboundRowMapper.php';

use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;

$resolver = new class extends ZasLookupReverseResolver {
    protected function loadPairs(string $n): array { return []; }
};
$mapper = new ZasInboundRowMapper($resolver);

$out1 = $mapper->map(['Name'=>'Test','Kostenstelle'=>'102']);
$out2 = $mapper->map(['Name'=>'Test','Kostenstelle'=>'0102']);
$out3 = $mapper->map(['Name'=>'Test','Kostenstelle'=>'']);

$noKsWarn = fn($o) => !array_filter($o['warnings'], fn($w) => str_contains($w, 'Kostenstelle'));
$cases = [
    ['gemappt "102"',          ($out1['employee']['cost_center'] ?? null) === '102'],
    ['fuehrende Null erhalten', ($out2['employee']['cost_center'] ?? null) === '0102'],
    ['leer -> nicht gesetzt',   !array_key_exists('cost_center', $out3['employee'])],
    ['keine Kostenstellen-Warnung mehr', $noKsWarn($out1) && $noKsWarn($out3)],
];
$ok = true;
foreach ($cases as [$name, $pass]) { $ok = $ok && $pass; echo ($pass?'PASS':'FAIL')." — $name\n"; }
exit($ok ? 0 : 1);
```

Run: `php /tmp/verify_cost_center.php; echo "exit=$?"; rm /tmp/verify_cost_center.php`
Expected: 4× PASS, `exit=0`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Zas/ZasInboundRowMapper.php src/Services/Zas/ZasEmployeeFieldResolver.php
git commit -m "feat(zas): Kostenstelle aus Inbound-CSV am MA + Export-Vorrang MA-Feld vor Stelle"
```

---

### Task 3: HR-Backend-Feld + Doku

**Files:**
- Modify: `src/Livewire/Employees/Show.php`
- Modify: `/Users/shaustein/Documents/dev/docs/meingedeck/zas-mitarbeiter-import.md`

**Interfaces:**
- Consumes: Spalte + fillable (Task 1); gelbes HR-only-Rendering für `fieldGroups()`-Sektionen mit „HR-only" im Namen (existiert bereits im Blade).

- [ ] **Step 1: Feld in die gelbe HR-only-Gruppe**

In `src/Livewire/Employees/Show.php` die Gruppe
```php
            // Liegt auf rec_employees (nicht hr_data), wird aber als HR-only
            // gerendert (gelb) — speist Lohn-Export + ZAS. MA-Portal sieht es NIE.
            'Personalnummer (HR-only, ZAS)' => [
                'personnel_number' => ['type' => 'text', 'label' => 'Personalnummer (ZAS)'],
            ],
```
ersetzen durch:
```php
            // Liegen auf rec_employees (nicht hr_data), werden aber als HR-only
            // gerendert (gelb) — speisen Lohn-/ZAS-Export. MA-Portal sieht sie NIE.
            'ZAS / Abrechnung (HR-only)' => [
                'personnel_number' => ['type' => 'text', 'label' => 'Personalnummer (ZAS)'],
                'cost_center'      => ['type' => 'text', 'label' => 'Kostenstelle (Vorrang vor Stelle)'],
            ],
```
(Sektionsname enthält weiterhin „HR-only" → gelbes Styling greift; Felder bleiben in `fieldGroups()` → Speichern läuft weiter über `$employee->update()`.)

- [ ] **Step 2: Lint**

Run: `php -l src/Livewire/Employees/Show.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Doku aktualisieren**

In `/Users/shaustein/Documents/dev/docs/meingedeck/zas-mitarbeiter-import.md`:
1. Im Abschnitt **Feld-Mapping** den Punkt `**Ignoriert:** … `Kostenstelle` (kein Ziel-Feld → Warnung) …` anpassen: `Kostenstelle` aus der Ignoriert-Liste entfernen und beim Mapping ergänzen: ``**`Kostenstelle` → `cost_center`** (MA-eigenes Feld; Export-Vorrang: MA-Feld vor `position->cost_center`).``
2. Im Abschnitt **Offene Punkte** die Zeile „`Kostenstelle` → Positions-Zuordnung." ersetzen durch: „Kostenstelle→Positions-*Zuordnung* weiterhin manuell (HR); der Kostenstellen-*Wert* wird seit 2026-07-07 am MA gespeichert und exportiert."

- [ ] **Step 4: Commit**

```bash
git add src/Livewire/Employees/Show.php
git commit -m "feat(zas): Kostenstelle im HR-only-Block pflegbar"
```
(Die Doku liegt außerhalb des Repos — kein Commit nötig.)

---

### Abschluss (nach Review): Merge + Deploy + Verifikation

- [ ] Merge `feat/ma-kostenstelle` → `main` (--no-ff), Branch löschen, Push.
- [ ] meingedeck: `composer update martin3r/platform-recruiting` + composer.lock committen + pushen.
- [ ] Server: `php artisan migrate` (legt `cost_center` an).
- [ ] End-to-End: Markus-CSV als `dry_run` erneut senden → erwartet: **keine** Kostenstellen-Warnung mehr; danach kein Echt-Lauf nötig (Markus existiert → wäre eh `skipped`). Optional: bei Markus (#34) im HR-Backend Kostenstelle `102` eintragen → er erscheint im nächsten `updates.csv` mit `Kostenstelle=102` (Observer + Export-Vorrang bestätigt).

## Self-Review

- **Spec-Abdeckung:** Spalte+fillable+Observer (T1), Import-Mapping ohne Warnung (T2), Export-Kaskade MA→Stelle (T2), HR-Feld gelb (T3), Doku (T3). ✅
- **Kein Portal-Leak:** `editableFieldGroups()` unberührt. ✅
- **Keine Regression:** Recruiting-MA haben leeres MA-Feld → Fallback Stelle = heutiges Verhalten; Export-Header unverändert. ✅
- **Placeholder-Scan:** keine. **Typ-Konsistenz:** `cost_center` string(32) überall als String behandelt (Export castet). ✅
