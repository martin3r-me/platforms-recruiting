<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PersonenSpiegel;
use Platform\Recruiting\Services\Zas\ZasInboundDuplicateFinder;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * ZAS-Eingang und Personen-Spiegel (Spec 2026-10-09 §5.2, §2.3).
 *
 *  - Ueberschreibt der Import Personenfelder an einer ZAS-Akte, bekommt die
 *    Geschwister-Akte derselben Person den Wert mit Marker; die getroffene
 *    Akte selbst bleibt markerlos (kein Echo), Lohn-Eintraege gibt es nicht.
 *  - Legt ZAS fuer einen bekannten Menschen eine neue Akte an und paart der
 *    Import sie, gewinnen unsere Daten; leere Felder bei uns werden mit dem
 *    ZAS-Wert nachgetragen.
 *  - Von ZAS gefuehrte Werte (Status, Personalnummer, Firma) bleiben je Akte.
 *  - Ein Fehler im Spiegel bricht die Import-Zeile nicht ab.
 */
class ZasInboundSpiegelTest extends TestCase
{
    use SpiegelHarness;

    public static function setUpBeforeClass(): void
    {
        self::baueWelt(true, [
            '2026_06_17_000001_add_zas_inbound_file_id_to_rec_employees',
            '2026_08_19_000001_add_status_ma_since_to_rec_employee_hr_data',
            '2026_08_26_000002_add_company_to_rec_employees',
            '2026_09_23_000001_add_nationality_to_rec_employees',
        ]);
        $config = Container::getInstance()->make('config');
        $config->set('activity-log', ['events' => []]);
        $config->set('recruiting.zas', ['inbound_team_id' => 614, 'inbound_overwrite_zas_owned' => true]);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(PersonenSpiegel::class);
    }

    private function importer(): ZasInboundEmployeeImporter
    {
        $lookups = new class extends ZasLookupReverseResolver {
            protected function loadPairs(string $lookupName): array
            {
                return [];
            }
        };

        return new ZasInboundEmployeeImporter(new ZasInboundRowMapper($lookups), new ZasInboundDuplicateFinder());
    }

    private function row(array $overrides = []): array
    {
        return array_merge(['ZasPersonalNr' => 'MA1', 'Status' => 'GO'], $overrides);
    }

    private function run_(array $row): array
    {
        return $this->importer()->import([$row], (object) ['id' => 99], false);
    }

    public function test_schema_traegt_alle_personenfelder(): void
    {
        // Fehlt eine Spalte, liest SQLite den Namen als Text-Literal — die
        // Uebernahme bei der Paarung vergliche dann Muell (Pruefmuster Nr. 11).
        $spalten = Capsule::schema()->getColumnListing('rec_employees');
        $this->assertSame([], array_values(array_diff(\Platform\Recruiting\Support\PersonenFelder::SPIEGELN, $spalten)));
    }

