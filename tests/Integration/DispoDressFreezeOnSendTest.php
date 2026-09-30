<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;

/**
 * Was beim Versand galt, bleibt an der Einbuchung stehen — auch wenn jemand
 * das Paket spaeter umhaengt. Geprueft wird die Schreiboperation selbst; der
 * Versandweg (Meta) bleibt aussen vor.
 */
class DispoDressFreezeOnSendTest extends DressTestCase
{
    public function test_freeze_stamps_each_day_with_its_own_package(): void
    {
        $event = $this->event();
        $service  = $this->package('Standard', 'weisses Hemd');
        $logistik = $this->package('Logistik', 'Hoodie');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service',
            'rec_dispo_dress_package_id' => $service->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);
        $tag1 = $this->assignment($event, ['taetigkeit' => 'Service']);
        $tag2 = $this->assignment($event, ['taetigkeit' => 'Logistik']);

        $stamped = (new DispoDressResolver())->freeze([$tag1->id, $tag2->id]);

        $this->assertSame(2, $stamped);
        $this->assertSame($service->id, RecDispoAssignment::find($tag1->id)->rec_dispo_dress_package_id);
        $this->assertSame($logistik->id, RecDispoAssignment::find($tag2->id)->rec_dispo_dress_package_id,
            'Ein Versand, zwei Taetigkeiten, zwei Pakete.');
    }
}
