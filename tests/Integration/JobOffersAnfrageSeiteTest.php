<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Livewire\Public\JobOffers;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Markus 29.10.: „der 04.11. ist verkehrt für die Nachricht“ — der Link aus
 * einer Nachricht zeigt nur die Anfrage, aus der die Nachricht kam
 * („{token}.{anfrage}“). Alte Links ohne Anfrage zeigen weiter alles. „Einmal
 * pro Tag“ gilt trotzdem ueber alle Anfragen.
 */
class JobOffersAnfrageSeiteTest extends TestCase
{
    private const TEAM = 613;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]]]));
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_dispo_assignments', 'rec_dispo_events', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private function employee(string $token): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Markus', 'last_name' => 'Muster',
            'personnel_number' => 'MA-' . $token, 'portal_token' => $token, 'is_active' => true,
        ]);
    }

    /** Laufende Anfrage mit EINER Schicht an $datum fuer $event, angeboten an $employee. @return array{0:int,1:int} request id, offer_shift id */
    private function anfrage(RecEmployee $employee, int $event, string $datum, string $anlass, string $von = '17:00'): array
    {
        $request = RecDispoJobRequest::create(['team_id' => self::TEAM, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'anlass' => $anlass, 'created_by_user_id' => 1]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => $von, 'taetigkeit' => 'Service', 'gesucht' => 5]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0']]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $employee->id, 'anchor_employee_id' => $employee->id]);
        $os = $offer->shifts()->create(['shift_id' => $shift->id]);

        return [(int) $request->id, (int) $os->id];
    }

    private function seite(string $link): JobOffers
    {
        $c = new JobOffers();
        $c->mount($link);

        return $c;
    }

    public function test_link_mit_anfrage_zeigt_nur_diese_anfrage(): void
    {
        $emp = $this->employee('tok-sep-1');
        $schalke = RecDispoEvent::create(['einsatz_ref' => 'E-SCHALKE', 'name' => 'Schalke Kiosk']);
        $broich  = RecDispoEvent::create(['einsatz_ref' => 'E-BROICH', 'name' => 'Broich Summit']);
        [$dringend] = $this->anfrage($emp, $schalke->id, now()->addDays(2)->toDateString(), 'dringend');
        [$wochen]   = $this->anfrage($emp, $broich->id, now()->addDays(6)->toDateString(), 'vier_wochen');

        $seite = $this->seite('tok-sep-1.' . $dringend);
        $this->assertSame($dringend, $seite->anfrageId);
        $this->assertFalse($seite->tokenInvalid, 'Token wird ohne den Anfrage-Teil gesucht');
        $this->assertSame(['Schalke Kiosk'], array_column($seite->angebote(), 'name'));

        $seite = $this->seite('tok-sep-1.' . $wochen);
        $this->assertSame(['Broich Summit'], array_column($seite->angebote(), 'name'));

        $seite = $this->seite('tok-sep-1');
        $this->assertNull($seite->anfrageId);
        $this->assertEqualsCanonicalizing(['Schalke Kiosk', 'Broich Summit'], array_column($seite->angebote(), 'name'), 'alter Link ohne Anfrage: alles wie bisher');

        $seite = $this->seite('tok-sep-1.999999');
        $this->assertSame([], $seite->angebote(), 'fremde/abgeschlossene Anfrage: nichts, kein Durchrutschen auf alles');
    }

    public function test_einmal_pro_tag_gilt_ueber_die_getrennten_seiten_hinweg(): void
    {
        $emp = $this->employee('tok-sep-2');
        $tag = now()->addDays(3)->toDateString();
        $a = RecDispoEvent::create(['einsatz_ref' => 'E-A', 'name' => 'VA A']);
        $b = RecDispoEvent::create(['einsatz_ref' => 'E-B', 'name' => 'VA B']);
        [$anfrageA] = $this->anfrage($emp, $a->id, $tag, 'dringend', '13:00');
        [, $osB]    = $this->anfrage($emp, $b->id, $tag, 'naechste_woche', '18:00');
        \Platform\Recruiting\Models\RecDispoJobOfferShift::query()->whereKey($osB)->update(['accepted_at' => now()]);

        $seite = $this->seite('tok-sep-2.' . $anfrageA);
        $this->assertSame(['VA A'], array_column($seite->angebote(), 'name'), 'Seite zeigt nur Anfrage A');
        $schicht = $seite->angebote()[0]['days'][$tag][0];
        $this->assertTrue($schicht['tag_belegt'], 'Zusage in Anfrage B sperrt den Tag auch auf der Seite von A');
        $this->assertFalse($schicht['accepted']);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);
        $files = [
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php'],
            [$own, 'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php'],
            [$own, 'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php'],
        ];
        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
