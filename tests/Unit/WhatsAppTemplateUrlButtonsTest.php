<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Was ein Meta-Template ueber seine Buttons hergibt — die Frage „darf ich hier
 * einen URL-Parameter mitschicken, und an welche Position?".
 *
 * WOZU: der Sender setzt den Button-Parameter auf index 0 (an allen sechs
 * Sendestellen des Moduls hartkodiert, Spec H3). Ein Template mit Quick-Reply
 * an 0 und URL-Button an 1 bekaeme den Parameter also an die falsche
 * Komponente. Und ein STATISCHER URL-Button darf ueberhaupt keinen Parameter
 * bekommen — fuenf der sieben Erkennungsstellen im Modul pruefen das nicht
 * (Spec H1), diese Klasse prueft es.
 */
class WhatsAppTemplateUrlButtonsTest extends TestCase
{
    /** Ein dynamischer URL-Button an Position 0 — der Normalfall. */
    public function testDynamischerButtonAnPositionNull(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Zertifikat', 'url' => 'https://example.org/recruiting/zertifikat/{{1}}'],
            ]],
        ];

        $this->assertSame([0], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
        $this->assertTrue(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 0));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 1));
    }

    /**
     * Quick-Reply an 0, dynamischer URL-Button an 1.
     *
     * DER FALL, DER EINE FALSCHE MELDUNG ERZEUGT, wenn man nur „gibt es einen
     * Button" fragt: es gibt einen, er steht nur woanders. Deshalb Positionen
     * statt bool.
     */
    public function testDynamischerButtonAnPositionEins(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'text' => 'Zertifikat', 'url' => 'https://example.org/zertifikat/{{1}}'],
            ]],
        ];

        $this->assertSame([1], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 0));
        $this->assertTrue(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 1));
    }

    /**
     * Statischer URL-Button: type stimmt, Variable fehlt.
     *
     * Der wichtigste Negativfall des Tasks. Ein Parameter fuer einen statischen
     * Button ist ein Parameter zu viel; RecInterview.php:162 und
     * InterviewSchedule/Index.php:145 pruefen deshalb auf '{{', die anderen
     * fuenf Stellen nicht.
     */
    public function testStatischerUrlButtonZaehltNicht(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'URL', 'text' => 'Website', 'url' => 'https://example.org/karriere'],
            ]],
        ];

        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt($components, 0));
    }

    public function testQuickReplyAlleinZaehltNicht(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Ja'],
                ['type' => 'QUICK_REPLY', 'text' => 'Nein'],
            ]],
        ];

        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
    }

    public function testOhneButtonsKomponenteUndOhneKomponentenUeberhaupt(): void
    {
        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes([
            ['type' => 'BODY', 'text' => 'Hallo'],
        ]));
        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes([]));
        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes(null));
        $this->assertFalse(WhatsAppTemplateUrlButtons::hasDynamicAt(null, 0));
    }

    /** Kaputte Struktur darf nicht werfen — Meta-JSON ist nicht unser Schema. */
    public function testUnerwarteteStrukturWirftNicht(): void
    {
        $components = [
            'kein-array',
            ['type' => 'BUTTONS'],
            ['type' => 'BUTTONS', 'buttons' => 'auch-kein-array'],
            ['type' => 'BUTTONS', 'buttons' => ['kein-array', ['type' => 'URL']]],
        ];

        $this->assertSame([], WhatsAppTemplateUrlButtons::dynamicIndexes($components));
    }

    /**
     * describe() liefert Typ UND Position im Klartext — das Material fuer die
     * Fehlermeldung an HR. Ohne die Position sagt die Meldung nicht, was zu tun
     * ist (Spec W5).
     */
    public function testDescribeNenntTypUndPosition(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'text' => 'Zertifikat', 'url' => 'https://example.org/z/{{1}}'],
                ['type' => 'URL', 'text' => 'Website', 'url' => 'https://example.org/karriere'],
            ]],
        ];

        $this->assertSame([
            'Position 0: QUICK_REPLY',
            'Position 1: URL mit Variable',
            'Position 2: URL ohne Variable',
        ], WhatsAppTemplateUrlButtons::describe($components));
    }

    public function testDescribeOhneButtonsIstLeer(): void
    {
        $this->assertSame([], WhatsAppTemplateUrlButtons::describe(null));
        $this->assertSame([], WhatsAppTemplateUrlButtons::describe([['type' => 'BODY', 'text' => 'x']]));
    }
}
