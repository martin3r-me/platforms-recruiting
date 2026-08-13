# ZAS-Import-Härtung vor dem 900er-Massenlauf Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Erkenntnisse aus dem 100er-Testlauf (Inbound-Lieferung ID 10) umsetzen: kaputte/verschobene Zeilen abweisen, Personalnummer als Pflicht, ZAS-Freitext-Schreibweisen per Alias auf unsere Lookup-Codes mappen, fehlende Lookup-Werte ergänzen.

**Architecture:** Zwei Zeilen-Guards im `ZasInboundEmployeeImporter` (Struktur-Check vor dem Mapping, Personalnummer-Pflicht danach). Eine Alias-Stufe im `ZasLookupReverseResolver` (nach Exakt-Match, vor Prefix/Raw), validiert gegen existierende Lookup-Werte. Neue Lookup-Werte werden als Daten (per MCP/UI) angelegt, kein Schema-Change, keine Migration.

**Tech Stack:** Laravel (PHP 8.4), bestehende ZAS-Services.

## Global Constraints

- **Keine PHPUnit-Harness** (bewusst). Verifikation: `php -l` + Standalone-Skript (Alias-Logik) + Server-Re-Test der echten Lieferung ID 10 via Tinker (Importer mit `dryRun=true` — legt nichts an).
- Branch `feat/zas-import-haertung`, Merge → main + meingedeck-Bump. **Keine Migration nötig** (nur Code + Lookup-Daten).
- Aliase geben nur Codes zurück, die **im Lookup existieren** (Validierung gegen `loadPairs`) — fehlt der Ziel-Wert, greift der bisherige Raw-Fallback. Dadurch ist die Alias-Tabelle unabhängig davon deploybar, ob Task 0 schon gelaufen ist.
- Fachliche Entscheidungen (bestätigt): Länderliste erweitern (Venezuela, Kamerun, Armenien, Bangladesch) · „Rentner" als „Ich bin"-Wert · „Minijob" als Anstellungsart · Techniker-Typo-Alias. „AOK" (ohne Region) bleibt bewusst roh (nicht eindeutig).

---

### Task 0: Lookup-Werte ergänzen (Daten, per MCP — nach Freigabe)

**Kein Code.** Über `core.extra_fields.lookup_values.MANAGE` (Team RHEINGEDECK-HR):

| Lookup (id) | neuer value | Label |
|---|---|---|
| geburtsland (10) | `ve` | Venezuela |
| geburtsland (10) | `cm` | Kamerun |
| geburtsland (10) | `am` | Armenien |
| geburtsland (10) | `bd` | Bangladesch |
| beschaeftigung_art (9) | `rentner` | Rentner |
| anstellungsart (13) | `minijob` | Minijob |

- [ ] **Step 1:** Sechs `MANAGE`-Calls mit `action=add`. Danach je Lookup per `core.extra_fields.lookups.GET` verifizieren, dass die Werte aktiv sind.

Hinweis: Wirkt sofort auch auf den **Export** (Label-Auflösung) und die HR-Dropdowns — gewollt. „Venezuela"/„Kamerun" matchen danach ohne Alias (exakter Label-Match); „Rentner" ebenso.

---

### Task 1: Zeilen-Guards im Importer

**Files:**
- Modify: `src/Services/Zas/ZasInboundEmployeeImporter.php`

**Interfaces:**
- Consumes: `$row` (Header→Wert-Map aus `ZasInboundController::inspect()`; überzählige Werte bekommen dort `col_N`-Keys via `zip()`, Header endet mit `|`-Spalte aus dem `;|;`-Zeilenende).
- Produces: Struktur-defekte Zeilen und Zeilen ohne `ZasPersonalNr` → `failed`-Eintrag mit klarem Grund, kein Anlegen.

- [ ] **Step 1: Guard-Methode ergänzen**

Am Ende der Klasse einfügen:

```php
    /**
     * Erkennt verschobene/kaputte Zeilen (Erkenntnisse aus dem 100er-Testlauf:
     * eine Zeile mit Spaltenversatz haette einen Muell-MA ohne Dubletten-
     * Schluessel angelegt).
     *
     *  - col_N-Keys: die Zeile hatte MEHR Werte als der Header (zip() haengt
     *    Ueberzaehlige als col_N an) — typisch: Semikolon im Feldwert.
     *  - '|'-Marker: das ZAS-Zeilenende `;|;` erzeugt eine '|'-Spalte, deren
     *    Wert in jeder intakten Zeile '|' ist. Alles andere = Versatz/zu kurz.
     */
    protected function detectRowStructureIssue(array $row): ?string
    {
        foreach (array_keys($row) as $key) {
            if (str_starts_with((string) $key, 'col_')) {
                return 'Zeile hat mehr Spalten als der Header (Spaltenversatz, vermutlich Semikolon im Feldwert) — nicht importiert';
            }
        }
        if (array_key_exists('|', $row) && trim((string) $row['|']) !== '|') {
            return 'Zeilenende-Marker verschoben (Spaltenversatz oder Zeile zu kurz) — nicht importiert';
        }
        return null;
    }
```

