# ZAS-Mitarbeiter-Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eingehende ZAS-CSV-Datensätze von Mitarbeitern, die bei uns noch nicht existieren, als `RecEmployee` anlegen (Neuanlage-only), sodass sie im Mitarbeiter-Portal erscheinen.

**Architecture:** Der bestehende `ZasInboundController` (Phase 1: roh speichern) ruft nach dem Speichern einen `ZasInboundEmployeeImporter` auf. Dieser entscheidet pro Zeile (UUID-/`zas_id`-Match → überspringen, sonst anlegen), mappt CSV-Spalten via `ZasInboundRowMapper` und löst Lookup-Labels via `ZasLookupReverseResolver` (Hybrid) auf.

**Tech Stack:** Laravel (PHP 8.4), Eloquent, bestehende ZAS-Services unter `src/Services/Zas/`.

## Global Constraints

- Namespace: `Platform\Recruiting\...`, PSR-4 unter `src/`.
- **Keine PHPUnit-Harness** im Modul — Verifikation per Standalone-PHP-Skript (reine Logik) + `?dry_run=true`-curl auf dem Server (Integration). Kein neues Test-Framework einführen.
- Scope: **NUR Neuanlage.** Bestehende MA werden übersprungen (kein Update).
- Lookup-Hybrid: Treffer (case-insensitiv gegen `value`/`label`) → unser Code; `anstellungsart` zusätzlich Präfix; kein Treffer → roher String + Warnung.
- Export-Schleifen-Schutz: importierte MA bekommen `zas_initial_exported_at = now()`.
- Echt-Antwort PII-frei: Bestätigung per `employee_id`+`zas_id`; Name nur bei `dry_run`.
- Migrationen werden über `loadMigrationsFrom` (ServiceProvider) geladen; laufen beim Deploy via `php artisan migrate`. Nach Push: meingedeck composer.lock bumpen.
- `str_getcsv` immer mit explizitem `$escape`-Parameter (`''`) wegen PHP-8.4-Deprecation.

---

### Task 1: Datenmodell — Herkunfts-Marker `rec_zas_inbound_file_id`

**Files:**
- Create: `database/migrations/2026_06_17_000001_add_zas_inbound_file_id_to_rec_employees.php`
- Modify: `src/Models/RecEmployee.php` (fillable + Relation)
- Modify: `src/Models/RecZasInboundFile.php` (Gegen-Relation)
- Modify: `config/recruiting.php` (Team-Config)

**Interfaces:**
- Produces: Spalte `rec_employees.rec_zas_inbound_file_id` (nullable FK). `RecEmployee::zasInboundFile(): BelongsTo`. `RecZasInboundFile::employees(): HasMany`. Config-Key `recruiting.zas.inbound_team_id`.

- [ ] **Step 1: Migration anlegen**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Herkunfts-/Provenienz-Marker: aus welcher ZAS-Inbound-Lieferung wurde
     * dieser MA angelegt. NULL = nicht aus ZAS importiert (z.B. Recruiting-Anlage).
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'rec_zas_inbound_file_id')) {
                $table->unsignedBigInteger('rec_zas_inbound_file_id')->nullable()->after('zas_id');
                $table->index('rec_zas_inbound_file_id', 'idx_rec_employees_zas_inbound_file');
                $table->foreign('rec_zas_inbound_file_id')
                    ->references('id')->on('rec_zas_inbound_files')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'rec_zas_inbound_file_id')) {
                $table->dropForeign('rec_employees_rec_zas_inbound_file_id_foreign');
                $table->dropIndex('idx_rec_employees_zas_inbound_file');
                $table->dropColumn('rec_zas_inbound_file_id');
            }
        });
    }
};
```

- [ ] **Step 2: `RecEmployee` — fillable + Relation ergänzen**

In `src/Models/RecEmployee.php` im `$fillable`-Array nach `'zas_id',` einfügen:
```php
        'rec_zas_inbound_file_id',
```
Und eine Relation hinzufügen (bei den anderen `belongsTo`-Methoden):
```php
    public function zasInboundFile(): BelongsTo
    {
        return $this->belongsTo(RecZasInboundFile::class, 'rec_zas_inbound_file_id');
    }
```

- [ ] **Step 3: `RecZasInboundFile` — Gegen-Relation**

In `src/Models/RecZasInboundFile.php` `use`-Block ergänzen:
```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```
Methode hinzufügen:
```php
    public function employees(): HasMany
    {
        return $this->hasMany(RecEmployee::class, 'rec_zas_inbound_file_id');
    }
