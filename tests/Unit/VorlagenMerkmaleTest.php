<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VorlagenMerkmale;

class VorlagenMerkmaleTest extends TestCase
{
    private const LABELS = ['RG' => 'RheinGedeck'];

    public function test_zeile_mit_beiden_merkmalen(): void
    {
        $this->assertSame('RheinGedeck · Eventmitarbeiter', VorlagenMerkmale::zeile('RG', 'eventmitarbeiter', self::LABELS));
    }

    public function test_unbekannte_firma_zeigt_den_code_und_leer_bleibt_leer(): void
    {
        $this->assertSame('MA', VorlagenMerkmale::zeile('MA', null, self::LABELS));
        $this->assertSame('', VorlagenMerkmale::zeile(null, null, self::LABELS));
        $this->assertSame('Logistiker', VorlagenMerkmale::zeile('', 'logistiker', self::LABELS));
    }
}