- [ ] **Step 2: Guards in die Schleife einbauen**

In `import()` den Schleifen-Anfang
```php
            try {
                $mapped = $this->mapper->map($row);
                foreach ($mapped['warnings'] as $w) {
                    $warnings[] = "Zeile " . ($index + 1) . ": {$w}";
                }
```
ersetzen durch:
```php
            try {
                // Guard 1: Struktur — verschobene Zeilen erzeugen Muell-Daten
                // in falschen Feldern; lieber abweisen und ZAS melden.
                $structureIssue = $this->detectRowStructureIssue($row);
                if ($structureIssue !== null) {
                    $pn = trim((string) ($row['ZasPersonalNr'] ?? ''));
                    $failed[] = ['personnel_number' => $pn !== '' ? $pn : null, 'reason' => "Zeile " . ($index + 1) . ": {$structureIssue}"];
                    continue;
                }

                $mapped = $this->mapper->map($row);
                foreach ($mapped['warnings'] as $w) {
                    $warnings[] = "Zeile " . ($index + 1) . ": {$w}";
                }

                // Guard 2: ohne ZAS-Personalnummer kein Dubletten-Schluessel —
                // ein Re-Send wuerde die Zeile doppelt anlegen. Abweisen.
                if (!$mapped['personnel_number']) {
                    $failed[] = ['personnel_number' => null, 'reason' => "Zeile " . ($index + 1) . ": ZasPersonalNr fehlt — nicht importiert (kein Dubletten-Schluessel)"];
                    continue;
                }
```

- [ ] **Step 3: Lint + Commit**

Run: `php -l src/Services/Zas/ZasInboundEmployeeImporter.php`
Expected: „No syntax errors detected".

```bash
git add src/Services/Zas/ZasInboundEmployeeImporter.php
git commit -m "feat(zas): Zeilen-Guards — Spaltenversatz + fehlende Personalnummer abweisen"
```

---

### Task 2: Alias-Stufe im Lookup-Reverse-Resolver

**Files:**
- Modify: `src/Services/Zas/ZasLookupReverseResolver.php`

**Interfaces:**
- Consumes/Produces: `resolve()`-Signatur unverändert. Neue Auflösungs-Reihenfolge: exakt (value/label, case-insensitiv) → **Alias** (validiert) → Prefix (wenn erlaubt) → roh.

- [ ] **Step 1: Alias-Konstante einfügen**

Nach der `$cache`-Property einfügen:

```php
    /**
     * Bekannte ZAS-Schreibweisen → unsere Lookup-Codes (aus dem 100er-Testlauf).
     * Keys lowercase. Ein Alias greift NUR, wenn der Ziel-Code im Lookup
     * existiert (sonst Raw-Fallback wie bisher) — dadurch unabhaengig davon
     * deploybar, ob neue Lookup-Werte schon angelegt sind.
     */
    private const ALIASES = [
        'geburtsland' => [
            'deutsch' => 'de',
            'indisch' => 'in', 'indian' => 'in',
            'kosovarisch' => 'xk', 'kosov' => 'xk',
            'tuerkisch' => 'tr', 'türkisch' => 'tr',
            'ghanaisch' => 'gh',
            'lettisch' => 'lv',
            'tunesisch' => 'tn', 'tunesische' => 'tn', 'tunisien' => 'tn',
            'irakisch' => 'iq', 'irakische' => 'iq',
            'iranisch' => 'ir',
            'pakistani' => 'pk', 'pakistanisch' => 'pk',
            'bangladeshi' => 'bd', 'bangladesh' => 'bd', 'bangladeschisch' => 'bd',
            'griechisch' => 'gr',
            'libanesisch' => 'lb',
            'armenisch' => 'am',
            'venezolanisch' => 've',
            'kamerunisch' => 'cm',
        ],
        'beschaeftigung_art' => [
            'studentin' => 'student',
            'student, erwerbstätig' => 'student',
            'student erwerbst.' => 'student',
            'dualer student' => 'student',
            'angestellt' => 'erwerbstaetig',
            'hausfrau' => 'hausmann_frau', 'hausmann' => 'hausmann_frau', 'hausfrau / mann' => 'hausmann_frau',
        ],
        'krankenkasse' => [
            'technicker krankenkassen' => 'tk', 'techniker krankenkassen' => 'tk',
        ],
        'anstellungsart' => [
            '556,00 € basis' => 'minijob',
        ],
    ];
```

