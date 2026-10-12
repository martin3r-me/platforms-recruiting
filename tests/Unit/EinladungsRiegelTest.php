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
        $this->assertSame(['keine Nummer', 'kein Geburtsdatum', 'kein WhatsApp'], EinladungsRiegel::GRUENDE);
    }
}
