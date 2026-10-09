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

    public function test_spiegel_fehler_bricht_import_nicht_ab(): void
    {
        Container::getInstance()->instance(PersonenSpiegel::class, new class extends PersonenSpiegel {
            public function spiegele(RecEmployee $quelle, array $werte, bool $markerSetzen, bool $lohnVerfolgen): array
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
