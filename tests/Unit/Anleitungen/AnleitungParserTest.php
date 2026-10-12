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

    public function test_nicht_utf8_kippt_nichts(): void
    {
        $a = AnleitungParser::parse('x', self::KOPF . "\n## A | B\n\nGr\xFC\xDFe\n");

        $this->assertStringContainsString('UTF-8', (string) $a->fehler);
    }

    public function test_bilder_im_fliesstext_sind_nicht_erlaubt(): void
    {
        $a = AnleitungParser::parse('x', self::KOPF . "\n## A\n\n1. Schritt ![t](https://fremd.example/t.png)\n");

        $this->assertStringContainsString('Bild nur als eigene Zeile', (string) $a->fehler);
    }
}
