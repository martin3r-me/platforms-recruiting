<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\EmployeeSenderResolver;

/**
 * Reine Entscheidung "ist der WhatsApp-Absender ein Mitarbeiter?" —
 * gleiche Normalisierung wie die Dispo-Kommunikation (DispoPhoneMatcher).
 */
class EmployeeSenderResolverTest extends TestCase
{
    public function test_nationale_nummer_beim_mitarbeiter_trifft_internationalen_absender(): void
    {
        $result = EmployeeSenderResolver::decide('4915112345678', [7 => '0151 12345678', 8 => '0221 999888'], [], []);

        $this->assertSame(EmployeeSenderResolver::EMPLOYEE, $result['status']);
        $this->assertSame(7, $result['employee_id']);
    }

    public function test_fremde_nummer_ist_kein_mitarbeiter(): void
    {
        $result = EmployeeSenderResolver::decide('4917600000000', [7 => '0151 12345678'], [], []);

        $this->assertSame(EmployeeSenderResolver::NONE, $result['status']);
        $this->assertNull($result['employee_id']);
    }

    public function test_nur_endziffern_gleich_reicht_nicht(): void
    {
        // Exakter Vergleich nach Normalisierung — kein Suffix-Match.
        $result = EmployeeSenderResolver::decide('4315112345678', [7 => '0151 12345678'], [], []);

        $this->assertSame(EmployeeSenderResolver::NONE, $result['status']);
    }

    public function test_zwei_personen_mit_derselben_nummer_sind_mehrdeutig(): void
    {
        $result = EmployeeSenderResolver::decide('4915112345678', [7 => '0151 12345678', 9 => '+49 151 12345678'], [], []);

        $this->assertSame(EmployeeSenderResolver::AMBIGUOUS, $result['status']);
        $this->assertNull($result['employee_id']);
        $this->assertSame([7, 9], $result['employee_ids']);
    }

    public function test_zwei_datensaetze_derselben_person_per_crm_kontakt_sind_ein_treffer(): void
    {
        $result = EmployeeSenderResolver::decide(
            '4915112345678',
            [9 => '0151 12345678', 7 => '+49 151 12345678'],
            [7 => [7, 9], 9 => [7, 9]],
            [],
        );

        $this->assertSame(EmployeeSenderResolver::EMPLOYEE, $result['status']);
        $this->assertSame(7, $result['employee_id'], 'Kanonisch = kleinste id der Gruppe.');
    }

    public function test_zwei_datensaetze_derselben_person_per_person_key_sind_ein_treffer(): void
    {
        $result = EmployeeSenderResolver::decide(
            '4915112345678',
            [7 => '0151 12345678', 9 => '0151 12345678'],
            [],
            [7 => 'pk-abc', 9 => 'pk-abc'],
        );

        $this->assertSame(EmployeeSenderResolver::EMPLOYEE, $result['status']);
        $this->assertSame(7, $result['employee_id']);
    }

    public function test_gleiche_person_plus_fremde_person_bleibt_mehrdeutig(): void
    {
        $result = EmployeeSenderResolver::decide(
            '4915112345678',
            [7 => '0151 12345678', 9 => '0151 12345678', 11 => '0151 12345678'],
            [],
            [7 => 'pk-abc', 9 => 'pk-abc', 11 => 'pk-xyz'],
        );

        $this->assertSame(EmployeeSenderResolver::AMBIGUOUS, $result['status']);
    }

    public function test_leerer_absender_ist_kein_mitarbeiter(): void
    {
        $result = EmployeeSenderResolver::decide('', [7 => ''], [], []);

        $this->assertSame(EmployeeSenderResolver::NONE, $result['status']);
    }
}
