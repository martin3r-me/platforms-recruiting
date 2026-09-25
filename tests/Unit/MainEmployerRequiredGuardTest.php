<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\MainEmployerRequiredGuard;

/**
 * Pflichtangabe Haupt-/Nebenarbeitgeber im MA-Portal (Markus 24.09.2026:
 * "Die Angabe Haupt-/Nebenarbeitgeber soll fuer den Bewerber Pflicht sein").
 *
 * Endzustands-Pruefung wie NationalityRequiredGuard: blockt auch Saves, die
 * nur andere Felder aendern — sonst koennte ein Bestands-MA die Frage
 * beliebig lange umgehen, und an ihr haengt die Steuerklasse.
 *
 * Der Name des anderen Arbeitgebers ist NUR bei "nein" Pflicht: wer sagt,
 * wir seien der Hauptarbeitgeber, darf trotzdem nebenher woanders arbeiten —
 * das ist erlaubt, aber keine Bringschuld.
 *
 * Arbeitet auf den rohen Formularwerten (Strings aus wire:model).
 */
final class MainEmployerRequiredGuardTest extends TestCase
{
    public function test_unbeantwortet_blockt(): void
    {
        foreach (['', null] as $leer) {
            $this->assertNotNull(
                MainEmployerRequiredGuard::error($leer, ''),
                var_export($leer, true) . ' muss blocken',
            );
        }
    }

    public function test_ja_ohne_weiteren_arbeitgeber_geht_durch(): void
    {
        $this->assertNull(MainEmployerRequiredGuard::error('1', ''));
    }

    public function test_ja_mit_weiterem_arbeitgeber_geht_durch(): void
    {
        // Der Fall, den Markus' Zwei-Wege-Auswahl allein nicht abbilden
        // koennte: Rheingedeck ist Hauptarbeitgeber UND es gibt einen Nebenjob.
        $this->assertNull(MainEmployerRequiredGuard::error('1', 'Musterkantine GmbH'));
    }

    public function test_nein_ohne_namen_blockt(): void
    {
        $this->assertNotNull(
            MainEmployerRequiredGuard::error('0', ''),
            'Wenn wir nicht der Hauptarbeitgeber sind, muessen wir wissen wer es ist.',
        );
    }

    public function test_nein_mit_namen_geht_durch(): void
    {
        $this->assertNull(MainEmployerRequiredGuard::error('0', 'Musterkantine GmbH'));
    }

    public function test_reiner_leerraum_zaehlt_nicht_als_name(): void
    {
        $this->assertNotNull(MainEmployerRequiredGuard::error('0', '   '));
    }

    /**
     * DER GEFAEHRLICHE FALL (Befund Review 25.09.2026): Guard und
     * Schreibpfad muessen dieselben Werte als "ja" bzw. "nein" lesen.
     *
     * EmployeePortal::saveAll() wandelt '1'|'true'|'ja' in true und
     * '0'|'false'|'nein' in false. Ein Guard, der nur auf '0' prueft, laesst
     * 'nein' als vermeintliches "ja" durch — gespeichert wird aber false,
     * und zwar OHNE den dann verlangten Namen. Genau der Zustand, den die
     * Pflicht verhindern soll.
     *
     * Dieselbe Konvention haelt FirstAiderDateGuard fest.
     */
    public function test_alle_nein_schreibweisen_verlangen_den_namen(): void
    {
        foreach (['0', 'false', 'nein', 'NEIN', ' Nein '] as $nein) {
            $this->assertNotNull(
                MainEmployerRequiredGuard::error($nein, ''),
                var_export($nein, true) . ' bedeutet nein und verlangt den Namen',
            );
            $this->assertNull(
                MainEmployerRequiredGuard::error($nein, 'Musterkantine GmbH'),
                var_export($nein, true) . ' mit Namen muss durchgehen',
            );
        }
    }

    public function test_alle_ja_schreibweisen_gehen_ohne_namen_durch(): void
    {
        foreach (['1', 'true', 'ja', 'JA', ' Ja '] as $ja) {
            $this->assertNull(
                MainEmployerRequiredGuard::error($ja, ''),
                var_export($ja, true) . ' bedeutet ja',
            );
        }
    }

    /**
     * Werte, die der Schreibpfad zu NULL macht, muss der Guard als
     * unbeantwortet abweisen. Sonst meldet das Portal "gespeichert",
     * waehrend eine vorher gueltige Antwort still geloescht wurde.
     */
    public function test_werte_die_der_schreibpfad_verwirft_gelten_als_unbeantwortet(): void
    {
        foreach (['x', '2', 'vielleicht', 'yes', 'no'] as $muell) {
            $this->assertNotNull(
                MainEmployerRequiredGuard::error($muell, 'Musterkantine GmbH'),
                var_export($muell, true) . ' wuerde als NULL gespeichert und muss blocken',
            );
        }
    }

    /**
     * rec_employees.other_employer ist string(128). Ohne Grenze im Portal
     * schlaegt ein laengerer Wert als SQLSTATE 22001 durch und reisst den
     * ganzen Speichervorgang mit — im Vertrags-Schritt ist die Grenze
     * ausdruecklich gesetzt, im Portal fehlte sie (Befund Review 25.09.2026).
     */
    public function test_zu_langer_name_wird_abgewiesen(): void
    {
        $this->assertNotNull(MainEmployerRequiredGuard::error('0', str_repeat('a', 129)));
        $this->assertNull(MainEmployerRequiredGuard::error('0', str_repeat('a', 128)));
    }

    public function test_meldungen_sagen_dass_nichts_gespeichert_wurde(): void
    {
        // Gleiche Zusage wie die anderen Portal-Guards: der Mitarbeiter muss
        // wissen, dass sein ganzer Speichervorgang liegengeblieben ist.
        $this->assertStringContainsString('nichts gespeichert', MainEmployerRequiredGuard::error('', ''));
        $this->assertStringContainsString('nichts gespeichert', MainEmployerRequiredGuard::error('0', ''));
    }
}
