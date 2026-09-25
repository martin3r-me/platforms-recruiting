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

    public function test_meldungen_sagen_dass_nichts_gespeichert_wurde(): void
    {
        // Gleiche Zusage wie die anderen Portal-Guards: der Mitarbeiter muss
        // wissen, dass sein ganzer Speichervorgang liegengeblieben ist.
        $this->assertStringContainsString('nichts gespeichert', MainEmployerRequiredGuard::error('', ''));
        $this->assertStringContainsString('nichts gespeichert', MainEmployerRequiredGuard::error('0', ''));
    }
}
