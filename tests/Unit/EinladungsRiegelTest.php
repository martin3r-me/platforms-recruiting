<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinladungsRiegel;

final class EinladungsRiegelTest extends TestCase
{
    public function test_reihenfolge_der_gruende(): void
    {
        $this->assertSame('keine Nummer', EinladungsRiegel::grund(null, true, true), 'keine Nummer sticht alles');
        $this->assertSame('keine Nummer', EinladungsRiegel::grund('   ', false, false));
        $this->assertSame('kein Geburtsdatum', EinladungsRiegel::grund('+4915111111111', true, true));
        $this->assertSame('kein WhatsApp', EinladungsRiegel::grund('+4915111111111', false, true));
        $this->assertNull(EinladungsRiegel::grund('+4915111111111', false, false));
        // Pilot-Sperre (19.10.2026): nach "keine Nummer", vor allem anderen.
        $this->assertSame(EinladungsRiegel::KEIN_NEUES_PORTAL, EinladungsRiegel::grund('+4915111111111', true, true, true));
        $this->assertSame('keine Nummer', EinladungsRiegel::grund(null, false, false, true));
        $this->assertNull(EinladungsRiegel::grund('+4915111111111', false, false, false));
        $this->assertSame('kein neues Portal (portal_v2_since fehlt — erst recruiting:portal-umstellen)', EinladungsRiegel::KEIN_NEUES_PORTAL);
        $this->assertSame(['keine Nummer', EinladungsRiegel::KEIN_NEUES_PORTAL, 'kein Geburtsdatum', 'kein WhatsApp'], EinladungsRiegel::GRUENDE);
    }
}
