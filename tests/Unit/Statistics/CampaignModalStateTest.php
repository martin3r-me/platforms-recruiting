<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;

/**
 * Der Kampagnen-Bereich ist nur im Scope „Ohne Termin“ erreichbar — die
 * anderen Drill-Downs (gebucht, unterschrieben, Termin-Teilnehmer) zeigen ihn
 * nicht. Geprueft ohne Container (new Index(), Muster FremdeFilialeReasonTextTest).
 */
final class CampaignModalStateTest extends TestCase
{
    public function testKampagneNurImScopeOhneSchulung(): void
    {
        $c = new Index();
        $c->drillIds = [1, 2];

        $c->drillScopeType = 'ohne_schulung';
        $this->assertTrue($c->campaignEnabled());

        $c->drillScopeType = 'schulung';
        $this->assertFalse($c->campaignEnabled());

        $c->drillScopeType = 'ohne_schulung';
        $c->drillIds = [];
        $this->assertFalse($c->campaignEnabled(), 'Leere Auswahl → kein Button.');
    }

    public function testDefaultsDerProperties(): void
    {
        $c = new Index();
        $this->assertSame('', $c->drillScopeType);
        $this->assertSame([], $c->campaignSelection);
        $this->assertNull($c->campaignUuid);
        $this->assertSame('', $c->campaignError);
    }
}
