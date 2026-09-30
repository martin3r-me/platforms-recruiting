<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;

/**
 * Vorrang: festgeschrieben → Taetigkeit → VA-weit → nichts.
 */
class DispoDressResolverTest extends DressTestCase
{
    public function test_taetigkeit_beats_va_wide(): void
    {
        $event = $this->event();
        $standard = $this->package('Standard', 'weisses Hemd');
        $logistik = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');

        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $standard->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);

        $service = $this->assignment($event, ['taetigkeit' => 'Service']);
        $logi    = $this->assignment($event, ['taetigkeit' => 'Logistik']);

        $result = (new DispoDressResolver())->forAssignments([$service, $logi]);

        $this->assertSame('Standard', $result[$service->id]->name);
        $this->assertSame('Logistik', $result[$logi->id]->name);
    }

    public function test_without_any_row_there_is_no_package(): void
    {
        $event = $this->event();
        $assignment = $this->assignment($event);

        $result = (new DispoDressResolver())->forAssignments([$assignment]);

        $this->assertNull($result[$assignment->id]);
    }

    public function test_frozen_package_wins_over_later_changes(): void
    {
        $event = $this->event();
        $alt = $this->package('Alt', 'altes Hemd');
        $neu = $this->package('Neu', 'neues Hemd');

        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $alt->id,
        ]);
        $assignment = $this->assignment($event);

        $this->assertSame(1, (new DispoDressResolver())->freeze([$assignment->id]));

        // Die Dispo haengt jetzt ein anderes Paket an die VA.
        RecDispoEventDress::query()->where('rec_dispo_event_id', $event->id)
            ->update(['rec_dispo_dress_package_id' => $neu->id]);

        $result = (new DispoDressResolver())->forAssignments([$assignment->fresh()]);
        $this->assertSame('Alt', $result[$assignment->id]->name, 'Bestaetigt bleibt bestaetigt.');
    }

    public function test_freeze_skips_assignments_without_a_package_and_does_not_restamp(): void
    {
        $event = $this->event();
        $ohne = $this->assignment($event);

        $this->assertSame(0, (new DispoDressResolver())->freeze([$ohne->id]));

        $paket = $this->package('Standard', 'weisses Hemd');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);

        $this->assertSame(1, (new DispoDressResolver())->freeze([$ohne->id]));
        $stamp = RecDispoAssignment::find($ohne->id)->dress_frozen_at;
        $this->assertNotNull($stamp);

        $this->assertSame(0, (new DispoDressResolver())->freeze([$ohne->id]), 'Zweiter Lauf stempelt nicht neu.');
    }

    public function test_deactivated_package_still_resolves(): void
    {
        $event = $this->event();
        $paket = $this->package('Ausgemustert', 'gruenes Hemd');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $paket->update(['is_active' => false]);
        $assignment = $this->assignment($event);

        $result = (new DispoDressResolver())->forAssignments([$assignment]);

        $this->assertSame('Ausgemustert', $result[$assignment->id]->name,
            'Deaktivieren nimmt nur aus der Auswahl, es zieht nichts zurueck.');
    }
}
