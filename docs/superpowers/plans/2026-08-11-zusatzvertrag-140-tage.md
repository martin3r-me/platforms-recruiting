# Zusatzvertrag „Erklärung 140-Tage-Tätigkeit" — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Erklärung zur 140-Tage-Tätigkeit als Zusatzvertrag `AT-140` auf dem HR-Schreibtisch auswählbar machen, personalisiert im Bewerber-Portal anzeigen und dort mit einer Resttage-Angabe elektronisch unterschreiben lassen.

**Architecture:** Die Zusatzvertrags-Mechanik existiert vollständig (Dropdown, `SendContractsService`, Portal, PDF). Neu sind: eine Vertragsvorlage als Datensatz (kein Code), das Auflösen von Lookup-Werten zu Labels im Vorlagen-Renderer, ein zweiter Vorschalt-Schritt im Signier-Flow, und eine Typ-Weiche in `embedPreSigningData()`, damit sowohl das Signieren als auch eine spätere Re-Personalisierung die Resttage-Zahl korrekt behandeln.

**Tech Stack:** PHP 8.2+, Laravel, Livewire 3, Blade, Tailwind, PHPUnit 11 (pur, ohne Laravel/DB).

## Global Constraints

- **Testkonvention:** Nur reines PHPUnit. `tests/bootstrap.php` registriert einen eigenen Autoloader **ohne Composer und ohne Illuminate** — nur `Platform\Recruiting\*` aus `src/` ist ladbar. Klassen, die eine Framework-Klasse importieren (alle Models, Livewire-Komponenten, Services mit Facades), sind im Test **nicht ladbar**. Testbare Logik gehört deshalb nach `src/Support/` und darf dort **keinen** `use Illuminate\...`-Import haben.
- **Testlauf (aus dem Modulverzeichnis):** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
- **Baseline vor Beginn:** 437 Tests, 1307 Assertions, grün. Nach jeder Task müssen alle Tests grün sein.
- **Blade-Prüfung: `php tools/blade-check.php [datei]`** — nie `php -l` auf eine `.blade.php`. **Repo-Learning:** `php -l` auf eine Blade-Datei meldet *immer* „No syntax errors" und prüft nichts; Blade-Dateien sind kein PHP. Das Skript kompiliert mit dem echten `BladeCompiler` und lintet das erzeugte PHP, plus eine separate `@php`/`@endphp`-Balance-Prüfung. Belegte Abdeckung: Syntaxfehler im `@php`-Block ✓, fehlendes `@endif` ✓, `@php` ohne `@endphp` ✓ (nur über die Balance-Prüfung — Blade lässt ein unterminiertes `@php` als literalen Text stehen, das kompilierte PHP bleibt gültig). Verifiziert: alle 40 Modul-Views grün, drei absichtlich kaputte Kopien rot.
- **Der Blade-Check darf die Host-App nicht booten.** `meingedeck` hat **kein `.env`**, und beim Booten fragen Service-Provider (z. B. `CrmServiceProvider`) die Datenbank ab — ein Check, der davon abhängt, ist nicht reproduzierbar und meldete in einer Vorabversion trotz Fatal Error `EXIT=0`. Das Skript nutzt deshalb eine **ungebootete** `Illuminate\Foundation\Application` plus Stub-View-Factory. Verifiziert: bleibt mit absichtlich unerreichbarer DB grün.
- **Client-Eingaben sind kein Zustand.** Livewire-Properties sind ohne `#[Locked]` client-schreibbar. Sicherheitsrelevante Entscheidungen (welcher Vertrag, welcher Vorschalt-Typ) werden **serverseitig aus dem frisch geladenen Model abgeleitet**, nicht aus einer Property gelesen.
- **Kein Edit außerhalb `platforms-recruiting`** ohne ausdrückliche Rückfrage (gilt für `platforms-core`, CRM, HCM).
- **Vertragscode:** `AT-140` — exakt so, das Dropdown filtert auf `code LIKE 'AT-%'`.
- **Wortlaut des Dokuments** wird unverändert übernommen, inklusive „140 Tage" und „§9 Nr. 9 ArGV".
- **Resttage-Feld:** Pflichteingabe, ganze Zahl, 0–140, **kein Vorbelegungswert**.
- **Kein `app()->singleton()`, kein `static`-Cache** für den Lookup-Resolver — Queue-Worker leben zu lange, veraltete Labels wären ein stiller Datenfehler.
- **Branch:** `feat/zusatzvertrag-140-tage`, vor dem Anlegen `git fetch` und Basis gegen `origin/main` prüfen.
- **Nach dem Merge:** `meingedeck` composer.lock bumpen, sonst ist nichts live. **Kein `queue:restart` nötig** — belegt: die einzigen zwei `ShouldQueue`-Jobs im Modul (`NotifyWaitlistForInterview`, `MatchApplicantToPostingJob`) enthalten keine Vertragslogik, und die Kette ab `InterviewBookings/Index.php:562` läuft synchron im Request.

---

## File Structure

| Datei | Verantwortung |
|---|---|
| `src/Support/LookupLabelFormatter.php` *(neu)* | Reine Formatierung: Lookup-Wert + Label-Map → Klartext |
| `src/Support/ContractPreSigningType.php` *(neu)* | Reine Entscheidung: Vertragscode → welcher Vorschalt-Schritt |
| `src/Support/ResttagePlaceholder.php` *(neu)* | Reine Textersetzung + Erkennung unaufgelöster Platzhalter |
| `src/Services/Zas/ZasLookupResolver.php` *(ändern)* | Lädt weiterhin die Map aus der DB, delegiert das Formatieren |
| `src/Models/RecContractTemplate.php` *(ändern)* | Löst Lookup-Extrafelder zu Labels auf, Resolver-Instanz pro Dokument |
| `src/Models/RecContract.php` *(ändern)* | Typ-Weiche in `embedPreSigningData()` |
| `src/Livewire/Public/ContractSigning.php` *(ändern)* | Zweiter Vorschalt-Typ, Resttage-Eingabe, Guard |
| `resources/views/livewire/public/contract-signing.blade.php` *(ändern)* | UI des Resttage-Schritts, Guard-Hinweis |
| `tests/Unit/LookupLabelFormatterTest.php` *(neu)* | Tests Task 1 |
| `tests/Unit/ContractPreSigningTypeTest.php` *(neu)* | Tests Task 3 |
| `tests/Unit/ResttagePlaceholderTest.php` *(neu)* | Tests Task 4 |
| `tools/blade-check.php` *(neu)* | Blade-Syntax-Check fürs ganze Modul: kompiliert mit dem echten `BladeCompiler` und lintet das erzeugte PHP, plus `@php`/`@endphp`-Balance. Ersetzt das wertlose `php -l` auf Blade-Dateien. Wird in Task 7 mit committet. |

**Namespace-Hinweis:** `ZasLookupResolver` liegt im `Services\Zas`-Namespace, wird nach Task 2 aber auch vom Vertrags-Renderer benutzt. Ein Umbenennen/Verschieben würde vier ZAS-Dateien anfassen, die mit diesem Feature nichts zu tun haben — bewusst nicht Teil dieses Plans.

---

### Task 1: Lookup-Werte zu Labels formatieren (reine Logik)

Lookup-Extrafelder speichern den Maschinenwert (`tr`), das Dokument braucht das Label (`Türkei`). Die Formatierlogik steckt heute in `ZasLookupResolver::resolveLabel()` verwoben mit dem DB-Zugriff. Sie wird herausgezogen, damit sie testbar ist und von beiden Seiten genutzt werden kann.

**Files:**
- Create: `src/Support/LookupLabelFormatter.php`
- Test: `tests/Unit/LookupLabelFormatterTest.php`
- Modify: `src/Services/Zas/ZasLookupResolver.php:38-60`

**Interfaces:**
- Produces: `LookupLabelFormatter::format(mixed $value, array $labelMap): ?string`
  — `$labelMap` ist `['tr' => 'Türkei', ...]`. Rückgabe `null` bei leerem Wert oder leerem Ergebnis, sonst der Klartext. Arrays werden komma-separiert. Unbekannte Werte fallen auf den Rohwert zurück.

- [ ] **Step 1: Test schreiben**

`tests/Unit/LookupLabelFormatterTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\LookupLabelFormatter;

class LookupLabelFormatterTest extends TestCase
{
    private const MAP = ['tr' => 'Türkei', 'de' => 'Deutschland', 'xk' => 'Kosovo'];

    public function test_resolves_scalar_value_to_label(): void
    {
        $this->assertSame('Türkei', LookupLabelFormatter::format('tr', self::MAP));
    }

    public function test_resolves_array_to_comma_separated_labels(): void
    {
        $this->assertSame(
            'Türkei, Kosovo',
            LookupLabelFormatter::format(['tr', 'xk'], self::MAP)
        );
    }

    public function test_unknown_value_falls_back_to_raw_value(): void
    {
        $this->assertSame('zz', LookupLabelFormatter::format('zz', self::MAP));
    }

    public function test_empty_map_falls_back_to_raw_value(): void
    {
        $this->assertSame('tr', LookupLabelFormatter::format('tr', []));
    }

    public function test_null_and_empty_string_return_null(): void
    {
        $this->assertNull(LookupLabelFormatter::format(null, self::MAP));
        $this->assertNull(LookupLabelFormatter::format('', self::MAP));
    }

    public function test_empty_array_returns_null(): void
    {
        $this->assertNull(LookupLabelFormatter::format([], self::MAP));
    }

    public function test_array_with_only_empty_entries_returns_null(): void
    {
        $this->assertNull(LookupLabelFormatter::format(['', null], self::MAP));
    }

    public function test_non_string_scalar_is_cast(): void
    {
        $this->assertSame('42', LookupLabelFormatter::format(42, self::MAP));
    }

    /**
     * BEWUSSTE ABWEICHUNG vom alten Verhalten. Der alte Code filterte die
     * Array-Labels mit ->filter() ohne Callback, also nach PHP-Truthiness —
     * damit fiel ein Label '0' (oder ein unbekannter Wert, der auf '0'
     * zurueckfaellt) still aus der Liste. Neu bleibt er drin. Das neue
     * Verhalten ist das richtige; der Test nagelt es fest.
     */
    public function test_zero_label_is_kept_unlike_old_filter_behaviour(): void
    {
        $this->assertSame('0', LookupLabelFormatter::format(['0'], self::MAP));
        $this->assertSame('Türkei, 0', LookupLabelFormatter::format(['tr', '0'], self::MAP));
    }

    /**
     * DOKUMENTIERTES BESTANDSVERHALTEN, kein Bug-Fix. Ein als JSON-String
     * gespeicherter Multi-Select wird NICHT dekodiert — er wird als Ganzes
     * in der Map gesucht und faellt auf den Rohwert zurueck. Der ZAS-Export
     * fuettert genau solche Rohwerte (ZasFieldResolver:447-451 liest die
     * value-Spalte ohne decodeSelectValue). Das hier zu "reparieren" wuerde
     * den ZAS-Export still veraendern — gehoert auf die ZAS-Phase-2-Liste.
     */
    public function test_json_string_is_not_decoded(): void
    {
        $this->assertSame('["tr","xk"]', LookupLabelFormatter::format('["tr","xk"]', self::MAP));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter LookupLabelFormatterTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\LookupLabelFormatter" not found`