- [ ] **Step 2: `resolve()` um die Alias-Stufe erweitern**

Die Methode
```php
    public function resolve(string $lookupName, ?string $incoming, bool $allowPrefix = false): array
    {
        return self::matchValue($this->loadPairs($lookupName), $incoming, $allowPrefix);
    }
```
ersetzen durch:
```php
    public function resolve(string $lookupName, ?string $incoming, bool $allowPrefix = false): array
    {
        $pairs = $this->loadPairs($lookupName);
        $res = self::matchValue($pairs, $incoming, $allowPrefix);
        if ($res['matched'] || $res['value'] === null) {
            return $res;
        }

        // Alias-Stufe: bekannte ZAS-Schreibweisen — nur wenn der Ziel-Code
        // tatsaechlich als Lookup-Wert existiert.
        $alias = self::ALIASES[$lookupName][mb_strtolower(trim((string) $incoming))] ?? null;
        if ($alias !== null && in_array($alias, array_column($pairs, 'value'), true)) {
            return ['value' => $alias, 'matched' => true];
        }

        return $res;
    }
```
(`matchValue()` bleibt unverändert — reine Logik weiterhin standalone testbar.)

- [ ] **Step 3: Lint + Standalone-Verifikation**

Run: `php -l src/Services/Zas/ZasLookupReverseResolver.php` → „No syntax errors detected".

`/tmp/verify_alias.php` (Subklasse stubbt `loadPairs`):

```php
<?php
$src = '/Users/shaustein/Documents/dev/platforms/platform/modules/platforms-recruiting/src/Services/Zas/';
require $src.'ZasLookupReverseResolver.php';
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

$fixtures = [
  'geburtsland' => [['value'=>'de','label'=>'Deutschland'],['value'=>'in','label'=>'Indien'],['value'=>'xk','label'=>'Kosovo'],['value'=>'ve','label'=>'Venezuela']],
  'beschaeftigung_art' => [['value'=>'student','label'=>'Student'],['value'=>'erwerbstaetig','label'=>'Erwerbstätig'],['value'=>'hausmann_frau','label'=>'Hausmann/-Frau'],['value'=>'rentner','label'=>'Rentner']],
  'krankenkasse' => [['value'=>'tk','label'=>'Techniker Krankenkasse']],
  'anstellungsart' => [['value'=>'kurzfristig','label'=>'kurzfristig'],['value'=>'vollzeit','label'=>'Vollzeit']], // OHNE minijob → Alias muss durchfallen
];
$r = new class($fixtures) extends ZasLookupReverseResolver {
    public function __construct(private array $fx) {}
    protected function loadPairs(string $n): array { return $this->fx[$n] ?? []; }
};

$cases = [
  ['deutsch -> de',                $r->resolve('geburtsland', 'Deutsch'),               ['value'=>'de','matched'=>true]],
  ['Indian -> in',                 $r->resolve('geburtsland', 'Indian'),                ['value'=>'in','matched'=>true]],
  ['Kosov -> xk',                  $r->resolve('geburtsland', 'Kosov'),                 ['value'=>'xk','matched'=>true]],
  ['Venezuela exakt (Label)',      $r->resolve('geburtsland', 'Venezuela'),             ['value'=>'ve','matched'=>true]],
  ['Rentner exakt (Label)',        $r->resolve('beschaeftigung_art', 'Rentner'),        ['value'=>'rentner','matched'=>true]],
  ['Studentin -> student',         $r->resolve('beschaeftigung_art', 'Studentin'),      ['value'=>'student','matched'=>true]],
  ['Student, erwerbstätig',        $r->resolve('beschaeftigung_art', 'Student, erwerbstätig'), ['value'=>'student','matched'=>true]],
  ['angestellt -> erwerbstaetig',  $r->resolve('beschaeftigung_art', 'angestellt'),     ['value'=>'erwerbstaetig','matched'=>true]],
  ['Hausfrau -> hausmann_frau',    $r->resolve('beschaeftigung_art', 'Hausfrau'),       ['value'=>'hausmann_frau','matched'=>true]],
  ['Technicker-Typo -> tk',        $r->resolve('krankenkasse', 'Technicker Krankenkassen'), ['value'=>'tk','matched'=>true]],
  ['Alias-Ziel fehlt -> roh',      $r->resolve('anstellungsart', '556,00 € Basis'),     ['value'=>'556,00 € Basis','matched'=>false]],
  ['Unbekannt bleibt roh',         $r->resolve('geburtsland', 'AOK'),                   ['value'=>'AOK','matched'=>false]],
  ['Exakt-Match hat Vorrang',      $r->resolve('geburtsland', 'Deutschland'),           ['value'=>'de','matched'=>true]],
];
$ok = true;
foreach ($cases as [$n,$got,$exp]) { $p = $got === $exp; $ok = $ok && $p; echo ($p?'PASS':'FAIL')." — $n → ".json_encode($got, JSON_UNESCAPED_UNICODE)."\n"; }
exit($ok ? 0 : 1);
```

