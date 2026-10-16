# Infos im Mitarbeiterportal — Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR stellt Text-Infos („Willkommen", „News") als neue Dokumentart bereit; neue Mitarbeiter sehen sie beim ersten Öffnen des Portals ohne Klick, alle finden sie im Reiter „Dokumente & Infos".

**Architecture:** Erweiterung des Dokumente-Pakets (`rec_documents`/`rec_document_recipients`): neue Kategorie `info` mit Markdown-Inhalt statt PDF, Aktion immer `none`. Ein reiner Renderer (`InfoText`) macht aus Markdown sicheres HTML mit Knöpfen. Eine Automatik (`DokumentAutoZustellung`) legt beim ersten Öffnen des Portals Empfängerzeilen für Infos mit Haken „an neue Mitarbeiter" an, wenn die Anstellung nach der Veröffentlichung angelegt wurde. Portal zeigt ungesehene Infos oben im Kasten „Neu für dich"; das Anzeigen setzt `first_viewed_at`.

**Tech Stack:** Laravel 12, Livewire 3, league/commonmark (`Str::markdown`, aus dem Core), Toast UI Editor (von der Host-App global geladen, `window.ToastUIEditor`), PHPUnit 11 mit Capsule+SQLite (`DokumenteHarness`).

**Spec:** `docs/superpowers/specs/2026-10-16-portal-infos-design.md` (S-Info); Grundlage `docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` (S-Dok).

## Global Constraints

- Zweig `feat/portal-infos` (auf `feat/ma-konto`). `feat/ma-konto` NICHT anfassen. Nichts außerhalb des Moduls ändern (Core, UI, meingedeck sind read-only).
- Tests: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml` (kein eigenes vendor/). Vor jedem Commit: Suite grün (`grep -q "^OK"`), Baseline 4769.
- Blade-Hausregeln: kein inline `@if` in Komponenten-Attributen, keine an Wortzeichen geklebten Direktiven, `@php` nur als Block, keine PHP-Block-Direktivwörter in Blade-Kommentaren; prüfen mit `php tools/blade-check.php <datei>`.
- Portal-Texte als `$duzen`-Ternary. `#[Locked]` auf identitätstragenden Livewire-Properties. Keine Eloquent-Schreibzugriffe auf `rec_employees`. Query Builder für Stempel (`first_viewed_at`), idempotent (`whereNull` + `update`).
- Eine Info: `category = 'info'`, `action = 'none'`, `content_markdown` gesetzt, Datei-Spalten NULL, `file_sha256 = hash('sha256', content_markdown)`.
- Automatik-Stichtag: `rec_documents.published_at < rec_employees.created_at` (strikt kleiner).
- Erlaubte HTML-Elemente im gerenderten Text: `h2 h3 p strong em ul ol li a br hr`.
- Commit-Trailer:
  `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`
  `Claude-Session: https://claude.ai/code/session_01NbBXyZcH4EKkT41cWxPtda`

## Review Focus

1. **Markdown mit eingebettetem HTML/JS** (`<script>`, `<img onerror>`, `[x](javascript:…)`): muss restlos verschwinden, nie im Portal landen. → Task 1 Tests.
2. **Bestandsmitarbeiter, der nach Veröffentlichung umgestellt wird**: Anstellung ist älter als die Info → darf die Willkommensinfo NICHT bekommen. → Task 3 Test „vorher angelegt".
3. **Person mit RG+MA, beide Anstellungen nach Veröffentlichung angelegt**: genau EINE Empfängerzeile, nicht zwei. → Task 3 Test.
4. **Fremde Empfänger-ID beim Aufklappen im Reiter**: darf nichts zeigen und nichts stempeln. → Task 4 Test.
5. **Info ohne Empfänger und ohne Automatik-Haken**: muss abgelehnt werden (sonst liegt eine Info, die niemand je sieht). → Task 2 Test.

---

### Task 1: Schema, Kategorie `info`, Renderer `InfoText`

**Files:**
- Create: `database/migrations/2026_10_16_000001_add_info_fields_to_rec_documents.php`
- Modify: `src/Support/DokumentKategorie.php`
- Modify: `src/Models/RecDocument.php` (fillable, casts)
- Create: `src/Support/InfoText.php`
- Test: `tests/Unit/InfoTextTest.php`, `tests/Unit/DokumentKategorieInfoTest.php`

**Interfaces:**
- Produces: `DokumentKategorie::INFO = 'info'`, `DokumentKategorie::istText(string $category): bool`; `InfoText::html(string $markdown): string`; Spalten `content_markdown` (text null), `auto_neue_mitarbeiter` (bool, default false), `published_at` (datetime null); `disk`, `stored_path`, `original_filename`, `file_sha256`, `file_size` nullable.

- [ ] **Step 1: Failing Tests für Kategorie und Renderer schreiben**

`tests/Unit/DokumentKategorieInfoTest.php`:
```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DokumentKategorie;

final class DokumentKategorieInfoTest extends TestCase
{
    public function test_info_ist_eine_kategorie_mit_aktion_none_und_textinhalt(): void
    {
        $this->assertTrue(DokumentKategorie::exists(DokumentKategorie::INFO));
        $this->assertSame('Info / News', DokumentKategorie::label(DokumentKategorie::INFO));
        $this->assertSame(DokumentKategorie::AKTION_NONE, DokumentKategorie::defaultAktion(DokumentKategorie::INFO));
        $this->assertTrue(DokumentKategorie::istText(DokumentKategorie::INFO));
        foreach ([DokumentKategorie::CONTRACT, DokumentKategorie::INSTRUCTION, DokumentKategorie::OTHER] as $c) {
            $this->assertFalse(DokumentKategorie::istText($c), $c);
        }
    }
}
```

`tests/Unit/InfoTextTest.php`:
```php
<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\InfoText;

final class InfoTextTest extends TestCase
{
    public function test_ueberschrift_fett_liste_und_link_werden_gerendert(): void
    {
        $html = InfoText::html("# Willkommen\n\n**Kleidung**\n\n- Schwarze Schuhe\n- Weißes Hemd\n\n[Anleitung](https://www.youtube.com/watch?v=abc)");
        $this->assertStringContainsString('<h2>Willkommen</h2>', $html);
        $this->assertStringContainsString('<strong>Kleidung</strong>', $html);
        $this->assertStringContainsString('<li>Schwarze Schuhe</li>', $html);
        $this->assertMatchesRegularExpression('~<a [^>]*href="https://www\.youtube\.com/watch\?v=abc"[^>]*class="info-link"[^>]*target="_blank"[^>]*rel="noopener"[^>]*>Anleitung</a>~', $html);
    }

    public function test_telefonnummer_im_text_wird_zum_anruf_knopf(): void
    {
        $html = InfoText::html("Marco – Disposition Köln/Bonn\n+49 1523 4079291");
        $this->assertStringContainsString('href="tel:+4915234079291"', $html);
        $this->assertStringContainsString('class="info-tel"', $html);
        $this->assertStringContainsString('>+49 1523 4079291</a>', $html);
    }

    public function test_html_und_gefaehrliche_links_verschwinden(): void
    {
        $html = InfoText::html("Hallo <script>alert(1)</script> <img src=x onerror=alert(1)>\n\n[klick](javascript:alert(1))\n\n<b>fett</b>");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('fett', $html);
    }

    public function test_nur_erlaubte_elemente_bleiben_h1_wird_h2(): void
    {
        $html = InfoText::html("# Titel\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n```\ncode\n```\n\n---\n\nText");
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('<h2>Titel</h2>', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('<pre', $html);
        $this->assertStringNotContainsString('<code', $html);
        $this->assertStringContainsString('<hr', $html);
        $this->assertStringContainsString('code', $html, 'Inhalt bleibt als Text, nur das Element faellt');
    }

    public function test_leerer_text_ergibt_leeres_html(): void
    {
        $this->assertSame('', InfoText::html("   \n  "));
    }
}
```