- [ ] **Step 3: Klasse implementieren**

`src/Support/LookupLabelFormatter.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Formatiert Lookup-Feldwerte zu ihren menschenlesbaren Labels.
 *
 * Lookup-Extrafelder speichern den Maschinenwert ("tr"), Dokumente und
 * Exporte brauchen das Label ("Türkei"). Diese Klasse macht nur die
 * Formatierung — das Laden der value=>label-Map bleibt beim Aufrufer
 * (siehe ZasLookupResolver). Bewusst ohne Framework-Import, damit sie
 * im Unit-Test ohne Laravel-Bootstrap ladbar ist.
 */
final class LookupLabelFormatter
{
    /**
     * @param  mixed  $value     Roher Feldwert (String, Zahl oder Array bei Multi-Select)
     * @param  array<string, string>  $labelMap  value => label
     * @return string|null  Klartext, oder null wenn nichts aufzulösen war
     */
    public static function format(mixed $value, array $labelMap): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $labels = [];
            foreach ($value as $entry) {
                $label = self::format($entry, $labelMap);
                if ($label !== null && $label !== '') {
                    $labels[] = $label;
                }
            }

            return $labels === [] ? null : implode(', ', $labels);
        }

        $stringValue = (string) $value;

        return $labelMap[$stringValue] ?? $stringValue;
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter LookupLabelFormatterTest`
Expected: PASS (10 Tests)

- [ ] **Step 5: `ZasLookupResolver` auf die neue Klasse umstellen**

In `src/Services/Zas/ZasLookupResolver.php` die Methode `resolveLabel()` (Zeilen 38–60) ersetzen durch:

```php
    public function resolveLabel(int $definitionId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!isset($this->labelMaps[$definitionId])) {
            $this->loadLabelMap($definitionId);
        }

        return LookupLabelFormatter::format($value, $this->labelMaps[$definitionId]);
    }
```

Import oben ergänzen (nach `use Illuminate\Support\Facades\DB;`):

```php
use Platform\Recruiting\Support\LookupLabelFormatter;
```

Die ungenutzte `use Illuminate\Support\Collection;`-Zeile bleibt stehen — nicht anfassen, gehört nicht zu diesem Feature.

**Gleich bleibt:** leerer Wert → `null`, leeres Array → `null`, Array → komma-separiert mit `', '`, unbekannter Wert → Rohwert, JSON-String → **nicht** dekodiert (Rohwert).

**Eine bewusste Abweichung:** Der alte Code filterte die Array-Labels mit `->filter()` **ohne Callback**, also nach PHP-Truthiness. Ein Label `'0'` — oder ein unbekannter Wert, der über `?? $stringValue` auf `'0'` zurückfällt — verschwand damit still aus der Liste. `LookupLabelFormatter` prüft stattdessen explizit auf `null` und `''` und behält die `'0'`. Das ist die Korrektur eines echten, wenn auch seltenen Datenverlusts; sie trifft ausschließlich Multi-Select-Lookups mit einem `'0'`-Label. Festgenagelt durch `test_zero_label_is_kept_unlike_old_filter_behaviour`.

Zweiter, unkritischer Unterschied: Bei einem leeren Array lädt die neue Fassung die Label-Map, bevor sie `null` liefert — eine Query, die der alte Code sich gespart hätte. Kein Handlungsbedarf.

- [ ] **Step 6: Gesamte Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS, mindestens 447 Tests (437 Baseline + 10 neue)

- [ ] **Step 7: Commit**

```bash
git add src/Support/LookupLabelFormatter.php tests/Unit/LookupLabelFormatterTest.php src/Services/Zas/ZasLookupResolver.php
git commit -m "refactor(recruiting): Lookup-Label-Formatierung in testbare Support-Klasse ziehen"
```

---

### Task 2: Vorlagen-Renderer löst Lookup-Felder auf

`RecContractTemplate::personalizeContent()` gibt bei Lookup-Extrafeldern heute den Rohwert aus. `{{nationalitaet}}` würde als `tr` im Dokument stehen.

**Files:**
- Modify: `src/Models/RecContractTemplate.php` — `personalizeContent()` (ab Zeile 73) und `resolveSource()` (ab Zeile 99)

**Interfaces:**
- Consumes: `ZasLookupResolver::resolveLabel(int $definitionId, mixed $value): ?string`
- Produces: geänderte private Signatur `resolveSource(string $source, RecApplicant $applicant, $contact, ?RecContract $contract, ?ZasLookupResolver $lookups): string`

**Lebensdauer der Resolver-Instanz:** Eine Instanz **pro `personalizeContent()`-Aufruf**, also pro gerendertem Dokument. Kein `app()`, kein Singleton, kein `static`. Begründung: Der Cache soll genau so lange leben wie ein Dokument. Ein Singleton würde im Queue-Worker über Jobs hinweg überleben und veraltete Labels ausliefern, nachdem jemand einen Lookup-Wert umbenannt hat. Der Preis ist gering — der Resolver wird nur angefasst, wenn ein Mapping wirklich auf eine Lookup-Definition zeigt; für AV und IFSG (kein einziges Lookup-Mapping) entsteht **keine** zusätzliche Query.

**Kein Unit-Test möglich:** `RecContractTemplate` erweitert `Illuminate\Database\Eloquent\Model` und ist ohne Composer-Autoloader im Test nicht ladbar (siehe Global Constraints). Die reine Formatierlogik ist in Task 1 abgedeckt; dieser Schritt ist Verdrahtung und wird in Task 8 live verifiziert.

- [ ] **Step 1: Import ergänzen**

In `src/Models/RecContractTemplate.php` bei den `use`-Statements oben ergänzen:

```php
use Platform\Recruiting\Services\Zas\ZasLookupResolver;
```

- [ ] **Step 2: Resolver in `personalizeContent()` erzeugen und durchreichen**

In `personalizeContent()` den Block

```php
        $replacements = [];
        foreach ($mappings as $placeholder => $source) {
            $replacements['{{' . $placeholder . '}}'] = $this->resolveSource($source, $applicant, $contactModel, $contract);
        }
```

ersetzen durch:

```php
        // Eine Resolver-Instanz pro Dokument: der Label-Cache lebt genau so
        // lange wie dieser Render-Vorgang. Bewusst kein Singleton — ein
        // langlebiger Queue-Worker wuerde sonst veraltete Labels ausliefern.
        $lookups = new ZasLookupResolver();

        $replacements = [];
        foreach ($mappings as $placeholder => $source) {
            $replacements['{{' . $placeholder . '}}'] = $this->resolveSource($source, $applicant, $contactModel, $contract, $lookups);
        }
```

- [ ] **Step 3: Signatur von `resolveSource()` erweitern**

Die Zeile

```php
    private function resolveSource(string $source, RecApplicant $applicant, $contact, ?RecContract $contract): string
```

ersetzen durch:

```php
    private function resolveSource(string $source, RecApplicant $applicant, $contact, ?RecContract $contract, ?ZasLookupResolver $lookups = null): string
```

Der Default `= null` hält die Methode aufrufbar, falls sie später von woanders genutzt wird; der einzige heutige Aufrufer reicht die Instanz durch.

- [ ] **Step 4: Lookup-Auflösung in den Extra-Field-Zweig einbauen**

Im Zweig `if (str_starts_with($source, 'applicant.'))` → `if (str_starts_with($field, 'extra_field.'))`. Direkt **nach** dem Leer-Check und **vor** der Carbon-Behandlung einfügen:

Vorher:

```php
                $value = $applicant->getExtraField($efName);
                if ($value === null || $value === '') {
                    return '';
                }
                if ($value instanceof Carbon) {
```

Nachher:

```php
                $value = $applicant->getExtraField($efName);
                if ($value === null || $value === '') {
                    return '';
                }

                // Lookup-Felder speichern den Maschinenwert ("tr") — im Dokument
                // muss das Label stehen ("Türkei"). Nur wenn die Definition
                // wirklich ein Lookup ist, sonst bleibt alles wie gehabt.
                if ($lookups !== null) {
                    $definition = $applicant->getExtraFieldDefinitions()->firstWhere('name', $efName);
                    $lookupId = $definition?->options['lookup_id'] ?? null;
                    if ($lookupId) {
                        $label = $lookups->resolveLabel((int) $definition->id, $value);
                        if ($label !== null && $label !== '') {
                            return $label;
                        }
                    }
                }

                if ($value instanceof Carbon) {
```

Der Rest der Methode bleibt unverändert. Nicht-Lookup-Felder laufen exakt wie bisher durch die Carbon-/Datums-/Skalar-Behandlung. Belegt: Über alle zehn Vorlagen in Team 3 gibt es genau ein `applicant.extra_field.*`-Mapping (`kontakt_geburtsort` → `geburtsort`), und `geburtsort` ist vom Typ `text` mit `options: []` — kein Lookup. Für den Bestand ändert sich also nichts.

- [ ] **Step 5: Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS, unverändert grün

- [ ] **Step 6: Commit**

```bash
git add src/Models/RecContractTemplate.php
git commit -m "feat(recruiting): Vertragsvorlagen rendern Lookup-Felder als Klartext-Label"
```

---

### Task 3: Vorschalt-Schritt-Typ als reine Entscheidung

Der Signier-Flow entscheidet heute über `str_starts_with($code, 'AV-')`, ob der §15/§16-Schritt kommt (`ContractSigning.php:71`). Es braucht einen zweiten Typ, und die Entscheidung muss ohne Livewire testbar sein.

**Files:**
- Create: `src/Support/ContractPreSigningType.php`
- Test: `tests/Unit/ContractPreSigningTypeTest.php`

**Interfaces:**
- Produces:
  - `ContractPreSigningType::PAR_15_16` = `'par1516'`
  - `ContractPreSigningType::RESTTAGE` = `'resttage'`
  - `ContractPreSigningType::forCode(?string $code): ?string`

