# Anleitungen-Bereich Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Menüpunkt „Anleitungen“ im Recruiting zeigt je Bereich Anleitungen im Rheingedeck-Folien-Look (Web + Druck-PDF), gespeist aus je einer Markdown-Datei im Repo; Screenshots entstehen per Rezept gegen die Demo.

**Architecture:** Ein reiner Parser (`AnleitungParser`) macht aus einer Datei ein `Anleitung`-Objekt mit Folien; ein `AnleitungKatalog` liest den Ordner `docs/anleitungen/` und liefert Übersicht, Einzelanleitung, Anleitungen je Route und sichere Bildpfade. Livewire-Seiten (Übersicht, Anleitung) und eine eigenständige Druckseite rendern dieselbe Folien-Partial mit eigenem CSS (kein Tailwind für den Folien-Look). Ein Node/Playwright-Skript erzeugt Screenshots aus `<!-- shot: … -->`-Rezepten.

**Tech Stack:** Laravel 11, Livewire 3, Blade, `league/commonmark` 2.x (über Laravel vorhanden, `GithubFlavoredMarkdownConverter`), PHPUnit 11 (Runner `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml`), Node 24 + `playwright-core` mit installiertem Chrome.

**Spec:** `docs/superpowers/specs/2026-10-12-anleitungen-bereich-design.md`

## Global Constraints

- Quelle: eine Datei pro Anleitung `docs/anleitungen/<slug>.md`, Bilder `docs/anleitungen/bilder/<slug>/`. Slug nur `[a-z0-9-]`.
- Bereiche (feste Reihenfolge): `Bewerber`, `Schulungen`, `Mitarbeiter`, `Verträge`, `Dispo`.
- Folie beginnt mit `## Kicker | Titel`; ohne `|` ist alles Titel und Kicker = Bereich. Höchstens **ein** Bild je Folie.
- Rohes HTML wird nie ausgegeben (`html_input => 'strip'`, `allow_unsafe_links => false`); nur `<!-- shot: … -->` wird gelesen.
- Folien-Look: blauer Balken links `#1273DE`, Kicker blau, Großbuchstaben; Titel fett; Linie `#B8BCC4`; Fußzeile „RheinGedeck · Intern“ + zweistellige Seitenzahl. Styling über eigenes CSS mit `rg-`-Klassen in `_stil.blade.php` — **keine** Tailwind-Arbitrary-Klassen für den Folien-Look (der Tailwind-Build von meingedeck scannt sie nicht zuverlässig).
- Druck: A4 quer, jede Folie eine Seite, `print-color-adjust: exact`; PDF über `window.print()` auf einer eigenständigen Druckseite (ohne App-Layout).
- Bilder: nur `png|jpg|jpeg|webp`, nur aus `bilder/<slug>/`, kein `..`, keine Pfadtrenner; fehlendes Bild = grauer Platzhalter mit Alt-Text.
- Alle Nutzer sehen alle Anleitungen; `RecruitingRouteGate` öffnet `recruiting.anleitungen.index|show|druck|bild` für beide eingeschränkten Stufen.
- Bildregeln Skript: 1440×900, deviceScaleFactor 2, `colorScheme: 'light'`, Ausschnitt + 16 px Rand, Markierung `3px solid #1273DE` mit `outline-offset: 3px`, `handy` = 390×844. Sitzung unter `~/.config/rg-anleitungen/sitzung.json`, nie im Repo. Basis-URL `https://demo.bhgdigital.de` (überschreibbar per `RG_ANLEITUNGEN_BASIS`).
- Kundensprache: Du-/Ihr-Form, keine Technikbegriffe in Anleitungstexten.
- Deploy: kein `migrate`, kein `queue:restart`; `view:clear`.
- Keine Änderungen außerhalb von platforms-recruiting.

## Review Focus

- Datei mit Windows-Zeilenenden (`\r\n`) oder BOM → muss genauso geparst werden wie `\n` (Test in Task 1).
- Bildname mit Großbuchstaben/Unterstrich wie `A_liste_angehakt.png` → gültig, wird ausgeliefert (Test in Task 2).
- Bildpfad-Ausbruch über einen Symlink im `bilder/<slug>/`-Ordner → 404 (realpath-Prüfung, Test in Task 2).
- Eine fehlerhafte Datei darf die Übersicht nicht kippen: andere Anleitungen bleiben sichtbar, die kaputte erscheint mit Grund (Test in Task 2).
- Livewire-Neurender einer Arbeitsseite (Route `livewire.update`) darf das „?“ nicht verlieren → Route wird explizit übergeben, nie aus `request()` gelesen (Task 4).

---

### Task 1: Parser und Datenobjekte

**Files:**
- Create: `src/Support/Anleitungen/Anleitung.php`
- Create: `src/Support/Anleitungen/Folie.php`
- Create: `src/Support/Anleitungen/Bild.php`
- Create: `src/Support/Anleitungen/AnleitungParser.php`
- Test: `tests/Unit/Anleitungen/AnleitungParserTest.php`

**Interfaces:**
- Produces:
  - `final class Bild { public readonly string $datei; public readonly string $alt; /** @var array<string,string|true> */ public readonly array $rezept; }`
  - `final class Folie { int $nr; string $kicker; string $titel; ?string $einleitungHtml; ?string $schritteHtml; int $schritteAnzahl; ?Bild $bild; ?string $merksatzHtml; ?string $tabelleHtml; public function anker(): string /* "folie-<nr>" */ }`
  - `final class Anleitung { string $slug; string $titel; string $bereich; string $stand /* Y-m-d */; /** list<string> */ array $seiten; /** list<Folie> */ array $folien; ?string $fehler; public function standDeutsch(): string /* d.m.Y */; public static function kaputt(string $slug, string $grund): self }`
  - `AnleitungParser::BEREICHE = ['Bewerber','Schulungen','Mitarbeiter','Verträge','Dispo']`
  - `AnleitungParser::parse(string $slug, string $inhalt): Anleitung` (wirft nie; Fehler → `Anleitung::kaputt`)
  - `AnleitungParser::rezept(string $text): array<string,string|true>`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Anleitungen\AnleitungParser;

class AnleitungParserTest extends TestCase
{
    private const KOPF = "---\ntitel: Absage-Erkennung\nbereich: Dispo   # Kommentar\nstand: 2026-10-12\nseiten: [recruiting.dispo.settings, recruiting.dispo.events.show]\n---\n";

    public function test_kopf_wird_gelesen(): void
    {
        $a = AnleitungParser::parse('dispo-absage', self::KOPF . "\n## Dispo · Haken | Haken setzen\n\n1. Eins\n");

        $this->assertNull($a->fehler);
        $this->assertSame('dispo-absage', $a->slug);
        $this->assertSame('Absage-Erkennung', $a->titel);
        $this->assertSame('Dispo', $a->bereich);
        $this->assertSame('2026-10-12', $a->stand);
        $this->assertSame('12.10.2026', $a->standDeutsch());
        $this->assertSame(['recruiting.dispo.settings', 'recruiting.dispo.events.show'], $a->seiten);
    }

    public function test_folie_mit_allen_bausteinen(): void
    {
        $md = self::KOPF . <<<'MD'

## Dispo · Absage-Erkennung | Haken setzen

Einleitung mit **fett**.

![Einstellungen mit Haken](haken-pruefen.png)
<!-- shot: seite=/recruiting/dispo-settings
     ausschnitt="Filial-Konfiguration" markieren="prüfen" handy -->

1. Schritt **eins**.
2. Schritt zwei.

> Gilt nur heute.
MD;
        $f = AnleitungParser::parse('x', $md)->folien[0];

        $this->assertSame(1, $f->nr);
        $this->assertSame('folie-1', $f->anker());
        $this->assertSame('Dispo · Absage-Erkennung', $f->kicker);
        $this->assertSame('Haken setzen', $f->titel);
        $this->assertStringContainsString('<strong>fett</strong>', $f->einleitungHtml);
        $this->assertStringContainsString('<ol>', $f->schritteHtml);
        $this->assertStringContainsString('<strong>eins</strong>', $f->schritteHtml);
        $this->assertSame(2, $f->schritteAnzahl);
        $this->assertSame('haken-pruefen.png', $f->bild->datei);
        $this->assertSame('Einstellungen mit Haken', $f->bild->alt);
        $this->assertSame(
            ['seite' => '/recruiting/dispo-settings', 'ausschnitt' => 'Filial-Konfiguration', 'markieren' => 'prüfen', 'handy' => true],
            $f->bild->rezept
        );
        $this->assertStringContainsString('Gilt nur heute.', $f->merksatzHtml);
        $this->assertNull($f->tabelleHtml);
    }

    public function test_schritte_direkt_unter_dem_rezept_gehen_nicht_verloren(): void
    {
        $f = AnleitungParser::parse('x', self::KOPF . "\n## A\n\n![Bild](a.png)\n<!-- shot: seite=/x -->\n1. eins\n2. zwei\n")->folien[0];

        $this->assertSame(['seite' => '/x'], $f->bild->rezept);
        $this->assertSame(2, $f->schritteAnzahl);
    }

    public function test_ohne_trenner_ist_alles_titel_und_kicker_ist_der_bereich(): void
    {
        $f = AnleitungParser::parse('x', self::KOPF . "\n## Regeln auf einen Blick\n\n| Thema | So ist es |\n| --- | --- |\n| A | B |\n")->folien[0];

        $this->assertSame('Dispo', $f->kicker);
        $this->assertSame('Regeln auf einen Blick', $f->titel);
        $this->assertStringContainsString('<table>', $f->tabelleHtml);
        $this->assertNull($f->bild);
        $this->assertSame(0, $f->schritteAnzahl);
    }

    public function test_mehrere_folien_werden_durchnummeriert(): void
    {
        $a = AnleitungParser::parse('x', self::KOPF . "\n## A\n\n1. a\n\n## B\n\n- b\n- c\n");

        $this->assertSame([1, 2], array_map(fn ($f) => $f->nr, $a->folien));
        $this->assertSame(2, $a->folien[1]->schritteAnzahl);
        $this->assertStringContainsString('<ul>', $a->folien[1]->schritteHtml);
    }

    public function test_rohes_html_und_gefaehrliche_links_werden_nicht_ausgegeben(): void
    {
        $f = AnleitungParser::parse('x', self::KOPF . "\n## A\n\n<script>alert(1)</script> Text [klick](javascript:alert(1))\n\n1. <b>x</b> y\n")->folien[0];

        $this->assertStringNotContainsString('<script', $f->einleitungHtml);
        $this->assertStringNotContainsString('javascript:', $f->einleitungHtml);
        $this->assertStringNotContainsString('<b>', $f->schritteHtml);
    }

    public function test_windows_zeilenenden_und_bom(): void
    {
        $md = "\u{FEFF}" . str_replace("\n", "\r\n", self::KOPF . "\n## A | B\n\n1. a\n");
        $a = AnleitungParser::parse('x', $md);

        $this->assertNull($a->fehler);
        $this->assertSame('B', $a->folien[0]->titel);
    }