```

- [ ] **Step 4: Config — Team-ID**

In `config/recruiting.php` im `'zas' => [ ... ]`-Block ergänzen:
```php
        // Team, dem von ZAS importierte Mitarbeiter zugeordnet werden (Pflicht fuer Import).
        'inbound_team_id'        => env('RECRUITING_ZAS_INBOUND_TEAM_ID'),
```

- [ ] **Step 5: Lint + Commit**

Run: `php -l database/migrations/2026_06_17_000001_add_zas_inbound_file_id_to_rec_employees.php && php -l src/Models/RecEmployee.php && php -l src/Models/RecZasInboundFile.php && php -l config/recruiting.php`
Expected: „No syntax errors detected" für alle vier.
(Migration läuft auf dem Server via `php artisan migrate`.)

```bash
git add database/migrations/2026_06_17_000001_add_zas_inbound_file_id_to_rec_employees.php src/Models/RecEmployee.php src/Models/RecZasInboundFile.php config/recruiting.php
git commit -m "feat(zas): Herkunfts-Marker rec_zas_inbound_file_id + Inbound-Team-Config"
```

---

### Task 2: `ZasLookupReverseResolver` — Label → unser Lookup-Code (Hybrid)

**Files:**
- Create: `src/Services/Zas/ZasLookupReverseResolver.php`

**Interfaces:**
- Produces:
  - `resolve(string $lookupName, ?string $incoming, bool $allowPrefix = false): array` → `['value' => ?string, 'matched' => bool]`. `matched=true` auch bei leerem Input (kein Mapping nötig). Bei Nicht-Treffer: `['value' => <roher Input>, 'matched' => false]`.
  - `static matchValue(array $pairs, ?string $incoming, bool $allowPrefix): array` — reine Logik, `$pairs` = Liste `['value'=>..,'label'=>..]`.

- [ ] **Step 1: Reine Match-Logik + DB-Wrapper schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;

/**
 * Umkehrung von ZasLookupResolver: ZAS liefert Klartext-Labels, wir brauchen
 * unseren Lookup-Code. Hybrid: exakter (case-insensitiver) Match gegen value
 * ODER label; optional Praefix-Match; sonst roher Wert zurueck (matched=false),
 * damit der Aufrufer eine Warnung setzen kann.
 */
class ZasLookupReverseResolver
{
    /** Cache: lookupName → Liste [['value'=>..,'label'=>..], ...] */
    protected array $cache = [];

    public function resolve(string $lookupName, ?string $incoming, bool $allowPrefix = false): array
    {
        return self::matchValue($this->loadPairs($lookupName), $incoming, $allowPrefix);
    }

    /**
     * @param array<int,array{value:string,label:string}> $pairs
     * @return array{value: ?string, matched: bool}
     */
    public static function matchValue(array $pairs, ?string $incoming, bool $allowPrefix): array
    {
        $needle = $incoming === null ? '' : trim($incoming);
        if ($needle === '') {
            return ['value' => null, 'matched' => true];
        }
        $lc = mb_strtolower($needle);

        // 1. exakter Match gegen value oder label (case-insensitiv)
        foreach ($pairs as $p) {
            if (mb_strtolower((string) $p['value']) === $lc || mb_strtolower((string) $p['label']) === $lc) {
                return ['value' => $p['value'], 'matched' => true];
            }
        }
        // 2. Praefix-Match (nur wenn erlaubt) — z.B. "Vollzeit 172 Stunden" → vollzeit
        if ($allowPrefix) {
            foreach ($pairs as $p) {
                $lbl = mb_strtolower((string) $p['label']);
                $val = mb_strtolower((string) $p['value']);
                if (($lbl !== '' && str_starts_with($lc, $lbl)) || ($val !== '' && str_starts_with($lc, $val))) {
                    return ['value' => $p['value'], 'matched' => true];
                }
            }
        }
        // 3. kein Treffer → roher Wert (Aufrufer warnt)
        return ['value' => $needle, 'matched' => false];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    protected function loadPairs(string $lookupName): array
    {
        if (!isset($this->cache[$lookupName])) {
            $lookupId = DB::table('core_lookups')->where('name', $lookupName)->value('id');
            $this->cache[$lookupName] = $lookupId
                ? DB::table('core_lookup_values')
                    ->where('lookup_id', $lookupId)
                    ->get(['value', 'label'])
                    ->map(fn ($r) => ['value' => (string) $r->value, 'label' => (string) $r->label])
                    ->all()
                : [];
        }
        return $this->cache[$lookupName];
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Services/Zas/ZasLookupReverseResolver.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Match-Logik gegen echte Lookup-Werte verifizieren (Standalone)**

Erstelle eine temporäre Datei `/tmp/verify_reverse.php` mit den per MCP geprüften echten Werten und teste `matchValue` direkt:

```php
<?php
require '/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/src/Services/Zas/ZasLookupReverseResolver.php';
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver as R;

