<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonPflichten;

/**
 * Die Pflichtliste gehoert dem Menschen, nicht der einzelnen Anstellung
 * (Spec 2.5). Geprueft wird deshalb genau das, was eine Vereinigung von einer
 * Einzelabfrage unterscheidet: dass nichts verschwindet, nur weil die andere
 * Anstellung es nicht verlangt.
 */
final class PersonPflichtenTest extends TestCase
{
    public function test_zwei_anstellungen_ergeben_die_vereinigung_der_pflichten(): void
    {
        $pflichten = PersonPflichten::vereinige([
            ['is_eu_citizen' => true,  'employment_type' => 'student',  'is_first_aider' => false],
            ['is_eu_citizen' => true,  'employment_type' => 'aushilfe', 'is_first_aider' => false],
        ]);

        // Die Immatrikulation kommt aus der einen Anstellung und darf nicht
        // verschwinden, nur weil die andere sie nicht verlangt.
        $this->assertContains('immatrikulation', $pflichten);
        $this->assertContains('ausweis', $pflichten);
    }

    /**
     * Zusatz zum Brief: der Test darueber traegt die Immatrikulation in der
     * ERSTEN Anstellung und bliebe deshalb gruen, wenn die Schleife nur das
     * erste Feld lesen wuerde. Dieselbe Lage mit vertauschter Reihenfolge
     * deckt die andere Richtung ab — gemessen, nicht vermutet: ohne diesen
     * Test ueberlebt die Mutation auf $anstellungen[0] den ersten Test.
     */
    public function test_auch_die_spaetere_anstellung_bringt_ihre_pflicht_mit(): void
    {
        $pflichten = PersonPflichten::vereinige([
            ['is_eu_citizen' => true, 'employment_type' => 'aushilfe', 'is_first_aider' => false],
            ['is_eu_citizen' => true, 'employment_type' => 'student',  'is_first_aider' => false],
        ]);

        $this->assertContains('immatrikulation', $pflichten);
    }

    public function test_eine_nicht_eu_anstellung_zieht_die_ganze_person_mit(): void
    {
        $pflichten = PersonPflichten::vereinige([
            ['is_eu_citizen' => true,  'employment_type' => null, 'is_first_aider' => false],
            ['is_eu_citizen' => false, 'employment_type' => null, 'is_first_aider' => false],
        ]);

        // Die Staatsangehoerigkeit gehoert zum Menschen, nicht zur Anstellung —
        // widersprechen sich die Datensaetze, gilt die strengere Lesart.
        $this->assertContains('aufenthaltstitel', $pflichten);
        $this->assertContains('arbeitsgenehmigung', $pflichten);
    }

    public function test_jede_pflicht_steht_genau_einmal(): void
    {
        $pflichten = PersonPflichten::vereinige([
            ['is_eu_citizen' => true, 'employment_type' => 'student', 'is_first_aider' => true],
            ['is_eu_citizen' => true, 'employment_type' => 'student', 'is_first_aider' => true],
        ]);

        $this->assertSame(array_values(array_unique($pflichten)), $pflichten);
    }

    public function test_ohne_anstellung_gibt_es_keine_pflichten(): void
    {
        $this->assertSame([], PersonPflichten::vereinige([]));
    }
}