    public function test_zas_ueberschreibt_ma_akte_und_rg_bekommt_wert_und_marker(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'street' => 'Alt', 'personnel_number' => 'RG1', 'company' => 'RG']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'street' => 'Alt', 'personnel_number' => 'MA1', 'company' => 'MA']);

        $report = $this->run_($this->row(['Strasse' => 'Neu 3']));

        $this->assertSame([], $report['failed']);
        $this->assertSame('Neu 3', $this->zeile($ma)->street);
        $this->assertNull($this->zeile($ma)->zas_changed_at, 'kein Echo');
        $this->assertSame('Neu 3', $this->zeile($rg)->street, 'Bewerbungs-Akte (nicht ZAS-Bestand) wird trotzdem beschrieben');
        $this->assertNotNull($this->zeile($rg)->zas_changed_at);
        $this->assertSame([], $this->lohn($rg));
        $this->assertSame([], $this->lohn($ma));
    }

    public function test_von_zas_gefuehrte_werte_bleiben_je_akte(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'street' => 'Alt', 'personnel_number' => 'RG1', 'company' => 'RG']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'street' => 'Alt', 'personnel_number' => 'MA1', 'company' => 'MA']);

        $this->run_($this->row(['Status' => 'MA', 'StatusMASeit' => '01.10.2026', 'Strasse' => 'Neu 3']));

        $this->assertSame('Neu 3', $this->zeile($rg)->street, 'Vorbedingung: Spiegel lief');
        $this->assertSame('MA', Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $ma)->value('export_status'));
        $this->assertNull(Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $rg)->value('export_status'), 'Status bleibt je Akte');
        $this->assertSame('RG1', $this->zeile($rg)->personnel_number);
        $this->assertSame('RG', $this->zeile($rg)->company);
    }

    public function test_paarung_uebernimmt_unsere_daten_in_die_neue_ma_akte(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'first_name' => 'Max', 'last_name' => 'Muster',
            'birth_date' => '1990-01-01', 'street' => 'Unsere Str 1', 'iban' => null, 'personnel_number' => 'RG1', 'company' => 'RG']);

        $report = $this->run_($this->row(['ZasPersonalNr' => 'MA77', 'Status' => 'GO', 'Vorname' => 'Max', 'Name' => 'Muster',
            'Geburtsdatum' => '01.01.1990', 'Strasse' => 'ZAS Str 9', 'IBAN' => 'DE99']));

        $this->assertSame([], $report['failed']);
        $this->assertSame('paired', $report['created'][0]['person_pairing'] ?? null, 'Vorbedingung: Paarung');
        $ma = (int) Capsule::table('rec_employees')->where('personnel_number', 'MA77')->value('id');
        $this->assertSame($p, (int) $this->zeile($ma)->rec_person_id);
        $this->assertSame('Unsere Str 1', $this->zeile($ma)->street, 'unsere Daten gewinnen');
        $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'ZAS bekommt unsere Werte fuer MA (Marker nach dem Zuruecksetzen der Neuanlage)');
        $this->assertSame('DE99', $this->zeile($ma)->iban, 'leeres eigenes Feld: ZAS-Wert bleibt');
        $this->assertSame('DE99', $this->zeile($rg)->iban, 'und wird bei uns nachgetragen');
        $this->assertSame('Unsere Str 1', $this->zeile($rg)->street, 'unser Wert wird nie ueberschrieben');
        $this->assertNotNull($this->zeile($rg)->zas_changed_at, 'Nachtrag bei uns geht an ZAS');
        $this->assertSame('MA', $this->zeile($ma)->company, 'Firma bleibt je Akte');
        $this->assertSame([], $this->lohn($rg));
        $this->assertSame([], $this->lohn($ma));
    }


    public function test_veraltete_zas_lieferung_dreht_portal_aenderung_nicht_zurueck(): void
    {
        // Review C1: Portal-Aenderung an RG wird auf MA gespiegelt; die naechste
        // ZAS-Lieferung kennt unseren Export noch nicht und bringt den alten
        // Wert fuer MA. Ohne Schutz stuenden danach BEIDE Akten auf dem alten Wert.
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'iban' => 'DEALT', 'personnel_number' => 'RG1', 'company' => 'RG']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'iban' => 'DEALT', 'shirt_size' => 'M',
            'personnel_number' => 'MA1', 'company' => 'MA']);

        RecEmployee::find($rg)->update(['iban' => 'DENEU']);
        $this->assertSame('DENEU', $this->zeile($ma)->iban, 'Vorbedingung: Spiegel lief');
        $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'Vorbedingung: Marker offen');

        $report = $this->run_($this->row(['Status' => 'MA', 'IBAN' => 'DEALT', 'HemdGroesse' => 'XL']));

        $this->assertSame([], $report['failed']);
        $this->assertSame('DENEU', $this->zeile($ma)->iban, 'Personenfeld mit offenem Marker bleibt');
        $this->assertSame('DENEU', $this->zeile($rg)->iban, 'und wird nicht zurueckgespiegelt');
        $this->assertNotNull($this->zeile($ma)->zas_changed_at, 'unser Export bleibt faellig');
        $this->assertSame('XL', $this->zeile($ma)->shirt_size, 'andere Felder ueberschreibt ZAS weiter');
        $this->assertSame('MA', Capsule::table('rec_employee_hr_data')->where('rec_employee_id', $ma)->value('export_status'), 'Status-Sync bleibt');
    }

    public function test_zas_spiegel_laesst_geschwister_mit_offenem_marker_aus(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'street' => 'Unser Neu', 'personnel_number' => 'RG1', 'company' => 'RG',
            'zas_changed_at' => '2026-10-09 08:00:00']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'street' => 'Alt', 'personnel_number' => 'MA1', 'company' => 'MA']);

        $this->run_($this->row(['Strasse' => 'ZAS Neu']));

        $this->assertSame('ZAS Neu', $this->zeile($ma)->street, 'Vorbedingung: MA ohne Marker wird ueberschrieben');
        $this->assertSame('Unser Neu', $this->zeile($rg)->street, 'Geschwister mit offener Aenderung bleibt');
    }

    public function test_rohwert_ohne_lookup_ueberschreibt_sauberen_code_beim_geschwister_nicht(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'nationality' => 'SYR', 'personnel_number' => 'RG1', 'company' => 'RG']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'nationality' => null, 'personnel_number' => 'MA1', 'company' => 'MA']);
        $p2 = $this->person();
        $rg2 = $this->akte(['rec_person_id' => $p2, 'rec_applicant_id' => 8, 'nationality' => null, 'personnel_number' => 'RG2', 'company' => 'RG']);
        $this->akte(['rec_person_id' => $p2, 'rec_zas_inbound_file_id' => 5, 'nationality' => null, 'personnel_number' => 'MA2', 'company' => 'MA']);

        $this->run_($this->row(['Nation' => 'Syrisch']));
        $this->run_($this->row(['ZasPersonalNr' => 'MA2', 'Nation' => 'Syrisch']));

        $this->assertSame('Syrisch', $this->zeile($ma)->nationality, 'Vorbedingung: Rohwert landet im leeren Feld');
        $this->assertSame('SYR', $this->zeile($rg)->nationality, 'sauberer Code beim Geschwister bleibt');
        $this->assertSame('Syrisch', $this->zeile($rg2)->nationality, 'leeres Geschwisterfeld wird gefuellt');
    }

    public function test_geschuetztes_feld_im_ueberschreiben_erreicht_das_geschwister_nicht(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'identity_card_number' => 'X1', 'personnel_number' => 'RG1', 'company' => 'RG']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'identity_card_number' => 'X1', 'street' => 'Alt',
            'personnel_number' => 'MA1', 'company' => 'MA']);

        $this->run_($this->row(['AusweisNr' => 'Z9', 'Strasse' => 'Neu 3']));

        $this->assertSame('Neu 3', $this->zeile($rg)->street, 'Vorbedingung: Spiegel lief');
        $this->assertSame('X1', $this->zeile($ma)->identity_card_number);
        $this->assertSame('X1', $this->zeile($rg)->identity_card_number);
        $this->assertNull($this->zeile($rg)->country_code, 'Default-Land des Mappers wandert nicht');
    }

    public function test_paarung_traegt_geschuetzte_felder_nicht_bei_uns_nach(): void
    {
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'first_name' => 'Max', 'last_name' => 'Muster',
            'birth_date' => '1990-01-01', 'country_code' => null, 'identity_card_number' => null, 'personnel_number' => 'RG1', 'company' => 'RG']);

        $report = $this->run_($this->row(['ZasPersonalNr' => 'MA77', 'Vorname' => 'Max', 'Name' => 'Muster',
            'Geburtsdatum' => '01.01.1990', 'AusweisNr' => 'Z1', 'IBAN' => 'DE99']));

        $this->assertSame('paired', $report['created'][0]['person_pairing'] ?? null, 'Vorbedingung: Paarung');
        $this->assertSame('DE99', $this->zeile($rg)->iban, 'Vorbedingung: Nachtrag laeuft');
        $this->assertNull($this->zeile($rg)->country_code, 'erfundenes Default-Land landet nicht bei uns');
        $this->assertNull($this->zeile($rg)->identity_card_number, 'Login-Faktor kommt nicht aus ZAS');
    }

    public function test_paarung_mit_zas_bestand_als_bestehender_akte(): void
    {
        $p = $this->person();
        $alt = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'first_name' => 'Max', 'last_name' => 'Muster',
            'birth_date' => '1990-01-01', 'street' => 'Bestand 1', 'iban' => null, 'country_code' => null, 'personnel_number' => 'MA1', 'company' => 'MA']);

        $report = $this->run_($this->row(['ZasPersonalNr' => 'RG55', 'Vorname' => 'Max', 'Name' => 'Muster',
            'Geburtsdatum' => '01.01.1990', 'Strasse' => 'ZAS Str 9', 'IBAN' => 'DE77']));

        $this->assertSame([], $report['failed']);
        $this->assertSame('paired', $report['created'][0]['person_pairing'] ?? null, 'Vorbedingung: Paarung');
        $neu = (int) Capsule::table('rec_employees')->where('personnel_number', 'RG55')->value('id');
        $this->assertSame('Bestand 1', $this->zeile($neu)->street, 'Werte der bestehenden Akte gewinnen');
        $this->assertSame('DE77', $this->zeile($alt)->iban, 'leeres Feld wird nachgetragen');
        $this->assertSame('Bestand 1', $this->zeile($alt)->street);
        $this->assertNull($this->zeile($alt)->country_code);
        $this->assertSame('MA1', $this->zeile($alt)->personnel_number);
    }
    public function test_spiegel_fehler_bricht_import_nicht_ab(): void
    {
        Container::getInstance()->instance(PersonenSpiegel::class, new class extends PersonenSpiegel {
            public function spiegele(RecEmployee $quelle, array $werte, bool $markerSetzen, bool $lohnVerfolgen, array $nurInLeere = [], bool $offeneMarkerAuslassen = false): array
            {
                throw new \RuntimeException('klemmt');
            }

            public function uebernimmBeiPaarung(int $bestehendeId, int $neueId): void
            {
                throw new \RuntimeException('klemmt');
            }
        });
        $p = $this->person();
        $rg = $this->akte(['rec_person_id' => $p, 'rec_applicant_id' => 7, 'street' => 'Alt', 'personnel_number' => 'RG1', 'company' => 'RG',
            'birth_date' => '1990-01-01']);
        $ma = $this->akte(['rec_person_id' => $p, 'rec_zas_inbound_file_id' => 5, 'street' => 'Alt', 'personnel_number' => 'MA1', 'company' => 'MA']);

        $report = $this->run_($this->row(['Strasse' => 'Neu 3']));
        $this->assertSame([], $report['failed']);
        $this->assertCount(1, $report['updated']);
        $this->assertSame('Neu 3', $this->zeile($ma)->street);
        $this->assertSame('Alt', $this->zeile($rg)->street);

        $report = $this->run_($this->row(['ZasPersonalNr' => 'MA88', 'Vorname' => 'Max', 'Name' => 'Muster', 'Geburtsdatum' => '01.01.1990']));
        $this->assertSame([], $report['failed']);
        $this->assertSame('paired', $report['created'][0]['person_pairing'] ?? null);
    }
}
