<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Services\AltaktenNachweise;
use Platform\Recruiting\Services\ProofReader;
use Platform\Recruiting\Support\ProofChecklist;

/**
 * Pilot-Feedback Markus 29.10.2026: Ausweis/Infektionsschutz/Krankenkassenkarte
 * liegen in der Papierakte, nicht im HCM — das Portal darf sie nicht anfordern.
 */
final class AltaktenNachweiseAbgleichTest extends TestCase
{
    private const TEAM = 931;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000001_add_full_field_set_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php',
            'database/migrations/2026_08_06_000001_add_erstbescheinigung_file_id_to_rec_employees.php',
            'database/migrations/2026_06_17_000001_add_zas_inbound_file_id_to_rec_employees.php',
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
            'database/migrations/2026_09_23_000003_add_ifsg_instruction_to_rec_employees.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php',
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
            'database/migrations/2026_09_23_000001_create_rec_employee_proofs_table.php',
        ] as $relative) {
            (require $own . '/' . $relative)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employee_proofs')->delete();
        Capsule::table('rec_employees')->delete();
    }

    private function ma(array $werte = []): RecEmployee
    {
        return RecEmployee::create($werte + [
            'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster', 'is_active' => true,
            'rec_zas_inbound_file_id' => 77, 'rec_applicant_id' => null,
        ]);
    }

    public function test_legt_belegte_nachweise_ohne_datei_an_und_die_checkliste_sagt_ok(): void
    {
        $ma = $this->ma([
            'identity_card_valid_until' => now()->addYears(3)->format('Y-m-d'),
            'infection_protection_valid_until' => '2027-01-01',
            'health_insurance' => 'AOK',
        ]);

        $probe = (new AltaktenNachweise())->abgleichen(self::TEAM, false);
        $this->assertSame(['personen' => 1, 'angelegt' => ['ausweis' => 1, 'erstbescheinigung' => 1, 'krankenkasse' => 1]], $probe);
        $this->assertSame(0, RecEmployeeProof::count(), 'Probelauf schreibt nichts.');

        (new AltaktenNachweise())->abgleichen(self::TEAM, true);

        $proofs = RecEmployeeProof::query()->orderBy('proof_type_code')->get();
        $this->assertSame(['ausweis', 'erstbescheinigung', 'krankenkasse'], $proofs->pluck('proof_type_code')->all());
        $this->assertSame([AltaktenNachweise::QUELLE], $proofs->pluck('uploaded_via')->unique()->values()->all());
        $this->assertNull($proofs->first()->file_id);

        $status = collect((new ProofReader())->checklist($ma->fresh()))->pluck('status', 'code');
        $this->assertSame(ProofChecklist::OK, $status['ausweis']);
        $this->assertSame(ProofChecklist::OK, $status['krankenkasse']);

        // Altspalten bleiben, wie sie waren (kein ProofWriter-Spiegel mit null).
        $this->assertNull($ma->fresh()->identity_card_front_file_id);
    }

    public function test_idempotent_und_belegtes_bleibt(): void
    {
        $this->ma(['health_insurance' => 'AOK', 'identity_card_valid_until' => '2031-01-01', 'identity_card_front_file_id' => 5001]);

        $erst = (new AltaktenNachweise())->abgleichen(self::TEAM, true);
        $this->assertSame(['krankenkasse' => 1], $erst['angelegt'], 'Ausweis-Datei liegt schon in der Altspalte.');
        $this->assertSame(['personen' => 0, 'angelegt' => []], (new AltaktenNachweise())->abgleichen(self::TEAM, true));
    }

    public function test_bewerbungs_ma_und_inaktive_bleiben_aussen_vor(): void
    {
        $this->ma(['health_insurance' => 'AOK', 'rec_zas_inbound_file_id' => null, 'rec_applicant_id' => 12]);
        $this->ma(['health_insurance' => 'AOK', 'is_active' => false]);

        $this->assertSame(['personen' => 0, 'angelegt' => []], (new AltaktenNachweise())->abgleichen(self::TEAM, true));
    }

    public function test_zwei_anstellungen_einer_person_bekommen_einen_nachweis(): void
    {
        $rg = $this->ma(['rec_person_id' => 501, 'health_insurance' => 'AOK']);
        $this->ma(['rec_person_id' => 501, 'health_insurance' => 'AOK']);

        $r = (new AltaktenNachweise())->abgleichen(self::TEAM, true);

        $this->assertSame(['personen' => 1, 'angelegt' => ['krankenkasse' => 1]], $r);
        $this->assertSame([(int) $rg->id], RecEmployeeProof::pluck('rec_employee_id')->map(fn ($i) => (int) $i)->all());
    }

    /** Review 30.10.: die kleinste id war oft eine beendete Anstellung — Erinnerungen fragen nur aktive ab. */
    public function test_traeger_ist_die_aktive_anstellung_im_neuen_portal(): void
    {
        $alt = $this->ma(['rec_person_id' => 502, 'is_active' => false, 'health_insurance' => 'AOK']);
        $ohnePortal = $this->ma(['rec_person_id' => 502]);
        $neu = $this->ma(['rec_person_id' => 502, 'portal_v2_since' => '2026-10-29 08:00:00']);

        (new AltaktenNachweise())->abgleichen(self::TEAM, true);

        $this->assertSame([(int) $neu->id], RecEmployeeProof::pluck('rec_employee_id')->map(fn ($i) => (int) $i)->all());
        $this->assertLessThan($ohnePortal->id, $alt->id);
    }

    /** Review 30.10.: ein neueres AusweisBis aus ZAS erneuert den Vermerk, ein aelteres nicht. */
    public function test_neueres_ausweisdatum_aus_zas_erneuert_den_vermerk(): void
    {
        $ma = $this->ma(['identity_card_valid_until' => '2025-06-30']);
        (new AltaktenNachweise())->abgleichen(self::TEAM, true);

        $ma->update(['identity_card_valid_until' => '2035-06-30']);
        $r = (new AltaktenNachweise())->abgleichen(self::TEAM, true);
        $this->assertSame(['ausweis' => 1], $r['angelegt']);

        $aktuell = RecEmployeeProof::query()->aktuell()->get();
        $this->assertCount(1, $aktuell);
        $this->assertSame('2035-06-30', $aktuell->first()->valid_until->format('Y-m-d'));
        $this->assertSame(2, (int) $aktuell->first()->version);

        $ma->update(['identity_card_valid_until' => '2030-01-01']);
        $this->assertSame([], (new AltaktenNachweise())->abgleichen(self::TEAM, true)['angelegt']);
    }

    /** Ein echter Upload bleibt — auch wenn ZAS spaeter ein anderes Datum hat. */
    public function test_echter_nachweis_wird_nie_ersetzt(): void
    {
        $ma = $this->ma(['identity_card_valid_until' => '2035-06-30']);
        RecEmployeeProof::create([
            'team_id' => self::TEAM, 'rec_employee_id' => $ma->id, 'proof_type_code' => 'ausweis',
            'file_id' => 9001, 'valid_until' => '2027-01-01', 'version' => 1, 'uploaded_via' => 'employee',
        ]);

        $this->assertSame([], (new AltaktenNachweise())->abgleichen(self::TEAM, true)['angelegt']);
    }
}
