<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPhase;

/**
 * Der Buchungs-Dialog filtert per DB-Query auf allow_manual_booking. Fehlt das
 * Feld in $fillable, speichert die Checkbox auf der Stellen-Seite stillschweigend
 * nichts; fehlt der boolean-Cast, kommt aus MySQL 0/1 und aus SQLite true/false
 * zurueck — beides Fallen, die erst live auffallen.
 */
final class PhaseManualBookingFlagTest extends TestCase
{
    public function test_flag_ist_fillable(): void
    {
        $this->assertContains('allow_manual_booking', (new RecPhase())->getFillable());
    }

    public function test_flag_wird_boolean_gecastet(): void
    {
        $this->assertSame('boolean', (new RecPhase())->getCasts()['allow_manual_booking'] ?? null);
    }
}
