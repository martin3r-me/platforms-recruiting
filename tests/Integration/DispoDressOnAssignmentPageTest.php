<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoEventDress;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;
use Platform\Recruiting\Support\DressPanels;

/**
 * Zusammenspiel Resolver + Panel-Regel, so wie eventGroups() es verdrahtet:
 * mit Paket verschwindet der ZAS-Text, ohne Paket bleibt die Seite wie heute.
 */
class DispoDressOnAssignmentPageTest extends DressTestCase
{
    public function test_package_replaces_the_zas_text_for_the_whole_event(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'Ausweis mitnehmen']);
        $paket = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => RecDispoEventDress::ALL,
            'rec_dispo_dress_package_id' => $paket->id,
        ]);
        $tag1 = $this->assignment($event, ['datum' => '2026-10-01']);
        $tag2 = $this->assignment($event, ['datum' => '2026-10-02']);

        $panels = $this->panelsFor([$tag1, $tag2], $event->dresscode, $event->hinweis);

        $this->assertSame(
            ['heading' => DressPanels::HEADING_PACKAGE, 'text' => 'Hoodie; Sicherheitsschuhe'],
            $panels['group']
        );
        $this->assertSame('Ausweis mitnehmen', $panels['hinweis']);
    }

    public function test_service_and_logistics_on_different_days_split_the_panel(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd']);
        $service  = $this->package('Standard', 'weisses Hemd; schwarze Hose');
        $logistik = $this->package('Logistik', 'Hoodie; Sicherheitsschuhe');
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Service',
            'rec_dispo_dress_package_id' => $service->id,
        ]);
        RecDispoEventDress::create([
            'rec_dispo_event_id' => $event->id, 'taetigkeit' => 'Logistik',
            'rec_dispo_dress_package_id' => $logistik->id,
        ]);
        $tag1 = $this->assignment($event, ['datum' => '2026-10-01', 'taetigkeit' => 'Service']);
        $tag2 = $this->assignment($event, ['datum' => '2026-10-02', 'taetigkeit' => 'Logistik']);

        $panels = $this->panelsFor([$tag1, $tag2], $event->dresscode, $event->hinweis);

        $this->assertNull($panels['group']);
        $this->assertSame('weisses Hemd; schwarze Hose', $panels['perDay'][$tag1->id]['text']);
        $this->assertSame('Hoodie; Sicherheitsschuhe', $panels['perDay'][$tag2->id]['text']);
    }

    public function test_without_any_package_nothing_changes(): void
    {
        $event = $this->event(['dresscode' => 'Bitte folgende Kleidung: weisses Hemd', 'hinweis' => 'wird nicht gezeigt']);
        $tag = $this->assignment($event);

        $panels = $this->panelsFor([$tag], $event->dresscode, $event->hinweis);

        $this->assertSame(
            ['heading' => DressPanels::HEADING_ZAS, 'text' => 'Bitte folgende Kleidung: weisses Hemd'],
            $panels['group']
        );
        $this->assertNull($panels['hinweis']);
    }

    /** @param list<\Platform\Recruiting\Models\RecDispoAssignment> $assignments */
    private function panelsFor(array $assignments, ?string $zas, ?string $hinweis): array
    {
        $packages = (new DispoDressResolver())->forAssignments($assignments);
        $days = [];
        foreach ($assignments as $a) {
            $days[$a->id] = $packages[$a->id]?->items_text;
        }

        return DressPanels::build($days, $zas, $hinweis);
    }
}
