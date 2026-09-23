<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ApplicantEmployeeFieldMapping;

/**
 * Staatsangehoerigkeit wandert vom Bewerber (Extra-Feld `nationalitaet`,
 * Lookup `geburtsland`, ISO-Codes) in die eigene MA-Spalte `nationality`.
 *
 * Anlass (Clara, 28.08. / Abgleich 23.09.2026): Die Spalte fehlte an
 * rec_employees, der Wert ging beim Uebergang Bewerber -> Mitarbeiter
 * verloren, und der ZAS-Export schickte stattdessen das Geburtsland als
 * `Nation` — also eine falsche Staatsangehoerigkeit ins Lohnsystem.
 */
class ApplicantEmployeeNationalityMappingTest extends TestCase
{
    public function test_nationality_is_carried_from_the_applicant_field(): void
    {
        $resolved = ApplicantEmployeeFieldMapping::resolve(['nationalitaet' => 'bd']);

        $this->assertSame('bd', $resolved['nationality']);
    }

    public function test_nationality_and_birth_country_are_distinct_columns(): void
    {
        // Geboren in der Tuerkei, deutscher Pass — beides muss getrennt ankommen.
        $resolved = ApplicantEmployeeFieldMapping::resolve([
            'nationalitaet' => 'de',
            'geburtsland'   => 'tr',
        ]);

        $this->assertSame('de', $resolved['nationality']);
        $this->assertSame('tr', $resolved['birth_country']);
    }

    public function test_text_map_binds_nationality_to_the_form_field_name(): void
    {
        $this->assertSame('nationalitaet', ApplicantEmployeeFieldMapping::TEXT_MAP['nationality']);
    }
}
