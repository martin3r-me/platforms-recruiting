<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Haupt-/Nebenarbeitgeber ist lohnrelevant: die Angabe entscheidet, ob nach
 * Steuerklasse VI abgerechnet wird. Sie gehoert damit in dieselbe Liste wie
 * die Steuerklasse selbst.
 *
 * Der NAME des anderen Arbeitgebers gehoert NICHT dazu — er aendert an der
 * Abrechnung nichts, wuerde die Lohnliste aber mit Umbenennungen fluten.
 *
 * Hinweis zum Verhalten: der Observer ueberspringt Erstbefuellungen
 * (null -> Wert). Dass jemand die Frage zum ersten Mal beantwortet, ist
 * keine Lohnaenderung; ein spaeterer Wechsel von "ja" auf "nein" sehr wohl.
 */
final class PayrollMainEmployerTest extends TestCase
{
    public function test_hauptarbeitgeber_steht_bei_steuer_und_sv(): void
    {
        $gruppe = RecApplicantSettings::PAYROLL_TRACKABLE_FIELDS['Steuer / SV'] ?? [];

        $this->assertArrayHasKey('is_main_employer', $gruppe);
        $this->assertNotSame('', trim((string) $gruppe['is_main_employer']));
    }

    public function test_es_gibt_eine_beschriftung_fuer_die_lohnliste(): void
    {
        $this->assertArrayHasKey('is_main_employer', RecApplicantSettings::payrollFieldLabels());
    }

    public function test_der_name_des_anderen_arbeitgebers_wird_nicht_verfolgt(): void
    {
        $this->assertArrayNotHasKey('other_employer', RecApplicantSettings::payrollFieldLabels());

        foreach (RecApplicantSettings::PAYROLL_TRACKABLE_FIELDS as $gruppe) {
            $this->assertArrayNotHasKey('other_employer', $gruppe);
        }
    }

    /**
     * Die Vorgabe-Auswahl greift nur bei Teams ohne eigene gespeicherte
     * Liste. Fuer Bestandsteams muss der Haken zusaetzlich in den
     * Einstellungen gesetzt werden — deshalb steht er hier auch in der
     * Vorgabe, damit neue Teams ihn von vornherein haben.
     */
    public function test_die_vorgabe_verfolgt_das_feld(): void
    {
        $this->assertContains(
            'is_main_employer',
            RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields'],
        );
    }
}
