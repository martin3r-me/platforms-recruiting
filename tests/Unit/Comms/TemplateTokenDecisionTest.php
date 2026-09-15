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
}