$geschlecht = [['value'=>'maennlich','label'=>'Männlich'],['value'=>'weiblich','label'=>'Weiblich'],['value'=>'divers','label'=>'Divers']];
$familienstand = [['value'=>'ledig','label'=>'Ledig'],['value'=>'verheiratet','label'=>'Verheiratet']];
$krankenkasse = [['value'=>'pkv','label'=>'Private Krankenkasse'],['value'=>'tk','label'=>'Techniker Krankenkasse']];
$anstellungsart = [['value'=>'kurzfristig','label'=>'kurzfristig'],['value'=>'vollzeit','label'=>'Vollzeit'],['value'=>'teilzeit','label'=>'Teilzeit']];
$ichbin = [['value'=>'student','label'=>'Student'],['value'=>'erwerbstaetig','label'=>'Erwerbstätig']];

$cases = [
  ['geschlecht "Weiblich"',      R::matchValue($geschlecht, 'Weiblich', false),            ['value'=>'weiblich','matched'=>true]],
  ['familienstand "verheiratet"',R::matchValue($familienstand, 'verheiratet', false),      ['value'=>'verheiratet','matched'=>true]],
  ['krankenkasse exakt',         R::matchValue($krankenkasse, 'Private Krankenkasse', false),['value'=>'pkv','matched'=>true]],
  ['anstellungsart praefix',     R::matchValue($anstellungsart, 'Vollzeit 172 Stunden', true),['value'=>'vollzeit','matched'=>true]],
  ['anstellungsart ohne praefix',R::matchValue($anstellungsart, 'Vollzeit 172 Stunden', false),['value'=>'Vollzeit 172 Stunden','matched'=>false]],
  ['ichbin kein treffer',        R::matchValue($ichbin, 'Geschäftsführer/in', false),       ['value'=>'Geschäftsführer/in','matched'=>false]],
  ['leer',                       R::matchValue($geschlecht, '', false),                     ['value'=>null,'matched'=>true]],
];
$ok = true;
foreach ($cases as [$name, $got, $exp]) {
  $pass = $got === $exp;
  $ok = $ok && $pass;
  echo ($pass ? 'PASS' : 'FAIL') . " — $name → " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
}
exit($ok ? 0 : 1);
```

Run: `php /tmp/verify_reverse.php; echo "exit=$?"; rm /tmp/verify_reverse.php`
Expected: alle Zeilen „PASS", `exit=0`.

- [ ] **Step 4: Commit**

```bash
git add src/Services/Zas/ZasLookupReverseResolver.php
git commit -m "feat(zas): ZasLookupReverseResolver (Label->Code, Hybrid mit Praefix)"
```

---

### Task 3: `ZasInboundRowMapper` — CSV-Zeile → Feld-Arrays

**Files:**
- Create: `src/Services/Zas/ZasInboundRowMapper.php`

**Interfaces:**
- Consumes: `ZasLookupReverseResolver` (Task 2).
- Produces: `map(array $row): array` → `['uuid'=>?string, 'zas_id'=>?string, 'employee'=>array, 'hr'=>array, 'warnings'=>string[]]`. `$row` ist eine Header→Wert-Map (eine Datenzeile).

- [ ] **Step 1: Mapper schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas;

use Carbon\Carbon;

/**
 * Bildet eine ZAS-CSV-Datenzeile (Header→Wert-Map) auf RecEmployee- und
 * RecEmployeeHrData-Feld-Arrays ab. Inversion der ZasEmployeeFieldResolver-Tabelle.
 * Reine Transformation, keine DB-Schreibzugriffe.
 */
class ZasInboundRowMapper
{
    /** CSV-Spalte → rec_employees-Spalte (String, getrimmt) */
    private const DIRECT = [
        'Name' => 'last_name', 'Vorname' => 'first_name', 'Geburtsname' => 'birth_name',
        'Geburtsort' => 'birth_place', 'AusweisNr' => 'identity_card_number',
        'Telefon' => 'phone', 'Email' => 'email', 'Strasse' => 'street',
        'Hausnummer' => 'house_number', 'PLZ' => 'zip', 'Ort' => 'city',
        'Bank' => 'bank_institute', 'IBAN' => 'iban', 'BIC' => 'bic',
        'Kontoinhaber' => 'account_holder', 'Steuerklasse' => 'tax_class',
        'SteuerID' => 'steuer_id', 'SVNummer' => 'sozialversicherungsnummer',
        'Fuehrerschein' => 'drivers_license_class',
        'GeworbenVonPersNr' => 'recruited_by_personnel_number', 'HemdGroesse' => 'shirt_size',
    ];

    /** CSV-Spalte → rec_employees-Datumsspalte (d.m.Y → Y-m-d) */
    private const DATES = [
        'Geburtsdatum' => 'birth_date', 'AusweisBis' => 'identity_card_valid_until',
        'AufenthaltsErlaubnisBis' => 'residence_permit_valid_until',
        'ArbeitsGenehmigungBis' => 'work_permit_valid_until',
        'SchulBeschGueltigBis' => 'school_certificate_valid_until',
        'InfekErstbescheinigung' => 'infection_protection_first_issued_at',
        'Eintritt' => 'employed_since',
    ];

    /** CSV-Spalte → rec_employees-Integer-Spalte */
    private const INTS = [
        'KinderAnzahl' => 'number_of_children', 'HosenGroesse' => 'pants_size', 'SchuhGroesse' => 'shoe_size',
    ];

    /** CSV-Spalte → rec_employees-Bool-Spalte (Ja/Nein) */
    private const BOOLS = [
        'PKW' => 'has_car', 'EUBuerger' => 'is_eu_citizen',
    ];

    /** CSV-Spalte → [field, lookup, prefix] auf rec_employees */
    private const LOOKUPS = [
        'Geschlecht'    => ['gender', 'geschlecht', false],
        'Familienstand' => ['marital_status', 'familienstand', false],
        'Religion'      => ['religion', 'religion', false],
        'Krankenkasse'  => ['health_insurance', 'krankenkasse', false],
        'Ichbin'        => ['employment_type', 'beschaeftigung_art', false],
        'Nation'        => ['birth_country', 'geburtsland', false],
    ];

    /** CSV-Spalte → rec_employee_hr_data-Datumsspalte */
    private const HR_DATES = [
        'VertragVersendetAm' => 'contract_sent_date',
        'VertragZurueckAm'   => 'contract_signed_at',
        'BefristetBis'       => 'contract_end_date',
    ];

    public function __construct(private ZasLookupReverseResolver $lookups) {}

    public function map(array $row): array
    {
        $get = fn (string $col): string => trim((string) ($row[$col] ?? ''));
        $employee = [];
        $hr = [];
        $warnings = [];

        foreach (self::DIRECT as $col => $field) {
            $v = $get($col);
            if ($v !== '') {
                $employee[$field] = $v;
            }
        }
        foreach (self::DATES as $col => $field) {
            $d = $this->date($get($col));
            if ($d !== null) {
                $employee[$field] = $d;
            }
        }
        foreach (self::INTS as $col => $field) {
            $v = $get($col);
            if ($v !== '' && is_numeric($v)) {
                $employee[$field] = (int) $v;
            }
        }
        foreach (self::BOOLS as $col => $field) {
            $v = $get($col);
            if ($v !== '') {
                $employee[$field] = mb_strtolower($v) === 'ja';
            }
        }
        foreach (self::LOOKUPS as $col => [$field, $lookup, $prefix]) {
            $v = $get($col);
            if ($v === '') {
                continue;
            }
            $res = $this->lookups->resolve($lookup, $v, $prefix);
            $employee[$field] = $res['value'];
            if (!$res['matched']) {
                $warnings[] = "{$field}: '{$v}' roh gespeichert (kein Lookup-Treffer)";
            }
        }

        // Land → country_code (kein Lookup; Default 'de' wenn leer)
        $land = $get('Land');
        $employee['country_code'] = $land !== '' ? $land : 'de';

        // HR-Daten
        foreach (self::HR_DATES as $col => $field) {
            $d = $this->date($get($col));
            if ($d !== null) {
                $hr[$field] = $d;
            }
        }
        $status = $get('Status');
        if ($status !== '') {
            $hr['export_status'] = mb_strtoupper($status); // "go" → "GO"
        }
        $anst = $get('Anstellungsart');
        if ($anst !== '') {
            $res = $this->lookups->resolve('anstellungsart', $anst, true);
            $hr['employment_classification'] = $res['value'];
            if (!$res['matched']) {
                $warnings[] = "employment_classification: '{$anst}' roh gespeichert (kein Lookup-Treffer)";
            }
        }

        // Ignorierte Felder mit Inhalt vermerken (keine Ziel-Spalte)
        if ($get('Kostenstelle') !== '') {
            $warnings[] = "Kostenstelle '{$get('Kostenstelle')}' ignoriert (keine Positions-Zuordnung)";
        }

        return [
            'uuid'     => $get('UUID') !== '' ? $get('UUID') : null,
            'zas_id'   => $get('ZasPersonalNr') !== '' ? $get('ZasPersonalNr') : null,
            'employee' => $employee,
            'hr'       => $hr,
            'warnings' => $warnings,
        ];
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('d.m.Y', $value)->format('Y-m-d');
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Services/Zas/ZasInboundRowMapper.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Mapper gegen die echte Markus-Zeile verifizieren (Standalone)**

Erstelle `/tmp/verify_mapper.php`. Stubbe den Lookup-Resolver (Subklasse, die `loadPairs` mit den echten Werten überschreibt), parse die echte Datenzeile und prüfe Schlüssel-Ergebnisse:

```php
<?php
$base = '/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/src/Services/Zas/';
require $base.'ZasLookupReverseResolver.php';
require $base.'ZasInboundRowMapper.php';
require '/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/nesbot/carbon/src/Carbon/Carbon.php'; // falls Carbon nicht autoloaded: stattdessen Skript via `php artisan tinker --execute` auf dem Server fahren

