<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Models\RecDispoEventDress;

/**
 * Zuordnungstabelle: je VA hoechstens eine Zeile pro Taetigkeit, und der
 * Leerstring ist die VA-weite Vorgabe.
 */
class DispoEventDressTest extends DressTestCase
{
    public function test_va_wide_row_uses_the_empty_string_sentinel(): void
    {
        $event = $this->event();
        $package = $this->package('Standard', 'weisses Hemd');

        $row = RecDispoEventDress::create([
            'rec_dispo_event_id'         => $event->id,
            'taetigkeit'                 => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $package->id,
        ]);

        $this->assertSame('', $row->taetigkeit);
        $this->assertNotEmpty($row->uuid);
        $this->assertSame('Standard', $row->package->name);
    }

    public function test_the_same_taetigkeit_cannot_be_assigned_twice_in_one_event(): void
    {
        $event = $this->event();
        $a = $this->package('A', 'weisses Hemd');
        $b = $this->package('B', 'schwarzes Hemd');

        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $a->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $b->id,
        ]);
    }

    public function test_new_columns_exist_and_default_to_null(): void
    {
        $event = $this->event();
        $assignment = $this->assignment($event);

        $this->assertNull($assignment->rec_dispo_dress_package_id);
        $this->assertNull($assignment->dress_frozen_at);
        $this->assertNull(Capsule::table('rec_dispo_events')->where('id', $event->id)->value('hinweis'));
        $this->assertNull(Capsule::table('rec_dispo_events')->where('id', $event->id)->value('dresscode_ack_at'));
    }
}
