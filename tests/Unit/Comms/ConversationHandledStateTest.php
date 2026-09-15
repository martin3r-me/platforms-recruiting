<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ConversationHandledState;

/**
 * Task 2: Ein abgehakter Chat bleibt abgehakt — bis die Person erneut
 * schreibt. Das ist ein reiner Zeitvergleich, kein Job und kein zweites
 * Zustandsfeld, das veralten koennte.
 */
class ConversationHandledStateTest extends TestCase
{
    private const T10 = 1_757_930_000; // irgendein fester Zeitpunkt
    private const T11 = 1_757_933_600; // eine Stunde spaeter

    public function test_ohne_stempel_nie_erledigt(): void
    {
        $this->assertFalse(ConversationHandledState::isHandled(null, self::T10));
    }

    public function test_gestempelt_und_kein_neuer_eingang_ist_erledigt(): void
    {
        $this->assertTrue(ConversationHandledState::isHandled(self::T11, self::T10));
    }

    public function test_neuer_eingang_nach_dem_stempel_hebt_ihn_auf(): void
    {
        $this->assertFalse(ConversationHandledState::isHandled(self::T10, self::T11));
    }

    public function test_eingang_exakt_auf_dem_stempel_bleibt_erledigt(): void
    {
        // Gleichstand zaehlt als "war beim Abhaken schon da" — sonst springt
        // ein Chat allein durch Sekundengenauigkeit zurueck in die Liste.
        $this->assertTrue(ConversationHandledState::isHandled(self::T10, self::T10));
    }

    public function test_gestempelt_ohne_jeden_eingang_ist_erledigt(): void
    {
        $this->assertTrue(ConversationHandledState::isHandled(self::T10, null));
    }
}
