<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecKostenstellenSoll;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\GeleisteterEinsatz;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\MitarbeiterStatistikDaten;
use Platform\Recruiting\Services\Statistics\Mitarbeiter\Personalreport;

/** Mitarbeiter-Statistik gegen SQLite: Abfragen, Soll-Verlauf, Report-Inhalt. */
class MitarbeiterStatistikDatenTest extends TestCase
{
    private static Capsule $capsule;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => [], 'filialen' => [
            100 => ['code' => 'DUS & ES', 'name' => 'Düsseldorf & Essen'],
            200 => ['code' => 'MGL', 'name' => 'Mönchengladbach'],
        ]]]));
        self::$capsule = $capsule;

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_11_19_000001_create_rec_kostenstellen_soll_table.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
        $schema = $capsule->getConnection()->getSchemaBuilder();
        // Spalten der Einbuchung, die spaetere Migrationen ergaenzt haben (nur was hier gelesen wird).
        foreach (['deletion_marked_at', 'declined_at', 'missing_since'] as $spalte) {
            if (!$schema->hasColumn('rec_dispo_assignments', $spalte)) {
                $schema->table('rec_dispo_assignments', fn ($t) => $t->timestamp($spalte)->nullable());
            }
        }
        $schema->create('rec_positions', function ($t) {
            $t->id();
            $t->integer('cost_center')->nullable();
        });
        $schema->create('rec_persons', function ($t) {
            $t->id();
            $t->string('phone')->nullable();
        });
        $schema->create('rec_employees', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('rec_person_id')->nullable();
            $t->unsignedBigInteger('rec_position_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_test')->nullable();
            $t->string('cost_center')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('personnel_number')->nullable();
            $t->string('phone')->nullable();
            $t->string('company')->nullable();
        });
        $schema->create('rec_employee_hr_data', function ($t) {
            $t->id();
            $t->unsignedBigInteger('rec_employee_id');
            $t->string('export_status', 8)->default('GO');
        });
        $schema->create('comms_whatsapp_threads', function ($t) {
            $t->id();
            $t->string('remote_phone_number');
        });
        $schema->create('comms_whatsapp_messages', function ($t) {
            $t->id();
            $t->unsignedBigInteger('comms_whatsapp_thread_id');
            $t->string('direction');
            $t->timestamp('created_at')->nullable();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_assignments', 'rec_dispo_events', 'rec_employees', 'rec_employee_hr_data', 'rec_persons', 'rec_positions', 'comms_whatsapp_messages', 'comms_whatsapp_threads', 'rec_kostenstellen_soll'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('rec_dispo_events')->insert(['id' => 1, 'uuid' => 'ev-1', 'einsatz_ref' => 'E1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private static int $ds = 0;

    private function einbuchung(int $employeeId, string $datum, int $status = RecDispoAssignment::STATUS_AUFTRAG, array $extra = []): void
    {
        self::$ds++;
        DB::table('rec_dispo_assignments')->insert($extra + [
            'uuid' => 'a-' . self::$ds, 'ds_ref' => 'DS' . self::$ds, 'rec_dispo_event_id' => 1, 'pnr_raw' => 'x',
            'rec_employee_id' => $employeeId, 'datum' => $datum, 'status_id' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_einschraenkung_deckt_sich_mit_der_regel(): void
    {
        DB::table('rec_employees')->insert(['id' => 1, 'team_id' => 3]);
        $faelle = [];
        foreach ([0, 1, 2, 3] as $status) {
            foreach ([null, 'missing_since', 'deletion_marked_at', 'declined_at'] as $flag) {
                $this->einbuchung(1, '2026-11-01', $status, $flag ? [$flag => '2026-11-02 10:00:00'] : []);
                $faelle[self::$ds] = GeleisteterEinsatz::zaehlt($status, $flag === 'missing_since' ? 'x' : null, $flag === 'deletion_marked_at' ? 'x' : null, $flag === 'declined_at' ? 'x' : null);
            }
        }
        $q = DB::table('rec_dispo_assignments');
        $treffer = GeleisteterEinsatz::einschraenken($q)->pluck('ds_ref')->map(fn ($r) => (int) substr($r, 2))->sort()->values()->all();
        $erwartet = array_keys(array_filter($faelle));
        sort($erwartet);
        $this->assertSame($erwartet, $treffer);
        $this->assertCount(2, $treffer);
    }

    public function test_auswertung_ueber_db(): void
    {
        DB::table('rec_positions')->insert(['id' => 10, 'cost_center' => 201]);
        DB::table('rec_persons')->insert(['id' => 50, 'phone' => '+491701111111']);
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => 3, 'rec_person_id' => 50, 'rec_position_id' => null, 'is_active' => 1, 'is_test' => 0, 'cost_center' => '102', 'first_name' => 'Anna', 'last_name' => 'Alt', 'personnel_number' => 'RG1', 'phone' => null, 'company' => 'RG'],
            ['id' => 2, 'team_id' => 3, 'rec_person_id' => 50, 'rec_position_id' => null, 'is_active' => 1, 'is_test' => 0, 'cost_center' => '103', 'first_name' => 'Anna', 'last_name' => 'Alt', 'personnel_number' => 'MA1', 'phone' => null, 'company' => 'RG'],
            ['id' => 3, 'team_id' => 3, 'rec_person_id' => null, 'rec_position_id' => 10, 'is_active' => 1, 'is_test' => null, 'cost_center' => null, 'first_name' => 'Ben', 'last_name' => 'Bau', 'personnel_number' => 'RG3', 'phone' => '0170 2222222', 'company' => 'RG'],
            ['id' => 4, 'team_id' => 3, 'rec_person_id' => null, 'rec_position_id' => null, 'is_active' => 1, 'is_test' => 1, 'cost_center' => '100', 'first_name' => 'Test', 'last_name' => 'Test', 'personnel_number' => null, 'phone' => null, 'company' => 'RG'],
            ['id' => 5, 'team_id' => 9, 'rec_person_id' => null, 'rec_position_id' => null, 'is_active' => 1, 'is_test' => 0, 'cost_center' => '100', 'first_name' => 'Fremd', 'last_name' => 'Team', 'personnel_number' => null, 'phone' => null, 'company' => 'RG'],
        ]);
        $this->einbuchung(1, '2026-11-10', RecDispoAssignment::STATUS_BEENDET);
        $this->einbuchung(2, '2026-11-11');
        $this->einbuchung(2, '2026-11-12', RecDispoAssignment::STATUS_AUFTRAG, ['declined_at' => '2026-11-11 09:00:00']);
        $this->einbuchung(3, '2026-11-12', RecDispoAssignment::STATUS_STORNO);
        $this->einbuchung(4, '2026-11-12');

        DB::table('comms_whatsapp_threads')->insert([['id' => 1, 'remote_phone_number' => '491702222222'], ['id' => 2, 'remote_phone_number' => '491701111111']]);
        DB::table('comms_whatsapp_messages')->insert([
            ['comms_whatsapp_thread_id' => 1, 'direction' => 'inbound', 'created_at' => '2026-11-05 08:00:00'],
            ['comms_whatsapp_thread_id' => 2, 'direction' => 'outbound', 'created_at' => '2026-11-05 08:00:00'],
        ]);

        RecKostenstellenSoll::create(['team_id' => 3, 'kostenstelle' => 100, 'soll' => 4, 'gueltig_ab' => '2026-11-01']);
        RecKostenstellenSoll::create(['team_id' => 3, 'kostenstelle' => 100, 'soll' => 2, 'gueltig_ab' => '2026-11-15']);
        RecKostenstellenSoll::create(['team_id' => 3, 'kostenstelle' => 100, 'soll' => 9, 'gueltig_ab' => '2026-12-01']);
        RecKostenstellenSoll::create(['team_id' => 9, 'kostenstelle' => 200, 'soll' => 7, 'gueltig_ab' => '2026-11-01']);

        $r = (new MitarbeiterStatistikDaten())->auswerten(3, '2026-11-19', '2026-10-23', '2026-11-19');

        $this->assertCount(2, $r['personen'], 'Anna einmal, Ben; Test-MA und fremdes Team fehlen');
        $byName = array_column($r['personen'], null, 'name');
        $this->assertSame(2, $byName['Alt, Anna']['termine'], 'abgesagte Einbuchung zählt nicht');
        $this->assertSame(100, $byName['Alt, Anna']['kostenstelle']);
        $this->assertTrue($byName['Alt, Anna']['produktiv']);
        $this->assertFalse($byName['Alt, Anna']['rueckmeldung'], 'nur ausgehende Nachricht');
        $this->assertSame(200, $byName['Bau, Ben']['kostenstelle'], 'Kostenstelle der Stelle');
        $this->assertFalse($byName['Bau, Ben']['produktiv'], 'Storno zählt nicht');
        $this->assertTrue($byName['Bau, Ben']['rueckmeldung']);

        $this->assertSame([100 => 2], $r['soll'], 'Soll am Stichtag, nur eigenes Team');
        $this->assertSame([100, 200], array_column($r['zeilen'], 'kostenstelle'));
        $this->assertSame(-1, $r['zeilen'][0]['abweichung']);
        $this->assertSame(1, $r['gesamt']['produktiv']);

        $inhalt = Personalreport::inhalt($r, '2026-11-19', 'https://example.test/statistik/mitarbeiter');
        $this->assertSame('Personalreport 19.11.2026', $inhalt['betreff']);
        $this->assertStringContainsString('Düsseldorf &amp; Essen', $inhalt['html']);
        $this->assertStringContainsString('Mönchengladbach', $inhalt['html']);
        $this->assertStringContainsString('−1', $inhalt['html']);
        $this->assertStringContainsString('Gesamt', $inhalt['text']);
        $this->assertStringContainsString('https://example.test/statistik/mitarbeiter', $inhalt['text']);
    }

    public function test_geleertes_soll_hebt_es_ab_seinem_tag_auf(): void
    {
        RecKostenstellenSoll::create(['team_id' => 3, 'kostenstelle' => 100, 'soll' => 4, 'gueltig_ab' => '2026-11-01']);
        RecKostenstellenSoll::create(['team_id' => 3, 'kostenstelle' => 200, 'soll' => 6, 'gueltig_ab' => '2026-11-01']);
        RecKostenstellenSoll::create(['team_id' => 3, 'kostenstelle' => 100, 'soll' => null, 'gueltig_ab' => '2026-11-10']);
        $this->assertSame([100 => 4, 200 => 6], RecKostenstellenSoll::geltend(3, '2026-11-09'));
        $this->assertSame([200 => 6], RecKostenstellenSoll::geltend(3, '2026-11-10'));

        // Zweites Speichern am selben Tag aktualisiert statt die Eindeutigkeit zu verletzen.
        RecKostenstellenSoll::query()->updateOrCreate(['team_id' => 3, 'kostenstelle' => 100, 'gueltig_ab' => '2026-11-10'], ['soll' => 5]);
        $this->assertSame([100 => 5, 200 => 6], RecKostenstellenSoll::geltend(3, '2026-11-10'));
    }

    public function test_niederlassung_nach_altem_letzten_einsatz_auch_ausserhalb_des_ladezeitraums(): void
    {
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => 3, 'rec_person_id' => 70, 'is_active' => 1, 'cost_center' => '100', 'first_name' => 'C', 'last_name' => 'C', 'company' => 'RG'],
            ['id' => 2, 'team_id' => 3, 'rec_person_id' => 70, 'is_active' => 1, 'cost_center' => '200', 'first_name' => 'C', 'last_name' => 'C', 'company' => 'RG'],
        ]);
        $this->einbuchung(2, '2026-07-15', RecDispoAssignment::STATUS_BEENDET);
        $this->einbuchung(1, '2026-07-01', RecDispoAssignment::STATUS_STORNO);

        $r = (new MitarbeiterStatistikDaten())->auswerten(3, '2026-11-19', '2026-10-23', '2026-11-19');
        $this->assertSame(200, $r['personen'][0]['kostenstelle']);
        $this->assertFalse($r['personen'][0]['produktiv']);
    }

    public function test_gesellschaft_rg_ohne_auf_ma_gewechselte_ma_getrennt_beide_einmal(): void
    {
        // Merle Steckel (19.11.): RG-Anstellung aktiv mit ZAS-Status MA, MA-Anstellung inaktiv -> zählt nirgends.
        // Lena: RG GO aktiv und MA aktiv (eine Person) -> RG 1, MA 1, beide 1.
        // Tom: nur MA aktiv.
        DB::table('rec_employees')->insert([
            ['id' => 1, 'team_id' => 3, 'rec_person_id' => 80, 'is_active' => 1, 'cost_center' => '200', 'first_name' => 'Merle', 'last_name' => 'Steckel', 'company' => 'RG'],
            ['id' => 2, 'team_id' => 3, 'rec_person_id' => 80, 'is_active' => 0, 'cost_center' => '201', 'first_name' => 'Merle', 'last_name' => 'Steckel', 'company' => 'MA'],
            ['id' => 3, 'team_id' => 3, 'rec_person_id' => 81, 'is_active' => 1, 'cost_center' => '100', 'first_name' => 'Lena', 'last_name' => 'L', 'company' => 'RG'],
            ['id' => 4, 'team_id' => 3, 'rec_person_id' => 81, 'is_active' => 1, 'cost_center' => '101', 'first_name' => 'Lena', 'last_name' => 'L', 'company' => 'MA'],
            ['id' => 5, 'team_id' => 3, 'rec_person_id' => null, 'is_active' => 1, 'cost_center' => '400', 'first_name' => 'Tom', 'last_name' => 'T', 'company' => 'MA'],
        ]);
        DB::table('rec_employee_hr_data')->insert([
            ['rec_employee_id' => 1, 'export_status' => 'MA'],
            ['rec_employee_id' => 2, 'export_status' => 'INAKTIV'],
            ['rec_employee_id' => 3, 'export_status' => 'GO'],
            ['rec_employee_id' => 4, 'export_status' => 'GO'],
            ['rec_employee_id' => 5, 'export_status' => 'GO'],
        ]);
        // Lena arbeitet nur über die MA-PNr
        $this->einbuchung(4, '2026-11-10');

        $d = new MitarbeiterStatistikDaten();
        $rg = $d->auswerten(3, '2026-11-19', '2026-10-23', '2026-11-19', 'RG');
        $this->assertSame(['L, Lena'], array_column($rg['personen'], 'name'));
        $this->assertFalse($rg['personen'][0]['produktiv'], 'MA-Einsatz zählt nicht für die RG');

        $ma = $d->auswerten(3, '2026-11-19', '2026-10-23', '2026-11-19', 'MA');
        $this->assertSame(['L, Lena', 'T, Tom'], array_column($ma['personen'], 'name'));
        $this->assertTrue($ma['personen'][0]['produktiv']);

        $beide = $d->auswerten(3, '2026-11-19', '2026-10-23', '2026-11-19', 'beide');
        $this->assertSame(['L, Lena', 'T, Tom'], array_column($beide['personen'], 'name'), 'Steckel zählt nirgends, Lena einmal');
        $this->assertTrue($beide['personen'][0]['produktiv']);
        $this->assertSame(2, $beide['gesamt']['aktiv']);
    }
}