Run: `php /tmp/verify_alias.php; echo "exit=$?"; rm /tmp/verify_alias.php`
Expected: 13× PASS, `exit=0`.

- [ ] **Step 4: Commit**

```bash
git add src/Services/Zas/ZasLookupReverseResolver.php
git commit -m "feat(zas): Alias-Stufe fuer ZAS-Freitext (Nationalitaeten, Ichbin, KK-Typo, Minijob)"
```

---

### Task 3: Merge + Deploy + Server-Re-Test gegen die echte 100er-Lieferung

- [ ] **Step 1:** Merge `feat/zas-import-haertung` → main (--no-ff), Push, meingedeck-Bump + Push. (Keine Migration nötig.)

- [ ] **Step 2: Re-Test der gespeicherten Lieferung ID 10** (Forge-Command-Runner; ruft den echten Importer mit `dryRun=true` auf — legt NICHTS an):

```bash
php artisan tinker --execute="
\$f = \Platform\Recruiting\Models\RecZasInboundFile::find(10);
\$raw = preg_replace('/^\xEF\xBB\xBF/', '', \Illuminate\Support\Facades\Storage::disk(\$f->disk)->get(\$f->stored_path));
\$lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', \$raw), fn(\$l)=>trim(\$l)!==''));
\$cols = str_getcsv(\$lines[0], ';', '\"', '');
\$rows = [];
foreach (array_slice(\$lines,1) as \$line) { \$vals = array_map('trim', str_getcsv(\$line, ';', '\"', '')); \$m=[]; \$n=max(count(\$cols),count(\$vals)); for(\$i=0;\$i<\$n;\$i++){ \$m[\$cols[\$i] ?? ('col_'.\$i)] = \$vals[\$i] ?? ''; } \$rows[]=\$m; }
\$res = app(\Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter::class)->import(\$rows, \$f, true);
print('status='.\$res['status'].' create='.count(\$res['created']).' skip='.count(\$res['skipped']).' fail='.count(\$res['failed']).' warn='.count(\$res['warnings']).PHP_EOL);
foreach (\$res['failed'] as \$x) print('FAILED: '.json_encode(\$x, JSON_UNESCAPED_UNICODE).PHP_EOL);
foreach (\$res['warnings'] as \$w) print('WARN: '.\$w.PHP_EOL);
"
```

**Expected:** `fail=1` (die Knezevic-Zeile, mit Struktur-Grund) · `create=99` · Warnungen von ~115 auf grob **~15–25** gesunken (übrig: „AOK", Exoten, Freitexte ohne Alias wie „Bürohelfer/in"/„ausbildungssuchend"/„zwischen Schule und Studium", `Eintritt 'Ja'`-Rest entfällt durch Guard 1).

- [ ] **Step 3:** Ergebnis hier auswerten → Doku (`dev/docs/meingedeck/zas-mitarbeiter-import.md`: Guards + Alias-Stufe ergänzen) + Memory aktualisieren → **grünes Licht an Michel** (mit Hinweis: Datensatz „Knezevic, Jelena" bei ZAS prüfen — Spaltenversatz).

## Self-Review

- **Alle Testlauf-Funde abgedeckt:** Zeile 36/Versatz (T1 Guard 1), fehlende PersNr (T1 Guard 2), Nationalitäts-Adjektive + Ichbin-Freitext + KK-Typo (T2 Aliase), fehlende Länder/Rentner/Minijob (T0), „AOK" bewusst roh (dokumentiert). ✅
- **Reihenfolge sicher:** Alias validiert gegen existierende Werte → Code auch ohne T0 deploybar; Exakt-Match hat Vorrang vor Alias (Test „Deutschland"). ✅
- **Kein Verhaltensbruch:** Guards wirken nur auf Zeilen, die vorher Müll erzeugt hätten; `matchValue()` unverändert; keine Migration; Export unberührt (nur neue Label-Auflösungen durch neue Lookup-Werte). ✅
- **Placeholder-Scan:** keine. **Typen:** `resolve()`-Signatur/Rückgabe unverändert (`['value','matched']`). ✅