use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;

$fixtures = [
  'geschlecht'    => [['value'=>'weiblich','label'=>'Weiblich']],
  'familienstand' => [['value'=>'verheiratet','label'=>'Verheiratet']],
  'krankenkasse'  => [['value'=>'pkv','label'=>'Private Krankenkasse']],
  'geburtsland'   => [['value'=>'de','label'=>'Deutschland']],
  'beschaeftigung_art' => [['value'=>'student','label'=>'Student']],
  'anstellungsart' => [['value'=>'vollzeit','label'=>'Vollzeit']],
  'religion' => [],
];
$resolver = new class($fixtures) extends ZasLookupReverseResolver {
    public function __construct(private array $fx) {}
    protected function loadPairs(string $n): array { return $this->fx[$n] ?? []; }
};

$header = ['Name','Vorname','Geburtsname','Geburtsdatum','Geburtsort','Nation','Geschlecht','Familienstand','AusweisNr','AusweisBis','Religion','KinderAnzahl','Telefon','Email','Strasse','Hausnummer','PLZ','Ort','Land','Kostenstelle','Ichbin','Bank','IBAN','BIC','Kontoinhaber','Steuerklasse','SteuerID','SVNummer','Krankenkasse','Fuehrerschein','PKW','GeworbenVonPersNr','HemdGroesse','HosenGroesse','SchuhGroesse','UplAuweis','UplAusw2','UplSelfie','UplVersicher','UplImma','UplSchule','UplArbErl','UplArbErl2','UplVisum','UplZusatzblatt','UplFiktion','UplFiktion2','UplVertrag','UplIfsg','AufenthaltsErlaubnisBis','ArbeitsGenehmigungBis','SchulBeschGueltigBis','InfekErstbescheinigung','EUBuerger','VertragVersendetAm','VertragZurueckAm','BefristetBis','Status','Anstellungsart','Waeschepaket','Sternebewertung','Qualifikation','BeschErforderlich','AufenthaltGenehmigungErforderlich','FolgeBescheinigungAm','InfekGueltigBis','InfekBeschErforderlich','InfekBeschVorhanden','Eintritt','SchulungsStandort','SchulungsDatum','Grundlohn','Zuschlag','UUID','ZasPersonalNr'];
$dataLine = 'Ammerer;Markus;;28.01.1976;Düsseldorf;Deutschland;Weiblich;verheiratet;L6WL83CWX;19.08.2028;;1;+49 1722853905;markus.ammerer@rheingedeck.de;Pallenbergstraße ;15;40474;Düsseldorf;;102;Geschäftsführer/in;Sparkasse HRV;DE74334500001032311662;WELADED1VEL;;;86024538719;13280176A039;Private Krankenkasse;;Nein;97940;XL;;;;;;;;;;;;;;;;;;;;01.08.2018;;01.10.2016;01.10.2016;;go;Vollzeit 172 Stunden;;;;Nein;Nein;01.08.2022;01.01.2050;Ja;Ja;01.10.2016;;;0;0;;1187';
$vals = str_getcsv($dataLine, ';', '"', '');
$row = [];
foreach ($header as $i => $h) { $row[$h] = $vals[$i] ?? ''; }

