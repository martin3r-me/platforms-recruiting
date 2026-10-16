<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\Comms\ThemenAusloeser;

final class ThemenAusloeserTest extends TestCase
{
    private function k(array $o = []): array
    {
        return $o + [
            'enabled' => true, 'hr_kanal' => true, 'aktiver_ma' => true,
            'quick_reply' => false, 'message_type' => 'text',
            'letzte_aktivitaet_ts' => null, 'jetzt_ts' => 1_000_000, 'ruhe_stunden' => 8,
            'fremder_kontext' => false,
        ];
    }

    public function test_loest_aus_wenn_alles_passt(): void
    {
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k()));
    }

    public function test_gruende(): void
    {
        $this->assertSame('schalter_aus', ThemenAusloeser::qualifiziert($this->k(['enabled' => false])));
        $this->assertSame('kein_hr_kanal', ThemenAusloeser::qualifiziert($this->k(['hr_kanal' => false])));
        $this->assertSame('kein_aktiver_ma', ThemenAusloeser::qualifiziert($this->k(['aktiver_ma' => false])));
        $this->assertSame('button_klick', ThemenAusloeser::qualifiziert($this->k(['quick_reply' => true])));
        $this->assertSame('sprachnachricht', ThemenAusloeser::qualifiziert($this->k(['message_type' => 'audio'])));
        $this->assertSame('fremder_kontext', ThemenAusloeser::qualifiziert($this->k(['fremder_kontext' => true])));
    }

    public function test_bilder_und_dokumente_loesen_aus(): void
    {
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k(['message_type' => 'image'])));
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k(['message_type' => 'document'])));
    }

    public function test_ruhezeit_in_beide_richtungen(): void
    {
        $jetzt = 1_000_000;
        $this->assertSame('ruhezeit', ThemenAusloeser::qualifiziert($this->k(['letzte_aktivitaet_ts' => $jetzt - 7 * 3600, 'jetzt_ts' => $jetzt])));
        $this->assertNull(ThemenAusloeser::qualifiziert($this->k(['letzte_aktivitaet_ts' => $jetzt - 8 * 3600 - 1, 'jetzt_ts' => $jetzt])));
        $this->assertSame('ruhezeit', ThemenAusloeser::qualifiziert($this->k(['letzte_aktivitaet_ts' => $jetzt - 8 * 3600, 'jetzt_ts' => $jetzt])), 'genau 8h ist noch Ruhezeit');
    }
}
