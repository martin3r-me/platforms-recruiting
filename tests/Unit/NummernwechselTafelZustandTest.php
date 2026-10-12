<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NummernwechselTafel;

final class NummernwechselTafelZustandTest extends TestCase
{
    public function test_jede_verzweigung(): void
    {
        $offen = (object) ['merged_into_person_id' => null, 'locked_at' => null, 'wechsel_quelle' => 'notfall'];
        $this->assertSame(NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::zustand($offen));
        $this->assertSame(NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::zustand($offen, true));
        $this->assertSame(NummernwechselTafel::ZUSTAND_NOTFALLWEG_AUS, NummernwechselTafel::zustand($offen, false));

        $andereQuelle = (object) ['merged_into_person_id' => null, 'locked_at' => null, 'wechsel_quelle' => 'kuenftig'];
        $this->assertSame(NummernwechselTafel::ZUSTAND_OFFEN, NummernwechselTafel::zustand($andereQuelle, false), 'der Schalter betrifft nur Weg 4');

        $gesperrt = (object) ['merged_into_person_id' => null, 'locked_at' => '2026-10-01 00:00:00', 'wechsel_quelle' => 'notfall'];
        $this->assertSame('gesperrt, wird nie angewendet', NummernwechselTafel::zustand($gesperrt, false), 'gesperrt sticht den Schalter');

        $still = (object) ['merged_into_person_id' => 3, 'locked_at' => null, 'wechsel_quelle' => 'notfall'];
        $this->assertSame('stillgelegt, wird nie angewendet', NummernwechselTafel::zustand($still, false));
    }
}