$out = (new ZasInboundRowMapper($resolver))->map($row);

$checks = [
  ['zas_id', $out['zas_id'], '1187'],
  ['uuid', $out['uuid'], null],
  ['last_name', $out['employee']['last_name'] ?? null, 'Ammerer'],
  ['birth_date', $out['employee']['birth_date'] ?? null, '1976-01-28'],
  ['gender', $out['employee']['gender'] ?? null, 'weiblich'],
  ['health_insurance', $out['employee']['health_insurance'] ?? null, 'pkv'],
  ['birth_country', $out['employee']['birth_country'] ?? null, 'de'],
  ['employment_type(raw)', $out['employee']['employment_type'] ?? null, 'Geschäftsführer/in'],
  ['has_car', $out['employee']['has_car'] ?? null, false],
  ['employed_since', $out['employee']['employed_since'] ?? null, '2016-10-01'],
  ['country_code(default)', $out['employee']['country_code'] ?? null, 'de'],
  ['hr.export_status', $out['hr']['export_status'] ?? null, 'GO'],
  ['hr.employment_classification', $out['hr']['employment_classification'] ?? null, 'vollzeit'],
  ['hr.contract_signed_at', $out['hr']['contract_signed_at'] ?? null, '2016-10-01'],
];
$ok = true;
foreach ($checks as [$n,$got,$exp]) { $p = $got === $exp; $ok = $ok && $p; echo ($p?'PASS':'FAIL')." — $n = ".var_export($got,true)."\n"; }
echo "warnings: ".json_encode($out['warnings'], JSON_UNESCAPED_UNICODE)."\n";
exit($ok ? 0 : 1);
```

Run: `php /tmp/verify_mapper.php; echo "exit=$?"; rm /tmp/verify_mapper.php`
Expected: alle „PASS", `exit=0`, Warnungen enthalten `employment_type` (Geschäftsführer) + Kostenstelle.
Hinweis: Falls Carbon lokal nicht ladbar ist, das Skript stattdessen auf dem Server via `php artisan tinker --execute='...'` im Site-Verzeichnis ausführen (dort ist Carbon autoloaded).

- [ ] **Step 4: Commit**

```bash
git add src/Services/Zas/ZasInboundRowMapper.php
git commit -m "feat(zas): ZasInboundRowMapper (CSV-Zeile -> RecEmployee/HrData-Felder)"
```

---

### Task 4: `ZasInboundEmployeeImporter` — Matching-Kaskade + Anlage

**Files:**
- Create: `src/Services/Zas/ZasInboundEmployeeImporter.php`

**Interfaces:**
- Consumes: `ZasInboundRowMapper::map()` (Task 3); `RecEmployee`, `RecEmployeeHrData`, `RecZasInboundFile` Models; Config `recruiting.zas.inbound_team_id`.
- Produces: `import(array $rows, RecZasInboundFile $inbound, bool $dryRun): array` → `['status'=>'processed'|'partial'|'failed', 'created'=>[...], 'skipped'=>[...], 'failed'=>[...], 'warnings'=>string[]]`. `$rows` = Liste von Header→Wert-Maps (Datenzeilen).

- [ ] **Step 1: Importer schreiben**

```php
<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Verarbeitet ZAS-Inbound-Datenzeilen: legt MA an, die bei uns noch nicht
 * existieren (Neuanlage-only). Bestehende (UUID- oder zas_id-Match) werden
 * uebersprungen. Pro Zeile gekapselt — eine fehlerhafte Zeile stoppt nicht den Rest.
 */