- [ ] **Step 1: Test schreiben**

`tests/Unit/ContractPreSigningTypeTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ContractPreSigningType;

class ContractPreSigningTypeTest extends TestCase
{
    public function test_arbeitsvertrag_codes_get_the_paragraph_step(): void
    {
        $this->assertSame(ContractPreSigningType::PAR_15_16, ContractPreSigningType::forCode('AV-default'));
        $this->assertSame(ContractPreSigningType::PAR_15_16, ContractPreSigningType::forCode('AV-010'));
        $this->assertSame(ContractPreSigningType::PAR_15_16, ContractPreSigningType::forCode('AV-260'));
    }

    public function test_at140_gets_the_resttage_step(): void
    {
        $this->assertSame(ContractPreSigningType::RESTTAGE, ContractPreSigningType::forCode('AT-140'));
    }

    public function test_ifsg_and_unknown_codes_get_no_step(): void
    {
        $this->assertNull(ContractPreSigningType::forCode('IFSG'));
        $this->assertNull(ContractPreSigningType::forCode('AV'));
        $this->assertNull(ContractPreSigningType::forCode('SONSTIGES'));
    }

    public function test_other_at_codes_get_no_step(): void
    {
        // Zusatzvertraege ohne Resttage-Frage duerfen keinen Schritt bekommen.
        $this->assertNull(ContractPreSigningType::forCode('AT-SONSTIGES'));
    }

    public function test_null_and_empty_code_get_no_step(): void
    {
        $this->assertNull(ContractPreSigningType::forCode(null));
        $this->assertNull(ContractPreSigningType::forCode(''));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ContractPreSigningTypeTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\ContractPreSigningType" not found`

- [ ] **Step 3: Klasse implementieren**

`src/Support/ContractPreSigningType.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet, welcher Vorschalt-Schritt vor der Unterschrift kommt.
 *
 * Arbeitsvertraege (AV-*) fragen Angaben nach §15/§16 ab. Die Erklaerung
 * zur 140-Tage-Taetigkeit (AT-140) fragt das Rest-Kontingent ab. Alles
 * andere — insbesondere IFSG — geht direkt zu Ansicht und Unterschrift.
 *
 * Eine weitere Vorlage mit Resttage-Frage ist ein Eintrag in
 * RESTTAGE_CODES — mehr nicht.
 */
final class ContractPreSigningType
{
    public const PAR_15_16 = 'par1516';
    public const RESTTAGE  = 'resttage';

    /** Vertragscodes, die vor der Unterschrift nach dem Rest-Kontingent fragen. */
    private const RESTTAGE_CODES = ['AT-140'];

    public static function forCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (in_array($code, self::RESTTAGE_CODES, true)) {
            return self::RESTTAGE;
        }

        if (str_starts_with($code, 'AV-')) {
            return self::PAR_15_16;
        }

        return null;
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ContractPreSigningTypeTest`
Expected: PASS (5 Tests)

- [ ] **Step 5: Commit**

```bash
git add src/Support/ContractPreSigningType.php tests/Unit/ContractPreSigningTypeTest.php
git commit -m "feat(recruiting): Vorschalt-Schritt-Typ als testbare Support-Klasse"
```

---

### Task 4: Resttage-Platzhalter — Ersetzung, Typ-Erkennung, Guard

Drei zusammengehörige reine Funktionen: den Platzhalter füllen, erkennen ob ein `pre_signing_data`-Array vom Resttage-Typ ist, und prüfen ob im fertigen Text noch ein unaufgelöster Platzhalter steht.

**Files:**
- Create: `src/Support/ResttagePlaceholder.php`
- Test: `tests/Unit/ResttagePlaceholderTest.php`

**Interfaces:**
- Produces:
  - `ResttagePlaceholder::PLACEHOLDER` = `'{{resttage}}'`
  - `ResttagePlaceholder::TYPE` = `'resttage'` — Diskriminator in `pre_signing_data`. Wertgleich mit `ContractPreSigningType::RESTTAGE`, aber bewusst eine eigene Konstante: die eine benennt einen UI-Schritt, die andere ein persistiertes Datenformat. Sie dürfen sich unabhängig ändern.
  - `ResttagePlaceholder::fill(string $content, int $tage): string` — ersetzt **alle** Vorkommen
  - `ResttagePlaceholder::appliesTo(array $data): bool` — `true` wenn `$data['type'] === 'resttage'`
  - `ResttagePlaceholder::embed(string $content, array $data): ?string` — **der einzige Einstiegspunkt für `embedPreSigningData()`.** `null` = nicht zuständig (Altdaten). Sonst der Inhalt: gefüllt, wenn eine brauchbare Zahl da ist — **unverändert** (Platzhalter bleibt stehen), wenn nicht. Damit liegt der Lesepfad-Schutz in der getesteten Klasse und nicht als `??`-Fallback im Model.
  - `ResttagePlaceholder::hasUnresolvedPlaceholder(string $content): bool` — generisches `{{…}}`-Muster

- [ ] **Step 1: Test schreiben**

`tests/Unit/ResttagePlaceholderTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ResttagePlaceholder;

class ResttagePlaceholderTest extends TestCase
{
    public function test_replaces_placeholder_with_number(): void
    {
        $content = '<p>im laufenden Kalenderjahr noch {{resttage}} Tage</p>';

        $this->assertSame(
            '<p>im laufenden Kalenderjahr noch 87 Tage</p>',
            ResttagePlaceholder::fill($content, 87)
        );
    }

    public function test_replaces_every_occurrence(): void
    {
        $content = '{{resttage}} und nochmal {{resttage}}';

        $this->assertSame('12 und nochmal 12', ResttagePlaceholder::fill($content, 12));
    }

    public function test_zero_is_written_out(): void
    {
        $this->assertSame('noch 0 Tage', ResttagePlaceholder::fill('noch {{resttage}} Tage', 0));
    }

    public function test_fill_is_idempotent(): void
    {
        $once = ResttagePlaceholder::fill('noch {{resttage}} Tage', 90);

        $this->assertSame($once, ResttagePlaceholder::fill($once, 90));
    }

    public function test_content_without_placeholder_is_untouched(): void
    {
        $content = '<p>Ein Vertrag ohne Platzhalter</p>';

        $this->assertSame($content, ResttagePlaceholder::fill($content, 140));
    }

    public function test_applies_to_recognises_resttage_type(): void
    {
        $this->assertTrue(ResttagePlaceholder::appliesTo(['type' => 'resttage', 'resttage' => 90]));
    }

    public function test_applies_to_rejects_legacy_par1516_rows(): void
    {
        // Bestandszeilen haben keinen 'type'-Schluessel — sie sind immer §15/§16.
        $this->assertFalse(ResttagePlaceholder::appliesTo([
            'par15_has_previous' => false,
            'par15_entries' => [],
            'par16_was_jobseeking' => false,
            'par16_entries' => [],
        ]));
    }

    public function test_applies_to_rejects_empty_array(): void
    {
        $this->assertFalse(ResttagePlaceholder::appliesTo([]));
    }

    public function test_detects_unresolved_placeholder(): void
    {
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('noch {{resttage}} Tage'));
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('noch {{ resttage }} Tage'));
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('Hallo {{kontakt_vorname}}'));
    }

    /**
     * Der Vorlagen-Editor validiert Platzhalternamen nur als
     * required|string|max:255 — Punkte und Bindestriche sind erlaubt. Dass
     * heute alle Mappings snake_case sind, ist eine Momentaufnahme, keine
     * Systemeigenschaft. Ein Guard, der hier nicht ausloest, schuetzt nicht.
     */
    public function test_detects_placeholders_with_dots_and_dashes(): void
    {
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('Hallo {{kontakt.vorname}}'));
        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder('Hallo {{kontakt-vorname}}'));
    }

    public function test_clean_content_has_no_unresolved_placeholder(): void
    {
        $this->assertFalse(ResttagePlaceholder::hasUnresolvedPlaceholder('noch 90 Tage'));
        $this->assertFalse(ResttagePlaceholder::hasUnresolvedPlaceholder('<p style="{color:red}">x</p>'));
    }

    public function test_embed_is_not_responsible_for_legacy_rows(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertNull(ResttagePlaceholder::embed($content, [
            'par15_has_previous' => false,
            'par15_entries' => [],
        ]));
        $this->assertNull(ResttagePlaceholder::embed($content, []));
    }

    public function test_embed_fills_when_number_present(): void
    {
        $this->assertSame(
            'noch 87 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => 87])
        );
    }

    public function test_embed_accepts_numeric_string(): void
    {
        $this->assertSame(
            'noch 87 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => '87'])
        );
    }

    /**
     * KERN DES LESEPFAD-SCHUTZES. RePersonalizeContractsTool nimmt
     * pre_signing_data unvalidiert aus der DB. Fehlt die Zahl, darf NICHT
     * still "noch 0 Tage" in ein unterschriebenes Dokument geschrieben
     * werden — der Platzhalter muss stehen bleiben, damit der Guard greift.
     */
    public function test_embed_leaves_content_untouched_when_number_missing(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => null]));
    }

    public function test_embed_leaves_content_untouched_when_number_not_numeric(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => 'abc']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => []]));
    }

    /**
     * Der Lesepfad muss dieselbe Form verlangen wie der Schreibpfad
     * (integer|min:0|max:140). is_numeric waere zu permissiv gewesen:
     * '-5' und '1e3' landeten als Zahl im Dokument, 87.9 wuerde still auf
     * 87 trunkiert — eine falsche Zahl in einer haftungsbewehrten
     * Selbstauskunft, ohne Absturz und ohne Auffaelligkeit.
     */
    public function test_embed_rejects_values_the_write_path_would_never_produce(): void
    {
        $content = 'noch {{resttage}} Tage';

        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '-5']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => -5]));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '1e3']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => 87.9]));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => '+5']));
        $this->assertSame($content, ResttagePlaceholder::embed($content, ['type' => 'resttage', 'resttage' => ' 87']));
    }

    /**
     * Der Schreibpfad castet auf int, bevor er speichert — dieser Weg muss
     * offen bleiben. Fuehrende Nullen sind zulaessig und werden zur Zahl;
     * hier festgenagelt, damit das niemand spaeter auf ctype_digit plus
     * Laengenpruefung umbaut und dabei '007' verliert.
     */
    public function test_embed_accepts_int_and_leading_zeroes(): void
    {
        $this->assertSame(
            'noch 87 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => 87])
        );
        $this->assertSame(
            'noch 7 Tage',
            ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage', 'resttage' => '007'])
        );
    }

    public function test_embed_result_still_carries_placeholder_for_the_guard(): void
    {
        $result = ResttagePlaceholder::embed('noch {{resttage}} Tage', ['type' => 'resttage']);

        $this->assertTrue(ResttagePlaceholder::hasUnresolvedPlaceholder($result));
    }
}
```

