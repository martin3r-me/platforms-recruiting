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

    public function test_einfacher_zeilenumbruch_bleibt_als_br_und_telefon_wird_knopf(): void
    {
        $html = InfoText::html("Marco – Disposition Köln/Bonn\n+49 1523 4079291");
        $this->assertMatchesRegularExpression('~Köln/Bonn<br>\s*<a [^>]*href="tel:\+4915234079291"~u', $html);
    }

    public function test_br_aus_dem_editor_wird_zeilenumbruch_statt_zu_verkleben(): void
    {
        $html = InfoText::html('Zeile eins<br>Zeile zwei und<br/>drei');
        $this->assertStringNotContainsString('einsZeile', $html);
        $this->assertStringNotContainsString('unddrei', $html);
        $this->assertMatchesRegularExpression('~Zeile eins<br>\s*Zeile zwei und<br>\s*drei~', $html);
    }

    public function test_telefon_muster_laeuft_nicht_ueber_den_zeilenumbruch(): void
    {
        preg_match_all(InfoText::TELEFON_MUSTER, "Ruf 0221 123456\n10 Personen", $m);
        $this->assertSame(['0221 123456'], $m[1]);

        preg_match_all(InfoText::TELEFON_MUSTER, "+49 1523 4079291\n+49 1577 4990932", $m);
        $this->assertSame(['+49 1523 4079291', '+49 1577 4990932'], $m[1]);

        preg_match_all(InfoText::TELEFON_MUSTER, 'Kundennr. 120221 123456', $m);
        $this->assertSame([], $m[1], 'linke Grenze: kein Treffer mitten in einer Ziffernfolge');
    }

    public function test_telefon_vor_ziffernzeile_bekommt_eigenen_knopf(): void
    {
        $html = InfoText::html("Ruf 0221 123456\n10 Personen");
        $this->assertStringContainsString('<a href="tel:+49221123456" class="info-tel">0221 123456</a>', $html);
        $this->assertStringContainsString('10 Personen', $html);

        $html = InfoText::html("+49 1523 4079291\n+49 1577 4990932");
        $this->assertSame(2, substr_count($html, 'class="info-tel"'));
        $this->assertStringContainsString('href="tel:+4915234079291"', $html);
        $this->assertStringContainsString('href="tel:+4915774990932"', $html);
    }

    public function test_block_html_wird_samt_inhalt_entfernt(): void
    {
        $html = InfoText::html("<div onclick=\"x()\">Block</div>\n\n<style>body{display:none}</style>\n\n<table><tr><td>Zelle</td></tr></table>\n\nText");
        $this->assertStringNotContainsString('Block', $html);
        $this->assertStringNotContainsString('display', $html);
        $this->assertStringNotContainsString('Zelle', $html);
        $this->assertStringContainsString('Text', $html);
    }

    public function test_leerer_text_ergibt_leeres_html(): void
    {
        $this->assertSame('', InfoText::html("   \n  "));
    }
}
