<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;

/**
 * Der MA-Export schickt in `Nation` die Staatsangehoerigkeit — wie der
 * Bewerber-Export (ZasFieldResolver::Nation nimmt `nationalitaet`, dann
 * `geburtsland`). Bisher ging hier das Geburtsland raus.
 */
class ZasNationalityExportTest extends TestCase
{
    private function resolver(): object
    {
        return new class extends ZasEmployeeFieldResolver {
            public function __construct() {}

            protected function loadLookupMap(string $lookupName): array
            {
                return $lookupName === 'geburtsland'
                    ? ['de' => 'Deutschland', 'bd' => 'Bangladesch', 'tr' => 'Türkei']
                    : [];
            }

            public function col(RecEmployee $e, string $column): ?string
            {
                return $this->resolveColumn($e, null, $column);
            }
        };
    }

    public function test_nation_exports_the_nationality(): void
    {
        $e = new RecEmployee(['nationality' => 'bd', 'birth_country' => 'tr']);

        $this->assertSame('Bangladesch', $this->resolver()->col($e, 'Nation'));
    }

    public function test_nation_falls_back_to_birth_country_when_nationality_is_empty(): void
    {
        // Bestand aus dem ZAS-Import: dort steht die Staatsangehoerigkeit noch
        // im Geburtsland-Feld, bis der Backfill sie umgezogen hat.
        $e = new RecEmployee(['nationality' => null, 'birth_country' => 'tr']);

        $this->assertSame('Türkei', $this->resolver()->col($e, 'Nation'));
    }

    public function test_nation_is_empty_when_both_are_empty(): void
    {
        $e = new RecEmployee(['nationality' => null, 'birth_country' => null]);

        $this->assertNull($this->resolver()->col($e, 'Nation'));
    }

    public function test_observer_marks_nationality_changes_for_export(): void
    {
        $this->assertContains('nationality', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
    }
}