- [ ] **Step 2: Test laufen lassen, Fehlschlag prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ResttagePlaceholderTest`
Expected: FAIL — `Class "Platform\Recruiting\Support\ResttagePlaceholder" not found`

- [ ] **Step 3: Klasse implementieren**

`src/Support/ResttagePlaceholder.php`:

```php
<?php

namespace Platform\Recruiting\Support;

/**
 * Der Platzhalter fuer das Rest-Kontingent in der 140-Tage-Erklaerung.
 *
 * Er ist in der Vorlage bewusst NICHT als field_mapping hinterlegt: damit
 * ueberlebt er personalizeContent() (das nur ueber die gemappten Keys
 * ersetzt) und wird erst beim Unterschreiben durch die Angabe des
 * Bewerbers ersetzt.
 */
final class ResttagePlaceholder
{
    public const PLACEHOLDER = '{{resttage}}';

    /** Diskriminator in rec_contracts.pre_signing_data. */
    public const TYPE = 'resttage';

    public static function fill(string $content, int $tage): string
    {
        return str_replace(self::PLACEHOLDER, (string) $tage, $content);
    }

    /**
     * Ist dieses pre_signing_data-Array vom Resttage-Typ?
     *
     * Bestandszeilen (§15/§16) haben keinen 'type'-Schluessel — bis AT-140
     * war das der einzige Vorschalt-Schritt. Fehlt der Schluessel, ist die
     * Antwort deshalb immer false.
     */
    public static function appliesTo(array $data): bool
    {
        return ($data['type'] ?? null) === self::TYPE;
    }

    /**
     * Einstiegspunkt fuer RecContract::embedPreSigningData().
     *
     * null  = nicht zustaendig (Altdaten ohne 'type').
     * Sonst = der Inhalt. Gefuellt, wenn eine brauchbare Zahl vorliegt.
     *         UNVERAENDERT, wenn nicht — der Platzhalter bleibt dann stehen,
     *         damit der Guard im Signier-Flow greift.
     *
     * Bewusst KEIN `?? 0`-Fallback: RePersonalizeContractsTool liest
     * pre_signing_data unvalidiert aus der DB. Ein Default 0 wuerde still
     * "noch 0 Tage" in ein bereits unterschriebenes Dokument schreiben —
     * ohne Validator davor und ohne dass der Guard es merkt, weil
     * "noch 0 Tage" syntaktisch vollstaendig ist.
     */
    public static function embed(string $content, array $data): ?string
    {
        if (!self::appliesTo($data)) {
            return null;
        }

        $tage = $data['resttage'] ?? null;

        // Bewusst NICHT is_numeric: das liesse '-5' (→ "noch -5 Tage"),
        // '1e3' (→ "noch 1000 Tage") und 87.9 (→ stille Trunkierung auf 87)
        // durch. Der Schreibpfad erlaubt integer|min:0|max:140; hier wird
        // dieselbe Form verlangt, nur ohne die Obergrenze zu duplizieren —
        // sonst stuende die 140 an einer dritten Stelle.
        $isValidInt = is_int($tage) && $tage >= 0;
        $isValidString = is_string($tage) && preg_match('/^\d+$/', $tage);

        if (!($isValidInt || $isValidString)) {
            return $content;
        }

        return self::fill($content, (int) $tage);
    }

    /**
     * Steht im Text noch ein unaufgeloester {{...}}-Platzhalter?
     *
     * Bewusst generisch statt auf PLACEHOLDER begrenzt: der wahrscheinlichste
     * Fehler ist ein Tippfehler in der Vorlage ("{{ resttage }}" mit
     * Leerzeichen), den eine exakte Suche nicht faende.
     *
     * Zeichenklasse bewusst [^{}]+ und nicht [A-Za-z0-9_]+: Der Vorlagen-
     * Editor validiert Platzhalternamen nur als required|string|max:255,
     * Punkte und Bindestriche sind also erlaubt. Ein Guard, der bei
     * {{kontakt.vorname}} nicht ausloest, schuetzt nicht. Einfache
     * geschweifte Klammern (CSS wie {color:red}) bleiben unberuehrt, weil
     * zwei Klammern gefordert sind.
     */
    public static function hasUnresolvedPlaceholder(string $content): bool
    {
        return preg_match('/\{\{[^{}]+\}\}/', $content) === 1;
    }
}
```

- [ ] **Step 4: Test laufen lassen, grün prüfen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --filter ResttagePlaceholderTest`
Expected: PASS (19 Tests)

- [ ] **Step 5: Gesamte Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS, mindestens 471 Tests (437 + 10 + 5 + 19)

- [ ] **Step 6: Commit**

```bash
git add src/Support/ResttagePlaceholder.php tests/Unit/ResttagePlaceholderTest.php
git commit -m "feat(recruiting): Resttage-Platzhalter — Ersetzung, Typ-Erkennung, Guard"
```

---

### Task 5: Typ-Weiche in `embedPreSigningData()`

