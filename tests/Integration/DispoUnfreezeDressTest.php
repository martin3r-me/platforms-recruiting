<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Console\Commands\DispoUnfreezeDress;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;

/**
 * Fix-Runde 3, Befund 4: ein beim ersten Versand falsch gesetztes Paket war
 * nicht mehr korrigierbar — freeze() ueberspringt alles mit gesetztem
 * dress_frozen_at, und es gab keinen Weg zurueck ausser SQL auf prod.
 *
 * Geprueft wird die reine Logik (DispoUnfreezeDress::unfreeze()) ohne
 * Artisan-Lebenszyklus — Muster DispoResetCommand/DispoEscalateCommand.
 */
class DispoUnfreezeDressTest extends DressTestCase
{
    public function test_dry_run_lists_without_clearing(): void
    {
        [$event, $service, $logistik] = $this->frozenEvent();

        $result = DispoUnfreezeDress::unfreeze((int) $event->id, null, true);

        $this->assertNotNull($result['event']);
        $this->assertCount(2, $result['rows'], 'Beide festgeschriebenen Einbuchungen werden aufgefuehrt.');
        $this->assertSame(0, $result['cleared']);
        $this->assertNotNull(RecDispoAssignment::find($service->id)->dress_frozen_at,
            'Der Probelauf darf nichts anfassen.');
        $this->assertSame('Hoodie', RecDispoAssignment::find($logistik->id)->dress_items_text);
    }

    public function test_live_run_clears_reference_timestamp_and_text_copy(): void
    {
        [$event, $service, $logistik] = $this->frozenEvent();

        $result = DispoUnfreezeDress::unfreeze((int) $event->id, null, false);

        $this->assertSame(2, $result['cleared']);
        foreach ([$service, $logistik] as $assignment) {
            $frisch = RecDispoAssignment::find($assignment->id);
            $this->assertNull($frisch->rec_dispo_dress_package_id);
            $this->assertNull($frisch->dress_frozen_at);
            $this->assertNull($frisch->dress_items_text, 'Auch die Textkopie muss weg, sonst bleibt der alte Wortlaut stehen.');
        }
    }

    public function test_taetigkeit_option_limits_the_run(): void
    {
        [$event, $service, $logistik] = $this->frozenEvent();

        $result = DispoUnfreezeDress::unfreeze((int) $event->id, 'Logistik', false);

        $this->assertSame(1, $result['cleared']);
        $this->assertNull(RecDispoAssignment::find($logistik->id)->dress_frozen_at);
        $this->assertNotNull(RecDispoAssignment::find($service->id)->dress_frozen_at,
            'Die andere Taetigkeit bleibt festgeschrieben.');
    }

    public function test_unknown_event_is_reported_as_missing(): void
    {
        $result = DispoUnfreezeDress::unfreeze(999999, null, true);

        $this->assertNull($result['event']);
        $this->assertSame([], $result['rows']);
    }

    /**
     * Eine VA mit zwei festgeschriebenen Einbuchungen (Service + Logistik).
     *
     * @return array{0: \Platform\Recruiting\Models\RecDispoEvent, 1: RecDispoAssignment, 2: RecDispoAssignment}
     */
    private function frozenEvent(): array
    {
        $event = $this->event();
        $paketService  = $this->package('Standard', 'weisses Hemd');
        $paketLogistik = $this->package('Logistik', 'Hoodie');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service',
            'rec_dispo_dress_package_id' => $paketService->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $paketLogistik->id,
        ]);
        $service  = $this->assignment($event, ['taetigkeit' => 'Service']);
        $logistik = $this->assignment($event, ['taetigkeit' => 'Logistik']);

        (new DispoDressResolver())->freeze([$service->id, $logistik->id]);

        return [$event, $service, $logistik];
    }
}