class ZasInboundEmployeeImporter
{
    public function __construct(private ZasInboundRowMapper $mapper) {}

    public function import(array $rows, $inbound, bool $dryRun): array
    {
        $teamId = config('recruiting.zas.inbound_team_id');
        $created = [];
        $skipped = [];
        $failed = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            try {
                $mapped = $this->mapper->map($row);
                foreach ($mapped['warnings'] as $w) {
                    $warnings[] = "Zeile " . ($index + 1) . ": {$w}";
                }

                // Matching-Kaskade
                $existing = $this->findExisting($mapped['uuid'], $mapped['zas_id'], $teamId);
                if ($existing !== null) {
                    $skipped[] = ['zas_id' => $mapped['zas_id'], 'employee_id' => $existing->id, 'reason' => 'exists'];
                    continue;
                }

                if (!$teamId) {
                    $failed[] = ['zas_id' => $mapped['zas_id'], 'reason' => 'RECRUITING_ZAS_INBOUND_TEAM_ID nicht konfiguriert'];
                    continue;
                }

                if ($dryRun) {
                    $created[] = [
                        'would_create' => true,
                        'zas_id' => $mapped['zas_id'],
                        'name'   => trim(($mapped['employee']['last_name'] ?? '') . ', ' . ($mapped['employee']['first_name'] ?? '')),
                    ];
                    continue;
                }

                $employee = $this->createEmployee($mapped, $teamId, $inbound->id);
                $created[] = ['employee_id' => $employee->id, 'zas_id' => $employee->zas_id];
            } catch (\Throwable $e) {
                $failed[] = ['zas_id' => $row['ZasPersonalNr'] ?? null, 'reason' => $e->getMessage()];
            }
        }

