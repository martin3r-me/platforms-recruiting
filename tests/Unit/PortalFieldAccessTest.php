<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalFieldAccess;

/**
 * R27 — bedingte Sichtbarkeit (visible_if) als reine, geteilte Regel.
 * Reine Logik: kein Framework, keine DB.
 *
 * Dreiwertig gedacht: sichtbar bleibt ein Feld, solange die Bedingung nicht
 * ausdruecklich widerlegt ist. Gemessen gegen den Formularwert, ersatzweise
 * gegen den Datensatz (E16).
 */
final class PortalFieldAccessTest extends TestCase
{
    public function test_feld_ohne_bedingung_ist_immer_sichtbar(): void
    {
        $this->assertTrue(PortalFieldAccess::istSichtbar(['type' => 'text'], [], []));
    }

    public function test_unbeantwortet_versteckt_nichts(): void
    {
        // R27: sichtbar, solange die Bedingung nicht ausdruecklich widerlegt ist
        // — sonst saehe niemand, dass nach einem "nein" noch etwas verlangt wird.
        $meta = ['type' => 'text', 'visible_if' => ['is_main_employer' => false]];

        $this->assertTrue(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => null], []));
        $this->assertTrue(PortalFieldAccess::istSichtbar($meta, [], []));
    }

    public function test_der_formularwert_schlaegt_den_datensatz(): void
    {
        // E16: bei "ja" verschwindet das Namensfeld SOFORT, nicht erst nach dem
        // Speichern. Deshalb wird gegen den Formularwert gemessen.
        $meta = ['type' => 'text', 'visible_if' => ['is_main_employer' => false]];

        $this->assertFalse(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => false], ['is_main_employer' => '1']));
        $this->assertTrue(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => true], ['is_main_employer' => '0']));
        // Leerer Formularwert ist keine Aussage — dann entscheidet der Datensatz.
        $this->assertFalse(PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => true], ['is_main_employer' => '']));
    }

    public function test_ja_nein_wird_ueber_PortalBoolValue_gelesen(): void
    {
        // E13: eine einzige Quelle. 'nein' muss dasselbe heissen wie '0'.
        $meta = ['type' => 'text', 'visible_if' => ['is_main_employer' => false]];

        $this->assertTrue(PortalFieldAccess::istSichtbar($meta, [], ['is_main_employer' => 'nein']));
        $this->assertFalse(PortalFieldAccess::istSichtbar($meta, [], ['is_main_employer' => 'Ja']));
    }

    public function test_leere_gruppen_fallen_heraus(): void
    {
        // Gegenteil von §1.4 Punkt 4: das alte Blade rendert eine leere Gruppe
        // als leere Karte mit Ueberschrift. Das neue laesst sie weg.
        $gruppen = [
            'Arbeitgeber' => [
                'is_main_employer' => ['type' => 'bool', 'label' => 'Hauptarbeitgeber?'],
                'other_employer'   => ['type' => 'text', 'label' => 'Wer ist es dann?',
                                       'visible_if' => ['is_main_employer' => false]],
            ],
            'Leer' => [
                'nur_bei_nein' => ['type' => 'text', 'label' => 'X',
                                   'visible_if' => ['is_main_employer' => false]],
            ],
        ];

        $sichtbar = PortalFieldAccess::sichtbareGruppen($gruppen, ['is_main_employer' => true], []);

        $this->assertSame(['Arbeitgeber'], array_keys($sichtbar));
        $this->assertSame(['is_main_employer'], array_keys($sichtbar['Arbeitgeber']));
    }

    public function test_flache_liste_traegt_die_metadaten_weiter(): void
    {
        $gruppen = ['A' => ['feld' => ['type' => 'date', 'label' => 'Feld', 'maxlength' => 128]]];

        $flach = PortalFieldAccess::sichtbareFelderFlach($gruppen, [], []);

        $this->assertSame(['feld'], array_keys($flach));
        $this->assertSame(128, $flach['feld']['maxlength']);
    }
}