Ohne diesen Schritt zerstört `RePersonalizeContractsTool` jede signierte 140-Tage-Erklärung: Es rendert `personalized_content` aus der Vorlage neu (`{{resttage}}` kommt zurück) und ruft danach `embedPreSigningData()` auf, das bei fehlenden §15/§16-Schlüsseln **nicht** nichts anhängt, sondern die vollständigen Verneinungs-Blöcke („Nein, ich war in den letzten 12 Monaten nicht kurzfristig beschäftigt", `RecContract.php:162-165` und `:197-200`). In einer unterschriebenen Erklärung stünden dann zwei fachfremde §-Blöcke mit Aussagen, die der Bewerber nie gemacht hat.

Die Weiche gehört **in** `embedPreSigningData()`, nicht in die Aufrufer: Beide Aufrufstellen (`ContractSigning.php:142` und `RePersonalizeContractsTool.php:113`) sind damit in einem Schritt abgedeckt, und das Tool braucht keine Änderung.

**Files:**
- Modify: `src/Models/RecContract.php:226-229`

**Interfaces:**
- Consumes: `ResttagePlaceholder::embed(string $content, array $data): ?string` (Task 4)

**Kein Unit-Test möglich:** `RecContract` erweitert `Illuminate\Database\Eloquent\Model`. Deshalb liegt **die ganze** Entscheidung in `ResttagePlaceholder::embed()` — Zuständigkeit, Zahlen-Prüfung und Ersetzung — und im Model bleiben vier Zeilen Verdrahtung ohne eigene Logik. Insbesondere steht hier **kein** `?? 0`: `embedPreSigningData()` ist ein **Lesepfad**, der von `RePersonalizeContractsTool` mit unvalidierten DB-Daten gefüttert wird. Ein Default würde still „noch 0 Tage" in ein unterschriebenes Dokument schreiben.

- [ ] **Step 1: Import ergänzen**

In `src/Models/RecContract.php` bei den `use`-Statements ergänzen:

```php
use Platform\Recruiting\Support\ResttagePlaceholder;
```

- [ ] **Step 2: Weiche als erste Anweisung einbauen**

Vorher:

```php
    public static function embedPreSigningData(string $content, array $data): string
    {
        $par15Html = self::buildPar15Html($data);
        $par16Html = self::buildPar16Html($data);
```

Nachher:

```php
    public static function embedPreSigningData(string $content, array $data): string
    {
        // Typ-Weiche. Bestandszeilen haben kein 'type' — sie sind immer
        // §15/§16, weil das bis AT-140 der einzige Vorschalt-Schritt war.
        // Ohne diese Weiche wuerden an eine 140-Tage-Erklaerung die
        // §15/§16-Verneinungsbloecke angehaengt (siehe buildPar15Html).
        //
        // Die gesamte Entscheidung liegt in embed(): null heisst "nicht
        // zustaendig". Fehlt die Zahl, gibt embed() den Inhalt unveraendert
        // zurueck — der Platzhalter bleibt stehen, damit der Guard greift.
        $resttageContent = ResttagePlaceholder::embed($content, $data);
        if ($resttageContent !== null) {
            return $resttageContent;
        }

        $par15Html = self::buildPar15Html($data);
        $par16Html = self::buildPar16Html($data);
```

Der Rest der Methode bleibt unverändert.

**Bestandsschutz:** Alle heute existierenden `pre_signing_data`-Zeilen entstehen an genau einer Stelle (`ContractSigning.php:135-140`) und haben die Schlüssel `par15_has_previous`, `par15_entries`, `par16_was_jobseeking`, `par16_entries` — kein `type`. `appliesTo()` liefert dafür `false`, die Ausführung läuft byte-identisch in den bestehenden Code.

- [ ] **Step 3: Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS, unverändert grün

- [ ] **Step 4: Commit**

```bash
git add src/Models/RecContract.php
git commit -m "feat(recruiting): embedPreSigningData unterscheidet Vorschalt-Typen"
```

---

### Task 6: Signier-Flow um den Resttage-Schritt erweitern

**Files:**
- Modify: `src/Livewire/Public/ContractSigning.php` (Imports, Properties, `mount()`, `nextStep()`, `sign()`, `validatePreSigningData()`)

**Interfaces:**
- Consumes: `ContractPreSigningType::forCode()`, `::RESTTAGE`, `::PAR_15_16` (Task 3); `ResttagePlaceholder::fill()`, `::TYPE`, `::hasUnresolvedPlaceholder()` (Task 4)
- Produces: Öffentliche Properties für die Blade in Task 7:
  - `public ?string $preSigningType` — `'par1516'`, `'resttage'` oder `null`. **Nur für die Blade-Darstellung.** Für jede sicherheitsrelevante Entscheidung in `sign()` gilt ausschließlich die dort lokal abgeleitete `$type`.
  - `public ?string $resttage` — Eingabe des Bewerbers. **Bewusst `?string`, nicht `?int`:** Livewire kann ein geleertes Zahlenfeld (`''`) nicht in ein typisiertes `int`-Property hydrieren und wirft einen TypeError. Die Validierung prüft `integer`, gecastet wird bei Benutzung.
  - `public bool $contentIncomplete` — `true` wenn im angezeigten Text noch ein `{{…}}` steht; die Blade blendet dann den Unterschreiben-Button aus
  - `public bool $requiresPreSigningStep` — bleibt bestehen, jetzt `true` für **beide** Typen

**Kein `rawContractContent`-Property:** Eine zweite Kopie des Vertragstexts im Livewire-Payload würde diesen bei jedem Vertrag verdoppeln — auch bei den langen Arbeitsverträgen. `nextStep()` holt den Inhalt stattdessen frisch aus der DB. Das kostet einen Read pro Klick und hat den Nebeneffekt, dass „Zurück" mit korrigierter Zahl garantiert sauber neu ersetzt.

**`#[Locked]` auf allen servergesetzten Properties.** Heute ist in dieser Komponente **kein** Property gesperrt (`grep -rn "Locked"` → kein Treffer), und `sign()` lädt per `find($this->contractId)` mit der einzigen Prüfung `status !== 'sent'` — **keine** Token- oder Bewerber-Prüfung (`ContractSigning.php:128-133`). Wer einen beliebigen gültigen Vertrags-Token besitzt, kann damit `contractId` auf einen fremden Vertrag im Status `sent` umbiegen und ihn mit seiner Unterschrift abschließen; die IDs sind fortlaufend. `#[Locked]` blockiert nur Client-Updates, Server-Mutation in `mount()`/`nextStep()` bleibt erlaubt. Gesperrt werden: `contractId`, `step`, `preSigningType`, `requiresPreSigningStep`, `contentIncomplete`, `contractContent`. **Nicht** gesperrt werden die echten Eingabefelder: `resttage`, `signatureData`, `par15*`, `par16*`.

**Kein Unit-Test:** Livewire-Komponenten sind ohne Laravel-Bootstrap nicht ladbar. Entscheidungs- und Ersetzungslogik sind in Task 3 und 4 abgedeckt.

- [ ] **Step 1: Imports und Properties ergänzen**

Bei den `use`-Statements ergänzen:

```php
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Platform\Recruiting\Support\ContractPreSigningType;
use Platform\Recruiting\Support\ResttagePlaceholder;
```

Die bestehenden Property-Deklarationen (Zeilen 11–18) mit `#[Locked]` versehen — nur die servergesetzten:

```php
    #[Locked]
    public int $step = 1;

    public string $state = 'loading';

    #[Locked]
    public ?int $contractId = null;

    #[Locked]
    public string $contractContent = '';

    public string $contractTemplateName = '';

    #[Locked]
    public bool $requiresPreSigningStep = true;
```

Danach einfügen:

```php
    /**
     * 'par1516' | 'resttage' | null — welcher Vorschalt-Schritt gilt.
     *
     * NUR fuer die Darstellung. sign() leitet den Typ serverseitig neu aus
     * dem geladenen Vertrag ab und benutzt ausschliesslich diese lokale
     * Variable — sonst koennte ein Client den Typ auf null setzen und damit
     * an Validierung und Guard vorbeilaufen.
     */
    #[Locked]
    public ?string $preSigningType = null;

    /**
     * Restliche genehmigungsfreie Tage im laufenden Kalenderjahr (AT-140).
     *
     * Bewusst ?string und nicht ?int: Livewire kann ein geleertes
     * Zahlenfeld ('') nicht in ein typisiertes int-Property hydrieren.
     * Validierung prueft 'integer', gecastet wird bei Benutzung.
     */
    public ?string $resttage = null;

    /** Im angezeigten Text steht noch ein unaufgeloester {{...}}-Platzhalter. */
    #[Locked]
    public bool $contentIncomplete = false;
```

- [ ] **Step 2: `mount()` umstellen**

Den Block

```php
        $this->contractId = $contract->id;
        $this->contractContent = $contract->personalized_content ?? '';
        $this->contractTemplateName = $contract->contractTemplate?->name ?? 'Vertrag';

        // Only the Arbeitsvertrag-variants (AV-*) require the paragraph 15/16 pre-signing step.
        // IFSG and any other contracts go directly to view-and-sign.
        $code = $contract->contractTemplate?->code;
        $this->requiresPreSigningStep = $code !== null && str_starts_with($code, 'AV-');
        $this->step = $this->requiresPreSigningStep ? 1 : 2;
```

ersetzen durch:

```php
        $this->contractId = $contract->id;
        $this->contractContent = $contract->personalized_content ?? '';
        $this->contractTemplateName = $contract->contractTemplate?->name ?? 'Vertrag';

        // Arbeitsvertraege (AV-*) fragen §15/§16 ab, die 140-Tage-Erklaerung
        // (AT-140) das Rest-Kontingent. IFSG und alles andere geht direkt
        // zu Ansicht und Unterschrift.
        $code = $contract->contractTemplate?->code;

        $this->preSigningType = ContractPreSigningType::forCode($code);
        $this->requiresPreSigningStep = $this->preSigningType !== null;
        $this->step = $this->requiresPreSigningStep ? 1 : 2;

        // Vollstaendigkeits-Guard nur fuer Zusatzvertraege (AT-*).
        //
        // Deckt insbesondere den Tippfehler-Fall ab: eine Vorlage "AT-0140"
        // steht nicht in RESTTAGE_CODES, bekommt also keinen Vorschalt-Schritt
        // und landet direkt in Schritt 2 — mit sichtbarem {{resttage}}.
        //
        // Bewusst NICHT an !requiresPreSigningStep gehaengt: das wuerde die
        // 203 IFSG-Vertraege mit einbeziehen, ohne dass das engere AT-Muster
        // einen Fall verliert. Kein Blast Radius auf den Bestand.
        //
        // Fuer AT-140 selbst ist der Wert hier true (der Platzhalter steht ja
        // noch) und wird in nextStep() nach der Ersetzung neu bewertet; die
        // Blade nutzt ihn ausschliesslich in Schritt 2.
        if ($code !== null && str_starts_with($code, 'AT-')) {
            $this->contentIncomplete = ResttagePlaceholder::hasUnresolvedPlaceholder($this->contractContent);
        }
```

- [ ] **Step 3: `nextStep()` um Ersetzung und Guard erweitern**

`nextStep()` ersetzen durch:

```php
    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validatePreSigningData();

            // Der Bewerber soll in Schritt 2 das fertige Dokument sehen,
            // das er unterschreibt — inklusive seiner Zahl. Immer vom
            // DB-Stand aus ersetzen, damit ein "Zurueck" mit korrigierter
            // Eingabe sauber neu greift.
            if ($this->preSigningType === ContractPreSigningType::RESTTAGE) {
                $contract = RecContract::find($this->contractId);
                $this->contractContent = ResttagePlaceholder::fill(
                    $contract?->personalized_content ?? '',
                    (int) $this->resttage
                );
                $this->contentIncomplete = ResttagePlaceholder::hasUnresolvedPlaceholder($this->contractContent);

                if ($this->contentIncomplete) {
                    Log::warning('[ContractSigning] Unaufgeloester Platzhalter nach Resttage-Ersetzung', [
                        'contract_id' => $this->contractId,
                    ]);
                }
            }
        }

        $this->step++;
    }
```

- [ ] **Step 4: Validierung ergänzen — Regel und Texte an genau einer Stelle**

Die Resttage-Regel wird von **zwei** Aufrufern gebraucht (`validatePreSigningData()` und `sign()`). Sie wird deshalb nicht kopiert, sondern in zwei private Methoden gezogen. Grund: Die Rechtsprüfung kann plausibel `140 → 120` ergeben; dann darf es **eine** Codestelle sein, nicht zwei wörtliche Kopien, die auseinanderlaufen.

Neue private Methoden am Ende der Klasse, vor `render()`:

```php
    /**
     * Validierungsregel fuer das Rest-Kontingent.
     *
     * Bewusst zentral: die Obergrenze haengt an der Rechtsgrundlage (heute
     * 140 nach dem Ursprungsdokument). Aendert sie sich, ist das hier EINE
     * Stelle — plus max="140" in der Blade, das sich nicht teilen laesst.
     */
    private function resttageRules(): array
    {
        return ['resttage' => 'required|integer|min:0|max:140'];
    }

    private function resttageMessages(): array
    {
        return [
            'resttage.required' => $this->duzen
                ? 'Bitte gib an, wie viele Tage dir noch zur Verfügung stehen.'
                : 'Bitte geben Sie an, wie viele Tage Ihnen noch zur Verfügung stehen.',
            'resttage.integer' => 'Bitte eine ganze Zahl eingeben.',
            'resttage.min' => 'Die Zahl darf nicht negativ sein.',
            'resttage.max' => 'Es können höchstens 140 Tage sein.',
        ];
    }
```

In `validatePreSigningData()` direkt nach `$messages = [];` einfügen:

```php
        if ($this->preSigningType === ContractPreSigningType::RESTTAGE) {
            $this->validate($this->resttageRules(), $this->resttageMessages());

            return;
        }
```

Das `return;` sorgt dafür, dass die §15/§16-Regeln für diesen Vertragstyp gar nicht erst aufgebaut werden.

- [ ] **Step 5: `sign()` ersetzen**

`sign()` wird als Ganzes ersetzt — die Reihenfolge ändert sich: erst Vertrag laden, dann Typ ableiten, dann validieren. Die Validierung braucht den Typ, und der Typ darf nur aus dem Model kommen.

```php
    public function sign(): void
    {
        // Reihenfolge bewusst: Vertrag VOR der Validierung laden, weil die
        // Validierungsregeln vom serverseitig abgeleiteten Typ abhaengen.
        $contract = RecContract::find($this->contractId);

        if (! $contract || $contract->status !== 'sent') {
            $this->state = 'invalid';
            return;
        }

        // Code UND Typ serverseitig aus dem frisch geladenen Vertrag ableiten.
        // Ab hier ausschliesslich diese lokalen Variablen benutzen — nie
        // $this->preSigningType. Sonst koennte ein Client den Typ auf null
        // setzen: dann griffe der else-Zweig, {{resttage}} liefe unersetzt
        // durch, und der Guard wuerde uebersprungen.
        $code = $contract->contractTemplate?->code;
        $type = ContractPreSigningType::forCode($code);

        $rules = ['signatureData' => 'required'];
        $messages = [
            'signatureData.required' => $this->duzen
                ? 'Bitte unterschreibe den Vertrag.'
                : 'Bitte unterschreiben Sie den Vertrag.',
        ];

        // Auch hier validieren, nicht nur in nextStep(): sign() ist direkt
        // aufrufbar, Schritt 1 also ueberspringbar. Ohne diese Regel landete
        // still eine 0 im unterschriebenen Dokument — und der Platzhalter-
        // Guard merkt das nicht, weil "noch 0 Tage" vollstaendig aussieht.
        if ($type === ContractPreSigningType::RESTTAGE) {
            $rules = array_merge($rules, $this->resttageRules());
            $messages = array_merge($messages, $this->resttageMessages());
        }

        $this->validate($rules, $messages);

        if ($type === ContractPreSigningType::PAR_15_16) {
            $preSigningData = [
                'par15_has_previous' => $this->par15HasPrevious,
                'par15_entries' => $this->par15HasPrevious ? $this->par15Entries : [],
                'par16_was_jobseeking' => $this->par16WasJobseeking,
                'par16_entries' => $this->par16WasJobseeking ? $this->par16Entries : [],
            ];
            $personalizedContent = RecContract::embedPreSigningData(
                $contract->personalized_content ?? '',
                $preSigningData
            );
        } elseif ($type === ContractPreSigningType::RESTTAGE) {
            // Die Zahl wandert fest ins Dokument UND strukturiert in
            // pre_signing_data — letzteres traegt den Typ, damit eine
            // spaetere Re-Personalisierung sie wieder einsetzen kann.
            $preSigningData = [
                'type'     => ResttagePlaceholder::TYPE,
                'resttage' => (int) $this->resttage,
            ];
            $personalizedContent = RecContract::embedPreSigningData(
                $contract->personalized_content ?? '',
                $preSigningData
            );
        } else {
            $preSigningData = null;
            $personalizedContent = $contract->personalized_content ?? '';
        }

        // Harter Guard: ein unterschriebenes Dokument ist ein Archivstueck.
        // Bleibt ein Platzhalter stehen, wird NICHT gespeichert — die
        // Signatur wuerde sich sonst dauerhaft auf einen kaputten Text
        // beziehen. Nur fuer Zusatzvertraege (AT-*), damit Bestandsvertraege
        // mit womoeglich vorhandenen geschweiften Klammern nicht blockiert
        // werden.
        //
        // GLEICHES PRAEDIKAT WIE mount() — sonst ist der Tippfehler-Fall nur
        // in der UI geschuetzt: Eine Vorlage "AT-0140" liefert $type === null,
        // damit griffe ein Guard auf RESTTAGE nicht, {{resttage}} liefe durch
        // den else-Zweig unersetzt ins Dokument, und sign() ist direkt
        // aufrufbar. #[Locked] auf contentIncomplete hilft hier nicht, weil
        // sign() das Flag gar nicht liest.
        //
        // WICHTIG: Hier NICHT $this->contentIncomplete setzen. Die Meldung
        // haengt per addError an x-ui-input-signature, und dieses Feld wird
        // in der Blade nur im @else-Zweig von $contentIncomplete gerendert.
        // Setzt man das Flag, verschwindet das Feld, an dem die Meldung
        // haengt — und der Bewerber sieht gar nichts.
        if ($code !== null && str_starts_with($code, 'AT-')
            && ResttagePlaceholder::hasUnresolvedPlaceholder($personalizedContent)) {
            Log::error('[ContractSigning] Signieren abgebrochen — unaufgeloester Platzhalter', [
                'contract_id' => $contract->id,
            ]);
            $this->addError('signatureData', $this->duzen
                ? 'Dieses Dokument ist noch nicht vollständig. Bitte melde dich bei uns.'
                : 'Dieses Dokument ist noch nicht vollständig. Bitte melden Sie sich bei uns.');
            return;
        }

        $contract->update([
            'pre_signing_data' => $preSigningData,
            'personalized_content' => $personalizedContent,
            'signature_data' => $this->signatureData,
            'signed_at' => now(),
            'completed_at' => now(),
            'status' => 'completed',
        ]);

        $this->portalUrl = $this->buildPortalUrl($contract);
        $this->state = 'already_signed';
    }
```

Vier Invarianten, die zusammen gelten müssen — wer eine davon aufweicht, öffnet die anderen:

1. Der gespeicherte Inhalt kommt aus `$contract->personalized_content` (DB), nie aus `$this->contractContent`.
2. Verzweigung und Validierung hängen an derselben lokalen `$type`, beide aus dem geladenen Vertrag abgeleitet.
3. Der harte Guard hängt am **selben Prädikat wie `mount()`** (`AT-`-Präfix), nicht an `$type` — sonst bleibt der Tippfehler-Fall serverseitig offen.
4. `contractId` ist `#[Locked]` — sonst ist `find($this->contractId)` frei wählbar.

- [ ] **Step 6: Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS, unverändert grün

- [ ] **Step 7: Commit**

```bash
git add src/Livewire/Public/ContractSigning.php
git commit -m "feat(recruiting): Signier-Flow fragt bei AT-140 das Rest-Kontingent ab"
```

---

### Task 7: Resttage-Schritt in der Blade

**Files:**
- Modify: `resources/views/livewire/public/contract-signing.blade.php:36-60` (Fortschrittsanzeige), `:64` (Schritt-1-Bedingung), Einfügung nach dem §15/§16-Block, `:255-268` (Unterschriebts-Bereich)

**Interfaces:**
- Consumes: `$preSigningType`, `$resttage`, `$contentIncomplete`, `$requiresPreSigningStep`, `$duzen` aus Task 6

**Pitfall (Modul-Konvention):** In `x-ui-*`-Komponenten kein inline-`@if` und keine `??`-Fallbacks in Attributen — Werte vorher in einem `@php`-Block berechnen. Der Resttage-Schritt nutzt ein natives `<input type="number">` wie der bestehende §15-Block; ein `x-ui-input-number` existiert nicht.

- [ ] **Step 1: Fortschrittsanzeige beschriften**

Die Zeile

```blade
                        @foreach([1 => '§15/§16 Angaben', 2 => 'Vertrag & Unterschrift'] as $num => $label)
```

ersetzen durch:

```blade
                        @php
                            $stepOneLabel = $preSigningType === 'resttage' ? 'Deine Angabe' : '§15/§16 Angaben';
                        @endphp
                        @foreach([1 => $stepOneLabel, 2 => 'Vertrag & Unterschrift'] as $num => $label)
```

- [ ] **Step 2: Schritt 1 nach Typ verzweigen**

Die Zeile

```blade
            {{-- Step 1: §15 + §16 zusammen --}}
            @if($step === 1)
```

ersetzen durch:

```blade
            {{-- Step 1: §15 + §16 zusammen (nur Arbeitsvertraege) --}}
            @if($step === 1 && $preSigningType === 'par1516')
```

- [ ] **Step 3: Resttage-Schritt einfügen**

Direkt **nach** dem schließenden `@endif` des §15/§16-Blocks (die Zeile vor `{{-- Step 2: Vertrag & Unterschrift --}}`) einfügen:

```blade
            {{-- Step 1 (Variante): Rest-Kontingent bei der 140-Tage-Erklaerung --}}
            @if($step === 1 && $preSigningType === 'resttage')
                @php
                    $resttageFrage = $duzen
                        ? 'Wie viele der 140 genehmigungsfreien Tage stehen dir in diesem Kalenderjahr noch zur Verfügung?'
                        : 'Wie viele der 140 genehmigungsfreien Tage stehen Ihnen in diesem Kalenderjahr noch zur Verfügung?';
                    $resttageHinweis = $duzen
                        ? 'Zähle alle Tage mit, die du dieses Jahr bereits bei anderen Arbeitgebern gearbeitet hast, und ziehe sie von 140 ab. Wenn du dieses Jahr noch nicht gearbeitet hast, sind es 140 Tage.'
                        : 'Zählen Sie alle Tage mit, die Sie dieses Jahr bereits bei anderen Arbeitgebern gearbeitet haben, und ziehen Sie sie von 140 ab. Wenn Sie dieses Jahr noch nicht gearbeitet haben, sind es 140 Tage.';
                    $resttageNachweis = $duzen
                        ? 'Hast du dieses Jahr schon woanders gearbeitet, brauchen wir zusätzlich eine Bescheinigung über die bereits gearbeiteten Tage.'
                        : 'Haben Sie dieses Jahr schon woanders gearbeitet, benötigen wir zusätzlich eine Bescheinigung über die bereits gearbeiteten Tage.';
                @endphp
                <div class="space-y-6">
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h2 class="text-xl font-bold text-gray-900 mb-2">Verfügbare Arbeitstage</h2>
                        <p class="text-gray-500 text-sm mb-6">{{ $resttageFrage }}</p>

                        <div class="max-w-xs">
                            <label for="resttage" class="block text-sm font-medium text-gray-700 mb-1">
                                Verfügbare Tage
                            </label>
                            <input type="number" id="resttage" wire:model="resttage"
                                min="0" max="140" step="1" inputmode="numeric"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:ring-blue-500 focus:border-blue-500">
                            @error('resttage')
                                <span class="text-xs text-red-500">{{ $message }}</span>
                            @enderror
                        </div>

                        <p class="text-sm text-gray-500 mt-4">{{ $resttageHinweis }}</p>

                        <div class="mt-6 rounded-md bg-amber-50 border border-amber-200 p-4">
                            <p class="text-sm text-amber-900">{{ $resttageNachweis }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="button" wire:click="nextStep"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition">
                            Weiter @svg('heroicon-o-arrow-right', 'w-4 h-4')
                        </button>
                    </div>
                </div>
            @endif
```

Der Zurück-Button in Schritt 2 funktioniert unverändert, weil er an `$requiresPreSigningStep` hängt und das für beide Typen `true` ist.

- [ ] **Step 4: Unterschriften-Block ersetzen**

Der komplette Unterschriften-Block (Zeilen 239–270, beginnend mit `{{-- Unterschrift --}}` bis zum schließenden `</div>` der Karte) wird ersetzt. **Vollständiger Ersetzungsblock**, keine Einfüge-Anweisung — hier hängt der Zurück-Weg des Bewerbers dran, und der darf beim Verzweigen nicht mit ausgeschaltet werden.

Vorher (unverändert, zum Abgleich):

```blade
                    {{-- Unterschrift --}}
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">Unterschrift</h3>
                        <p class="text-sm text-gray-500 mb-4">
                            {{ $duzen ? 'Mit deiner Unterschrift bestätigst du, dass du den Vertrag gelesen hast und die oben gemachten Angaben korrekt sind.' : 'Mit Ihrer Unterschrift bestätigen Sie, dass Sie den Vertrag gelesen haben und die oben gemachten Angaben korrekt sind.' }}
                        </p>

                        @php
                            $signatureLabel = $duzen ? 'Deine Unterschrift' : 'Ihre Unterschrift';
                        @endphp
                        <x-ui-input-signature
                            name="signatureData"
                            :label="$signatureLabel"
                            wire:model="signatureData"
                            :required="true"
                            :height="200"
                        />

                        <div class="flex {{ $requiresPreSigningStep ? 'justify-between' : 'justify-end' }} mt-8">
                            @if($requiresPreSigningStep)
                                <button type="button" wire:click="previousStep"
                                    class="inline-flex items-center gap-2 px-6 py-2.5 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                                    @svg('heroicon-o-arrow-left', 'w-4 h-4') Zurück
                                </button>
                            @endif
                            <button type="button" wire:click="sign"
                                class="inline-flex items-center gap-2 px-8 py-3 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 transition">
                                @svg('heroicon-o-check', 'w-5 h-5') Vertrag unterschreiben
                            </button>
                        </div>
                    </div>
```

Nachher:

```blade
                    {{-- Unterschrift --}}
                    <div class="bg-white rounded-lg border border-gray-200 p-8">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">Unterschrift</h3>

                        @if($contentIncomplete)
                            @php
                                $incompleteHinweis = $duzen
                                    ? 'Dieses Dokument ist noch nicht vollständig ausgefüllt. Bitte melde dich bei uns — wir klären das und schicken dir das Dokument neu.'
                                    : 'Dieses Dokument ist noch nicht vollständig ausgefüllt. Bitte melden Sie sich bei uns — wir klären das und schicken Ihnen das Dokument neu.';
                            @endphp
                            <div class="rounded-md bg-red-50 border border-red-200 p-4">
                                <p class="text-sm text-red-900">{{ $incompleteHinweis }}</p>
                            </div>
                        @else
                            <p class="text-sm text-gray-500 mb-4">
                                {{ $duzen ? 'Mit deiner Unterschrift bestätigst du, dass du den Vertrag gelesen hast und die oben gemachten Angaben korrekt sind.' : 'Mit Ihrer Unterschrift bestätigen Sie, dass Sie den Vertrag gelesen haben und die oben gemachten Angaben korrekt sind.' }}
                            </p>

                            @php
                                $signatureLabel = $duzen ? 'Deine Unterschrift' : 'Ihre Unterschrift';
                            @endphp
                            <x-ui-input-signature
                                name="signatureData"
                                :label="$signatureLabel"
                                wire:model="signatureData"
                                :required="true"
                                :height="200"
                            />
                        @endif

                        <div class="flex {{ $requiresPreSigningStep ? 'justify-between' : 'justify-end' }} mt-8">
                            @if($requiresPreSigningStep)
                                <button type="button" wire:click="previousStep"
                                    class="inline-flex items-center gap-2 px-6 py-2.5 bg-white text-gray-700 text-sm font-medium rounded-lg border border-gray-300 hover:bg-gray-50 transition">
                                    @svg('heroicon-o-arrow-left', 'w-4 h-4') Zurück
                                </button>
                            @endif
                            @if(!$contentIncomplete)
                                <button type="button" wire:click="sign"
                                    class="inline-flex items-center gap-2 px-8 py-3 bg-green-600 text-white text-sm font-bold rounded-lg hover:bg-green-700 transition">
                                    @svg('heroicon-o-check', 'w-5 h-5') Vertrag unterschreiben
                                </button>
                            @endif
                        </div>
                    </div>
```

Was sich strukturell **nicht** ändert: das äußere `<div>` der Karte und die Button-Zeile. Verzweigt wird nur an zwei Stellen — Signaturfeld gegen roten Hinweis, und der Unterschreiben-Button. **Der Zurück-Button bleibt außerhalb jeder neuen Bedingung.** Sonst stünde ein Bewerber mit unvollständigem Dokument in Schritt 2 vor einem roten Hinweis ohne Weg zurück — bei einem Resttage-Vertrag also ohne Möglichkeit, seine Zahl zu korrigieren.

Der einleitende Absatz („Mit deiner Unterschrift bestätigst du…") wandert in den `@else`-Zweig: Er ergibt keinen Sinn, wenn gerade kein Signaturfeld dasteht.

`@php`/`@endphp` bleiben balanciert (zwei Blöcke, zwei Enden) — die beiden liegen in gegenseitig ausschließenden Zweigen, was für die Balance-Prüfung in Step 5 unerheblich ist, weil sie zählt und nicht auswertet.

- [ ] **Step 5: Blade-Check laufen lassen**

Run: `php tools/blade-check.php resources/views/livewire/public/contract-signing.blade.php`
Expected: `OK` und Exit 0.

Danach über alle Views, um Kollateralschäden auszuschließen:

Run: `php tools/blade-check.php`
Expected: `40 Datei(en) geprueft, 0 mit Funden.` und Exit 0.

**Nicht `php -l` auf die Blade-Datei** — das meldet immer „No syntax errors" und prüft nichts.

Was der Check abdeckt (belegt durch absichtlich kaputte Kopien): Syntaxfehler in einem `@php`-Block, unbalancierte `@if`/`@endif`, und über die separate Balance-Prüfung ein `@php` ohne `@endphp`.

Was er **nicht** abdeckt:

* **Vertippte Komponentennamen.** Die Stub-View-Factory liefert `exists() === true` für alles — `<x-ui-input-signatur>` kompiliert damit sauber und fällt erst im Browser auf. Das ist der Preis dafür, dass der Check ohne App-Boot auskommt.
* Alles Semantische — ob die Verzweigung fachlich richtig ist, ob `$contentIncomplete` überhaupt gesetzt wird, ob die Buttons an der richtigen Stelle stehen.

Beides entscheidet die Sichtprüfung in Task 8.

- [ ] **Step 6: Suite laufen lassen**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit`
Expected: PASS, unverändert grün

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/public/contract-signing.blade.php tools/blade-check.php
git commit -m "feat(recruiting): Resttage-Abfrage und Vollstaendigkeits-Guard im Signier-Flow"
```

`tools/blade-check.php` liegt bereits im Arbeitsverzeichnis (während der Planung erstellt und gegen alle 40 Views plus drei absichtlich kaputte Kopien verifiziert), ist aber noch nicht eingecheckt — deshalb hier mit committen.

---

### Task 8: Vertragsvorlage `AT-140` anlegen und live prüfen

Die Vorlage ist ein Datensatz, kein Code — sie wird über die Platform angelegt, damit HR den Text danach selbst im Vorlagen-Editor pflegen kann.

**Files:** keine — dieser Schritt läuft gegen die laufende Platform.

**Voraussetzung:** Tasks 1–7 sind gemerged und `meingedeck` ist gebumpt und deployed. Wird die Vorlage vorher angelegt, könnte ein Bewerber eine Erklärung mit sichtbarem `{{resttage}}` unterschreiben.

- [ ] **Step 0: Sichttest `saveContractFields()` auf einem Bestandsvertrag** *(durch den Nutzer, VOR allem anderen)*

Der Fix aus `511451c` ist die **einzige ungegatete Änderung** im Branch — außerhalb aller sieben Tasks, nicht spec-getrieben — und liegt auf einem HR-Pfad, den über 400 Bestandsverträge berühren. Die übrigen Schritte dieses Tasks prüfen nur die Signier-Seite und die neue Vorlage; dieser Pfad kommt darin nirgends vor.

**An einem Testbewerber durchführen, nicht an einem echten Mitarbeitervertrag.** Der Klick ist nicht nebenwirkungsfrei — bestehendes Verhalten, unabhängig vom Fix:

* `personalized_content` wird mit **heutigen** Werten neu gerendert. `AV-default` mappt `datum_heute` → `meta.datum_heute` (`Carbon::now()`), `stundenlohn` → `settings.minimum_wage_hourly` und `zuschlag` → `applicant.zuschlag`. Das Datum im Dokument springt damit vom Signaturtag auf heute, Lohnwerte auf den aktuellen Stand.
* `resolveContractDates()` (`RecContract.php:139-145`) füllt bei gesetztem Vertragsbeginn und leerem Ende automatisch ein Ende ein (+1 Jahr, Monatsanfang, −1 Tag) — ein Wert, der vorher nicht existierte.
* `updated_at` wandert auf jetzt.

Nicht angetastet werden `signature_data`, `signed_at`, `completed_at` und `status`; der `saved`-Hook für `contract_signed_at` ist idempotent.

Vorgehen nach dem Deploy, **vor** dem Anlegen von `AT-140`:

1. Testbewerber anlegen, Arbeitsvertrag zuweisen und versenden, im Portal selbst unterschreiben (Status `completed`, `pre_signing_data` gefüllt).
2. PDF öffnen und den Vorher-Zustand ansehen: die Blöcke „§ 15 Angaben zu kurzfristigen Beschäftigungen" und „§ 16 Angaben zu beschäftigungslosen Zeiten".
3. In der Vertragsliste „Felder" öffnen und ohne jede Änderung „Speichern" klicken.
4. PDF neu laden: **Die §15/§16-Blöcke müssen noch drin sein.** Vor dem Fix hätte genau dieser Klick sie entfernt.

Fehlschlag A: Blöcke fehlen → Fix greift nicht, melden, nichts weiter anfassen.
Fehlschlag B: 500er beim Speichern → der `RecContract`-Import in `Applicant/Show.php` wäre doch das Problem (unwahrscheinlich, Zeile 14 vorhanden, aber von keinem Test erreichbar).

Das ist gleichzeitig der Beweis, dass der `RecContract`-Import in `Applicant/Show.php` greift und `embedPreSigningData()` nicht in einen Fatal läuft — dieser Zweig ist von keinem Test erreichbar.

**Ergebnis im Ledger festhalten.** Daran hängt Teil (a) des Folgetickets: ob es Altschaden aus der Zeit vor dem Fix gibt.

- [ ] **Step 1: Vorlage anlegen**

Über `recruiting.contract_templates.POST` im Team 3 (RHEINGEDECK-HR):

- `name`: `Erklärung 140-Tage-Tätigkeit`
- `code`: `AT-140`
- `description`: `Erklärung zur Aufnahme einer 140-Tage-Tätigkeit nach §9 Nr. 9 ArGV. Zusatzvertrag für nicht-EU-Bürger, wird vom HR-Schreibtisch zugewiesen. Der Bewerber gibt beim Unterschreiben sein verbleibendes Tages-Kontingent an.`
- `is_active`: `true`
- `requires_signature`: `true`
- `sort_order`: `0`
- `field_mappings`:

```json
{
  "kontakt_vorname": "contact.first_name",
  "kontakt_nachname": "contact.last_name",
  "kontakt_geburtsdatum": "contact.birth_date",
  "kontakt_geburtsort": "applicant.extra_field.geburtsort",
  "nationalitaet": "applicant.extra_field.nationalitaet",
  "pass_nr": "applicant.extra_field.ausweisnummer",
  "kontakt_strasse": "contact.address.street",
  "kontakt_hausnr": "contact.address.house_number",
  "kontakt_plz": "contact.address.postal_code",
  "kontakt_ort": "contact.address.city",
  "datum_heute": "meta.datum_heute"
}
```

`resttage` steht bewusst **nicht** in den Mappings.

- `content`:

```html
<h2 style="text-align: center; text-transform: uppercase;">Erklärung<br />zur Aufnahme einer 140-Tage-Tätigkeit</h2>
<p>Hiermit erklärt,</p>
<table style="width: 100%; border-collapse: collapse;">
<tbody>
<tr>
<td style="width: 30%; padding: 6px 0; vertical-align: bottom;">Herr/Frau:</td>
<td style="padding: 6px 0; border-bottom: 1px solid #000; vertical-align: bottom;">{{kontakt_vorname}} {{kontakt_nachname}}</td>
</tr>
<tr>
<td style="padding: 6px 0; vertical-align: bottom;">geb. am / in:</td>
<td style="padding: 6px 0; border-bottom: 1px solid #000; vertical-align: bottom;">{{kontakt_geburtsdatum}} in {{kontakt_geburtsort}}</td>
</tr>
<tr>
<td style="padding: 6px 0; vertical-align: bottom;">Nationalität:</td>
<td style="padding: 6px 0; border-bottom: 1px solid #000; vertical-align: bottom;">{{nationalitaet}}</td>
</tr>
<tr>
<td style="padding: 6px 0; vertical-align: bottom;">Pass Nr.:</td>
<td style="padding: 6px 0; border-bottom: 1px solid #000; vertical-align: bottom;">{{pass_nr}}</td>
</tr>
<tr>
<td style="padding: 6px 0; vertical-align: bottom;">wohnhaft in:</td>
<td style="padding: 6px 0; border-bottom: 1px solid #000; vertical-align: bottom;">{{kontakt_strasse}} {{kontakt_hausnr}}</td>
</tr>
<tr>
<td style="padding: 6px 0; vertical-align: bottom;">PLZ / Ort:</td>
<td style="padding: 6px 0; border-bottom: 1px solid #000; vertical-align: bottom;">{{kontakt_plz}} {{kontakt_ort}}</td>
</tr>
</tbody>
</table>
<p>dass er/sie von den arbeitsgenehmigungsfreien 140 Tagen nach &sect;9 Nr. 9 ArGV im laufenden Kalenderjahr noch <strong>{{resttage}}</strong> Tage zur Verfügung hat und dieses Kontingent ausschließlich der RheinGedeck GmbH zur Verfügung stellt (ggf. ist eine Bescheinigung über die bereits gearbeiteten Tage beizubringen).</p>
<p>Des Weiteren verpflichtet sich der/die Unterschreibende, bei Aufnahme einer weiteren Tätigkeit die RheinGedeck GmbH unverzüglich in Kenntnis zu setzen. Bei Zuwiderhandlungen geht die volle Haftung auf den Beschäftigten über.</p>
<p>{{kontakt_ort}}, den {{datum_heute}}</p>
<p><strong>Anlagen:</strong></p>
<ul>
<li>Immatrikulationsbescheinigung (ständig zu erneuern)</li>
<li>Kopie der Aufenthaltsgenehmigung im Personaldokument</li>
<li>unterschriebene Erklärung des Beschäftigten über alle Beschäftigungen im betreffenden Kalenderjahr mit Aufstellung der Arbeitstage und des jeweiligen Arbeitgebers</li>
</ul>
```

Keine Unterschriftszeile im Inhalt: Die PDF-Ansicht hängt einen eigenen Unterschrifts-Block mit gezeichnetem Bild und Datum an (`resources/views/pdf/contract.blade.php:74-80`).

- [ ] **Step 2: Anlage verifizieren**

Über `recruiting.contract_templates.GET` prüfen: `AT-140` existiert, `is_active: true`, `requires_signature: true`, elf Mappings, `resttage` nicht darunter.

- [ ] **Step 3: Sichtprüfung HR-Schreibtisch** *(durch den Nutzer)*

HR-Schreibtisch öffnen, Nicht-EU-Fall aufklappen. Erwartet: Das Zusatzvertrag-Dropdown zeigt „AT-140 — Erklärung 140-Tage-Tätigkeit" neben „— kein Zusatzvertrag —".

- [ ] **Step 4: Sichtprüfung Ende-zu-Ende** *(durch den Nutzer)*

An einem Testbewerber mit gepflegter Nationalität und Ausweisnummer: Zusatzvertrag zuweisen, Verträge senden, Portal öffnen. Erwartet:

1. Im Portal steht ein dritter Eintrag „Erklärung 140-Tage-Tätigkeit".
2. Beim Antippen kommt zuerst die Resttage-Frage, das Feld ist **leer** und lässt sich nicht mit „Weiter" überspringen.
3. Nach Eingabe (z. B. 90) zeigt Schritt 2 das Dokument mit „noch **90** Tage" — kein `{{resttage}}` mehr sichtbar.
4. Die Nationalität steht als Klartext („Türkei"), nicht als Kürzel („tr").
5. Nach dem Unterschreiben ist das PDF im Portal abrufbar und enthält Zahl und Unterschrift.

- [ ] **Step 5: Regressionsprüfung Bestandsverträge** *(durch den Nutzer)*

Der wichtigste Check — Task 6 fasst den Signier-Pfad an, den alle Arbeitsverträge und die IFSG-Belehrung teilen (über 400 Verträge):

1. **IFSG-Belehrung** öffnen — **der erste Klick nach dem Deploy.** Sie geht direkt zu Schritt 2; Unterschreiben-Button muss da sein. Nach der Einengung des `mount()`-Guards auf `AT-*` berührt die Änderung IFSG rechnerisch nicht mehr; das hier ist die Stichprobe, die es bestätigt.
2. **Arbeitsvertrag** öffnen: §15/§16-Schritt erscheint unverändert, Unterschreiben-Button ist da, Unterschreiben funktioniert. Das ist der eigentliche Regressionstest — `sign()` wurde komplett umgebaut.

- [ ] **Step 6: Re-Personalisierung prüfen** *(optional, durch den Nutzer)*

Nach dem Signieren an derselben Erklärung `recruiting.repersonalize_contracts` mit `include_completed=true` laufen lassen. Erwartet: Die Zahl steht danach immer noch im Dokument, und es sind **keine** §15/§16-Blöcke angehängt. Das verifiziert die Typ-Weiche aus Task 5.

---

## Ausführung

- **Tasks 1, 3, 4** — subagent-driven, je ein frischer Subagent mit Review dazwischen. Reine Support-Klassen, klar abgegrenzt, vollständig testbar.
- **Tasks 5, 6, 7** — **ein** Task mit durchgehendem Kontext. Die Invarianten aus `sign()` (DB-Inhalt statt Property, eine lokale `$type` für Verzweigung/Validierung/Guard, `#[Locked]` auf `contractId`) greifen über Model, Komponente und Blade ineinander; auf drei Subagenten verteilt fällt eine davon durch.
- **Task 8** — erst nach Deploy. Erster Klick ist die IFSG-Seite.
- Keine Migration, kein Zwei-Phasen-Push, kein `queue:restart`. `composer.lock`-Bump wie immer.

## Abschluss

- [ ] **Alle Tests grün:** `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml --testsuite Unit` — erwartet mindestens 471 Tests
- [ ] **Code-Review** über `superpowers:requesting-code-review`
- [ ] **Merge** nach Freigabe als Fast-Forward auf `main` (kein PR per CLI verfügbar)
- [ ] **`meingedeck` composer.lock bumpen** — ohne Bump ist nichts live
- [ ] **Kein `queue:restart`** — keine der geänderten Codestellen läuft in einem Worker
- [ ] **Nach Deploy:** Task 8 ausführen (Vorlage anlegen + Sichtprüfungen)

## Offene Punkte außerhalb dieses Plans

**Rechtsgrundlage.** Der Normverweis „§9 Nr. 9 ArGV" und die Zahl 140 sind aus dem Ursprungsdokument übernommen. Die ArGV ist seit 2013 aufgehoben; die heutige Regelung für Studierende aus Drittstaaten steht in § 16b Abs. 3 AufenthG und nennt 120 ganze bzw. 240 halbe Tage. Das sollte rechtlich geprüft werden.

Ergibt die Prüfung 120, ist der Änderungsaufwand bewusst klein gehalten: zwei Textstellen im Vorlagen-Editor (kein Deploy) plus **genau zwei** Codestellen (ein Deploy) — `max:140` in `ContractSigning::resttageRules()` samt der Meldung in `resttageMessages()`, und `max="140"` im Blade-Eingabefeld. Die Aufteilung auf Regel- und Meldungs-Methode ist der Grund, warum die Validierung nicht in `validatePreSigningData()` und `sign()` dupliziert wird; die Blade lässt sich nicht mit der PHP-Seite teilen und bleibt die zweite Stelle.

**ZAS-Export: Multi-Select-Lookups landen als JSON in der CSV.** `ZasFieldResolver::preloadExtraFields()` (`:447-451`) liest die rohe `value`-Spalte ohne `decodeSelectValue()`. Ein Multi-Select-Lookup kommt damit als `["a","b"]` bei `ZasLookupResolver::resolveLabel()` an, dessen `is_array()`-Weiche greift nicht, und der JSON-String landet unverändert in der CSV statt „A, B". Gehört auf die **ZAS-Phase-2-Liste**, nicht in diese Iteration — hier wird das Verhalten in Task 1 nur per Test festgenagelt, damit die Extraktion es nicht beiläufig verändert.

**Globaler Platzhalter-Guard.** Der Guard greift ausschließlich bei Verträgen mit `AT-`-Code — an beiden Stellen, `mount()` und dem harten Guard in `sign()`, mit demselben Prädikat. Arbeitsverträge und die IFSG-Belehrung sind nicht betroffen. Ihn generell für alle Verträge scharf zu schalten wäre sinnvoll, setzt aber voraus, dass kein Bestandsvertrag geschweifte Klammern im Inhalt hat. Das lässt sich mit den vorhandenen MCP-Tools nicht prüfen (kein Freitext-SQL). Eigener Task, falls gewünscht: ein Artisan-Kommando, das `rec_contracts` mit `personalized_content LIKE '%{{%'` zählt.
