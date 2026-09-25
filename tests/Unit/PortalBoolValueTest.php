<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalBoolValue;

/**
 * EINE Stelle, die entscheidet, was ein Ja/Nein-Feld aus dem Portal
 * bedeutet. Vorher gab es drei: die Bool-Umwandlung in
 * EmployeePortal::saveAll(), den MainEmployerRequiredGuard und den
 * FirstAiderDateGuard — und sie sind auseinandergelaufen (Befund Review
 * 25.09.2026: 'nein' galt dem einen als "beantwortet ja", der andere
 * schrieb false; 'Ja' passierte den Guard und landete als NULL).
 *
 * Dreiwertig, weil die Spalten es sind: ja / nein / unbeantwortet.
 */
final class PortalBoolValueTest extends TestCase
{
    public function test_ja_schreibweisen(): void
    {
        foreach (['1', 'true', 'ja', 'JA', ' Ja ', true] as $wert) {
            $this->assertTrue(PortalBoolValue::parse($wert), var_export($wert, true));
        }
    }

    public function test_nein_schreibweisen(): void
    {
        foreach (['0', 'false', 'nein', 'NEIN', ' Nein ', false] as $wert) {
            $this->assertFalse(PortalBoolValue::parse($wert), var_export($wert, true));
        }
    }

    public function test_unbeantwortet(): void
    {
        foreach (['', '   ', null, 'x', '2', 'vielleicht', 'yes', 'no'] as $wert) {
            $this->assertNull(PortalBoolValue::parse($wert), var_export($wert, true));
        }
    }
}
