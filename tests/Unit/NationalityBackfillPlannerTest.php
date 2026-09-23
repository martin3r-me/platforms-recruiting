<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NationalityBackfillPlanner;

/**
 * Entscheidungslogik des Bestands-Backfills fuer `nationality`, ohne DB.
 *
 * Zwei Herkuenfte, zwei Regeln:
 *  - Funnel-MA: Staatsangehoerigkeit aus dem Bewerber-Feld; das Geburtsland
 *    ist echt und bleibt.
 *  - Import-MA (rec_zas_inbound_file_id gesetzt): in `birth_country` steht
 *    ZAS' `Nation`, also die Staatsangehoerigkeit — sie wandert um, und das
 *    Geburtsland wird leer, weil es dort nie eines gab.
 */
class NationalityBackfillPlannerTest extends TestCase
{
    public function test_existing_nationality_is_never_overwritten(): void
    {
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => 'de',
            'birth_country'           => 'tr',
            'rec_zas_inbound_file_id' => 5,
            'applicant_nationality'   => 'bd',
        ]);

        $this->assertNull($plan);
    }

    public function test_funnel_employee_takes_the_applicant_value_and_keeps_birth_country(): void
    {
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => null,
            'birth_country'           => 'tr',
            'rec_zas_inbound_file_id' => null,
            'applicant_nationality'   => 'de',
        ]);

        $this->assertSame(['nationality' => 'de'], $plan);
    }

    public function test_imported_employee_moves_birth_country_into_nationality(): void
    {
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => null,
            'birth_country'           => 'de',
            'rec_zas_inbound_file_id' => 7,
            'applicant_nationality'   => null,
        ]);

        $this->assertSame(['nationality' => 'de', 'birth_country' => null], $plan);
    }

    public function test_paired_import_row_prefers_the_applicant_value_and_still_clears_birth_country(): void
    {
        // Zeile stammt aus dem Import (birth_country = ZAS-Nation), der
        // Bewerber kennt die echte Staatsangehoerigkeit.
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => null,
            'birth_country'           => 'de',
            'rec_zas_inbound_file_id' => 7,
            'applicant_nationality'   => 'bd',
        ]);

        $this->assertSame(['nationality' => 'bd', 'birth_country' => null], $plan);
    }

    public function test_funnel_employee_without_applicant_value_is_left_alone(): void
    {
        // Ein echtes Geburtsland darf nie zur Staatsangehoerigkeit erklaert werden.
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => null,
            'birth_country'           => 'tr',
            'rec_zas_inbound_file_id' => null,
            'applicant_nationality'   => null,
        ]);

        $this->assertNull($plan);
    }

    public function test_imported_employee_without_birth_country_is_left_alone(): void
    {
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => null,
            'birth_country'           => null,
            'rec_zas_inbound_file_id' => 7,
            'applicant_nationality'   => null,
        ]);

        $this->assertNull($plan);
    }

    public function test_blank_strings_count_as_empty(): void
    {
        $plan = NationalityBackfillPlanner::plan([
            'nationality'             => '',
            'birth_country'           => 'de',
            'rec_zas_inbound_file_id' => 7,
            'applicant_nationality'   => '',
        ]);

        $this->assertSame(['nationality' => 'de', 'birth_country' => null], $plan);
    }
}
