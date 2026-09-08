<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Support\EmployeeFileSlots;
use Platform\Recruiting\Support\ZasInboundFileSlots;

/**
 * Welche Dokumentslots ZAS bei uns BESCHREIBEN darf.
 *
 * Bewusst eine eigene, kurze Liste — nicht die 16 Slots, die wir ausliefern.
 * Der Eingang ist ein schreibender Endpunkt mit geteiltem Token; was dort
 * nicht ausdruecklich freigegeben ist, wird abgewiesen. Erweitern ist eine
 * Zeile, wenn irgendwann Ausweise dazukommen sollen.
 */
class ZasInboundFileSlotsTest extends TestCase
{
    public function test_only_the_selfie_slot_is_open(): void
    {
        $this->assertSame(['emp-selfie'], ZasInboundFileSlots::ALLOWED);
    }

    public function test_resolves_the_allowed_slot_to_its_employee_column(): void
    {
        $this->assertSame('selfie_file_id', ZasInboundFileSlots::columnFor('emp-selfie'));
    }

    public function test_refuses_slots_that_are_not_open_even_though_we_serve_them(): void
    {
        // Diese Slots kennt der Ausliefer-Endpunkt sehr wohl — beschreiben
        // darf ZAS sie trotzdem nicht.
        foreach (['emp-auweis', 'emp-versicher', 'emp-arberl', 'emp-vertrag'] as $slot) {
            $this->assertNull(ZasInboundFileSlots::columnFor($slot), "Slot '{$slot}' darf nicht offen sein.");
        }
    }

    public function test_refuses_unknown_and_malformed_slots(): void
    {
        foreach (['', 'selfie', 'upl-selfie', 'emp-selfie/../x', 'EMP-SELFIE'] as $slot) {
            $this->assertNull(ZasInboundFileSlots::columnFor($slot), "Slot '{$slot}' darf nicht aufgeloest werden.");
        }
    }

    public function test_every_open_slot_exists_in_the_outgoing_map_and_is_a_real_column(): void
    {
        // Drift-Schutz: der Eingang schreibt in dieselben Spalten, die der
        // Ausliefer-Endpunkt liest — und nur in solche, die auch anzeigbar
        // sind (sonst laege ein Bild im System, das niemand sehen kann).
        foreach (ZasInboundFileSlots::ALLOWED as $slot) {
            $column = ZasEmployeeFieldResolver::FILE_SLOT_FIELD_MAP[$slot] ?? null;
            $this->assertNotNull($column, "Slot '{$slot}' fehlt in FILE_SLOT_FIELD_MAP.");
            $this->assertContains($column, EmployeeFileSlots::COLUMNS, "Spalte '{$column}' ist nicht anzeigbar.");
            $this->assertSame($column, ZasInboundFileSlots::columnFor($slot));
        }
    }
}