        $status = $failed !== [] ? ($created !== [] || $skipped !== [] ? 'partial' : 'failed') : 'processed';

        return compact('status', 'created', 'skipped', 'failed', 'warnings');
    }

    protected function findExisting(?string $uuid, ?string $zasId, $teamId): ?RecEmployee
    {
        if ($uuid) {
            $byUuid = RecEmployee::where('uuid', $uuid)->first();
            if ($byUuid) {
                return $byUuid;
            }
        }
        if ($zasId) {
            return RecEmployee::where('zas_id', $zasId)
                ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
                ->first();
        }
        return null;
    }

    protected function createEmployee(array $mapped, int $teamId, int $inboundId): RecEmployee
    {
        return DB::transaction(function () use ($mapped, $teamId, $inboundId) {
            $employee = RecEmployee::create(array_merge($mapped['employee'], [
                'team_id'                 => $teamId,
                'rec_applicant_id'        => null,
                'zas_id'                  => $mapped['zas_id'],
                'rec_zas_inbound_file_id' => $inboundId,
                'is_active'               => true,
                // Export-Schleifen-Schutz: nicht erneut an ZAS exportieren.
                'zas_initial_exported_at' => now(),
            ]));

            if ($mapped['hr'] !== []) {
                $hr = $employee->ensureHrData();
                $hr->fill($mapped['hr'])->save();
            }

            return $employee;
        });
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Services/Zas/ZasInboundEmployeeImporter.php`
Expected: „No syntax errors detected".

- [ ] **Step 3: Commit**

```bash
git add src/Services/Zas/ZasInboundEmployeeImporter.php
git commit -m "feat(zas): ZasInboundEmployeeImporter (Matching-Kaskade + Neuanlage)"
```

(Integrationstest erfolgt in Task 5 via `dry_run`-curl gegen den Endpunkt.)

---

### Task 5: Verdrahtung im `ZasInboundController` + Antwort + Inbound-Status

**Files:**
- Modify: `src/Http/Controllers/ZasInboundController.php`

**Interfaces:**
- Consumes: `ZasInboundEmployeeImporter::import()` (Task 4); die in `inspect()` ermittelte Struktur (Header + Zeilen).

- [ ] **Step 1: Datenzeilen-Parsing in `inspect()` ergänzen**

In `ZasInboundController::inspect()` zusätzlich zu Header/erster Zeile **alle** Datenzeilen als Header→Wert-Maps zurückgeben. Den Rückgabe-Array um `rows` erweitern:

```php
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $values = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            $rows[] = $this->zip($columns, $values);
        }

        return [
            'delimiter'      => $delimiter,
            'columns'        => $columns,
            'row_count'      => $rowCount,
            'first_data_row' => $firstDataRow,
            'rows'           => $rows,
        ];
```
(Das bestehende `first_data_row` bleibt; `rows` kommt hinzu.)

- [ ] **Step 2: Importer aufrufen + Antwort/Status erweitern**

Constructor-Injection ergänzen:
```php
    public function __construct(private \Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter $importer) {}