    /** @return array<string, array{string, string}> */
    public static function kaputt(): array
    {
        return [
            'kein Kopf' => ["## A\n\n1. a\n", 'Kopf fehlt'],
            'Titel fehlt' => ["---\nbereich: Dispo\nstand: 2026-10-12\n---\n## A\n\n1. a\n", 'titel'],
            'unbekannter Bereich' => ["---\ntitel: T\nbereich: Lager\nstand: 2026-10-12\n---\n## A\n\n1. a\n", 'Bereich'],
            'Datum kaputt' => ["---\ntitel: T\nbereich: Dispo\nstand: 12.10.2026\n---\n## A\n\n1. a\n", 'stand'],
            'keine Folie' => ["---\ntitel: T\nbereich: Dispo\nstand: 2026-10-12\n---\nnur Text\n", 'Folie'],
            'zwei Bilder' => ["---\ntitel: T\nbereich: Dispo\nstand: 2026-10-12\n---\n## A\n\n![a](a.png)\n\n![b](b.png)\n", 'mehr als ein Bild'],
            'Bildname mit Pfad' => ["---\ntitel: T\nbereich: Dispo\nstand: 2026-10-12\n---\n## A\n\n![a](../geheim.png)\n", 'Bildname'],
            'leere Folie' => ["---\ntitel: T\nbereich: Dispo\nstand: 2026-10-12\n---\n## A\n\n## B\n\n1. b\n", 'leer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kaputt')]
    public function test_fehlerhafte_dateien_werfen_nicht_sondern_melden_den_grund(string $md, string $grund): void
    {
        $a = AnleitungParser::parse('kaputt-1', $md);

        $this->assertNotNull($a->fehler);
        $this->assertStringContainsString($grund, $a->fehler);
        $this->assertSame('kaputt-1', $a->slug);
        $this->assertSame([], $a->folien);
    }

    public function test_rezept_flags_und_werte(): void
    {
        $this->assertSame(
            ['seite' => '/a', 'klicks' => 'Öffnen; Weiter', 'manuell' => true, 'leer' => ''],
            AnleitungParser::rezept(' seite=/a klicks="Öffnen; Weiter" manuell leer="" ')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen/AnleitungParserTest.php`
Expected: FAIL / Error „Class … AnleitungParser not found“.

- [ ] **Step 3: Write the data objects**

`src/Support/Anleitungen/Bild.php`:
```php
<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Ein Bild einer Folie: Dateiname im Ordner bilder/<slug>/, Alt-Text, Screenshot-Rezept. */
final class Bild
{
    /** @param array<string, string|true> $rezept */
    public function __construct(
        public readonly string $datei,
        public readonly string $alt,
        public readonly array $rezept,
    ) {}
}
```

`src/Support/Anleitungen/Folie.php`:
```php
<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Eine Folie einer Anleitung — die HTML-Felder sind bereits sicher gerendert. */
final class Folie
{
    public function __construct(
        public readonly int $nr,
        public readonly string $kicker,
        public readonly string $titel,
        public readonly ?string $einleitungHtml,
        public readonly ?string $schritteHtml,
        public readonly int $schritteAnzahl,
        public readonly ?Bild $bild,
        public readonly ?string $merksatzHtml,
        public readonly ?string $tabelleHtml,
    ) {}

    public function anker(): string
    {
        return 'folie-' . $this->nr;
    }
}
```

`src/Support/Anleitungen/Anleitung.php`:
```php
<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Eine Kundenanleitung aus docs/anleitungen/<slug>.md. $fehler != null = Datei unlesbar. */
final class Anleitung
{
    /**
     * @param list<string> $seiten Routennamen, auf denen das „?“ erscheint
     * @param list<Folie>  $folien
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $titel,
        public readonly string $bereich,
        public readonly string $stand,
        public readonly array $seiten,
        public readonly array $folien,
        public readonly ?string $fehler = null,
    ) {}

    public static function kaputt(string $slug, string $grund): self
    {
        return new self($slug, $slug, '', '', [], [], $grund);
    }

    public function standDeutsch(): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->stand);

        return $d ? $d->format('d.m.Y') : '';
    }
}
```

- [ ] **Step 4: Write the parser**

`src/Support/Anleitungen/AnleitungParser.php`:
```php
<?php

namespace Platform\Recruiting\Support\Anleitungen;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Datei -> Anleitung (Spec 2026-10-12, Entscheidung 2). Zeilenbasiert:
 * Kopf zwischen ---, Folien ab "## ", in der Folie Bloecke getrennt durch
 * Leerzeilen. Markdown der Bloecke rendert CommonMark mit gesperrtem HTML.
 * Wirft nie — Fehler werden als Anleitung::kaputt gemeldet.
 */
final class AnleitungParser
{
    public const BEREICHE = ['Bewerber', 'Schulungen', 'Mitarbeiter', 'Verträge', 'Dispo'];

    private const BILDNAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(png|jpe?g|webp)$/';

    private static ?GithubFlavoredMarkdownConverter $md = null;

    public static function parse(string $slug, string $inhalt): Anleitung
    {
        try {
            return self::lesen($slug, $inhalt);
        } catch (AnleitungFehler $e) {
            return Anleitung::kaputt($slug, $e->getMessage());
        }
    }

    /** @return array<string, string|true> */
    public static function rezept(string $text): array
    {
        preg_match_all('/([a-z]+)(?:=(?:"([^"]*)"|(\S+)))?/u', $text, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $out = [];
        foreach ($m as $t) {
            // "…" -> Gruppe 2, nackter Wert -> Gruppe 3, reiner Schalter -> true
            $out[$t[1]] = $t[2] ?? $t[3] ?? true;
        }

        return $out;
    }

    private static function lesen(string $slug, string $inhalt): Anleitung
    {
        $inhalt = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\x{FEFF}/u', '', $inhalt) ?? $inhalt);
        if (!preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $inhalt, $m)) {
            throw new AnleitungFehler('Kopf fehlt (--- … --- am Dateianfang)');
        }
        $kopf = self::kopf($m[1]);
        $folien = self::folien($m[2], $kopf['bereich']);

        return new Anleitung($slug, $kopf['titel'], $kopf['bereich'], $kopf['stand'], $kopf['seiten'], $folien);
    }

    /** @return array{titel:string, bereich:string, stand:string, seiten:list<string>} */
    private static function kopf(string $text): array
    {
        $werte = [];
        foreach (explode("\n", $text) as $zeile) {
            $zeile = trim(preg_replace('/\s+#.*$/u', '', $zeile) ?? $zeile);
            if ($zeile === '') {
                continue;
            }
            if (!preg_match('/^([a-z]+):\s*(.*)$/u', $zeile, $kv)) {
                throw new AnleitungFehler('Kopfzeile unlesbar: ' . $zeile);
            }
            $werte[$kv[1]] = trim($kv[2]);
        }

        $titel = $werte['titel'] ?? '';
        if ($titel === '') {
            throw new AnleitungFehler('Kopf: titel fehlt');
        }
        $bereich = $werte['bereich'] ?? '';
        if (!in_array($bereich, self::BEREICHE, true)) {
            throw new AnleitungFehler('Kopf: Bereich „' . $bereich . '“ unbekannt (erlaubt: ' . implode(', ', self::BEREICHE) . ')');
        }
        $stand = $werte['stand'] ?? '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $stand);
        if (!$d || $d->format('Y-m-d') !== $stand) {
            throw new AnleitungFehler('Kopf: stand muss JJJJ-MM-TT sein');
        }
        $seiten = [];
        $roh = $werte['seiten'] ?? '';
        if ($roh !== '') {
            $seiten = array_values(array_filter(array_map('trim', explode(',', trim($roh, '[] ')))));
        }

        return ['titel' => $titel, 'bereich' => $bereich, 'stand' => $stand, 'seiten' => $seiten];
    }

    /** @return list<Folie> */
    private static function folien(string $koerper, string $bereich): array
    {
        $teile = preg_split('/^## /m', "\n" . $koerper);
        $vorspann = trim((string) array_shift($teile));
        if ($vorspann !== '') {
            throw new AnleitungFehler('Text vor der ersten Folie (## …)');
        }
        if ($teile === []) {
            throw new AnleitungFehler('Keine Folie (## Kicker | Titel)');
        }

        $folien = [];
        foreach ($teile as $i => $teil) {
            $folien[] = self::folie($i + 1, $teil, $bereich);
        }

        return $folien;
    }

    private static function folie(int $nr, string $teil, string $bereich): Folie
    {
        [$kopfzeile, $rest] = array_pad(explode("\n", $teil, 2), 2, '');
        $kopfzeile = trim($kopfzeile);
        if (str_contains($kopfzeile, '|')) {
            [$kicker, $titel] = array_map('trim', explode('|', $kopfzeile, 2));
        } else {
            [$kicker, $titel] = [$bereich, $kopfzeile];
        }

        $einleitung = [];
        $schritte = [];
        $merksatz = [];
        $tabelle = [];
        $bild = null;

        $bloecke = preg_split('/\n\s*\n/', trim($rest)) ?: [];
        while ($bloecke !== []) {
            $block = trim((string) array_shift($bloecke));
            if ($block === '') {
                continue;
            }
            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)\s*(.*)$/s', $block, $b)) {
                if ($bild !== null) {
                    throw new AnleitungFehler("Folie {$nr}: mehr als ein Bild");
                }
                if (!preg_match(self::BILDNAME, $b[2])) {
                    throw new AnleitungFehler("Folie {$nr}: Bildname „{$b[2]}“ ungültig (nur Dateiname, png/jpg/webp)");
                }
                $weiter = $b[3];
                $rezept = [];
                if (preg_match('/^<!--\s*shot:(.*?)-->/s', $weiter, $r)) {
                    $rezept = self::rezept($r[1]);
                    $weiter = substr($weiter, strlen($r[0]));
                }
                $bild = new Bild($b[2], trim($b[1]), $rezept);
                // Steht ohne Leerzeile weiterer Text unter Bild/Rezept, wird er normal weiterverarbeitet.
                $weiter = trim($weiter);
                if ($weiter !== '') {
                    array_unshift($bloecke, $weiter);
                }
                continue;
            }
            $block = trim(preg_replace('/<!--.*?-->/s', '', $block) ?? $block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^>/', $block)) {
                $merksatz[] = preg_replace('/^>\s?/m', '', $block);
            } elseif (str_starts_with($block, '|')) {
                $tabelle[] = $block;
            } elseif (preg_match('/^(\d+\.|[-*])\s/', $block)) {
                $schritte[] = $block;
            } else {
                $einleitung[] = $block;
            }
        }

        $schritteMd = implode("\n", $schritte);
        $anzahl = preg_match_all('/^(\d+\.|[-*])\s/m', $schritteMd);

        if ($einleitung === [] && $schritte === [] && $merksatz === [] && $tabelle === [] && $bild === null) {
            throw new AnleitungFehler("Folie {$nr} ist leer");
        }

        return new Folie(
            $nr,
            $kicker,
            $titel,
            $einleitung === [] ? null : self::html(implode("\n\n", $einleitung)),
            $schritte === [] ? null : self::html($schritteMd),
            $anzahl,
            $bild,
            $merksatz === [] ? null : self::html(implode("\n\n", $merksatz)),
            $tabelle === [] ? null : self::html(implode("\n\n", $tabelle)),
        );
    }

    private static function html(string $markdown): string
    {
        self::$md ??= new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return trim(self::$md->convert($markdown)->getContent());
    }
}
```

Also create `src/Support/Anleitungen/AnleitungFehler.php`:
```php
<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Interner Abbruch beim Lesen einer Anleitung — wird in AnleitungParser::parse() aufgefangen. */
final class AnleitungFehler extends \RuntimeException {}
```

Note for the implementer: `PREG_UNMATCHED_AS_NULL` makes unmatched groups `null`, so `x=""` gives `''`, `x=/a` gives `'/a'`, a bare `manuell` gives `true`.

- [ ] **Step 5: Run test to verify it passes**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen/AnleitungParserTest.php`
Expected: PASS (all tests).

- [ ] **Step 6: Commit**

```bash
git add src/Support/Anleitungen tests/Unit/Anleitungen/AnleitungParserTest.php
git commit -m "feat(recruiting): Anleitungen — Parser fuer Folien-Markdown (Kopf, Folien, Bild-Rezept, sicheres HTML)"
```

---

### Task 2: Katalog (Ordner, Übersicht, Route-Zuordnung, sichere Bildpfade)

**Files:**
- Create: `src/Support/Anleitungen/AnleitungKatalog.php`
- Test: `tests/Unit/Anleitungen/AnleitungKatalogTest.php`

**Interfaces:**
- Consumes: `AnleitungParser::parse`, `AnleitungParser::BEREICHE`, `Anleitung`.
- Produces:
  - `new AnleitungKatalog(string $verzeichnis)`, `AnleitungKatalog::standard(): self` (Paket-Ordner `docs/anleitungen`)
  - `alle(): list<Anleitung>` (gültige zuerst nach Bereich-Reihenfolge, dann Titel; kaputte am Ende)
  - `nachBereich(): array<string, list<Anleitung>>` (nur gültige, Bereich => Anleitungen, in BEREICHE-Reihenfolge, leere Bereiche weggelassen)
  - `fehlerhaft(): list<Anleitung>`
  - `finde(string $slug): ?Anleitung`
  - `fuerRoute(string $routeName): list<Anleitung>` (nur gültige)
  - `bildPfad(string $slug, string $datei): ?string` (absoluter Pfad oder null)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

class AnleitungKatalogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anleitungen-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bilder/dispo-a', 0777, true);
        $this->datei('dispo-a', 'Dispo', 'Zeta', '[recruiting.dispo.settings]');
        $this->datei('schulung-b', 'Schulungen', 'Beta', '[recruiting.dispo.settings, recruiting.interview-schedule.index]');
        $this->datei('dispo-c', 'Dispo', 'Alpha', '');
        file_put_contents($this->dir . '/kaputt.md', "kein kopf\n");
        file_put_contents($this->dir . '/README.md', "# Readme\n");
        file_put_contents($this->dir . '/Falscher_Name.md', "---\ntitel: x\n---\n");
        file_put_contents($this->dir . '/bilder/dispo-a/A_liste.png', 'png');
        file_put_contents($this->dir . '/geheim.png', 'geheim');
        AnleitungKatalog::vergessen();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        AnleitungKatalog::vergessen();
    }

    private function datei(string $slug, string $bereich, string $titel, string $seiten): void
    {
        file_put_contents($this->dir . "/{$slug}.md", "---\ntitel: {$titel}\nbereich: {$bereich}\nstand: 2026-10-12\nseiten: {$seiten}\n---\n## K | T\n\n1. a\n");
    }

    public function test_readme_wird_ignoriert_und_kaputte_stehen_am_ende(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame(
            ['schulung-b', 'dispo-c', 'dispo-a', 'Falscher_Name', 'kaputt'],
            array_map(fn ($a) => $a->slug, $k->alle())
        );
    }

    public function test_nach_bereich_in_fester_reihenfolge_ohne_kaputte(): void
    {
        $g = (new AnleitungKatalog($this->dir))->nachBereich();

        $this->assertSame(['Schulungen', 'Dispo'], array_keys($g));
        $this->assertSame(['dispo-c', 'dispo-a'], array_map(fn ($a) => $a->slug, $g['Dispo']));
    }

    public function test_kaputte_kippen_nichts_und_nennen_den_grund(): void
    {
        $f = (new AnleitungKatalog($this->dir))->fehlerhaft();

        $this->assertSame(['Falscher_Name', 'kaputt'], array_map(fn ($a) => $a->slug, $f));
        $this->assertStringContainsString('Dateiname', $f[0]->fehler);
        $this->assertStringContainsString('Kopf fehlt', $f[1]->fehler);
    }

    public function test_finde_nur_mit_gueltigem_slug(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame('Zeta', $k->finde('dispo-a')?->titel);
        $this->assertNull($k->finde('gibts-nicht'));
        $this->assertNull($k->finde('../dispo-a'));
        $this->assertNull($k->finde('README'));
    }

    public function test_fuer_route(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame(['schulung-b', 'dispo-a'], array_map(fn ($a) => $a->slug, $k->fuerRoute('recruiting.dispo.settings')));
        $this->assertSame([], $k->fuerRoute('recruiting.dashboard'));
    }

    public function test_bildpfad_liefert_nur_dateien_aus_dem_eigenen_ordner(): void
    {
        $k = new AnleitungKatalog($this->dir);

        $this->assertSame(realpath($this->dir . '/bilder/dispo-a/A_liste.png'), $k->bildPfad('dispo-a', 'A_liste.png'));
        $this->assertNull($k->bildPfad('dispo-a', 'fehlt.png'));
        $this->assertNull($k->bildPfad('dispo-a', '../../geheim.png'));
        $this->assertNull($k->bildPfad('dispo-a', '..%2F..%2Fgeheim.png'));
        $this->assertNull($k->bildPfad('dispo-a', 'A_liste.php'));
        $this->assertNull($k->bildPfad('../bilder', 'A_liste.png'));
        $this->assertNull($k->bildPfad('schulung-b', 'A_liste.png'));
    }

    public function test_bildpfad_folgt_keinem_symlink_nach_draussen(): void
    {
        symlink($this->dir . '/geheim.png', $this->dir . '/bilder/dispo-a/link.png');

        $this->assertNull((new AnleitungKatalog($this->dir))->bildPfad('dispo-a', 'link.png'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen/AnleitungKatalogTest.php`
Expected: Error „Class … AnleitungKatalog not found“.

- [ ] **Step 3: Implement**

`src/Support/Anleitungen/AnleitungKatalog.php`:
```php
<?php

namespace Platform\Recruiting\Support\Anleitungen;

/**
 * Alle Anleitungen aus docs/anleitungen/ (Spec 2026-10-12). Liest den Ordner
 * je Prozess einmal (statischer Zwischenspeicher je Verzeichnis). README.md
 * ist Doku fuer uns, keine Anleitung. Eine kaputte Datei kippt nichts: sie
 * steht mit Grund in fehlerhaft(), alle anderen bleiben sichtbar.
 */
final class AnleitungKatalog
{
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const BILDNAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(png|jpe?g|webp)$/';

    /** @var array<string, list<Anleitung>> */
    private static array $cache = [];

    public function __construct(private readonly string $verzeichnis) {}

    public static function standard(): self
    {
        return new self(dirname(__DIR__, 3) . '/docs/anleitungen');
    }

    public static function vergessen(): void
    {
        self::$cache = [];
    }

    /** @return list<Anleitung> */
    public function alle(): array
    {
        return self::$cache[$this->verzeichnis] ??= $this->lesen();
    }

    /** @return array<string, list<Anleitung>> */
    public function nachBereich(): array
    {
        $out = [];
        foreach (AnleitungParser::BEREICHE as $bereich) {
            $liste = array_values(array_filter($this->alle(), fn (Anleitung $a) => $a->fehler === null && $a->bereich === $bereich));
            if ($liste !== []) {
                $out[$bereich] = $liste;
            }
        }

        return $out;
    }

    /** @return list<Anleitung> */
    public function fehlerhaft(): array
    {
        return array_values(array_filter($this->alle(), fn (Anleitung $a) => $a->fehler !== null));
    }

    public function finde(string $slug): ?Anleitung
    {
        if (!preg_match(self::SLUG, $slug)) {
            return null;
        }
        foreach ($this->alle() as $a) {
            if ($a->slug === $slug) {
                return $a;
            }
        }

        return null;
    }

    /** @return list<Anleitung> */
    public function fuerRoute(string $routeName): array
    {
        return array_values(array_filter(
            $this->alle(),
            fn (Anleitung $a) => $a->fehler === null && in_array($routeName, $a->seiten, true)
        ));
    }

    public function bildPfad(string $slug, string $datei): ?string
    {
        if (!preg_match(self::SLUG, $slug) || !preg_match(self::BILDNAME, $datei) || str_contains($datei, '..')) {
            return null;
        }
        $ordner = realpath($this->verzeichnis . '/bilder/' . $slug);
        $pfad = realpath($this->verzeichnis . '/bilder/' . $slug . '/' . $datei);
        if ($ordner === false || $pfad === false || !is_file($pfad)) {
            return null;
        }

        return str_starts_with($pfad, $ordner . DIRECTORY_SEPARATOR) ? $pfad : null;
    }

    /** @return list<Anleitung> */
    private function lesen(): array
    {
        $gueltig = [];
        $kaputt = [];
        foreach (glob($this->verzeichnis . '/*.md') ?: [] as $datei) {
            $slug = basename($datei, '.md');
            if ($slug === 'README') {
                continue;
            }
            $a = preg_match(self::SLUG, $slug)
                ? AnleitungParser::parse($slug, (string) file_get_contents($datei))
                : Anleitung::kaputt($slug, 'Dateiname nur aus Kleinbuchstaben, Ziffern und Bindestrichen');
            if ($a->fehler === null) {
                $gueltig[] = $a;
            } else {
                $kaputt[] = $a;
            }
        }

        $rang = array_flip(AnleitungParser::BEREICHE);
        usort($gueltig, fn (Anleitung $x, Anleitung $y) => [$rang[$x->bereich], $x->titel] <=> [$rang[$y->bereich], $y->titel]);
        usort($kaputt, fn (Anleitung $x, Anleitung $y) => strcmp($x->slug, $y->slug));

        return array_merge($gueltig, $kaputt);
    }
}
```

Note: the symlink test passes because `realpath` resolves the link to `/…/geheim.png`, which is outside the slug folder.

- [ ] **Step 4: Run tests to verify they pass**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/Anleitungen/AnleitungKatalog.php tests/Unit/Anleitungen/AnleitungKatalogTest.php
git commit -m "feat(recruiting): Anleitungen — Katalog (Uebersicht, Route-Zuordnung, sichere Bildpfade)"
```

---

### Task 3: Seiten, Druckseite, Bild-Auslieferung, Zugang, Menü

**Files:**
- Create: `src/Livewire/Anleitungen/Index.php`, `src/Livewire/Anleitungen/Show.php`
- Create: `src/Http/Controllers/AnleitungBildController.php`, `src/Http/Controllers/AnleitungDruckController.php`
- Create: `resources/views/livewire/anleitungen/index.blade.php`, `resources/views/livewire/anleitungen/show.blade.php`
- Create: `resources/views/anleitungen/_stil.blade.php`, `resources/views/anleitungen/_folie.blade.php`, `resources/views/anleitungen/druck.blade.php`
- Modify: `routes/web.php` (am Ende anfügen)
- Modify: `src/Support/RecruitingRouteGate.php` (neue Konstante + Erlaubnis für beide Stufen)
- Modify: `resources/views/livewire/sidebar.blade.php` (Menüpunkt in beiden Zweigen)
- Test: `tests/Unit/RecruitingRouteGateTest.php` (zwei Tests anfügen)

**Interfaces:**
- Consumes: `AnleitungKatalog::standard()`, `->nachBereich()`, `->fehlerhaft()`, `->finde()`, `->bildPfad()`; `Folie`-Felder; `Anleitung::standDeutsch()`.
- Produces: Routen `recruiting.anleitungen.index` (`/anleitungen`), `recruiting.anleitungen.show` (`/anleitungen/{slug}`), `recruiting.anleitungen.druck` (`/anleitungen/{slug}/druck`), `recruiting.anleitungen.bild` (`/anleitungen/{slug}/bild/{datei}`); Partial `recruiting::anleitungen._folie` mit Variablen `$anleitung` (Anleitung), `$folie` (Folie).

- [ ] **Step 1: Write the failing gate tests** (append to `tests/Unit/RecruitingRouteGateTest.php` inside the class)

```php
    public function test_anleitungen_sind_fuer_beide_eingeschraenkten_stufen_offen(): void
    {
        foreach (['recruiting.anleitungen.index', 'recruiting.anleitungen.show', 'recruiting.anleitungen.druck', 'recruiting.anleitungen.bild'] as $route) {
            $this->assertNull(RecruitingRouteGate::redirectTo(eventOnly: true, trainingLeader: false, routeName: $route), $route);
            $this->assertNull(RecruitingRouteGate::redirectTo(eventOnly: false, trainingLeader: true, routeName: $route), $route);
        }
    }

    public function test_anleitungen_oeffnen_sonst_nichts(): void
    {
        $this->assertSame(
            'recruiting.dispo.events.index',
            RecruitingRouteGate::redirectTo(eventOnly: true, trainingLeader: false, routeName: 'recruiting.anleitungen-admin')
        );
        $this->assertSame(
            'recruiting.training-review.index',
            RecruitingRouteGate::redirectTo(eventOnly: false, trainingLeader: true, routeName: 'recruiting.dispo.settings')
        );
    }
```

- [ ] **Step 2: Run to verify the first fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/RecruitingRouteGateTest.php`
Expected: `test_anleitungen_sind_fuer_beide_eingeschraenkten_stufen_offen` FAILS (redirect returned).

- [ ] **Step 3: Open the gate** — in `src/Support/RecruitingRouteGate.php` add below `TRAINING_ROUTES`:

```php
    /**
     * Anleitungen (12.10.2026): lesen duerfen alle, auch eingeschraenkte
     * Konten. Reine Leseseiten ohne Aktionen und ohne Personendaten.
     */
    private const ANLEITUNG_ROUTES = [
        'recruiting.anleitungen.index',
        'recruiting.anleitungen.show',
        'recruiting.anleitungen.druck',
        'recruiting.anleitungen.bild',
    ];
```

and change the `$erlaubt` assembly to:

```php
        $erlaubt = array_merge(
            $eventOnly ? self::EVENT_ROUTES : [],
            $trainingLeader ? self::TRAINING_ROUTES : [],
            self::ANLEITUNG_ROUTES,
        );
```

Run the gate test again → PASS.

- [ ] **Step 4: Routes** — append to `routes/web.php`:

```php
// Anleitungen (Spec 2026-10-12): Quelle docs/anleitungen/<slug>.md, fuer alle lesbar.
Route::get('/anleitungen', \Platform\Recruiting\Livewire\Anleitungen\Index::class)
    ->name('recruiting.anleitungen.index');
Route::get('/anleitungen/{slug}', \Platform\Recruiting\Livewire\Anleitungen\Show::class)
    ->name('recruiting.anleitungen.show')
    ->where('slug', '[a-z0-9-]+');
Route::get('/anleitungen/{slug}/druck', \Platform\Recruiting\Http\Controllers\AnleitungDruckController::class)
    ->name('recruiting.anleitungen.druck')
    ->where('slug', '[a-z0-9-]+');
Route::get('/anleitungen/{slug}/bild/{datei}', \Platform\Recruiting\Http\Controllers\AnleitungBildController::class)
    ->name('recruiting.anleitungen.bild')
    ->where('slug', '[a-z0-9-]+')
    ->where('datei', '[A-Za-z0-9][A-Za-z0-9._-]*');
```

- [ ] **Step 5: Controllers**

`src/Http/Controllers/AnleitungBildController.php`:
```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Liefert ein Anleitungs-Bild aus docs/anleitungen/bilder/<slug>/ — alles andere 404. */
class AnleitungBildController
{
    public function __invoke(string $slug, string $datei)
    {
        $pfad = AnleitungKatalog::standard()->bildPfad($slug, $datei);
        abort_if($pfad === null, 404);

        return response()->file($pfad, ['Cache-Control' => 'private, max-age=3600']);
    }
}
```

`src/Http/Controllers/AnleitungDruckController.php`:
```php
<?php

namespace Platform\Recruiting\Http\Controllers;

use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Eigenstaendige Druckseite (ohne App-Layout): jede Folie eine A4-Querseite, oeffnet den Druckdialog. */
class AnleitungDruckController
{
    public function __invoke(string $slug)
    {
        $anleitung = AnleitungKatalog::standard()->finde($slug);
        abort_if($anleitung === null || $anleitung->fehler !== null, 404);

        return view('recruiting::anleitungen.druck', ['anleitung' => $anleitung]);
    }
}
```

- [ ] **Step 6: Livewire components**

`src/Livewire/Anleitungen/Index.php`:
```php
<?php

namespace Platform\Recruiting\Livewire\Anleitungen;

use Livewire\Component;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Uebersicht aller Anleitungen, gruppiert nach Bereich (Spec 2026-10-12). */
class Index extends Component
{
    public function render()
    {
        $katalog = AnleitungKatalog::standard();

        return view('recruiting::livewire.anleitungen.index', [
            'gruppen'    => $katalog->nachBereich(),
            'fehlerhaft' => $katalog->fehlerhaft(),
        ])->layout('platform::layouts.app');
    }
}
```

`src/Livewire/Anleitungen/Show.php`:
```php
<?php

namespace Platform\Recruiting\Livewire\Anleitungen;

use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Eine Anleitung als Folien-Karten mit Inhaltsverzeichnis (Spec 2026-10-12). */
class Show extends Component
{
    #[Locked]
    public string $slug = '';

    public function mount(string $slug): void
    {
        abort_if(AnleitungKatalog::standard()->finde($slug) === null, 404);
        $this->slug = $slug;
    }

    public function render()
    {
        return view('recruiting::livewire.anleitungen.show', [
            'anleitung' => AnleitungKatalog::standard()->finde($this->slug),
        ])->layout('platform::layouts.app');
    }
}
```

- [ ] **Step 7: Shared style** — `resources/views/anleitungen/_stil.blade.php`:

```blade
{{-- Folien-Look der Kundenanleitungen (RheinGedeck-HCM-Folien). Eigenes CSS statt
     Tailwind-Arbitrary-Klassen: der Tailwind-Build scannt diese Views nicht sicher. --}}
<style>
    .rg-folie { position: relative; overflow: hidden; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
        padding: 22px 26px 44px 38px; box-shadow: 0 1px 2px rgba(0,0,0,.05); color: #111111;
        font-family: Calibri, "Segoe UI", Arial, sans-serif; scroll-margin-top: 16px; }
    .rg-folie__balken { position: absolute; inset: 0 auto 0 0; width: 8px; background: #1273DE; }
    .rg-folie__kicker { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #1273DE; }
    .rg-folie__titel { margin-top: 4px; font-size: 26px; line-height: 1.2; font-weight: 700; text-wrap: balance; }
    .rg-folie__linie { height: 1px; background: #B8BCC4; margin: 12px 0 18px; }
    .rg-folie__einleitung { color: #5F6368; font-size: 15px; margin-bottom: 14px; }
    .rg-folie__zweispaltig { display: grid; gap: 24px; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); align-items: start; }
    .rg-folie__schritte { font-size: 16px; line-height: 1.5; }
    .rg-folie__schritte ol { list-style: decimal; padding-left: 1.4em; }
    .rg-folie__schritte ul { list-style: disc; padding-left: 1.4em; }
    .rg-folie__schritte li { margin-bottom: 8px; }
    .rg-folie__schritte--breit ol, .rg-folie__schritte--breit ul { columns: 2; column-gap: 32px; }
    .rg-folie__schritte--breit li { break-inside: avoid; }
    .rg-folie__merksatz { margin-top: 14px; font-size: 15px; font-weight: 700; color: #1273DE; }
    .rg-folie__bild img { width: 100%; height: auto; border: 1px solid #e5e7eb; border-radius: 4px; }
    .rg-folie__platzhalter { aspect-ratio: 16 / 10; display: flex; align-items: center; justify-content: center; text-align: center;
        background: #F1F3F5; border: 1px dashed #B8BCC4; border-radius: 4px; color: #5F6368; font-size: 14px; padding: 16px; }
    .rg-folie__tabelle { overflow-x: auto; margin-top: 4px; }
    .rg-folie__tabelle table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .rg-folie__tabelle th { text-align: left; color: #5F6368; font-weight: 600; padding: 6px 10px; border-bottom: 1px solid #B8BCC4; }
    .rg-folie__tabelle td { padding: 8px 10px; vertical-align: top; }
    .rg-folie__tabelle tr:nth-child(even) td { background: #F1F3F5; }
    .rg-folie__tabelle td:first-child { font-weight: 700; color: #1273DE; width: 28%; }
    .rg-folie__fuss { position: absolute; left: 38px; right: 26px; bottom: 14px; display: flex; justify-content: space-between;
        font-size: 12px; color: #5F6368; font-variant-numeric: tabular-nums; }
    .rg-folie strong { font-weight: 700; }
    @media (max-width: 1023px) {
        .rg-folie__zweispaltig { grid-template-columns: minmax(0, 1fr); }
        .rg-folie__schritte--breit ol, .rg-folie__schritte--breit ul { columns: 1; }
        .rg-folie { padding: 18px 16px 44px 26px; }
        .rg-folie__fuss { left: 26px; right: 16px; }
    }
</style>
```

- [ ] **Step 8: Slide partial** — `resources/views/anleitungen/_folie.blade.php`:

```blade
{{-- Eine Folie. Erwartet $anleitung (Anleitung) und $folie (Folie). HTML-Felder sind im Parser sicher gerendert. --}}
<section id="{{ $folie->anker() }}" class="rg-folie">
    <div class="rg-folie__balken"></div>
    <div class="rg-folie__kicker">{{ $folie->kicker }}</div>
    <h2 class="rg-folie__titel">{{ $folie->titel }}</h2>
    <div class="rg-folie__linie"></div>

    @if ($folie->einleitungHtml)
        <div class="rg-folie__einleitung">{!! $folie->einleitungHtml !!}</div>
    @endif

    @if ($folie->bild)
        <div class="rg-folie__zweispaltig">
            <div>
                @if ($folie->schritteHtml)
                    <div class="rg-folie__schritte">{!! $folie->schritteHtml !!}</div>
                @endif
                @if ($folie->merksatzHtml)
                    <div class="rg-folie__merksatz">{!! $folie->merksatzHtml !!}</div>
                @endif
            </div>
            <div class="rg-folie__bild">
                @if (\Platform\Recruiting\Support\Anleitungen\AnleitungKatalog::standard()->bildPfad($anleitung->slug, $folie->bild->datei) !== null)
                    <img src="{{ route('recruiting.anleitungen.bild', ['slug' => $anleitung->slug, 'datei' => $folie->bild->datei]) }}" alt="{{ $folie->bild->alt }}">
                @else
                    <div class="rg-folie__platzhalter">{{ $folie->bild->alt }} — Screenshot folgt</div>
                @endif
            </div>
        </div>
    @else
        @if ($folie->schritteHtml)
            <div class="rg-folie__schritte {{ $folie->schritteAnzahl >= 4 ? 'rg-folie__schritte--breit' : '' }}">{!! $folie->schritteHtml !!}</div>
        @endif
        @if ($folie->merksatzHtml)
            <div class="rg-folie__merksatz">{!! $folie->merksatzHtml !!}</div>
        @endif
    @endif

    @if ($folie->tabelleHtml)
        <div class="rg-folie__tabelle">{!! $folie->tabelleHtml !!}</div>
    @endif

    <div class="rg-folie__fuss">
        <span>RheinGedeck · Intern</span>
        <span>{{ sprintf('%02d', $folie->nr) }}</span>
    </div>
</section>
```

- [ ] **Step 9: Index view** — `resources/views/livewire/anleitungen/index.blade.php`:

```blade
<div class="p-6 max-w-5xl space-y-8">
    <div>
        <h1 class="text-xl font-semibold">Anleitungen</h1>
        <p class="text-sm text-gray-500">Schritt für Schritt erklärt — immer auf dem aktuellen Stand. Jede Anleitung lässt sich als PDF speichern.</p>
    </div>

    @forelse ($gruppen as $bereich => $anleitungen)
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ $bereich }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($anleitungen as $a)
                    <a href="{{ route('recruiting.anleitungen.show', ['slug' => $a->slug]) }}" wire:navigate
                       class="block rounded-lg border border-gray-200 bg-white p-4 hover:border-blue-400 hover:shadow-sm">
                        <div class="font-semibold text-gray-900">{{ $a->titel }}</div>
                        <div class="mt-1 text-xs text-gray-500">Stand {{ $a->standDeutsch() }} · {{ count($a->folien) }} {{ count($a->folien) === 1 ? 'Folie' : 'Folien' }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <p class="text-sm text-gray-500">Noch keine Anleitungen.</p>
    @endforelse

    @if ($fehlerhaft !== [])
        <section class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <div class="font-semibold">Diese Anleitungen konnten nicht gelesen werden</div>
            <ul class="mt-2 list-disc pl-5">
                @foreach ($fehlerhaft as $a)
                    <li><code>{{ $a->slug }}</code>: {{ $a->fehler }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
```

- [ ] **Step 10: Show view** — `resources/views/livewire/anleitungen/show.blade.php`:

```blade
<div class="p-6">
    @include('recruiting::anleitungen._stil')

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('recruiting.anleitungen.index') }}" wire:navigate class="text-sm text-blue-600 hover:underline">← Alle Anleitungen</a>
            <h1 class="mt-1 text-xl font-semibold">{{ $anleitung->titel }}</h1>
            @if ($anleitung->fehler === null)
                <p class="text-sm text-gray-500">{{ $anleitung->bereich }} · Stand {{ $anleitung->standDeutsch() }}</p>
            @endif
        </div>
        @if ($anleitung->fehler === null)
            <a href="{{ route('recruiting.anleitungen.druck', ['slug' => $anleitung->slug]) }}" target="_blank" rel="noopener"
               class="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700">Als PDF speichern</a>
        @endif
    </div>

    @if ($anleitung->fehler !== null)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">Diese Anleitung konnte nicht gelesen werden: {{ $anleitung->fehler }}</div>
    @else
        <div class="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
            <nav class="lg:sticky lg:top-4 lg:self-start" x-data="{ aktiv: '{{ $anleitung->folien[0]->anker() }}' }"
                 x-init="const o = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) aktiv = e.target.id }), { rootMargin: '-20% 0px -70% 0px' });
                         document.querySelectorAll('.rg-folie').forEach(el => o.observe(el))">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Inhalt</div>
                <ol class="space-y-1 text-sm">
                    @foreach ($anleitung->folien as $folie)
                        <li>
                            <a href="#{{ $folie->anker() }}" class="block rounded px-2 py-1 hover:bg-gray-100"
                               :class="aktiv === '{{ $folie->anker() }}' ? 'bg-blue-50 font-semibold text-blue-700' : 'text-gray-700'">
                                {{ $folie->nr }}. {{ $folie->titel }}
                            </a>
                        </li>
                    @endforeach
                </ol>
            </nav>
            <div class="min-w-0 space-y-6">
                @foreach ($anleitung->folien as $folie)
                    @include('recruiting::anleitungen._folie', ['anleitung' => $anleitung, 'folie' => $folie])
                @endforeach
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 11: Print page** — `resources/views/anleitungen/druck.blade.php`:

```blade
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $anleitung->titel }} — Anleitung</title>
    @include('recruiting::anleitungen._stil')
    <style>
        @page { size: A4 landscape; margin: 0; }
        html, body { margin: 0; background: #e5e7eb; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .rg-druck { display: flex; flex-direction: column; align-items: center; gap: 16px; padding: 16px; }
        .rg-druck .rg-folie { width: 297mm; height: 210mm; box-sizing: border-box; padding: 14mm 14mm 18mm 20mm; border-radius: 0; }
        .rg-druck .rg-folie__balken { width: 4.3mm; }
        .rg-druck .rg-folie__titel { font-size: 28pt; }
        .rg-druck .rg-folie__schritte { font-size: 15pt; }
        .rg-druck .rg-folie__fuss { left: 20mm; right: 14mm; bottom: 8mm; font-size: 10.5pt; }
        .rg-druck .rg-folie__zweispaltig { grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); }
        .rg-druck .rg-folie__bild img { max-height: 140mm; width: auto; max-width: 100%; }
        @media print {
            html, body { background: #fff; }
            .rg-druck { display: block; padding: 0; }
            .rg-druck .rg-folie { border: none; box-shadow: none; break-after: page; page-break-after: always; }
            .rg-druck .rg-folie:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="rg-druck">
        @foreach ($anleitung->folien as $folie)
            @include('recruiting::anleitungen._folie', ['anleitung' => $anleitung, 'folie' => $folie])
        @endforeach
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
```

- [ ] **Step 12: Sidebar** — in `resources/views/livewire/sidebar.blade.php`:

(a) Restricted branch: directly before the line `    @else` that follows the `@if ($this->trainingLeader) … @endif` block, insert:
```blade
        <x-ui-sidebar-list label="Hilfe">
            <x-ui-sidebar-item :href="route('recruiting.anleitungen.index')">
                @svg('heroicon-o-book-open', 'w-4 h-4 text-[var(--ui-secondary)]')
                <span class="ml-2 text-sm">Anleitungen</span>
            </x-ui-sidebar-item>
        </x-ui-sidebar-list>
```
(b) Normal branch: directly before `    {{-- Collapsed: Icons-only --}}` insert:
```blade
    {{-- Abschnitt: Hilfe (Anleitungen, Spec 2026-10-12) --}}
    <x-ui-sidebar-list label="Hilfe">
        <x-ui-sidebar-item :href="route('recruiting.anleitungen.index')">
            @svg('heroicon-o-book-open', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Anleitungen</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>
```

- [ ] **Step 13: Checks**

Run:
```
php tools/blade-check.php resources/views/livewire/anleitungen/index.blade.php resources/views/livewire/anleitungen/show.blade.php resources/views/anleitungen/_stil.blade.php resources/views/anleitungen/_folie.blade.php resources/views/anleitungen/druck.blade.php resources/views/livewire/sidebar.blade.php
php -l src/Livewire/Anleitungen/Index.php && php -l src/Livewire/Anleitungen/Show.php && php -l src/Http/Controllers/AnleitungBildController.php && php -l src/Http/Controllers/AnleitungDruckController.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
```
Expected: blade-check „0 mit Funden“, no syntax errors, suite green.

- [ ] **Step 14: Commit**

```bash
git add routes/web.php src/Support/RecruitingRouteGate.php src/Livewire/Anleitungen src/Http/Controllers/AnleitungBildController.php src/Http/Controllers/AnleitungDruckController.php resources/views/anleitungen resources/views/livewire/anleitungen resources/views/livewire/sidebar.blade.php tests/Unit/RecruitingRouteGateTest.php
git commit -m "feat(recruiting): Anleitungen — Uebersicht, Folien-Seite, Druck-PDF, Bild-Auslieferung, Menuepunkt fuer alle"
```

---

### Task 4: „?“ auf den Arbeitsseiten

**Files:**
- Create: `resources/views/anleitungen/_hilfe.blade.php`
- Modify: `resources/views/livewire/dispo/settings.blade.php` (Kopf)
- Modify: `resources/views/livewire/dispo/events/show.blade.php` (Kopf neben `<h1>`)
- Modify: `resources/views/livewire/interview-schedule/index.blade.php` (Actionbar)
- Test: `tests/Unit/Anleitungen/HilfeEinbauTest.php`

**Interfaces:**
- Consumes: `AnleitungKatalog::standard()->fuerRoute(string): list<Anleitung>`, route `recruiting.anleitungen.show`.
- Produces: Partial `recruiting::anleitungen._hilfe` mit Pflicht-Variable `$route` (Routenname als String — NIE aus `request()` lesen, weil Livewire-Neurender über `livewire.update` laufen).

- [ ] **Step 1: Write the failing test** — `tests/Unit/Anleitungen/HilfeEinbauTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;

/**
 * Das „?“ muss den Routennamen fest mitbekommen: Livewire-Neurender laufen
 * ueber die Route livewire.update — aus request() gelesen waere das „?“ nach
 * dem ersten Klick auf der Seite weg.
 */
class HilfeEinbauTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function einbau(): array
    {
        return [
            'Dispo-Einstellungen' => ['resources/views/livewire/dispo/settings.blade.php', 'recruiting.dispo.settings'],
            'VA-Seite' => ['resources/views/livewire/dispo/events/show.blade.php', 'recruiting.dispo.events.show'],
            'Schulungstermine' => ['resources/views/livewire/interview-schedule/index.blade.php', 'recruiting.interview-schedule.index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('einbau')]
    public function test_seite_bindet_das_fragezeichen_mit_festem_routennamen_ein(string $view, string $route): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $view);

        $this->assertStringContainsString("@include('recruiting::anleitungen._hilfe', ['route' => '{$route}'])", $quelle);
    }

    public function test_partial_liest_die_route_nicht_aus_dem_request(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/anleitungen/_hilfe.blade.php');

        $this->assertStringNotContainsString('request()', $quelle);
        $this->assertStringNotContainsString('Route::current', $quelle);
        $this->assertStringContainsString('fuerRoute($route)', $quelle);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen/HilfeEinbauTest.php`
Expected: FAIL (include not found / file missing).

- [ ] **Step 3: Partial** — `resources/views/anleitungen/_hilfe.blade.php`:

```blade
{{-- „?“ zur passenden Anleitung. Erwartet $route (Routenname der Seite, fest uebergeben). Oeffnet in neuem Tab, damit die Arbeit offen bleibt. --}}
@php
    $hilfeAnleitungen = \Platform\Recruiting\Support\Anleitungen\AnleitungKatalog::standard()->fuerRoute($route);
@endphp
@if (count($hilfeAnleitungen) === 1)
    <a href="{{ route('recruiting.anleitungen.show', ['slug' => $hilfeAnleitungen[0]->slug]) }}" target="_blank" rel="noopener"
       class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-sm font-semibold text-gray-500 hover:bg-gray-50 hover:text-blue-600"
       title="Anleitung: {{ $hilfeAnleitungen[0]->titel }}">?</a>
@elseif (count($hilfeAnleitungen) > 1)
    <div x-data="{ offen: false }" class="relative inline-block" @click.outside="offen = false">
        <button type="button" @click="offen = !offen"
                class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-gray-300 bg-white text-sm font-semibold text-gray-500 hover:bg-gray-50 hover:text-blue-600"
                title="Anleitungen zu dieser Seite">?</button>
        <div x-show="offen" x-cloak class="absolute right-0 z-20 mt-1 w-64 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg">
            @foreach ($hilfeAnleitungen as $hilfeAnleitung)
                <a href="{{ route('recruiting.anleitungen.show', ['slug' => $hilfeAnleitung->slug]) }}" target="_blank" rel="noopener"
                   class="block px-3 py-2 text-gray-700 hover:bg-gray-50">{{ $hilfeAnleitung->titel }}</a>
            @endforeach
        </div>
    </div>
@endif
```

- [ ] **Step 4: Einbau**

(a) `resources/views/livewire/dispo/settings.blade.php` — replace the header block
```blade
    <div>
        <h1 class="text-xl font-semibold">Dispo-Einstellungen</h1>
```
with
```blade
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Dispo-Einstellungen</h1>
            @include('recruiting::anleitungen._hilfe', ['route' => 'recruiting.dispo.settings'])
        </div>
```
(the following `<p>` and closing `</div>` stay unchanged).

(b) `resources/views/livewire/dispo/events/show.blade.php` — in the title row `<div class="flex flex-wrap items-center justify-between gap-2">`, the first child `<div>` holds `<h1 …>`. Directly after the closing `</h1>` of the event title insert nothing inside the h1; instead wrap: change the line `            <h1 class="text-xl font-semibold">` to `            <div class="flex items-center gap-2"><h1 class="text-xl font-semibold">` and the matching `            </h1>` (the one closing the event name h1) to
```blade
            </h1>
            @include('recruiting::anleitungen._hilfe', ['route' => 'recruiting.dispo.events.show'])
            </div>
```

(c) `resources/views/livewire/interview-schedule/index.blade.php` — inside `<x-ui-page-actionbar …>` directly before `<x-ui-button variant="primary" size="sm" wire:click="openCreateModal">` insert:
```blade
            @include('recruiting::anleitungen._hilfe', ['route' => 'recruiting.interview-schedule.index'])
```

- [ ] **Step 5: Checks**

Run:
```
php tools/blade-check.php resources/views/anleitungen/_hilfe.blade.php resources/views/livewire/dispo/settings.blade.php resources/views/livewire/dispo/events/show.blade.php resources/views/livewire/interview-schedule/index.blade.php
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen
```
Expected: no findings, PASS. Also run `tests/Unit/SharedPartialContractTest.php` — it must stay green (the new partial only uses its passed `$route`).

- [ ] **Step 6: Commit**

```bash
git add resources/views/anleitungen/_hilfe.blade.php resources/views/livewire/dispo/settings.blade.php resources/views/livewire/dispo/events/show.blade.php resources/views/livewire/interview-schedule/index.blade.php tests/Unit/Anleitungen/HilfeEinbauTest.php
git commit -m "feat(recruiting): Anleitungen — Fragezeichen auf Dispo-Einstellungen, VA-Seite und Schulungsterminen"
```

---

### Task 5: Inhalte umstellen, Dispo-Anleitung, README, PPTX-Skript stilllegen, Inhaltstest

**Files:**
- Delete: `docs/anleitungen/2026-10-08-schulungstermine-umplanen.md`, `docs/anleitungen/2026-10-08-schulungsnachbereitung-vertragsdaten.md`, `docs/anleitungen/folien/` (ganzer Ordner, per `git rm -r`, nachdem die Screenshots verschoben sind)
- Create: `docs/anleitungen/schulungstermine-umplanen.md`, `docs/anleitungen/schulungsnachbereitung-vertragsdaten.md`, `docs/anleitungen/dispo-absage-erkennung.md`
- Move: `docs/anleitungen/folien/screenshots/A_liste_angehakt.png`, `B_fenster_verschieben.png`, `C_status_teilgenommen.png` → `docs/anleitungen/bilder/schulungstermine-umplanen/`; `nachbereitung_vertragsdaten.png`, `statusfilter_keine_reaktion.png` → `docs/anleitungen/bilder/schulungsnachbereitung-vertragsdaten/`
- Modify: `docs/anleitungen/README.md` (komplett neu)
- Test: `tests/Unit/Anleitungen/AnleitungenInhaltTest.php`

**Interfaces:**
- Consumes: `AnleitungKatalog::standard()`, `->alle()`, `->bildPfad()`; `Anleitung::$seiten`, `Folie::$bild`, `Bild::$rezept`.

- [ ] **Step 1: Write the failing content test** — `tests/Unit/Anleitungen/AnleitungenInhaltTest.php`:

```php
<?php

namespace Platform\Recruiting\Tests\Unit\Anleitungen;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Anleitungen\AnleitungKatalog;

/** Waechter ueber die echten Dateien in docs/anleitungen/ — faengt Tippfehler vor dem Merge. */
class AnleitungenInhaltTest extends TestCase
{
    protected function setUp(): void
    {
        AnleitungKatalog::vergessen();
    }

    public function test_es_gibt_die_ersten_drei_anleitungen(): void
    {
        $slugs = array_map(fn ($a) => $a->slug, AnleitungKatalog::standard()->alle());

        foreach (['schulungstermine-umplanen', 'schulungsnachbereitung-vertragsdaten', 'dispo-absage-erkennung'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }

    public function test_alle_dateien_sind_lesbar(): void
    {
        foreach (AnleitungKatalog::standard()->alle() as $a) {
            $this->assertNull($a->fehler, "{$a->slug}: {$a->fehler}");
        }
    }

    public function test_jede_genannte_seite_ist_eine_echte_route(): void
    {
        $routen = (string) file_get_contents(dirname(__DIR__, 3) . '/routes/web.php');

        foreach (AnleitungKatalog::standard()->alle() as $a) {
            foreach ($a->seiten as $route) {
                $this->assertStringContainsString("->name('{$route}')", $routen, "{$a->slug}: Route {$route} gibt es nicht");
            }
        }
    }

    public function test_jedes_bild_hat_eine_datei_oder_ein_rezept(): void
    {
        $k = AnleitungKatalog::standard();
        foreach ($k->alle() as $a) {
            foreach ($a->folien as $f) {
                if ($f->bild === null) {
                    continue;
                }
                $this->assertTrue(
                    $k->bildPfad($a->slug, $f->bild->datei) !== null || isset($f->bild->rezept['seite']) || isset($f->bild->rezept['manuell']),
                    "{$a->slug} Folie {$f->nr}: {$f->bild->datei} hat weder Datei noch Rezept"
                );
            }
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen/AnleitungenInhaltTest.php`
Expected: FAIL (`2026-10-08-…` slugs are invalid file names → `fehler`; new slugs missing).

- [ ] **Step 3: Move screenshots**

```bash
mkdir -p docs/anleitungen/bilder/schulungstermine-umplanen docs/anleitungen/bilder/schulungsnachbereitung-vertragsdaten
git mv docs/anleitungen/folien/screenshots/A_liste_angehakt.png docs/anleitungen/folien/screenshots/B_fenster_verschieben.png docs/anleitungen/folien/screenshots/C_status_teilgenommen.png docs/anleitungen/bilder/schulungstermine-umplanen/
git mv docs/anleitungen/folien/screenshots/nachbereitung_vertragsdaten.png docs/anleitungen/folien/screenshots/statusfilter_keine_reaktion.png docs/anleitungen/bilder/schulungsnachbereitung-vertragsdaten/
git rm -r docs/anleitungen/folien
git rm docs/anleitungen/2026-10-08-schulungstermine-umplanen.md docs/anleitungen/2026-10-08-schulungsnachbereitung-vertragsdaten.md
```

- [ ] **Step 4: `docs/anleitungen/schulungstermine-umplanen.md`** (Inhalt aus dem bisherigen Baustein und PPTX-Skript, Folie für Folie):

```markdown
---
titel: Schulungstermine umplanen
bereich: Schulungen
stand: 2026-10-08
seiten: [recruiting.interview-schedule.index, recruiting.statistics.index]
---

## Teilnehmer verschieben · Schritt 1 | Teilnehmer auswählen

![Teilnehmerliste mit angehakten Personen und blauem Balken „N ausgewählt · Verschieben nach…“](A_liste_angehakt.png)
<!-- shot: manuell -->

1. **Schulungstermine** öffnen und den Termin anklicken, aus dem ihr verschieben wollt (Schulung A).
2. In der Teilnehmerliste die Personen **anhaken**. Das Kästchen oben in der Kopfzeile wählt alle verschiebbaren auf einmal.
3. Oben erscheint der blaue Balken „N ausgewählt“. Dort auf **„Verschieben nach…“** klicken.

> Geht vor, während und nach der Schulung.

## Teilnehmer verschieben · Schritt 2 | Zieltermin wählen und verschieben

![Fenster „Teilnehmer verschieben“ mit Zieltermin-Auswahl und Kommentarfeld](B_fenster_verschieben.png)
<!-- shot: manuell -->

4. Im Fenster den **Zieltermin** wählen (Schulung B). Hinter jedem Termin steht, wie viele Plätze noch frei sind.
5. Optional einen **Kommentar** eintragen, z. B. „Logistik-Gruppe“. Er steht später im Verlauf des Bewerbers.
6. Auf **„Verschieben“** klicken. Fertig.

> Status, Bestätigung und Notizen bleiben. Wer schon erinnert wurde, bekommt keine zweite Erinnerung. Es geht keine Nachricht raus.

## Teilnehmer verschieben · Während und nach der Schulung | Erst „Teilgenommen“, dann in Gruppen aufteilen

Zwischen Terminen am selben Tag könnt ihr jederzeit verschieben, auch während der Schulung und nachträglich, und auch mit Teilnehmern, die schon auf **Teilgenommen** stehen.

![Eine Zeile der Teilnehmerliste mit Status-Auswahl „Teilgenommen“](C_status_teilgenommen.png)
<!-- shot: manuell -->

- Am besten in der großen Gruppe alle Anwesenden auf **Teilgenommen** setzen, danach in die Gruppen verschieben. Der Status wandert mit.
- Ziel ist immer ein Termin derselben Stelle.
- Teilgenommen geht nur in einen Termin am selben Tag.

> Im Zieltermin steht an der Person „aus …“ mit dem alten Termin.

## Wenn eine Schulung voll ist · Schritt 1 | Bewerber ohne Termin finden

Ist eine Schulung ausgebucht, könnt ihr alle Bewerber, die noch keinen Termin haben, per WhatsApp auf die freien Termine hinweisen. Sie suchen sich dann selbst einen neuen Termin aus.

![Statistik → Schulungstermine: „Ausgebucht“ und „N ohne Termin“](F_statistik_pille.png)
<!-- shot: seite=/recruiting/statistics ausschnitt="Schulungstermine" markieren="ohne Termin" -->

1. Links auf **Statistik** gehen, eure Filiale auswählen und nach unten zu **„Schulungstermine“** scrollen.
2. Bei der vollen Schulung steht **„Ausgebucht“**. Daneben seht ihr, wie viele Bewerber noch keinen Termin haben, zum Beispiel **„12 ohne Termin“**. Darauf klicken.
3. Es öffnet sich ein Fenster. Oben steht, welche Schulung voll ist und ob es noch andere Termine mit freien Plätzen gibt. **Ist der Kasten rot, gibt es keinen freien Termin.** Dann bitte zuerst einen neuen Termin anlegen.

> „… ohne Termin“ erscheint nur, wenn die Schulung eine Platzzahl hat, komplett voll ist, noch bevorsteht und eine Ausschreibung hinterlegt ist.

## Wenn eine Schulung voll ist · Schritt 2 | Empfänger prüfen und WhatsApp senden

![Kampagnen-Fenster mit Empfängerliste, Nachricht und Senden-Knopf](G_kampagne_fenster.png)
<!-- shot: seite=/recruiting/statistics klicks="ohne Termin" ausschnitt="css:[role=dialog]" markieren="senden" -->

4. Im Fenster seht ihr alle Bewerber, die die Nachricht bekommen. Wer keine bekommen soll: einfach den **Haken entfernen**. Grau hinterlegte Personen können nicht angeschrieben werden, der Grund steht dabei.
5. Ganz unten seht ihr die Nachricht so, wie der Bewerber sie bekommt. Statt „Anna“ steht dort später der richtige Vorname.
6. Auf **„WhatsApp an … Personen senden“** klicken und bestätigen. Im Fenster seht ihr, wie die Nachrichten rausgehen.

> Die Bewerber buchen sich danach selbst in einen freien Termin.

## Gut zu wissen · Teilnehmer verschieben | Regeln auf einen Blick

| Thema | So ist es |
| --- | --- |
| Welche Termine | Nur aktive Termine **derselben Stelle** (gleicher Ort). Termine **am selben Tag** jederzeit, auch nachträglich. Termine an anderen Tagen nur, solange sie **noch nicht zu Ende** sind. Wer in eine andere Filiale soll, bucht über die Terminauswahl neu. |
| Wer | Gebucht, registriert und bestätigt immer. **Teilgenommen** nur in einen Termin am **selben Tag**. Stornierte, nicht erschienene und vor Ort aussortierte bleiben, wo sie sind. |
| Plätze | Hat der Zieltermin eine Platzzahl, werden nur so viele verschoben, wie Plätze frei sind. Wer nicht mehr passt, bleibt in Schulung A, das Fenster sagt es euch. |
| Nachricht | Keine. Wenn die Person Bescheid wissen soll, bitte selbst anschreiben. |

## Gut zu wissen · Kampagne „Schulung voll“ | Regeln auf einen Blick

| Thema | So ist es |
| --- | --- |
| Wer bekommt sie | Nur Bewerber, die Phase 1 abgeschlossen haben und in der Terminauswahl stehen. Wer die Bewerbung noch nicht vervollständigt hat, bleibt sichtbar, ist aber gesperrt. |
| Danach | Keine automatischen Erinnerungen. Bucht die Person einen Termin, läuft alles wie gewohnt weiter: Daten vervollständigen, Bestätigung, Vertrag. |
| Doppelt | Wer in den letzten 14 Tagen schon eine Kampagne bekam, ist vorsichtshalber abgehakt (Hinweis „angeschrieben am …“). Haken setzen geht trotzdem. |
| Ausschreibungswechsel | Bucht jemand am selben Ort eine Schulung einer anderen Ausschreibung, bleibt er in seiner Ausschreibung. In der Statistik seht ihr ihn beim neuen Termin unter „Herkunft“. |
| Vorlage pflegen | Bewerberliste → Einstellungen → „WhatsApp Template — Schulung voll, freie Termine“. Ohne Auswahl greift die normale Terminauswahl-Vorlage. |
```

Note: `recruiting.statistics.index` must exist as a route name — the Inhaltstest checks it. If the name differs, use the real name from `routes/web.php` (grep `statistics`).

- [ ] **Step 5: `docs/anleitungen/schulungsnachbereitung-vertragsdaten.md`**:

```markdown
---
titel: Vertragsdaten nach der Schulung
bereich: Schulungen
stand: 2026-10-08
seiten: [recruiting.interview-schedule.index]
---

## Nach der Schulung | Vertragsdaten eintragen

Im Reiter **„Nach der Schulung“** tragt ihr für jeden Teilnehmer Zuschlag, Vertragsbeginn und Vertragsende ein.

![Reiter „Nach der Schulung“ mit Zuschlag, Vertragsbeginn und Vertragsende je Teilnehmer](nachbereitung_vertragsdaten.png)
<!-- shot: manuell -->

1. Den Termin öffnen und auf **„Nach der Schulung“** wechseln.
2. In der Zeile des Teilnehmers **Zuschlag**, **Vertragsbeginn** und bei Bedarf **Vertragsende** eintragen. Ohne Vertragsende rechnet das System es selbst aus.
3. Jede Eingabe ist **sofort gespeichert**. Ihr müsst dafür nichts weiter drücken.
4. Wenn alles passt, die Verträge über den Sammelversand verschicken.

> Arbeiten zwei Personen auf zwei Laptops an derselben Liste, sieht jeder die Eingaben des anderen, sobald er auf der Seite etwas anklickt oder sie neu lädt.

## Nach der Schulung | Nach Status filtern

![Statusfilter mit dem Eintrag „Keine Reaktion“](statusfilter_keine_reaktion.png)
<!-- shot: manuell -->

1. Mit dem Feld **Status** oben in der Liste filtert ihr, zum Beispiel nur „Teilgenommen“.
2. **„Keine Reaktion“** heißen Gebuchte, die auf die Erinnerungen nicht reagiert haben. Ihr Platz ist wieder frei.

## Gut zu wissen | Regeln auf einen Blick

| Thema | So ist es |
| --- | --- |
| Vertragsdaten geleert | Leert ihr den Vertragsbeginn bei jemandem, der schon für den Versand vorgemerkt ist, wird die Vormerkung zurückgenommen. Es geht dann nichts automatisch raus. |
| Nach dem Versand | Ist der Vertrag raus, gelten die Daten im Vertrag. Änderungen dann über „Vertrag neu ausstellen“ auf der Bewerberseite. |
| „Keine Reaktion“ | So heißen Gebuchte, die auf die Erinnerungen nicht reagiert haben. Ihr Platz ist wieder frei. Im Statusfilter gibt es dafür einen eigenen Eintrag. |
```

- [ ] **Step 6: `docs/anleitungen/dispo-absage-erkennung.md`** (neu; Stand der Funktion: main 3f9eddd6):

```markdown
---
titel: Absage-Erkennung und „Wo bist du?“
bereich: Dispo
stand: 2026-10-12
seiten: [recruiting.dispo.settings, recruiting.dispo.events.show, recruiting.dispo.events.index]
---

## Dispo · Absage-Erkennung | Was sie macht

Schreibt ein Mitarbeiter nach der Bestätigungsanfrage per WhatsApp zurück, liest die KI die Nachricht mit. Klingt sie nach einer Absage, wird der Mitarbeiter in der Veranstaltung markiert und das Diensthandy der Filiale bekommt eine WhatsApp.

- Die KI **sagt nie selbst ab**. Ob es wirklich eine Absage ist, entscheidet ihr.
- Reine Zusagen wie „ok“, „passt“ oder 👍 werden gar nicht erst geprüft.
- Geprüft werden nur Mitarbeiter, die die Bestätigungsanfrage schon bekommen haben.

> Die Erkennung ist ein Hinweis, keine Entscheidung.

## Dispo · Absage-Erkennung | Für heute einschalten

![Dispo-Einstellungen, Filial-Konfiguration mit Haken „prüfen“](haken-pruefen.png)
<!-- shot: seite=/recruiting/dispo-settings ausschnitt="Filial-Konfiguration" markieren="prüfen" -->

1. Links unter **Disposition** auf **Einstellungen** gehen.
2. In der Zeile eurer Filiale den Haken **„prüfen“** setzen.
3. Prüfen, ob ein **Diensthandy** eingetragen ist (mit +49, z. B. +49 170 1234567).
4. Auf **„Speichern“** in dieser Zeile klicken. Daneben steht jetzt „heute ab … bis 24:00“.

> Der Haken gilt nur heute. Um Mitternacht geht er von selbst aus. Bei mehrtägigen Veranstaltungen jeden Morgen neu setzen.

## Dispo · Absage-Erkennung | Der Alarm aufs Diensthandy

![WhatsApp „Mögliche Absage“ auf dem Diensthandy mit Knopf „Veranstaltung öffnen“](alarm-diensthandy.png)
<!-- shot: manuell -->

1. Das Diensthandy bekommt: **Name**, **Veranstaltung** und die **betroffenen Tage**.
2. Mit **„Veranstaltung öffnen“** springt ihr direkt in die Veranstaltung.
3. Pro Nachricht gibt es genau einen Alarm.

> Kommt kein Alarm, obwohl eine Meldung in der Veranstaltung steht: Diensthandy-Nummer und Vorlage in den Einstellungen prüfen.

## Dispo · In der Veranstaltung | Mögliche Absagen finden

![Veranstaltung mit Filter „⚠ Mögliche Absage“ und markierter Zeile](va-moegliche-absage.png)
<!-- shot: seite=/recruiting/dispo-events klicks="TEST — Dispo-Bestaetigung" ausschnitt="css:table" markieren="mögliche Absage" -->

1. In der Übersicht der Veranstaltungen steht bei betroffenen Veranstaltungen ein **⚠** mit der Anzahl.
2. In der Veranstaltung zeigt der Filter **„⚠ Mögliche Absage“** nur diese Mitarbeiter.
3. In der Zeile steht **„⚠ mögliche Absage“**. Ein Klick darauf öffnet den Chat.

## Dispo · In der Veranstaltung | Absage erfassen oder verwerfen

![Chat mit orangefarbenem Hinweis, Nachricht des Mitarbeiters und den Knöpfen „Absage erfassen …“ und „Keine Absage“](chat-hinweis.png)
<!-- shot: seite=/recruiting/dispo-events klicks="TEST — Dispo-Bestaetigung; mögliche Absage" ausschnitt="Absage erfassen" markieren="Absage erfassen" -->

1. Oben im Chat steht der orangefarbene Hinweis mit der Nachricht und der Einschätzung der KI.
2. **„Absage erfassen …“** öffnet das gewohnte Absage-Fenster. Tage und Notiz sind schon vorausgefüllt. Prüfen und speichern.
3. **„Keine Absage“** nimmt die Markierung weg, wenn die KI sich geirrt hat.

> Bestätigt der Mitarbeiter danach doch noch, verschwindet die Markierung von selbst.

## Dispo · In der Veranstaltung | Wen haben wir schon gesucht?

![Zeile mit violetter Sprechblase und Hinweis „„Wo bist du?“ gesendet um …“](sprechblase-violett.png)
<!-- shot: seite=/recruiting/dispo-events klicks="TEST — Dispo-Bestaetigung" ausschnitt="css:table" markieren="css:.bg-violet-100" -->

1. Schickt ihr einem Mitarbeiter aus dem Chat die Vorlage **„Wo bist du?“**, wird seine Sprechblase in der Zeile dieses Tages **violett**.
2. Mit der Maus auf der Blase seht ihr, um wie viel Uhr sie rausging.
3. Eine **blaue** Blase heißt weiterhin: neue Nachricht, bitte lesen. Blau geht vor.

## Gut zu wissen | Regeln auf einen Blick

| Thema | So ist es |
| --- | --- |
| Wer wird geprüft | Nur Mitarbeiter mit Bestätigungsanfrage, in einer Filiale mit Haken, für kommende Einsätze. |
| Was wird nicht geprüft | Sprachnachrichten und Bilder ohne Text. Nachrichten von vor dem Einschalten. |
| Wie lange | Nur am Tag des Einschaltens, bis 24:00. Maßgeblich ist, wann die Nachricht reinkam. |
| Zähler | In den Einstellungen steht je Filiale, wie viele Nachrichten diesen Monat geprüft und gemeldet wurden. Rot: Nachrichten, die die KI nicht prüfen konnte. |
| Absagen | Abgesagt wird nur, wenn ihr „Absage erfassen“ speichert. Die KI ändert nie etwas an der Einbuchung. |
```

- [ ] **Step 7: `docs/anleitungen/README.md`** (komplett ersetzen):

```markdown
# Anleitungen für den Kunden

Die Anleitungen erscheinen im Recruiting unter **Hilfe → Anleitungen** (für alle Nutzer, auch
eingeschränkte Zugänge) und lassen sich dort als PDF speichern. Sie werden mit dem Paket
ausgeliefert. Spec: `docs/superpowers/specs/2026-10-12-anleitungen-bereich-design.md`.

## Eine Anleitung schreiben

- Datei `docs/anleitungen/<slug>.md`, Slug nur Kleinbuchstaben, Ziffern, Bindestriche.
- Bilder in `docs/anleitungen/bilder/<slug>/`.
- Sprache des Kunden: Du-/Ihr-Form, keine Technik, Schritte so, wie man klickt.
- Nach jedem kundensichtbaren Feature nachziehen; ändert sich die Oberfläche, Datei und Bilder anpassen.

Aufbau:

    ---
    titel: …
    bereich: Bewerber | Schulungen | Mitarbeiter | Verträge | Dispo
    stand: JJJJ-MM-TT
    seiten: [routenname, …]      # dort erscheint das „?“
    ---

    ## Kicker | Titel             # neue Folie
    Einleitungssatz (optional)

    ![Was das Bild zeigt](datei.png)
    <!-- shot: seite=/recruiting/… klicks="Text; Text" ausschnitt="Text" markieren="Text" -->

    1. Schritt
    2. Schritt

    > Merksatz

    | Thema | So ist es |      # Tabelle für „Gut zu wissen“
    | --- | --- |

Höchstens ein Bild je Folie. Fehlt die Bilddatei, zeigt die Folie einen Platzhalter mit dem Text
in den eckigen Klammern. Der Test `AnleitungenInhaltTest` prüft vor dem Merge, dass jede Datei
lesbar ist, jede Route in `seiten` existiert und jedes Bild eine Datei oder ein Rezept hat.

## Screenshots per Rezept

    cd tools/anleitungen-screenshots && npm install    # einmalig
    node tools/anleitungen-screenshots <slug>           # eine Anleitung
    node tools/anleitungen-screenshots --alle           # alle neu
    node tools/anleitungen-screenshots --anmelden       # Sitzung erneuern

- Läuft gegen `https://demo.bhgdigital.de` (nur Testdaten). Beim ersten Lauf öffnet sich Chrome,
  einmal bei Microsoft anmelden; die Sitzung liegt in `~/.config/rg-anleitungen/sitzung.json`.
- Rezept-Schlüssel: `seite` (Pfad), `klicks` (mit `;` getrennt), `ausschnitt`, `markieren`,
  `handy` (390×844), `manuell` (Bild selbst ablegen). Werte sind sichtbare Texte; mit `css:`
  davor ein CSS-Selektor.
- Feste Regeln: 1440×900, doppelte Pixeldichte, helles Design, Ausschnitt mit 16 px Rand,
  Markierung als blauer Rahmen (#1273DE).
- Die Demo läuft auf dem Konto-Zweig. Seiten, die dort anders aussehen als live, erst nach
  dessen Merge neu aufnehmen.
- Bilder nach dem Lauf ansehen, Rezept nachbessern, dann committen.
```

- [ ] **Step 8: Run tests**

Run: `/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml tests/Unit/Anleitungen`
Expected: PASS. If `test_jede_genannte_seite_ist_eine_echte_route` fails, correct the route name in the `seiten:` line to the real `->name('…')` from `routes/web.php`.

- [ ] **Step 9: Commit**

```bash
git add docs/anleitungen tests/Unit/Anleitungen/AnleitungenInhaltTest.php
git commit -m "docs(recruiting): Anleitungen im Folien-Format (Schulungen umgestellt, Dispo neu), PPTX-Skript stillgelegt"
```

---

### Task 6: Screenshot-Skript (Node + Playwright gegen die Demo)

**Files:**
- Create: `tools/anleitungen-screenshots/package.json`
- Create: `tools/anleitungen-screenshots/.gitignore`
- Create: `tools/anleitungen-screenshots/rezepte.mjs`
- Create: `tools/anleitungen-screenshots/index.mjs`
- Test: `tools/anleitungen-screenshots/rezepte.test.mjs`

**Interfaces:**
- Consumes: Markdown-Format aus Task 1 (Bildzeile + folgender `<!-- shot: … -->`), Bildordner `docs/anleitungen/bilder/<slug>/`.
- Produces: `rezepteAus(markdown: string): Array<{datei: string, alt: string, rezept: Record<string, string|true>}>`, `rezeptLesen(text: string)`; CLI `node tools/anleitungen-screenshots <slug|--alle|--anmelden>`.

- [ ] **Step 1: Write the failing test** — `tools/anleitungen-screenshots/rezepte.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rezeptLesen, rezepteAus } from './rezepte.mjs';

test('rezept: werte, anfuehrungszeichen, flags', () => {
    assert.deepEqual(
        rezeptLesen(' seite=/a klicks="Öffnen; Weiter" ausschnitt="css:[role=dialog]" handy '),
        { seite: '/a', klicks: 'Öffnen; Weiter', ausschnitt: 'css:[role=dialog]', handy: true },
    );
});

test('rezepte aus markdown: bild mit und ohne rezept, mehrzeiliger kommentar', () => {
    const md = [
        '## A | B', '',
        '![Bild eins](eins.png)',
        '<!-- shot: seite=/x',
        '     markieren="prüfen" -->', '',
        '1. a', '',
        '## C', '',
        '![Bild zwei](zwei.png)', '',
    ].join('\n');

    assert.deepEqual(rezepteAus(md), [
        { datei: 'eins.png', alt: 'Bild eins', rezept: { seite: '/x', markieren: 'prüfen' } },
        { datei: 'zwei.png', alt: 'Bild zwei', rezept: {} },
    ]);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test tools/anleitungen-screenshots/rezepte.test.mjs`
Expected: FAIL („Cannot find module …/rezepte.mjs“).

- [ ] **Step 3: `tools/anleitungen-screenshots/rezepte.mjs`**

```js
// Liest Bild-Rezepte aus Anleitungs-Markdown (gleiches Format wie AnleitungParser::rezept in PHP).

export function rezeptLesen(text) {
    const out = {};
    for (const m of text.matchAll(/([a-z]+)(?:=(?:"([^"]*)"|(\S+)))?/gu)) {
        out[m[1]] = m[2] !== undefined ? m[2] : (m[3] !== undefined ? m[3] : true);
    }
    return out;
}

export function rezepteAus(markdown) {
    const text = markdown.replace(/\r\n?/g, '\n');
    const bilder = [];
    const re = /^!\[([^\]]*)\]\(([^)\s]+)\)[ \t]*(?:\n<!--\s*shot:([\s\S]*?)-->)?/gm;
    for (const m of text.matchAll(re)) {
        bilder.push({ datei: m[2], alt: m[1].trim(), rezept: m[3] !== undefined ? rezeptLesen(m[3]) : {} });
    }
    return bilder;
}
```

Run: `node --test tools/anleitungen-screenshots/rezepte.test.mjs` → PASS.

- [ ] **Step 4: `package.json` and `.gitignore`**

`tools/anleitungen-screenshots/package.json`:
```json
{
  "name": "rg-anleitungen-screenshots",
  "private": true,
  "type": "module",
  "description": "Screenshots fuer docs/anleitungen per Rezept gegen die Demo",
  "scripts": { "test": "node --test" },
  "dependencies": { "playwright-core": "^1.48.0" }
}
```

`tools/anleitungen-screenshots/.gitignore`:
```
node_modules/
package-lock.json
```

- [ ] **Step 5: `tools/anleitungen-screenshots/index.mjs`**

```js
#!/usr/bin/env node
// Erzeugt die Screenshots der Kundenanleitungen aus ihren <!-- shot: … -->-Rezepten.
// Aufruf: node tools/anleitungen-screenshots <slug|--alle|--anmelden>
// Laeuft gegen die Demo (nur Testdaten). Sitzung liegt ausserhalb des Repos.
import { chromium } from 'playwright-core';
import { readFileSync, readdirSync, existsSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { rezepteAus } from './rezepte.mjs';

const BASIS = (process.env.RG_ANLEITUNGEN_BASIS || 'https://demo.bhgdigital.de').replace(/\/$/, '');
const SITZUNG = join(homedir(), '.config', 'rg-anleitungen', 'sitzung.json');
const WURZEL = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..', 'docs', 'anleitungen');
const BLAU = '#1273DE';
const RAND = 16;

async function anmelden() {
    mkdirSync(dirname(SITZUNG), { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: false });
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await page.goto(`${BASIS}/recruiting`);
    console.log('Bitte im Chrome-Fenster anmelden … (bis zu 5 Minuten)');
    await page.waitForURL(u => u.href.startsWith(`${BASIS}/recruiting`), { timeout: 300_000 });
    await ctx.storageState({ path: SITZUNG });
    await browser.close();
    console.log(`Sitzung gespeichert: ${SITZUNG}`);
}

function ziel(page, wert) {
    return wert.startsWith('css:') ? page.locator(wert.slice(4)).first() : page.getByText(wert, { exact: false }).first();
}

// Ausschnitt per Text: der naechste umschliessende Kasten (Karte, Tabelle, Fenster).
async function ausschnittElement(page, wert) {
    if (wert.startsWith('css:')) {
        return page.locator(wert.slice(4)).first();
    }
    return page.getByText(wert, { exact: false }).first()
        .locator('xpath=ancestor-or-self::*[self::table or self::section or @role="dialog" or contains(@class,"rounded-lg") or contains(@class,"rounded-xl")][1]');
}

async function aufnehmen(browser, slug, bild) {
    const r = bild.rezept;
    const ctx = await browser.newContext({
        storageState: SITZUNG,
        viewport: r.handy ? { width: 390, height: 844 } : { width: 1440, height: 900 },
        deviceScaleFactor: 2,
        colorScheme: 'light',
        isMobile: Boolean(r.handy),
    });
    const page = await ctx.newPage();
    try {
        await page.goto(BASIS + r.seite, { waitUntil: 'networkidle' });
        if (/\/login|microsoftonline/.test(page.url())) {
            throw new Error('Sitzung abgelaufen — node tools/anleitungen-screenshots --anmelden');
        }
        for (const klick of String(r.klicks || '').split(';').map(s => s.trim()).filter(Boolean)) {
            await ziel(page, klick).click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(400);
        }
        if (typeof r.markieren === 'string') {
            await ziel(page, r.markieren).evaluate((el, farbe) => {
                el.style.outline = `3px solid ${farbe}`;
                el.style.outlineOffset = '3px';
                el.style.borderRadius = el.style.borderRadius || '4px';
            }, BLAU);
        }
        const datei = join(WURZEL, 'bilder', slug, bild.datei);
        mkdirSync(dirname(datei), { recursive: true });
        if (typeof r.ausschnitt === 'string') {
            const el = await ausschnittElement(page, r.ausschnitt);
            await el.scrollIntoViewIfNeeded();
            const box = await el.evaluate(e => {
                const b = e.getBoundingClientRect();
                return { x: b.x + window.scrollX, y: b.y + window.scrollY, width: b.width, height: b.height };
            });
            await page.screenshot({
                path: datei,
                fullPage: true,
                clip: {
                    x: Math.max(0, box.x - RAND),
                    y: Math.max(0, box.y - RAND),
                    width: box.width + 2 * RAND,
                    height: box.height + 2 * RAND,
                },
            });
        } else {
            await page.screenshot({ path: datei });
        }
        console.log(`  ✓ ${bild.datei}`);
    } finally {
        await ctx.close();
    }
}

async function main() {
    const arg = process.argv[2];
    if (!arg) {
        console.error('Aufruf: node tools/anleitungen-screenshots <slug|--alle|--anmelden>');
        process.exit(1);
    }
    if (arg === '--anmelden' || !existsSync(SITZUNG)) {
        await anmelden();
        if (arg === '--anmelden') return;
    }
    const slugs = arg === '--alle'
        ? readdirSync(WURZEL).filter(f => f.endsWith('.md') && f !== 'README.md').map(f => f.slice(0, -3))
        : [arg];

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let fehler = 0;
    try {
        for (const slug of slugs) {
            const pfad = join(WURZEL, `${slug}.md`);
            if (!existsSync(pfad)) {
                console.error(`Anleitung ${slug} gibt es nicht.`);
                fehler++;
                continue;
            }
            console.log(slug);
            for (const bild of rezepteAus(readFileSync(pfad, 'utf8'))) {
                if (bild.rezept.manuell || !bild.rezept.seite) {
                    console.log(`  – ${bild.datei} (manuell)`);
                    continue;
                }
                try {
                    await aufnehmen(browser, slug, bild);
                } catch (e) {
                    fehler++;
                    console.error(`  ✗ ${bild.datei}: ${e.message}`);
                }
            }
        }
    } finally {
        await browser.close();
    }
    process.exit(fehler ? 1 : 0);
}

main();
```

- [ ] **Step 6: Verify**

Run:
```
node --test tools/anleitungen-screenshots/rezepte.test.mjs
node --check tools/anleitungen-screenshots/index.mjs
```
Expected: tests PASS, syntax OK. (Live run against the demo needs the user's Microsoft login — done together after the merge; not part of the automated check.)

- [ ] **Step 7: Commit**

```bash
git add tools/anleitungen-screenshots
git commit -m "feat(recruiting): Anleitungen — Screenshot-Skript (Rezepte, Playwright gegen die Demo)"
```

---

### Task 7: Abschluss

- [ ] **Step 1: Full suite + blade check**

```
/Users/shaustein/Documents/dev/platforms/meingedeck/vendor/bin/phpunit -c phpunit.xml
php tools/blade-check.php resources/views/anleitungen/*.blade.php resources/views/livewire/anleitungen/*.blade.php resources/views/livewire/sidebar.blade.php resources/views/livewire/dispo/settings.blade.php resources/views/livewire/dispo/events/show.blade.php resources/views/livewire/interview-schedule/index.blade.php
node --test tools/anleitungen-screenshots/rezepte.test.mjs
```
Expected: alles grün.

- [ ] **Step 2: Mutation probes** (je eine Zeile verfälschen, Test muss rot werden, dann `git checkout -- <datei>`):
  - `AnleitungKatalog::bildPfad`: `str_starts_with(...)`-Prüfung entfernen → Symlink-Test rot.
  - `AnleitungParser::html`: `'html_input' => 'strip'` → `'allow'` → HTML-Test rot.
  - `RecruitingRouteGate`: `self::ANLEITUNG_ROUTES` aus `$erlaubt` entfernen → Gate-Test rot.
  - `_hilfe.blade.php`: `fuerRoute($route)` → `fuerRoute(request()->route()->getName())` → HilfeEinbauTest rot.

- [ ] **Step 3: Review** — Prüf-Agent über den ganzen Branch (Außensichtbar + Zugriff/Route-Gate + Dateiauslieferung), Funde selbst nachprüfen.

- [ ] **Step 4: Report to user** — Stand, Deploy (`view:clear`), Sichttests (Übersicht, eine Anleitung am Rechner und am Handy, Druck in Chrome und Safari, „?“ auf den drei Seiten, eingeschränktes Konto sieht den Menüpunkt), erster Screenshot-Lauf gemeinsam (Microsoft-Login). Merge nur nach Freigabe.
