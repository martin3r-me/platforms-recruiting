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