- [ ] **Step 2: Tests laufen lassen, müssen rot sein**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/DokumentKategorieInfoTest.php tests/Unit/InfoTextTest.php`
Expected: FAIL (Konstante `INFO` fehlt, Klasse `InfoText` fehlt).

- [ ] **Step 3: Migration schreiben**

`database/migrations/2026_10_16_000001_add_info_fields_to_rec_documents.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Infos im Mitarbeiterportal (Spec 2026-10-16 §2): ein Dokument darf statt
 * einer PDF einen Text tragen. Drei neue Spalten, fuenf Datei-Spalten werden
 * nullable. change() nur, wenn die Spalte heute wirklich NOT NULL ist
 * (Muster 2026_10_01_000002) — ein unnoetiges ALTER kostet auf der
 * Produktion Sperrzeit fuer nichts. Vor dem Deploy: migrate --pretend auf
 * MySQL lesen (SQLite baut Tabellen anders um).
 */
return new class extends Migration
{
    private const DATEI_SPALTEN = ['disk', 'stored_path', 'original_filename', 'file_sha256', 'file_size'];

    public function up(): void
    {
        Schema::table('rec_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_documents', 'content_markdown')) {
                $table->text('content_markdown')->nullable()->after('file_size');
            }
            if (!Schema::hasColumn('rec_documents', 'auto_neue_mitarbeiter')) {
                $table->boolean('auto_neue_mitarbeiter')->default(false)->after('content_markdown');
            }
            if (!Schema::hasColumn('rec_documents', 'published_at')) {
                $table->dateTime('published_at')->nullable()->index()->after('auto_neue_mitarbeiter');
            }
        });

        foreach (self::DATEI_SPALTEN as $spalte) {
            if (!$this->istPflichtfeld($spalte)) {
                continue;
            }
            Schema::table('rec_documents', function (Blueprint $table) use ($spalte) {
                match ($spalte) {
                    'disk'              => $table->string('disk', 50)->nullable()->change(),
                    'stored_path'       => $table->string('stored_path', 500)->nullable()->change(),
                    'original_filename' => $table->string('original_filename', 255)->nullable()->change(),
                    'file_sha256'       => $table->char('file_sha256', 64)->nullable()->change(),
                    'file_size'         => $table->unsignedBigInteger('file_size')->nullable()->change(),
                };
            });
        }

        // Bestand: published_at = created_at, damit die Automatik (Spec §4)
        // einen Stichtag hat, falls HR eine alte Zeile spaeter umwidmet.
        DB::table('rec_documents')->whereNull('published_at')->update(['published_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('rec_documents', function (Blueprint $table) {
            foreach (['content_markdown', 'auto_neue_mitarbeiter', 'published_at'] as $spalte) {
                if (Schema::hasColumn('rec_documents', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
        // Datei-Spalten bleiben nullable: ein NOT NULL zurueck scheitert, sobald
        // eine Info existiert — bewusst kein Rueckbau (additiv, Spec §8).
    }

    private function istPflichtfeld(string $spalte): bool
    {
        $treiber = Schema::getConnection()->getDriverName();
        if ($treiber === 'sqlite') {
            foreach (DB::select("PRAGMA table_info('rec_documents')") as $z) {
                if ($z->name === $spalte) {
                    return (int) $z->notnull === 1;
                }
            }

            return false;
        }
        $spalten = Schema::getColumns('rec_documents');
        foreach ($spalten as $z) {
            if (($z['name'] ?? null) === $spalte) {
                return ($z['nullable'] ?? true) === false;
            }
        }

        return false;
    }
};
```
(Prüfe im Vorbild `2026_10_01_000002_add_employee_to_hr_desk_cases.php`, wie dort `istPflichtfeld()` geschrieben ist, und übernimm dessen Form, falls sie abweicht. Die Suite läuft auf SQLite mit allen Migrationen aus `database/migrations/*.php` — die Migration muss dort durchlaufen.)

- [ ] **Step 4: Kategorie und Model erweitern**

In `src/Support/DokumentKategorie.php`:
```php
    public const INFO        = 'info';
    // im KATALOG, nach OTHER:
        self::INFO        => ['label' => 'Info / News',                  'aktion' => self::AKTION_NONE],

    /**
     * Textbasierte Kategorie (Spec 2026-10-16 §2): statt einer PDF traegt das
     * Dokument content_markdown; die Aktion ist immer AKTION_NONE. Die EINE
     * Stelle fuer diese Regel — Service, Blade und Portal fragen hier.
     */
    public static function istText(string $category): bool
    {
        return $category === self::INFO;
    }
```

In `src/Models/RecDocument.php`: `'content_markdown', 'auto_neue_mitarbeiter', 'published_at'` in `$fillable`; Casts `'auto_neue_mitarbeiter' => 'boolean', 'published_at' => 'datetime'`.

- [ ] **Step 5: Renderer schreiben**

`src/Support/InfoText.php`:
```php
<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Str;

/**
 * Markdown einer Info → sicheres HTML fuers Portal (Spec 2026-10-16 §5.3).
 *
 * Zwei Sicherungen hintereinander, absichtlich: (1) commonmark mit
 * html_input=strip und allow_unsafe_links=false, (2) eine Weissliste der
 * Elemente per DOMDocument. Faellt eine Sicherung durch eine Bibliotheks-
 * aenderung weg, haelt die andere. Links werden zu Knoepfen (info-link,
 * target=_blank, rel=noopener), Telefonnummern im Fliesstext zu tel:-Links
 * (info-tel) — HR muss dafuer nichts tun. h1 wird h2: der Titel ist die h1.
 */
final class InfoText
{
    public const ERLAUBT = ['h2', 'h3', 'p', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'br', 'hr'];

    public static function html(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }
        $roh = (string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $roh . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $dom->getElementById('root');
        if ($root === null) {
            return '';
        }

        self::h1ZuH2($dom, $root);
        self::nurErlaubte($dom, $root);
        self::linksZuKnoepfen($root);
        self::telefonnummernVerlinken($dom, $root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $kind) {
            $out .= $dom->saveHTML($kind);
        }

        return trim($out);
    }

    private static function h1ZuH2(\DOMDocument $dom, \DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('h1')) as $h1) {
            $h2 = $dom->createElement('h2');
            while ($h1->firstChild) {
                $h2->appendChild($h1->firstChild);
            }
            $h1->parentNode->replaceChild($h2, $h1);
        }
    }

    /** Unerlaubte Elemente: Inhalt bleibt (als Text/Kinder), das Element faellt; Attribute nur href an a. */
    private static function nurErlaubte(\DOMDocument $dom, \DOMElement $root): void
    {
        $alle = iterator_to_array($root->getElementsByTagName('*'));
        foreach (array_reverse($alle) as $el) {
            $name = strtolower($el->nodeName);
            if (!in_array($name, self::ERLAUBT, true)) {
                $eltern = $el->parentNode;
                while ($el->firstChild) {
                    $eltern->insertBefore($el->firstChild, $el);
                }
                $eltern->removeChild($el);
                continue;
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                if (!($name === 'a' && $attr->nodeName === 'href')) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
            if ($name === 'a') {
                $href = trim((string) $el->getAttribute('href'));
                if (!preg_match('~^(https?://|tel:|mailto:)~i', $href)) {
                    $eltern = $el->parentNode;
                    while ($el->firstChild) {
                        $eltern->insertBefore($el->firstChild, $el);
                    }
                    $eltern->removeChild($el);
                }
            }
        }
    }

    private static function linksZuKnoepfen(\DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('a')) as $a) {
            if (str_starts_with(strtolower($a->getAttribute('href')), 'tel:')) {
                $a->setAttribute('class', 'info-tel');
                continue;
            }
            $a->setAttribute('class', 'info-link');
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener');
        }
    }

    /** +49 1523 4079291 / 0176 60450196 im Fliesstext → <a href="tel:+49…" class="info-tel">. Nicht innerhalb von <a>. */
    private static function telefonnummernVerlinken(\DOMDocument $dom, \DOMElement $root): void
    {
        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('.//text()[not(ancestor::a)]', $root)) as $text) {
            $wert = $text->nodeValue;
            if (!preg_match_all('/(\+49[\d\s\/-]{7,}\d|0\d{2,4}[\s\/-]?\d[\d\s\/-]{5,}\d)/', $wert, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $frag = $dom->createDocumentFragment();
            $pos = 0;
            foreach ($m[1] as [$treffer, $offset]) {
                $e164 = PhoneE164::normalize($treffer);
                if ($e164 === null) {
                    continue;
                }
                $frag->appendChild($dom->createTextNode(substr($wert, $pos, $offset - $pos)));
                $a = $dom->createElement('a', $treffer);
                $a->setAttribute('href', 'tel:' . $e164);
                $a->setAttribute('class', 'info-tel');
                $frag->appendChild($a);
                $pos = $offset + strlen($treffer);
            }
            $frag->appendChild($dom->createTextNode(substr($wert, $pos)));
            $text->parentNode->replaceChild($frag, $text);
        }
    }
}
```
(`PhoneE164::normalize()` existiert in `src/Support/PhoneE164.php`; prüfe die Signatur und ob sie für `+49 1523 4079291` `+4915234079291` liefert — sonst die Normalisierung dort nachlesen und den Test anpassen, nicht die Erwartung raten.)

- [ ] **Step 6: Tests grün, Suite grün**

Run: `../../../meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/DokumentKategorieInfoTest.php tests/Unit/InfoTextTest.php` → PASS.
Run: volle Suite → `OK` (die Migration läuft in `DokumenteHarness` über `glob(database/migrations/*.php)` mit; bestehende Dokumente-Tests müssen grün bleiben).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_16_000001_add_info_fields_to_rec_documents.php src/Support/DokumentKategorie.php src/Support/InfoText.php src/Models/RecDocument.php tests/Unit/InfoTextTest.php tests/Unit/DokumentKategorieInfoTest.php
git commit -m "feat(recruiting): Infos — Schema, Kategorie info, sicherer Markdown-Renderer InfoText"
```

---

### Task 2: `DokumentService::bereitstellenText`

**Files:**
- Modify: `src/Services/DokumentService.php`
- Test: `tests/Integration/DokumentInfoBereitstellenTest.php`

**Interfaces:**
- Consumes: `DokumentKategorie::INFO`, `::istText()`, Spalten aus Task 1.
- Produces: `DokumentService::bereitstellenText(int $teamId, array $daten, string $markdown, array $employeeIds, ?int $userId): array{dokument: RecDocument, empfaenger: int, versand_noetig: bool}` mit `$daten = ['title' => string, 'auto_neue_mitarbeiter' => bool]`.

- [ ] **Step 1: Failing Test**

`tests/Integration/DokumentInfoBereitstellenTest.php` (Harness wie `DokumentEmpfaengerAuswahlTest`: `use DokumenteHarness; harnessAufsetzen()` in `setUp`, `harnessAbbauen()` in `tearDown`; der Harness legt Team 3 und Mitarbeiter an — nachsehen, welche IDs er anbietet, und `mitarbeiterAnlegen(...)` oder das dortige Pendant nutzen):
```php
public function test_info_wird_ohne_datei_mit_textpruefsumme_angelegt(): void
{
    $service = new DokumentService($this->speicher);
    $r = $service->bereitstellenText(3, ['title' => 'Willkommen', 'auto_neue_mitarbeiter' => false], "# Hallo\n\nText", [$this->maId], 7);

    $d = $r['dokument'];
    $this->assertSame('info', $d->category);
    $this->assertSame('none', $d->action);
    $this->assertSame("# Hallo\n\nText", $d->content_markdown);
    $this->assertSame(hash('sha256', "# Hallo\n\nText"), $d->file_sha256);
    $this->assertNull($d->stored_path);
    $this->assertNull($d->original_filename);
    $this->assertNull($d->file_size);
    $this->assertNotNull($d->published_at);
    $this->assertFalse($d->auto_neue_mitarbeiter);
    $this->assertSame(1, $r['empfaenger']);
    $this->assertFalse($r['versand_noetig'], 'Aktion none: keine WhatsApp-Pflicht');
    $this->assertSame(1, RecDocumentRecipient::where('rec_document_id', $d->id)->count());
}

public function test_leerer_text_wird_abgelehnt(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new DokumentService($this->speicher))->bereitstellenText(3, ['title' => 'X'], "  \n ", [$this->maId], 7);
}

public function test_ohne_empfaenger_nur_mit_automatik_haken_erlaubt(): void
{
    $service = new DokumentService($this->speicher);
    try {
        $service->bereitstellenText(3, ['title' => 'X', 'auto_neue_mitarbeiter' => false], 'Text', [], 7);
        $this->fail('ohne Empfaenger und ohne Haken muss abgelehnt werden');
    } catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('Empfänger', $e->getMessage());
    }
    $r = $service->bereitstellenText(3, ['title' => 'X', 'auto_neue_mitarbeiter' => true], 'Text', [], 7);
    $this->assertSame(0, $r['empfaenger']);
    $this->assertTrue($r['dokument']->auto_neue_mitarbeiter);
}

public function test_text_laenger_als_20000_zeichen_wird_abgelehnt(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new DokumentService($this->speicher))->bereitstellenText(3, ['title' => 'X'], str_repeat('a', 20001), [$this->maId], 7);
}

public function test_fremde_und_unbekannte_ids_werden_stumm_uebergangen(): void
{
    $r = (new DokumentService($this->speicher))->bereitstellenText(3, ['title' => 'X'], 'Text', [$this->maId, 999999], 7);
    $this->assertSame(1, $r['empfaenger']);
}
```

- [ ] **Step 2: Rot laufen lassen** (`bereitstellenText` fehlt).

- [ ] **Step 3: Service erweitern**

In `DokumentService`: den Teil von `bereitstellen()`, der `$ids` → `$anstellungen` → `$geordnet` → `$empfaengerIds` baut, in eine private Methode `empfaengerAufloesen(int $teamId, array $employeeIds): array{ids: list<int>, anstellungen: array<int, array{id:int, person_key:?string}>}` ziehen und von `bereitstellen()` aufrufen (Verhalten identisch; der PDF-Weg wirft weiter „Bitte mindestens einen Empfänger wählen." bei leerer Menge). Dann:

```php
    public const INFO_MAX_ZEICHEN = 20000;

    /**
     * Info/News als Text statt PDF (Spec 2026-10-16 §3.1). Kategorie info,
     * Aktion none, Pruefsumme ueber den Text in file_sha256. Ohne Empfaenger
     * nur mit auto_neue_mitarbeiter — sonst laege eine Info, die nie jemand
     * sieht. Keine Datei, kein Speicher.
     *
     * @param  array{title:string, auto_neue_mitarbeiter?:bool} $daten
     * @param  list<int> $employeeIds
     * @return array{dokument: RecDocument, empfaenger: int, versand_noetig: bool}
     */
    public function bereitstellenText(int $teamId, array $daten, string $markdown, array $employeeIds, ?int $userId): array
    {
        $titel = trim((string) ($daten['title'] ?? ''));
        $auto = (bool) ($daten['auto_neue_mitarbeiter'] ?? false);
        if ($titel === '') {
            throw new \InvalidArgumentException('Bitte einen Titel angeben.');
        }
        if (trim($markdown) === '') {
            throw new \InvalidArgumentException('Bitte einen Text schreiben.');
        }
        if (mb_strlen($markdown) > self::INFO_MAX_ZEICHEN) {
            throw new \InvalidArgumentException('Der Text ist zu lang (höchstens ' . self::INFO_MAX_ZEICHEN . ' Zeichen).');
        }
        $aufgeloest = $this->empfaengerAufloesen($teamId, $employeeIds);
        if ($aufgeloest['ids'] === [] && !$auto) {
            throw new \InvalidArgumentException('Bitte mindestens einen Empfänger wählen oder „automatisch an neue Mitarbeiter“ anhaken.');
        }

        $dokument = DB::transaction(function () use ($teamId, $titel, $markdown, $auto, $userId, $aufgeloest) {
            $dokument = RecDocument::create([
                'team_id'               => $teamId,
                'uuid'                  => (string) UuidV7::generate(),
                'title'                 => mb_substr($titel, 0, 255),
                'category'              => DokumentKategorie::INFO,
                'action'                => DokumentKategorie::AKTION_NONE,
                'content_markdown'      => $markdown,
                'file_sha256'           => hash('sha256', $markdown),
                'auto_neue_mitarbeiter' => $auto,
                'published_at'          => now(),
                'created_by_user_id'    => $userId,
            ]);
            foreach ($aufgeloest['ids'] as $employeeId) {
                RecDocumentRecipient::create([
                    'team_id'         => $teamId,
                    'rec_document_id' => $dokument->id,
                    'rec_employee_id' => $employeeId,
                    'person_key'      => $aufgeloest['anstellungen'][$employeeId]['person_key'] ?? null,
                ]);
            }

            return $dokument;
        });

        return ['dokument' => $dokument->fresh(), 'empfaenger' => count($aufgeloest['ids']), 'versand_noetig' => false];
    }
```
Zusätzlich in `bereitstellen()` (PDF-Weg) als Schutz: `if (DokumentKategorie::istText($kategorie)) { throw new \InvalidArgumentException('Infos werden als Text bereitgestellt, nicht als PDF.'); }`.

- [ ] **Step 4: Grün, Suite grün** (bestehende `DokumentService`-Tests unverändert grün — der PDF-Weg ist nur umgezogen).

- [ ] **Step 5: Commit**
```bash
git add src/Services/DokumentService.php tests/Integration/DokumentInfoBereitstellenTest.php
git commit -m "feat(recruiting): Infos — DokumentService::bereitstellenText (Text statt PDF, Empfaenger optional mit Automatik-Haken)"
```

---

### Task 3: Automatik „an neue Mitarbeiter" beim ersten Öffnen

**Files:**
- Create: `src/Services/DokumentAutoZustellung.php`
- Modify: `src/Livewire/Public/PortalShell.php` (`identitaetLaden()` ~Z. 1439 und beide Aufrufer ~Z. 169/263)
- Test: `tests/Integration/DokumentAutoZustellungTest.php`

**Interfaces:**
- Consumes: `PersonScopeResolver::forEmployee(RecEmployee): array{ids: list<int>, …}` (src/Services/PersonScopeResolver.php), Spalten aus Task 1.
- Produces: `DokumentAutoZustellung::fuerErstesOeffnen(RecEmployee $employee): int` (Zahl neu angelegter Zeilen).

- [ ] **Step 1: Failing Tests**

```php
final class DokumentAutoZustellungTest extends TestCase
{
    use DokumenteHarness;
    // setUp/tearDown wie DokumentEmpfaengerAuswahlTest

    private function info(bool $auto, string $publishedAt): int
    {
        return (int) RecDocument::create([
            'team_id' => 3, 'title' => 'Willkommen', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'Hallo', 'file_sha256' => hash('sha256', 'Hallo'),
            'auto_neue_mitarbeiter' => $auto, 'published_at' => $publishedAt,
        ])->id;
    }

    private function anstellung(string $createdAt, string $company = 'RG', ?string $personKey = null): RecEmployee
    {
        // Harness-Helfer zum Anlegen nutzen; danach created_at per Query Builder setzen:
        $e = $this->mitarbeiterAnlegen(['company' => $company, 'person_key' => $personKey]);
        DB::table('rec_employees')->where('id', $e->id)->update(['created_at' => $createdAt]);
        return $e->fresh();
    }

    public function test_nach_veroeffentlichung_angelegte_anstellung_bekommt_die_info_einmal(): void
    {
        $info = $this->info(true, '2026-10-20 10:00:00');
        $e = $this->anstellung('2026-10-21 09:00:00');
        $dienst = new DokumentAutoZustellung();
        $this->assertSame(1, $dienst->fuerErstesOeffnen($e));
        $this->assertSame(0, $dienst->fuerErstesOeffnen($e), 'idempotent');
        $z = RecDocumentRecipient::where('rec_document_id', $info)->get();
        $this->assertCount(1, $z);
        $this->assertSame($e->id, (int) $z->first()->rec_employee_id);
        $this->assertNull($z->first()->notified_at, 'keine WhatsApp: der Mensch ist gerade im Portal');
    }

    public function test_vorher_angelegte_anstellung_bekommt_nichts(): void
    {
        $this->info(true, '2026-10-20 10:00:00');
        $e = $this->anstellung('2026-10-19 09:00:00');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($e));
    }

    public function test_gleicher_zeitpunkt_zaehlt_als_vorher(): void
    {
        $this->info(true, '2026-10-20 10:00:00');
        $e = $this->anstellung('2026-10-20 10:00:00');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($e));
    }

    public function test_ohne_haken_zurueckgezogen_oder_fremdes_team_nichts(): void
    {
        $this->info(false, '2026-10-20 10:00:00');
        $geloescht = $this->info(true, '2026-10-20 10:00:00');
        RecDocument::find($geloescht)->delete();
        RecDocument::create(['team_id' => 4, 'title' => 'Fremd', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'x', 'file_sha256' => hash('sha256', 'x'), 'auto_neue_mitarbeiter' => true, 'published_at' => '2026-10-20 10:00:00']);
        $e = $this->anstellung('2026-10-21 09:00:00');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($e));
    }

    public function test_rg_und_ma_derselben_person_eine_zeile(): void
    {
        $info = $this->info(true, '2026-10-20 10:00:00');
        $rg = $this->anstellung('2026-10-21 09:00:00', 'RG', 'pk-1');
        $ma = $this->anstellung('2026-10-21 09:05:00', 'MA', 'pk-1');
        $dienst = new DokumentAutoZustellung();
        $this->assertSame(1, $dienst->fuerErstesOeffnen($rg));
        $this->assertSame(0, $dienst->fuerErstesOeffnen($ma), 'Schwester-Anstellung hat die Info schon ueber die Person');
        $this->assertSame(1, RecDocumentRecipient::where('rec_document_id', $info)->count());
    }
}
```

- [ ] **Step 2: Rot** (Klasse fehlt).

- [ ] **Step 3: Dienst schreiben**

```php
<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Infos mit "automatisch an neue Mitarbeiter" (Spec 2026-10-16 §4): beim
 * ersten Oeffnen des Portals bekommt die Person jede solche Info, deren
 * published_at VOR dem created_at ihrer Anstellung liegt — der Bestand ist
 * damit von selbst draussen (seine Anstellungen sind aelter als jede Info).
 * Eine Zeile je Person (Scope ueber person_key wie DokumentLeser), keine
 * WhatsApp (der Mensch ist gerade im Portal), Query Builder, idempotent.
 */
