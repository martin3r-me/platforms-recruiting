<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Console\Commands\DispoSeedDressPackages;
use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Startbestand: Markus' Liste vom 29.09.2026, Trenner vereinheitlicht, die
 * doppelte Schuerze in "Standard schwarz-schwarz" entfernt (Tippfehler,
 * Kundenentscheid 30.09.).
 */
class DispoSeedDressPackagesTest extends DressTestCase
{
    public function test_the_catalogue_carries_markus_eleven_packages(): void
    {
        $this->assertCount(11, DispoSeedDressPackages::PACKAGES);

        $names = array_column(DispoSeedDressPackages::PACKAGES, 'name');
        $this->assertContains('Standard schwarz-weiß', $names);
        $this->assertContains('Logistik', $names);
        $this->assertContains('Lanxess Arena', $names);
    }

    public function test_no_package_lists_the_same_item_twice(): void
    {
        foreach (DispoSeedDressPackages::PACKAGES as $package) {
            $items = array_map('trim', explode(';', $package['items']));
            $normalised = array_map('mb_strtolower', $items);

            $this->assertSame(
                count($normalised),
                count(array_unique($normalised)),
                "Doppelter Eintrag in Paket „{$package['name']}\u{201c}"
            );
        }
    }

    public function test_seeding_is_idempotent(): void
    {
        $created = DispoSeedDressPackages::seed(self::TEAM);
        $this->assertSame(11, $created);
        $this->assertSame(11, RecDispoDressPackage::query()->count());

        $again = DispoSeedDressPackages::seed(self::TEAM);
        $this->assertSame(0, $again, 'Zweiter Lauf legt nichts erneut an.');
        $this->assertSame(11, RecDispoDressPackage::query()->count());
    }
}
