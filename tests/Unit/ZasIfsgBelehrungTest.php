<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * IfSG-Belehrung als eigenes Feld am Mitarbeiter (Befund 23.09.2026).
 *
 * Bisher rechnete der Export `FolgeBescheinigungAm`/`InfekGueltigBis` aus dem
 * unterschriebenen IfSG-Vertrag der BEWERBUNG. ZAS-Bestandsmitarbeiter haben
 * keine Bewerbung — ZAS liefert beide Werte aber zu 86 % (Lieferung 608).
 * Jetzt: gespeichertes Feld zuerst, der Vertrag bleibt Rueckfall.
 */
class ZasIfsgBelehrungTest extends TestCase
{
    private function map(array $row): array
    {
        $lookups = new class extends ZasLookupReverseResolver {
            protected function loadPairs(string $lookupName): array
            {
                return [];
            }
        };

        return (new ZasInboundRowMapper($lookups))->map($row);
    }

    /** Resolver ohne DB; der Vertrags-Rueckfall ist per Parameter steuerbar. */
    private function resolver(?Carbon $contractSignedAt = null): object
    {
        return new class($contractSignedAt) extends ZasEmployeeFieldResolver {
            public function __construct(private ?Carbon $signed) {}

            protected function ifsgSignedAt(RecEmployee $employee): ?Carbon
            {
                return $this->signed;
            }

            public function col(RecEmployee $e, string $column): ?string
            {
                return $this->resolveColumn($e, null, $column);
            }
        };
    }

    public function test_inbound_reads_both_ifsg_dates(): void
    {
        $res = $this->map(['FolgeBescheinigungAm' => '14.03.2025', 'InfekGueltigBis' => '14.03.2027']);

        $this->assertSame('2025-03-14', $res['employee']['infection_protection_instructed_at']);
        $this->assertSame('2027-03-14', $res['employee']['infection_protection_valid_until']);
    }

    public function test_inbound_leaves_empty_ifsg_dates_unset(): void
    {
        $res = $this->map(['FolgeBescheinigungAm' => '', 'InfekGueltigBis' => '']);

        $this->assertArrayNotHasKey('infection_protection_instructed_at', $res['employee']);
        $this->assertArrayNotHasKey('infection_protection_valid_until', $res['employee']);
    }

    public function test_both_columns_count_as_read_in_the_column_report(): void
    {
        $this->assertContains('FolgeBescheinigungAm', ZasInboundRowMapper::knownColumns());
        $this->assertContains('InfekGueltigBis', ZasInboundRowMapper::knownColumns());
    }

    public function test_export_prefers_the_stored_dates(): void
    {
        // Rohwerte statt fill(): der Datums-Cast braucht beim Setzen eine DB-Verbindung.
        $e = new RecEmployee();
        $e->setRawAttributes([
            'infection_protection_instructed_at' => '2025-03-14',
            'infection_protection_valid_until'   => '2027-03-14',
        ]);
        $r = $this->resolver(Carbon::parse('2020-01-01'));

        $this->assertSame('14.03.2025', $r->col($e, 'FolgeBescheinigungAm'));
        $this->assertSame('14.03.2027', $r->col($e, 'InfekGueltigBis'));
    }

    public function test_export_falls_back_to_the_ifsg_contract(): void
    {
        // Funnel-Mitarbeiter ohne gepflegtes Feld: Verhalten wie bisher.
        $r = $this->resolver(Carbon::parse('2026-05-10'));
        $e = new RecEmployee([]);

        $this->assertSame('10.05.2026', $r->col($e, 'FolgeBescheinigungAm'));
        $this->assertSame('10.05.2028', $r->col($e, 'InfekGueltigBis'));
    }

    public function test_export_is_empty_without_field_and_contract(): void
    {
        $e = new RecEmployee([]);

        $this->assertNull($this->resolver()->col($e, 'FolgeBescheinigungAm'));
        $this->assertNull($this->resolver()->col($e, 'InfekGueltigBis'));
    }

    public function test_hr_changes_mark_the_employee_for_export(): void
    {
        $this->assertContains('infection_protection_instructed_at', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
        $this->assertContains('infection_protection_valid_until', RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS);
    }

    public function test_fields_are_fillable_and_cast_as_dates(): void
    {
        $e = new RecEmployee();

        foreach (['infection_protection_instructed_at', 'infection_protection_valid_until'] as $field) {
            $this->assertTrue($e->isFillable($field), $field);
            $this->assertSame('date', $e->getCasts()[$field] ?? null, $field);
        }
    }
}