class DokumentAutoZustellung
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver())
    {
    }

    public function fuerErstesOeffnen(RecEmployee $employee): int
    {
        $createdAt = $employee->created_at;
        if ($createdAt === null) {
            return 0;
        }
        $ids = $this->scope->forEmployee($employee)['ids'];

        $kandidaten = DB::table('rec_documents')
            ->where('team_id', (int) $employee->team_id)
            ->where('category', DokumentKategorie::INFO)
            ->where('auto_neue_mitarbeiter', true)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->where('published_at', '<', $createdAt->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->pluck('id');
        if ($kandidaten->isEmpty()) {
            return 0;
        }
        $vorhanden = DB::table('rec_document_recipients')
            ->whereIn('rec_document_id', $kandidaten)
            ->whereIn('rec_employee_id', $ids)
            ->pluck('rec_document_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $neu = 0;
        foreach ($kandidaten as $dokumentId) {
            if (in_array((int) $dokumentId, $vorhanden, true)) {
                continue;
            }
            RecDocumentRecipient::create([
                'team_id'         => (int) $employee->team_id,
                'rec_document_id' => (int) $dokumentId,
                'rec_employee_id' => (int) $employee->id,
                'person_key'      => $employee->person_key,
            ]);
            $neu++;
        }
        if ($neu > 0) {
            Log::info('recruiting.dokumente.auto_zugestellt', ['employee_id' => (int) $employee->id, 'anzahl' => $neu]);
        }

        return $neu;
    }
}
```
(Prüfe `RecDocumentRecipient` auf `creating`-Hook für `uuid`; falls der dort fehlt, wie in `DokumentService::bereitstellen()` vorgehen — dort wird `uuid` nicht gesetzt, also setzt das Model sie selbst. Nachsehen.)

- [ ] **Step 4: In `PortalShell` einhängen**

In `identitaetLaden(RecEmployee $employee)` als erste Zeile:
```php
        // Spec 2026-10-16 §4: beim ersten Oeffnen Infos "an neue Mitarbeiter"
        // zustellen — hier, weil beide Wege (Token-Pruefung und Konto) hier
        // durchlaufen. Ein Fehler darf die Anmeldung nicht kosten.
        try {
            app(DokumentAutoZustellung::class)->fuerErstesOeffnen($employee);
        } catch (\Throwable $e) {
            Log::warning('recruiting.dokumente.auto_zustellung_fehlgeschlagen', ['employee_id' => (int) $employee->id, 'error' => $e->getMessage()]);
        }
```
Prüfe, ob `identitaetLaden()` auch bei jedem Livewire-Request (hydrate/mount mit gültiger Sitzung) läuft — dann ist der Aufruf idempotent und billig (eine Abfrage), aber: falls er pro Request läuft, die Abfrage auf Team-Infos mit Haken beschränken (ist sie) und im Bericht nennen. Besser: nur bei `mount()` und `verify()`, nicht in `hydrate()` — nachsehen und entsprechend platzieren.

- [ ] **Step 5: Grün, Suite grün. Commit**
```bash
git add src/Services/DokumentAutoZustellung.php src/Livewire/Public/PortalShell.php tests/Integration/DokumentAutoZustellungTest.php
git commit -m "feat(recruiting): Infos — Automatik an neue Mitarbeiter beim ersten Oeffnen des Portals"
```

---

### Task 4: Portal — Kasten „Neu für dich", Reiter „Dokumente & Infos"

**Files:**
- Modify: `src/Services/DokumentLeser.php` (`zeile()`: `is_info`, `content_html`, `auto`)
- Modify: `src/Livewire/Public/PortalShell.php` (`mitarbeiterDokumente()` ~Z. 648, `ansichtsDaten` ~Z. 953–1017, neue Methoden `infoAufklappen(int)`, `infosSchliessen()`, Property `public bool $infoKastenZu = false`, `public ?int $infoOffen = null`)
- Modify: `resources/views/livewire/public/portal-shell.blade.php` (Start-Kasten vor „Noch zu erledigen"; Reiter-Titel; Info-Block über „Deine Dokumente"; CSS `.info-link`, `.info-tel`, `.info-text h2/h3/p/ul`)
- Test: `tests/Integration/PortalInfosTest.php`

**Interfaces:**
- Consumes: `InfoText::html()`, `DokumentLeser::fuerMitarbeiter()`, `DokumentLeser::empfaenger()`, `DokumentUnterschrift::oeffnen($z)` (setzt `first_viewed_at`).
- Produces: in `DokumentLeser::zeile()` zusätzlich `'is_info' => bool, 'content_html' => string ('' bei PDF), 'gesehen' => bool`.

- [ ] **Step 1: Failing Tests** (Vorbild: bestehende PortalShell-Tests mit Capsule, z. B. `grep -l "PortalShell" tests/Integration/*.php` — denselben Aufbau nehmen: Mitarbeiter mit `portal_v2_since`, Sitzung setzen, Komponente rendern)

```php
public function test_ungesehene_info_steht_oben_im_kasten_und_das_anzeigen_setzt_first_viewed_at(): void
{
    [$e, $recipientId] = $this->mitarbeiterMitInfo("# Willkommen\n\nSchön, dass du da bist.");
    $html = $this->portalRendern($e);
    $this->assertStringContainsString('Neu für dich', $html);
    $this->assertStringContainsString('<h2>Willkommen</h2>', $html);
    $pos1 = strpos($html, 'Neu für dich'); $pos2 = strpos($html, 'Noch zu erledigen');
    if ($pos2 !== false) { $this->assertLessThan($pos2, $pos1, 'Infos stehen vor den offenen Punkten'); }
    $this->assertNotNull(DB::table('rec_document_recipients')->where('id', $recipientId)->value('first_viewed_at'));
    $erstes = DB::table('rec_document_recipients')->where('id', $recipientId)->value('first_viewed_at');
    $this->portalRendern($e);
    $this->assertSame($erstes, DB::table('rec_document_recipients')->where('id', $recipientId)->value('first_viewed_at'), 'erster Zeitpunkt gewinnt');
}

public function test_gesehene_info_kommt_beim_naechsten_login_nicht_mehr_hoch_steht_aber_im_reiter(): void
{
    [$e, $recipientId] = $this->mitarbeiterMitInfo('Text');
    DB::table('rec_document_recipients')->where('id', $recipientId)->update(['first_viewed_at' => '2026-10-20 10:00:00']);
    $html = $this->portalRendern($e);
    $this->assertStringNotContainsString('Neu für dich', $html);
    $this->assertStringContainsString('Dokumente &amp; Infos', $html);
    $this->assertStringContainsString('data-info="' . $recipientId . '"', $html);
}

public function test_info_ist_kein_offener_punkt(): void
{
    [$e] = $this->mitarbeiterMitInfo('Text');
    $punkte = app(DokumentLeser::class)->offenePunkte($e);
    $this->assertSame([], $punkte);
}

public function test_fremde_empfaenger_id_beim_aufklappen_zeigt_nichts_und_stempelt_nichts(): void
{
    [$e] = $this->mitarbeiterMitInfo('Text');
    [$fremd, $fremdId] = $this->mitarbeiterMitInfo('Fremd', personKey: 'andere-person');
    $komponente = $this->portalKomponente($e);
    $komponente->infoAufklappen($fremdId);
    $this->assertNull($komponente->infoOffen);
    $this->assertNull(DB::table('rec_document_recipients')->where('id', $fremdId)->value('first_viewed_at'));
}

public function test_pdf_zeilen_tragen_kein_content_html(): void
{
    // bestehende PDF-Zustellung anlegen (Harness) → zeile()['is_info'] === false, content_html === ''
}
```

- [ ] **Step 2: Rot.**

- [ ] **Step 3: `DokumentLeser::zeile()` erweitern**

```php
        $istInfo = DokumentKategorie::istText((string) $z->document->category);
        return [
            // … bestehende Schlüssel …
            'is_info'      => $istInfo,
            'content_html' => $istInfo ? InfoText::html((string) $z->document->content_markdown) : '',
            'gesehen'      => $z->first_viewed_at !== null,
        ];
```
Zusätzlich `public function ungeseheneInfos(RecEmployee $employee): array` — `sichtbare()` gefiltert auf `is_info && !gesehen`, älteste zuerst, als Zeilen.

- [ ] **Step 4: `PortalShell`**

```php
    /** Kasten "Neu fuer dich" fuer diese Sitzung zugeklappt (Spec 2026-10-16 §5.1) — nur Zustand, nichts in der DB. */
    public bool $infoKastenZu = false;

    /** Im Reiter aufgeklappte Info (Empfaenger-ID, gegen den Scope geprueft). */
    public ?int $infoOffen = null;

    public function infosSchliessen(): void
    {
        $this->infoKastenZu = true;
    }

    public function infoAufklappen(int $recipientId): void
    {
        $employee = $this->berechtigterMitarbeiter();
        $z = $employee ? app(DokumentLeser::class)->empfaenger($employee, $recipientId) : null;
        if ($z === null || !DokumentKategorie::istText((string) $z->document->category)) {
            $this->infoOffen = null;
            return;
        }
        app(DokumentUnterschrift::class)->oeffnen($z);   // first_viewed_at, idempotent
        $this->infoOffen = $this->infoOffen === (int) $z->id ? null : (int) $z->id;
    }
```
In `ansichtsDaten` (wo `$mitarbeiterDokumente` gebaut wird): `$neueInfos = $employee && !$this->infoKastenZu ? $leser->ungeseheneInfos($employee) : [];` und **nur wenn der Start gerendert wird** für jede Zeile `oeffnen($z)` aufrufen (das Anzeigen gilt als gesehen; vorher die Zeilen holen, damit sie im selben Render noch als „neu" erscheinen). `$infos = array_values(array_filter($mitarbeiterDokumente, fn ($d) => $d['is_info']))`, `$mitarbeiterDokumente` ohne Infos. An die View: `'neueInfos', 'infos', 'infoOffen'`.

- [ ] **Step 5: Blade**

Start, vor dem Kasten der offenen Punkte:
```blade
@if ($neueInfos !== [])
    <div class="info-kasten">
        <div class="info-kopf">
            <div class="sec-label">{{ $duzen ? 'Neu für dich' : 'Neu für Sie' }} <span class="count">{{ count($neueInfos) }}</span></div>
            <button type="button" class="mini" wire:click="infosSchliessen">Schließen</button>
        </div>
        @foreach ($neueInfos as $neueInfo)
            <article class="card info-text" data-info="{{ $neueInfo['recipient_id'] }}">
                <h2 class="info-titel">{{ $neueInfo['title'] }}</h2>
                {!! $neueInfo['content_html'] !!}
            </article>
        @endforeach
    </div>
@endif
```
Reiter: Überschrift „Dokumente" → `{{ $duzen ? 'Dokumente & Infos' : 'Dokumente & Infos' }}` (an der Stelle, an der der Reiter-Name steht — suchen nach dem Reiter-Label „Dokumente" im Navigations-Block). Über „Deine Dokumente" ein Block:
```blade
@if ($infos !== [])
    <div>
        <div class="sec-label">Infos <span class="count">{{ count($infos) }}</span></div>
        <div class="card" style="margin-top:11px">
            @foreach ($infos as $info)
                @php
                    $infoChip = $info['gesehen'] ? 'chip info' : 'chip crit';
                    $infoChipText = $info['gesehen'] ? 'Gesehen' : 'Neu';
                    $infoIstOffen = $infoOffen === $info['recipient_id'];
                @endphp
                <div class="doc" data-info="{{ $info['recipient_id'] }}">
                    <div class="docicon"><span>INFO</span></div>
                    <div class="body">
                        <div class="title">{{ $info['title'] }}</div>
                        <div class="sub">{{ \Carbon\Carbon::parse($info['provided_at'])->format('d.m.Y') }}</div>
                        <div class="row">
                            <span class="{{ $infoChip }}">{{ $infoChipText }}</span>
                            <button type="button" class="mini" wire:click="infoAufklappen({{ $info['recipient_id'] }})">{{ $infoIstOffen ? 'Zuklappen' : 'Lesen' }}</button>
                        </div>
                        @if ($infoIstOffen)
                            <div class="info-text">{!! $info['content_html'] !!}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
```
CSS im Portal-Stylesheet-Block: `.info-text h2`, `.info-text h3`, `.info-text p`, `.info-text ul/ol` (Abstände), `.info-link` (Knopf, volle Breite am Handy, Portal-Primärfarbe), `.info-tel` (Knopf mit Telefon-Optik), `.info-kasten` (Rahmen wie `aufgaben-kasten`). `{!! !!}` ist hier zulässig, weil `InfoText::html()` die Weissliste erzwingt — Kommentar im Blade dazu.

- [ ] **Step 6: `php tools/blade-check.php resources/views/livewire/public/portal-shell.blade.php`, Tests grün, Suite grün. Commit**
```bash
git add src/Services/DokumentLeser.php src/Livewire/Public/PortalShell.php resources/views/livewire/public/portal-shell.blade.php tests/Integration/PortalInfosTest.php
git commit -m "feat(recruiting): Portal — Infos im Kasten „Neu fuer dich“ und im Reiter Dokumente & Infos"
```

---

### Task 5: HR-Seite „Dokumente" — Umschalter PDF | Info, Editor, Vorschau

**Files:**
- Modify: `src/Livewire/Employees/Documents.php` (Properties `public string $art = 'pdf'`, `public string $infoMarkdown = ''`, `public bool $autoNeue = false`, `public bool $vorschauOffen = false`; `bereitstellenPruefen()`, `bereitstellen()`; `#[Computed] vorschauHtml()`)
- Modify: `resources/views/livewire/employees/documents.blade.php` (Umschalter, Editor-Block, Haken, Vorschau-Modal, Bestätigungstext)
- Test: `tests/Integration/DokumentInfoFormularTest.php` (Vorbild `DokumentEmpfaengerAuswahlTest`)

**Interfaces:**
- Consumes: `DokumentService::bereitstellenText()`, `InfoText::html()`.

- [ ] **Step 1: Failing Tests**

```php
public function test_info_ohne_datei_wird_bereitgestellt_und_auswahl_geleert(): void
{
    $c = $this->komponente(); // wie im Vorbild
    $c->art = 'info'; $c->titel = 'Willkommen'; $c->infoMarkdown = "# Hallo"; $c->autoNeue = true;
    $c->toggle($this->maId);
    $c->bereitstellenPruefen();
    $this->assertTrue($c->bestaetigungOffen);
    $c->bereitstellen();
    $d = RecDocument::where('category', 'info')->first();
    $this->assertNotNull($d);
    $this->assertTrue($d->auto_neue_mitarbeiter);
    $this->assertSame(1, RecDocumentRecipient::where('rec_document_id', $d->id)->count());
    $this->assertSame([], $c->gewaehlteIds());
    $this->assertSame('', $c->infoMarkdown);
}

public function test_info_ohne_text_wird_vor_der_bestaetigung_abgefangen(): void
{
    $c = $this->komponente(); $c->art = 'info'; $c->titel = 'X'; $c->toggle($this->maId);
    $c->bereitstellenPruefen();
    $this->assertFalse($c->bestaetigungOffen);
    $this->assertStringContainsString('Text', (string) $c->flashError);
}

public function test_info_ohne_empfaenger_nur_mit_haken(): void
{
    $c = $this->komponente(); $c->art = 'info'; $c->titel = 'X'; $c->infoMarkdown = 'Text';
    $c->bereitstellenPruefen();
    $this->assertFalse($c->bestaetigungOffen);
    $c->autoNeue = true;
    $c->bereitstellenPruefen();
    $this->assertTrue($c->bestaetigungOffen);
}

public function test_pdf_weg_unveraendert(): void
{
    // bestehender Ablauf mit $datei bleibt gruen (Vorbild-Test kopieren, art = 'pdf' Default)
}

public function test_vorschau_rendert_mit_demselben_renderer(): void
{
    $c = $this->komponente(); $c->infoMarkdown = "# T\n\n<script>x</script>";
    $this->assertSame(InfoText::html($c->infoMarkdown), $c->vorschauHtml());
    $this->assertStringNotContainsString('<script', $c->vorschauHtml());
}
```

- [ ] **Step 2: Rot.**

- [ ] **Step 3: Komponente**

```php
    /** 'pdf' | 'info' — Spec 2026-10-16 §3.2 */
    public string $art = 'pdf';
    public string $infoMarkdown = '';
    public bool $autoNeue = false;
    public bool $vorschauOffen = false;

    #[Computed]
    public function vorschauHtml(): string
    {
        return InfoText::html($this->infoMarkdown);
    }

    public function updatedArt(): void
    {
        $this->flashError = null;
        $this->bestaetigungOffen = false;
    }
```
`bereitstellenPruefen()`: bei `$this->art === 'info'`: Titel Pflicht; `trim($this->infoMarkdown) === ''` → `flashError = 'Bitte einen Text schreiben.'`; Empfänger leer und `!$this->autoNeue` → `'Bite mindestens einen Empfänger wählen oder „automatisch an neue Mitarbeiter“ anhaken.'` (Rechtschreibung „Bitte"); sonst Bestätigung. `bereitstellen()`: bei `info` → `app(DokumentService::class)->bereitstellenText($this->teamId(), ['title' => $this->titel, 'auto_neue_mitarbeiter' => $this->autoNeue], $this->infoMarkdown, $ids, auth()->id())`, danach wie beim PDF-Weg: Auswahl leeren, `infoMarkdown = ''`, `autoNeue = false`, Flash „Info „…" bereitgestellt (n Empfänger…)" bzw. bei 0 Empfängern „…wird automatisch an neue Mitarbeiter zugestellt". Kein Job-Dispatch (`versand_noetig` false).

- [ ] **Step 4: Blade**

Umschalter oben im Formular (zwei Radio-Knöpfe `wire:model.live="art"`, Werte `pdf`/`info`). Bei `info`: Dateifeld, Kategorie- und Aktions-Select ausblenden (Block-`@if`), stattdessen:
```blade
@if ($art === 'info')
    <div wire:ignore x-data="infoEditor(@js($infoMarkdown))" x-init="init()">
        <div x-show="!bereit" x-cloak>
            <textarea x-ref="fallback" wire:model.blur="infoMarkdown" rows="12" class="w-full border border-[var(--ui-border)] rounded-md px-3 py-2 text-sm font-mono" placeholder="Text (Markdown: # Überschrift, **fett**, - Liste, [Link](https://…))"></textarea>
        </div>
        <div x-show="bereit" x-ref="editorEl"></div>
    </div>
    <label class="mt-3 flex items-center gap-2 text-sm">
        <input type="checkbox" wire:model.live="autoNeue"> Automatisch an neue Mitarbeiter (beim ersten Öffnen des Portals)
    </label>
    <button type="button" wire:click="$set('vorschauOffen', true)" class="mt-2 text-xs text-blue-700 hover:underline">Vorschau wie im Portal</button>
@endif
```
Alpine-Komponente im Blade (`<script>`-Block, Muster `platform-notes` `note.blade.php` `boot()`):
```js
Alpine.data('infoEditor', (start) => ({
    bereit: false, editor: null,
    init() { if (!this.boot(start)) { window.addEventListener('toastui:ready', () => this.boot(start), { once: true }); } },
    boot(start) {
        const Editor = window.ToastUIEditor; if (!Editor) return false;
        this.editor = new Editor({
            el: this.$refs.editorEl, height: '360px', initialEditType: 'wysiwyg', hideModeSwitch: true,
            usageStatistics: false, placeholder: 'Text der Info …',
            toolbarItems: [['heading', 'bold', 'italic'], ['ul', 'ol'], ['link']],
            initialValue: start || '',
        });
        this.editor.on('blur', () => this.$wire.set('infoMarkdown', this.editor.getMarkdown(), false));
        this.editor.on('change', () => { clearTimeout(this._t); this._t = setTimeout(() => this.$wire.set('infoMarkdown', this.editor.getMarkdown(), false), 400); });
        this.bereit = true; return true;
    },
}));
```
(Prüfe, wie andere Blades im Modul Alpine-Komponenten registrieren — `document.addEventListener('alpine:init', …)` oder inline `x-data="{…}"` — und übernimm dieses Muster. `wire:ignore` am Editor-Container ist Pflicht, sonst zerstört Livewire den Editor beim Rerender.) Nach dem Bereitstellen muss der Editor geleert werden: `$this->dispatch('info-bereitgestellt')` in der Komponente, im Alpine `@info-bereitgestellt.window="editor && editor.setMarkdown('')"`.

Vorschau-Modal (`x-ui-modal wire:model="vorschauOffen"`): Titel + `<div class="info-text">{!! $this->vorschauHtml !!}</div>` mit denselben CSS-Regeln wie im Portal (die paar Zeilen hier wiederholen; Kommentar: Vorschau = Portal-Renderer). Bestätigungsmodal: bei `info` Text „Info „{{ $titel }}" an {{ n }} Empfänger" bzw. „an 0 Empfänger jetzt, automatisch an alle Neuen ab heute".

- [ ] **Step 5: `php tools/blade-check.php resources/views/livewire/employees/documents.blade.php`, Tests grün, Suite grün. Commit**
```bash
git add src/Livewire/Employees/Documents.php resources/views/livewire/employees/documents.blade.php tests/Integration/DokumentInfoFormularTest.php
git commit -m "feat(recruiting): Dokumente — Info als Text mit Editor, Automatik-Haken und Vorschau"
```

---

### Task 6: HR-Überblick, Zurückziehen, Doku

**Files:**
- Modify: `src/Livewire/Employees/Documents.php` (`dokumente()`: `auto`, `gesehen`-Fortschritt für Infos), `src/Services/DokumentService.php` (`dokumentZurueckziehen`/Zurückziehen-Pfad: Haken auf false), `src/Support/DokumentFortschritt.php` (bei `action = none`: Fortschritt = `gesehen` von `gesamt` — prüfen, ob `ausZaehlern()` das schon so macht; S-Dok §2 sagt bei none `abgelegt/gesehen`)
- Modify: `resources/views/livewire/employees/documents.blade.php` (Listen-Zeile: Abzeichen „automatisch an Neue seit dd.mm.", Fortschritt „gesehen x von y", Icon INFO statt PDF)
- Modify: `docs/meingedeck/deploy-checkliste-ma-konto.md` (Abschnitt „Paket Infos (feat/portal-infos)" nach 4.11: Migration, `migrate --pretend` auf MySQL lesen, `view:clear`, Sichttest), `docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md` (Nachtrag: Kategorie `info`, siehe S-Info)
- Test: `tests/Integration/DokumentInfoUeberblickTest.php`

- [ ] **Step 1: Failing Tests**
```php
public function test_ueberblick_zeigt_info_mit_gesehen_fortschritt_und_automatik_abzeichen(): void
{
    // Info mit auto=true, 2 Empfaenger, einer first_viewed_at gesetzt → dokumente()[0]['fortschritt'] erledigt=1 gesamt=2, ['auto'] === true, ['category_label'] === 'Info / News'
}

public function test_zurueckziehen_einer_info_nimmt_den_automatik_haken(): void
{
    // auto=true → dokumentZurueckziehen(id) → auto_neue_mitarbeiter false; danach fuerErstesOeffnen(neue Anstellung) === 0
}
```

- [ ] **Step 2: Rot. Step 3: Umsetzen.** In `dokumente()`: `'auto' => (bool) $d->auto_neue_mitarbeiter, 'published' => $d->published_at?->format('d.m.Y'), 'is_info' => DokumentKategorie::istText((string) $d->category)`. Im Zurückziehen-Pfad des ganzen Dokuments (Service): `DB::table('rec_documents')->where('id', $id)->update(['auto_neue_mitarbeiter' => false, 'updated_at' => now()])`. `DokumentFortschritt::ausZaehlern()` bei `none`: `erledigt = gesehen` (prüfen und ggf. so setzen; bestehende Tests dazu beachten).

- [ ] **Step 4: Blade-Zeile: `@if ($dok['is_info'])` Icon-Text INFO; `@if ($dok['auto'])` Abzeichen „automatisch an Neue seit {{ $dok['published'] }}"; Fortschrittstext bei Infos „gesehen x von y".**

- [ ] **Step 5: Checkliste + Spec-Nachtrag.** Checkliste-Abschnitt:
```markdown
## 4.12 Paket Infos (`feat/portal-infos`, nach dem Merge von `feat/ma-konto`)
- [ ] Merge ff `feat/portal-infos` → main, Bump, Forge-Deploy MIT migrate: `2026_10_16_000001_add_info_fields_to_rec_documents` (3 neue Spalten, 5 Datei-Spalten nullable per change() — vorher `migrate --pretend` auf MySQL lesen). `view:clear`. Kein neuer Zeitplan, keine neue Einstellung.
- [ ] Sichttest: Dokumente → Umschalter „Info" → Text im Editor (fällt der Editor aus: Textfeld) → Vorschau → Haken „automatisch an neue Mitarbeiter" → Bereitstellen ohne Empfänger → neue Test-Anstellung umstellen → Portal öffnen: Kasten „Neu für dich" oben, Text mit Knöpfen → Schließen → neu anmelden: kein Kasten → Reiter „Dokumente & Infos": Info mit „Gesehen".
- [ ] Sichttest HR: Überblick zeigt „gesehen 1 von 1" und Abzeichen „automatisch an Neue seit …"; Zurückziehen nimmt den Haken.
- [ ] Willkommenstext (Sheran, Mail 14.10.) von HR im Editor einpflegen; Satz zu Zugangsdaten bleibt (ZAS-Portal).
```

- [ ] **Step 6: blade-check, Tests grün, Suite grün. Commit**
```bash
git add src/Livewire/Employees/Documents.php src/Services/DokumentService.php src/Support/DokumentFortschritt.php resources/views/livewire/employees/documents.blade.php docs/meingedeck/deploy-checkliste-ma-konto.md docs/superpowers/specs/2026-10-08-dokumente-gegenzeichnen-design.md tests/Integration/DokumentInfoUeberblickTest.php
git commit -m "feat(recruiting): Dokumente — Infos im HR-Ueberblick (gesehen x von y, Automatik-Abzeichen), Zurueckziehen nimmt den Haken; Checkliste Paket Infos"
```

---

## Self-Review (vom Planer ausgeführt)

- **Spec-Abdeckung:** §2 Datenmodell → Task 1; §3.1 Service → Task 2; §3.2 Formular/Editor/Vorschau → Task 5; §3.3 Zurückziehen nimmt Haken → Task 6; §4 Automatik → Task 3; §5.1 Kasten ohne Klick, Anzeigen stempelt → Task 4; §5.2 Reiter → Task 4; §5.3 Renderer → Task 1; §5.4 Rechte → Task 4 (Scope-Test); §6 HR-Überblick → Task 6; §7 keine neue Vorlage → bestehender `DokumentHinweisSender` (Task 2/5: `versand_noetig` = Empfänger > 0, nur Automatik ohne Job); §8 Deploy → Task 6 Checkliste; §9 Tests → verteilt, Mutationsproben beim Review.
- **Typen:** `bereitstellenText(int, array, string, array, ?int): array` in Task 2 und 5 gleich; `fuerErstesOeffnen(RecEmployee): int` in Task 3 und 6; `zeile()`-Schlüssel `is_info/content_html/gesehen` in Task 4 und 6.
- **Review Focus:** 1 → Task 1 Tests 3/4; 2 → Task 3 „vorher angelegt"; 3 → Task 3 „RG+MA"; 4 → Task 4 „fremde ID"; 5 → Task 2 „ohne Empfänger ohne Haken".
