<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ApplicantTemplateSender;

/**
 * Task 8: Der Formular-Token darf NUR in einen URL-Knopf mit Platzhalter.
 * Die Bewerberakte setzt ihn bei jedem URL-Knopf — dadurch landete der Token
 * im MA-Portal-Link (Fall Theo Wirtz). Dieser Test haelt die Regel fest.
 */
class TemplateTokenDecisionTest extends TestCase
{
    public function test_url_knopf_mit_platzhalter_braucht_den_token(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}']],
        ]];

        $this->assertTrue(ApplicantTemplateSender::needsFormToken($components));
    }

    public function test_url_knopf_ohne_platzhalter_bekommt_keinen_token(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [['type' => 'URL', 'url' => 'https://example.test/portal']],
        ]];

        $this->assertFalse(ApplicantTemplateSender::needsFormToken($components));
    }

    public function test_ohne_url_knopf_kein_token(): void
    {
        $components = [
            ['type' => 'BODY', 'text' => 'Hallo {{1}}'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ja']]],
        ];

        $this->assertFalse(ApplicantTemplateSender::needsFormToken($components));
    }

    public function test_leere_komponenten_sind_harmlos(): void
    {
        $this->assertFalse(ApplicantTemplateSender::needsFormToken([]));
    }

    /**
     * Fix-Runde 1, Befund 1 (CRITICAL): needsFormToken() erkennt den
     * Platzhalter auch dann, wenn er NICHT am ersten Knopf sitzt (Quick-Reply
     * an Position 0, URL-Knopf mit Platzhalter an Position 1).
     */
    public function test_platzhalter_nicht_an_position_null_wird_erkannt(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}'],
            ],
        ]];

        $this->assertTrue(ApplicantTemplateSender::needsFormToken($components));
    }

    /** Mehrere BUTTONS-Bloecke — der Platzhalter im zweiten Block muss zaehlen. */
    public function test_mehrere_buttons_bloecke_platzhalter_im_zweiten_block(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ja']]],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}']]],
        ];

        $this->assertTrue(ApplicantTemplateSender::needsFormToken($components));
    }

    /** URL-Knopf ganz ohne 'url'-Schluessel — darf nicht als dynamisch gelten. */
    public function test_url_knopf_ohne_url_schluessel_kein_token(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [['type' => 'URL']],
        ]];

        $this->assertFalse(ApplicantTemplateSender::needsFormToken($components));
    }

    /**
     * Fix-Runde 1, Befund 1: der Token muss an die TATSAECHLICH gefundene
     * Position gehen, nicht hart an Index 0 (der urspruengliche Fehler).
     */
    public function test_token_geht_an_die_richtige_position_wenn_platzhalter_nicht_an_null_sitzt(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}'],
            ],
        ]];

        $result = ApplicantTemplateSender::buildTokenComponents($components, 'tok123');

        $this->assertTrue($result['ok']);
        $this->assertSame([[
            'type' => 'button',
            'sub_type' => 'url',
            'index' => 1,
            'parameters' => [['type' => 'text', 'text' => 'tok123']],
        ]], $result['components']);
    }

    /** Ueber mehrere BUTTONS-Bloecke hinweg gezaehlt — die Position bleibt korrekt. */
    public function test_token_position_ueber_mehrere_buttons_bloecke_hinweg(): void
    {
        $components = [
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Ja']]],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Nein'],
                ['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}'],
            ]],
        ];

        $result = ApplicantTemplateSender::buildTokenComponents($components, 'tok123');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['components'][0]['index']);
    }

    /**
     * Mehr als ein dynamischer URL-Knopf ist nicht eindeutig sendbar — ein
     * sprechender Fehler statt eines geratenen Index.
     */
    public function test_mehr_als_ein_dynamischer_knopf_liefert_fehler(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [
                ['type' => 'URL', 'url' => 'https://example.test/a/{{1}}'],
                ['type' => 'URL', 'url' => 'https://example.test/b/{{1}}'],
            ],
        ]];

        $result = ApplicantTemplateSender::buildTokenComponents($components, 'tok123');

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
        $this->assertSame([], $result['components']);
    }

    /** Genau ein dynamischer Knopf an Position 0 — der bisherige Normalfall bleibt richtig. */
    public function test_buildTokenComponents_normalfall_position_null(): void
    {
        $components = [[
            'type' => 'BUTTONS',
            'buttons' => [['type' => 'URL', 'url' => 'https://example.test/bewerbung/{{1}}']],
        ]];

        $result = ApplicantTemplateSender::buildTokenComponents($components, 'tok123');

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['components'][0]['index']);
    }

    /** Kein dynamischer Knopf — leere Komponenten, kein Fehler. */
    public function test_buildTokenComponents_ohne_dynamischen_knopf_ist_leer_und_ok(): void
    {
        $result = ApplicantTemplateSender::buildTokenComponents([], 'tok123');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame([], $result['components']);
    }
}
