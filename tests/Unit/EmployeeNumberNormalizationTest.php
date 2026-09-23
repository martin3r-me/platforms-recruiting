<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\ApplicantEmployeeFieldMapping;

/**
 * Die Normalisierung greift am Modell — damit jeder Schreibweg sie mitnimmt:
 * MA-Portal, HR-Akte, Anlage aus der Bewerbung und der ZAS-Import.
 *
 * Kein DB-Zugriff noetig: Mutatoren wirken beim Setzen, nicht beim Speichern.
 */
class EmployeeNumberNormalizationTest extends TestCase
{
    public function test_tax_id_loses_its_spaces_on_assignment(): void
    {
        $e = new RecEmployee();
        $e->steuer_id = '12 345 678 901';

        $this->assertSame('12345678901', $e->steuer_id);
    }

    public function test_social_security_number_loses_its_spaces_on_assignment(): void
    {
        $e = new RecEmployee();
        $e->sozialversicherungsnummer = '12 190367 K 123';

        $this->assertSame('12190367K123', $e->sozialversicherungsnummer);
    }

    public function test_mass_assignment_is_normalized_too(): void
    {
        // Der Weg, den Portal, HR-Maske und Import nehmen.
        $e = new RecEmployee(['steuer_id' => '12 345 678 901', 'sozialversicherungsnummer' => '12 190367 K 123']);

        $this->assertSame('12345678901', $e->steuer_id);
        $this->assertSame('12190367K123', $e->sozialversicherungsnummer);
    }

    public function test_empty_values_stay_null(): void
    {
        $e = new RecEmployee(['steuer_id' => '  ', 'sozialversicherungsnummer' => null]);

        $this->assertNull($e->steuer_id);
        $this->assertNull($e->sozialversicherungsnummer);
    }

    public function test_applicant_mapping_delivers_clean_values(): void
    {
        // Zweite Absicherung: das Mapping liefert schon sauber, damit auch
        // Auswertungen darauf nicht die Rohform sehen.
        $r = ApplicantEmployeeFieldMapping::resolve([
            'steuer_id'                 => '12 345 678 901',
            'sozialversicherungsnummer' => '12 190367 K 123',
        ]);

        $this->assertSame('12345678901', $r['steuer_id']);
        $this->assertSame('12190367K123', $r['sozialversicherungsnummer']);
    }
}
