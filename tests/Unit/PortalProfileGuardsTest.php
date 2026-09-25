<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalProfileGuards;

/**
 * Die drei Waechter des Mitarbeiterportals als eine Kaskade in der
 * bindenden Reihenfolge aus EmployeePortal::saveAll() (Bestandsaufnahme
 * §2.1): Ersthelfer -> Staatsangehoerigkeit -> Hauptarbeitgeber, jeder mit
 * Early-Return. Im neuen Portal steht immer nur EINE Gruppe im Formular,
 * die Rueckfaelle auf den Datensatz sind also der Normalfall.
 */
class PortalProfileGuardsTest extends TestCase
{
    private function datensatz(array $ueberschreiben = []): array
    {
        return array_merge([
            'is_first_aider'                  => null,
            'first_aider_valid_until'         => null,
            'first_aider_certificate_file_id' => null,
            'nationality'                     => 'DE',
            'is_main_employer'                => true,
            'other_employer'                  => null,
        ], $ueberschreiben);
    }

    public function test_vollstaendiger_zustand_laesst_durch(): void
    {
        $this->assertNull(PortalProfileGuards::fehler([], $this->datensatz()));
    }

    public function test_ersthelfer_kommt_vor_staatsangehoerigkeit(): void
    {
        // §2.1: Early-Return. Wer den ersten Waechter nicht passiert, sieht die
        // spaeteren Fehler nie — das ist Teil des Verhaltens, keine Reihenfolge
        // aus Bequemlichkeit.
        $fehler = PortalProfileGuards::fehler(
            ['is_first_aider' => '1'],
            $this->datensatz(['nationality' => null, 'is_main_employer' => null]),
        );

        $this->assertStringContainsString('Ersthelfer', $fehler);
    }

    public function test_staatsangehoerigkeit_kommt_vor_arbeitgeber(): void
    {
        $fehler = PortalProfileGuards::fehler(
            [],
            $this->datensatz(['nationality' => '', 'is_main_employer' => null]),
        );

        $this->assertStringContainsString('Staatsangeh', $fehler);
    }

    public function test_ersthelfer_verlangt_datum_UND_datei(): void
    {
        // R15: Dokumentpflicht, also $requireCertificate = true. HR ruft
        // denselben Waechter ohne den vierten Parameter.
        $nurDatum = PortalProfileGuards::fehler(
            ['is_first_aider' => '1', 'first_aider_valid_until' => '2027-01-01'],
            $this->datensatz(),
        );
        $this->assertStringContainsString('Nachweis', $nurDatum);

        $beides = PortalProfileGuards::fehler(
            ['is_first_aider' => '1', 'first_aider_valid_until' => '2027-01-01'],
            $this->datensatz(['first_aider_certificate_file_id' => 42]),
        );
        $this->assertNull($beides);
    }

    public function test_die_datei_kommt_vom_datensatz_nicht_aus_dem_formular(): void
    {
        // R15/E8: Dateien laufen nie ueber Formularwerte. Ein manipulierter POST
        // darf den Waechter nicht mit einer erfundenen File-Id passieren.
        $fehler = PortalProfileGuards::fehler(
            [
                'is_first_aider' => '1',
                'first_aider_valid_until' => '2027-01-01',
                'first_aider_certificate_file_id' => '999',
            ],
            $this->datensatz(),
        );

        $this->assertStringContainsString('Nachweis', $fehler);
    }

    public function test_wer_ordentlich_nein_geantwortet_hat_kann_weiter_speichern(): void
    {
        // E11/R18 — die teuerste Zeile des ganzen Umbaus. (string) false ergibt
        // '', also genau die Form, die der Waechter als "unbeantwortet" liest.
        // Im neuen Portal steht is_main_employer bei fast jedem Speichern gar
        // nicht im Formular, der Rueckfall ist also der Normalfall.
        $fehler = PortalProfileGuards::fehler(
            ['shirt_size' => 'M'],
            $this->datensatz(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']),
        );

        $this->assertNull($fehler);
    }

    public function test_unbeantworteter_hauptarbeitgeber_blockt(): void
    {
        // R17 — Endzustandspruefung: blockt auch ein Speichern, das nur die
        // Schuhgroesse aendert.
        $fehler = PortalProfileGuards::fehler(
            ['shoe_size' => '43'],
            $this->datensatz(['is_main_employer' => null]),
        );

        $this->assertStringContainsString('Hauptarbeitgeber', $fehler);
    }

    public function test_nein_ohne_namen_blockt_und_zu_lang_blockt(): void
    {
        $ohneNamen = PortalProfileGuards::fehler(
            ['is_main_employer' => '0', 'other_employer' => ''],
            $this->datensatz(),
        );
        $this->assertNotNull($ohneNamen);

        $zuLang = PortalProfileGuards::fehler(
            ['is_main_employer' => '0', 'other_employer' => str_repeat('a', 129)],
            $this->datensatz(),
        );
        $this->assertStringContainsString('zu lang', $zuLang);
    }

    public function test_staatsangehoerigkeit_faellt_auf_den_datensatz_zurueck(): void
    {
        // R16: das Formular schickt den Schluessel nicht mit, wenn gerade eine
        // andere Gruppe offen ist.
        $this->assertNull(PortalProfileGuards::fehler(['iban' => 'DE02'], $this->datensatz()));
        $this->assertNotNull(PortalProfileGuards::fehler(['iban' => 'DE02'], $this->datensatz(['nationality' => null])));
    }
}
