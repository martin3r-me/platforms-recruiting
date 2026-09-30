<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Paket-Katalog: UUID wird gestempelt, deaktivierte Pakete fallen aus der
 * Auswahl, bleiben aber lesbar.
 */
class DispoDressPackageTest extends DressTestCase
{
    public function test_creating_stamps_a_uuid(): void
    {
        $package = $this->package('Standard schwarz-weiss', 'weisses Hemd; schwarze Hose');

        $this->assertNotEmpty($package->uuid);

        // fresh(): is_active und sort_order stehen als DB-Defaults in der
        // Migration, nicht in den uebergebenen Attributen — das frisch
        // erzeugte Model kennt sie noch nicht.
        $reloaded = $package->fresh();
        $this->assertTrue($reloaded->is_active, 'Neue Pakete sind aktiv.');
        $this->assertSame(0, $reloaded->sort_order);
    }

    public function test_active_scope_hides_deactivated_packages(): void
    {
        $this->package('Aktiv', 'weisses Hemd');
        $alt = $this->package('Ausgemustert', 'gruenes Hemd');
        $alt->update(['is_active' => false]);

        $names = RecDispoDressPackage::query()->active()->pluck('name')->all();

        $this->assertSame(['Aktiv'], $names);
        $this->assertNotNull(
            RecDispoDressPackage::find($alt->id),
            'Deaktiviert heisst nicht geloescht — bestehende Zuordnungen brauchen den Datensatz.'
        );
    }
}
