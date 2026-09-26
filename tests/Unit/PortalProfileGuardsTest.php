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
 *
 * GEDREHT 25.09.2026, Fixrunde 1 zu Aufgabe 6 (Ruling C1): fehler() bekommt
 * jetzt eine dritte Reichweite -- ein Waechter blockt nur noch, wenn eines
 * seiner Felder darin vorkommt. Die BISHERIGEN Tests (Kaskade, Reihenfolge,
 * dreiwertige Abbildung) bleiben inhaltlich unveraendert und laufen mit
 * alleFelder() -- das entspricht einer Vollspeicherung ohne offene Gruppe
 * (PortalProfileWriter uebergibt dort editableFieldsFlat() als Reichweite,
 * also zwangslaeufig immer alle drei Themen). NEU sind die Tests, die
 * belegen, dass eine EINGESCHRAENKTE Reichweite (eine einzelne Gruppe) nur
 * noch fuer IHRE EIGENEN Felder blockt.
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

    /** Reichweite einer Vollspeicherung -- betrifft zwangslaeufig alle drei Waechter. */
    private function alleFelder(): array
    {
        return array_fill_keys([
            'is_first_aider', 'first_aider_valid_until', 'first_aider_certificate_file_id',
            'nationality', 'is_main_employer', 'other_employer',
        ], true);
    }

    // -----------------------------------------------------------------
    // Vollspeicherung (Reichweite = alle Felder) -- unveraendertes Verhalten
    // -----------------------------------------------------------------

    public function test_vollstaendiger_zustand_laesst_durch(): void
    {
        $this->assertNull(PortalProfileGuards::fehler([], $this->datensatz(), $this->alleFelder()));
    }

    public function test_ersthelfer_kommt_vor_staatsangehoerigkeit(): void
    {
        // §2.1: Early-Return. Wer den ersten Waechter nicht passiert, sieht die
        // spaeteren Fehler nie — das ist Teil des Verhaltens, keine Reihenfolge
        // aus Bequemlichkeit.
        $fehler = PortalProfileGuards::fehler(
            ['is_first_aider' => '1'],
            $this->datensatz(['nationality' => null, 'is_main_employer' => null]),
            $this->alleFelder(),
        );

        $this->assertStringContainsString('Ersthelfer', $fehler);
    }

    public function test_staatsangehoerigkeit_kommt_vor_arbeitgeber(): void
    {
        $fehler = PortalProfileGuards::fehler(
            [],
            $this->datensatz(['nationality' => '', 'is_main_employer' => null]),
            $this->alleFelder(),
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
            $this->alleFelder(),
        );
        $this->assertStringContainsString('Nachweis', $nurDatum);

        $beides = PortalProfileGuards::fehler(
            ['is_first_aider' => '1', 'first_aider_valid_until' => '2027-01-01'],
            $this->datensatz(['first_aider_certificate_file_id' => 42]),
            $this->alleFelder(),
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
            $this->alleFelder(),
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
            $this->alleFelder(),
        );

        $this->assertNull($fehler);
    }

    public function test_unbeantworteter_hauptarbeitgeber_blockt(): void
    {
        // R17 — Endzustandspruefung: blockt auch ein Speichern, das nur die
        // Schuhgroesse aendert -- SOLANGE die Reichweite den Hauptarbeitgeber
        // ueberhaupt betrifft (hier: Vollspeicherung). Die gruppen-beschraenkte
        // Gegenprobe steht unten (test_reichweite_ohne_waechter_felder_blockt_nie).
        $fehler = PortalProfileGuards::fehler(
            ['shoe_size' => '43'],
            $this->datensatz(['is_main_employer' => null]),
            $this->alleFelder(),
        );

        $this->assertStringContainsString('Hauptarbeitgeber', $fehler);
    }

    public function test_nein_ohne_namen_blockt_und_zu_lang_blockt(): void
    {
        $ohneNamen = PortalProfileGuards::fehler(
            ['is_main_employer' => '0', 'other_employer' => ''],
            $this->datensatz(),
            $this->alleFelder(),
        );
        $this->assertNotNull($ohneNamen);

        $zuLang = PortalProfileGuards::fehler(
            ['is_main_employer' => '0', 'other_employer' => str_repeat('a', 129)],
            $this->datensatz(),
            $this->alleFelder(),
        );
        $this->assertStringContainsString('zu lang', $zuLang);
    }

    public function test_staatsangehoerigkeit_faellt_auf_den_datensatz_zurueck(): void
    {
        // R16: das Formular schickt den Schluessel nicht mit, wenn gerade eine
        // andere Gruppe offen ist -- die Reichweite betrifft die
        // Staatsangehoerigkeit hier trotzdem (Vollspeicherung).
        $this->assertNull(PortalProfileGuards::fehler(['iban' => 'DE02'], $this->datensatz(), $this->alleFelder()));
        $this->assertNotNull(PortalProfileGuards::fehler(['iban' => 'DE02'], $this->datensatz(['nationality' => null]), $this->alleFelder()));
    }

    // -----------------------------------------------------------------
    // NEU (C1, Fixrunde 1): eingeschraenkte Reichweite blockt nur noch fuer
    // IHRE EIGENEN Felder -- sonst friert eine fehlende Angabe das GANZE
    // Profil ein (Doppel-Null-Deadlock, siehe PortalProfileGuards-Docblock).
    // -----------------------------------------------------------------

    public function test_reichweite_ohne_waechter_felder_blockt_nie(): void
    {
        // Eine Gruppe wie Arbeitskleidung oder Bankdaten hat mit keinem der
        // drei Themen etwas zu tun -- selbst wenn Staatsangehoerigkeit UND
        // Hauptarbeitgeber gleichzeitig unbeantwortet sind, darf das
        // Speichern der Schuhgroesse nicht blockieren.
        $fehler = PortalProfileGuards::fehler(
            ['shoe_size' => '43'],
            $this->datensatz(['nationality' => null, 'is_main_employer' => null]),
            ['shirt_size' => true, 'pants_size' => true, 'shoe_size' => true],
        );

        $this->assertNull($fehler);
    }

    public function test_reichweite_der_arbeitgeber_gruppe_blockt_nicht_mehr_wegen_fehlender_staatsangehoerigkeit(): void
    {
        $reichweite = ['is_main_employer' => true, 'other_employer' => true];

        $fehler = PortalProfileGuards::fehler(
            ['is_main_employer' => '1'],
            $this->datensatz(['nationality' => null]),
            $reichweite,
        );

        $this->assertNull($fehler);
    }

    public function test_reichweite_der_arbeitgeber_gruppe_blockt_weiterhin_fuer_ihre_eigene_pflicht(): void
    {
        // Die eigene Regel (kein Name bei "nein") bleibt in Kraft -- C1 nimmt
        // nur die FREMDEN Waechter aus der Reichweite, nicht die eigenen.
        $reichweite = ['is_main_employer' => true, 'other_employer' => true];

        $fehler = PortalProfileGuards::fehler(
            ['is_main_employer' => '0', 'other_employer' => ''],
            $this->datensatz(['nationality' => null]),
            $reichweite,
        );

        $this->assertStringContainsString('Hauptarbeitgeber', $fehler);
    }

    public function test_reichweite_der_adresse_gruppe_blockt_nicht_mehr_wegen_fehlendem_arbeitgeber(): void
    {
        $reichweite = ['street' => true, 'city' => true, 'nationality' => true];

        $fehler = PortalProfileGuards::fehler(
            ['nationality' => 'deutsch'],
            $this->datensatz(['nationality' => null, 'is_main_employer' => null]),
            $reichweite,
        );

        $this->assertNull($fehler);
    }

    public function test_reichweite_der_adresse_gruppe_blockt_weiterhin_ohne_staatsangehoerigkeit(): void
    {
        $reichweite = ['street' => true, 'city' => true, 'nationality' => true];

        $fehler = PortalProfileGuards::fehler(
            ['street' => 'Musterstrasse'],
            $this->datensatz(['nationality' => null]),
            $reichweite,
        );

        $this->assertStringContainsString('Staatsangeh', $fehler);
    }

    public function test_reichweite_der_arbeitsschutz_gruppe_blockt_weiterhin_fuer_den_ersthelfer(): void
    {
        $reichweite = ['is_first_aider' => true, 'first_aider_valid_until' => true, 'first_aider_certificate_file_id' => true];

        $fehler = PortalProfileGuards::fehler(
            ['is_first_aider' => '1'],
            $this->datensatz(['nationality' => null, 'is_main_employer' => null]),
            $reichweite,
        );

        $this->assertStringContainsString('Ersthelfer', $fehler);
    }
}