```
Nach dem Erstellen von `$record` (vor dem `return`), Verarbeitung anstoßen und Inbound-Eintrag aktualisieren:

```php
        $import = $this->importer->import($structure['rows'], $record, $isTest);

        $record->update([
            'status'       => $isTest ? 'received' : $import['status'],
            'processed_at' => $isTest ? null : now(),
            'notes'        => json_encode([
                'created'  => $import['created'],
                'skipped'  => $import['skipped'],
                'failed'   => $import['failed'],
                'warnings' => $import['warnings'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
```

Im JSON-`return` den `import`-Block ergänzen (innerhalb des bestehenden `$payload`):
```php
            'import' => $import,
```

- [ ] **Step 3: Lint**

Run: `php -l src/Http/Controllers/ZasInboundController.php`
Expected: „No syntax errors detected".

- [ ] **Step 4: Commit**

```bash
git add src/Http/Controllers/ZasInboundController.php
git commit -m "feat(zas): Inbound-Controller verarbeitet Zeilen -> MA-Import + Ergebnis-Antwort"
```

- [ ] **Step 5: Push + meingedeck bumpen**

```bash
git push origin main
cd /Users/shaustein/Documents/dev/platforms/meingedeck && git fetch origin -q && git reset --hard origin/main -q && composer update martin3r/platform-recruiting --no-interaction --no-scripts && git add composer.lock && git commit -m "chore: bump platform-recruiting (ZAS-MA-Import)" && git push origin main
```

- [ ] **Step 6: Migration + Config auf dem Server, dann End-to-End `dry_run`**

Auf dem Server: `RECRUITING_ZAS_INBOUND_TEAM_ID=3` setzen (RHEINGEDECK-HR) und `php artisan migrate` laufen lassen.
Dann Verbindungstest (legt nichts an):
```bash
curl -X POST "https://mitarbeiter.rheingedeck.de/recruiting/zas/inbound?dry_run=true" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@zas-ma-initial.csv"
```
Expected: `import.status` vorhanden, `created[].would_create=true`, `warnings` enthält `employment_type`/`Kostenstelle`.
Danach echter Lauf (ohne `dry_run`) mit einem Testdatensatz → `import.created[].employee_id` gesetzt; in `rec_zas_inbound_files.notes` Ergebnis sichtbar; Portal-Login mit Geburtsdatum + Ausweisnummer prüfen.

---

## Self-Review

**Spec coverage:**
- Ein Endpunkt, Pro-Zeile-Logik → Task 4 (`findExisting` Kaskade) + Task 5 (Zeilen-Parsing). ✓
- Neuanlage-only, Dubletten-Skip → Task 4. ✓
- Feld-Mapping (direkt/Datum/Bool/Int/Lookup/HR, Land-Default) → Task 3. ✓
- Lookup-Hybrid (exakt + Präfix für anstellungsart + Roh-Fallback + Warnung) → Task 2 + Task 3. ✓
- Export-Schleifen-Schutz (`zas_initial_exported_at=now()`) → Task 4. ✓
- Herkunfts-Marker `rec_zas_inbound_file_id` → Task 1 (Migration/Relation) + Task 4 (gesetzt). ✓
- Team-Config (Pflicht, Fehler wenn fehlt) → Task 1 (Config) + Task 4 (Guard). ✓
- Antwort: `import`-Block, PII-frei echt, Name nur dry_run → Task 4 (dry_run name) + Task 5. ✓
- Inbound-Status/Notes (processed/partial/failed) → Task 4 (status) + Task 5 (update). ✓
- Ignorierte Felder (Kostenstelle, Export-Rechenfelder, Upl*) → Task 3 (nicht gemappt, Kostenstelle als Warnung). ✓
- Verifikation ohne PHPUnit → Standalone-Skripte (Task 2/3) + dry_run-curl (Task 5). ✓

**Placeholder scan:** keine TBD/TODO; alle Code-Schritte vollständig. ✓

**Type consistency:** `matchValue`/`resolve` Rückgabe `['value','matched']` einheitlich (Task 2↔3). `map()` Rückgabe `['uuid','zas_id','employee','hr','warnings']` einheitlich (Task 3↔4). `import()` Rückgabe `['status','created','skipped','failed','warnings']` einheitlich (Task 4↔5). ✓

## Offene Punkte / spätere Erweiterungen

- Fall A (Update bestehender MA, non-destruktiv).
- UI-Sichtbarkeit roher Lookup-Werte.
- Kostenstelle → Positions-Zuordnung.
