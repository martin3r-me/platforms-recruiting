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
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersLaden;

/**
 * Spec 2026-11-13 §6.5: Karte „N neue Einsätze“ und Liste „Freie Einsätze“
 * kommen aus derselben Berechnung (JobOffersLaden) — Karte und Liste duerfen
 * nie auseinanderlaufen.
 */
class JobOffersLadenTest extends TestCase
{
    private const TEAM = 614;

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

    private function employee(string $token, array $extra = []): RecEmployee
    {
        return RecEmployee::create($extra + [
            'team_id' => self::TEAM, 'first_name' => 'Markus', 'last_name' => 'Muster',
            'personnel_number' => 'MA-' . $token, 'portal_token' => $token, 'is_active' => true,
        ]);
    }

    /**
     * Anfrage mit EINER Schicht an $datum, angeboten an $employee.
     *
     * @return array{0:int,1:int,2:int} request id, offer_shift id, shift id
     */
    private function anfrage(RecEmployee $employee, int $event, string $datum, array $opt = []): array
    {
        $opt += ['anlass' => 'vier_wochen', 'von' => '17:00', 'status' => RecDispoJobRequest::STATUS_LAEUFT, 'kanal' => 'whatsapp', 'modus' => RecDispoJobRequest::MODUS_SAMMELN];
        $request = RecDispoJobRequest::create(['team_id' => self::TEAM, 'filial_nr' => 200, 'zeitraum_von' => '2026-10-20', 'zeitraum_bis' => '2099-11-17',
            'modus' => $opt['modus'], 'status' => $opt['status'], 'anlass' => $opt['anlass'], 'created_by_user_id' => 1]);
        $shift = $request->shifts()->create(['rec_dispo_event_id' => $event, 'datum' => $datum, 'von' => $opt['von'], 'taetigkeit' => 'Service', 'gesucht' => 5]);
        $wave = $request->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['0']]);
        $offer = $wave->offers()->create(['request_id' => $request->id, 'rec_employee_id' => $employee->id, 'anchor_employee_id' => $employee->id, 'kanal' => $opt['kanal']]);
        $os = $offer->shifts()->create(['shift_id' => $shift->id]);

        return [(int) $request->id, (int) $os->id, (int) $shift->id];
    }

    private function event(string $name): int
    {
        return (int) RecDispoEvent::create(['einsatz_ref' => 'E-' . $name, 'name' => $name])->id;
    }

    private function tag(int $plus): string
    {
        return now()->addDays($plus)->toDateString();
    }

    public function test_gruppen_ist_genau_was_die_angebotsseite_zeigt(): void
    {
        $emp = $this->employee('tok-laden-1');
        [$dringend] = $this->anfrage($emp, $this->event('Schalke Kiosk'), $this->tag(2), ['anlass' => 'dringend']);
        $this->anfrage($emp, $this->event('Broich Summit'), $this->tag(6));

        $seite = new JobOffers();
        $seite->mount('tok-laden-1');

        $this->assertSame($seite->angebote(), JobOffersLaden::gruppen([(int) $emp->id], (int) $emp->id, 'Markus'));
        $this->assertCount(2, $seite->angebote());

        $seite = new JobOffers();
        $seite->mount('tok-laden-1.' . $dringend);
        $this->assertSame($seite->angebote(), JobOffersLaden::gruppen([(int) $emp->id], (int) $emp->id, 'Markus', $dringend));
        $this->assertSame(['Schalke Kiosk'], array_column($seite->angebote(), 'name'));
    }

    public function test_zusagbar_zaehlt_nur_schichten_mit_zusage_knopf(): void
    {
        $schicht = ['accepted' => false, 'declined' => false, 'voll' => false, 'tag_belegt' => false];
        $gruppen = [
            ['days' => [
                '2099-01-01' => [$schicht, ['accepted' => true] + $schicht],
                '2099-01-02' => [['declined' => true] + $schicht, ['voll' => true] + $schicht],
            ]],
            ['days' => [
                '2099-01-03' => [['tag_belegt' => true] + $schicht, $schicht],
            ]],
        ];

        $this->assertSame(2, JobOffersLaden::zusagbar($gruppen));
        $this->assertSame(0, JobOffersLaden::zusagbar([]));
    }

    public function test_anzahl_ist_die_zahl_der_zeilen_mit_zusage_knopf(): void
    {
        $emp = $this->employee('tok-laden-2');
        $andere = $this->employee('tok-laden-2b');

        // offen (WhatsApp) + offen (Portal): beide zaehlen
        $this->anfrage($emp, $this->event('Offen WA'), $this->tag(3));
        $this->anfrage($emp, $this->event('Offen Portal'), $this->tag(4), ['kanal' => 'portal']);
        // zugesagt am Tag 5, zweite Schicht am selben Tag ist dann tag-belegt
        [, $zugesagt] = $this->anfrage($emp, $this->event('Zugesagt'), $this->tag(5), ['von' => '09:00']);
        RecDispoJobOfferShift::query()->whereKey($zugesagt)->update(['accepted_at' => now()]);
        $this->anfrage($emp, $this->event('Tag belegt'), $this->tag(5), ['von' => '18:00']);
        // abgesagt
        [, $abgesagt] = $this->anfrage($emp, $this->event('Abgesagt'), $this->tag(6), ['anlass' => 'dringend']);
        RecDispoJobOfferShift::query()->whereKey($abgesagt)->update(['declined_at' => now()]);
        // voll (Schicht geschlossen)
        [, , $vollShift] = $this->anfrage($emp, $this->event('Voll'), $this->tag(7));
        Capsule::table('rec_dispo_job_shifts')->where('id', $vollShift)->update(['closed_at' => now()]);
        // wartet auf Freigabe: Anfrage im Entwurf
        $this->anfrage($emp, $this->event('Entwurf'), $this->tag(8), ['status' => RecDispoJobRequest::STATUS_ENTWURF, 'kanal' => 'portal']);
        // fremde Person
        $this->anfrage($andere, $this->event('Fremd'), $this->tag(9));

        $gruppen = JobOffersLaden::gruppen([(int) $emp->id], (int) $emp->id, 'Markus');
        $knoepfe = 0;
        foreach ($gruppen as $g) {
            foreach ($g['days'] as $schichten) {
                foreach ($schichten as $s) {
                    if (!$s['accepted'] && !$s['voll'] && !$s['declined'] && !$s['tag_belegt']) {
                        $knoepfe++;
                    }
                }
            }
        }

        $this->assertNotContains('Entwurf', array_column($gruppen, 'name'), 'wartet auf Freigabe: nicht sichtbar');
        $this->assertSame(2, $knoepfe);
        $this->assertSame(2, JobOffersLaden::anzahl([(int) $emp->id], (int) $emp->id));
    }

    public function test_gesperrtes_portal_oder_inaktiver_datensatz_zaehlt_null(): void
    {
        $gesperrt = $this->employee('tok-laden-3', ['portal_locked_at' => now()]);
        $this->anfrage($gesperrt, $this->event('A'), $this->tag(3));
        $this->assertSame(0, JobOffersLaden::anzahl([(int) $gesperrt->id], (int) $gesperrt->id));

        $inaktiv = $this->employee('tok-laden-4', ['is_active' => false]);
        $this->anfrage($inaktiv, $this->event('B'), $this->tag(3));
        $this->assertSame(0, JobOffersLaden::anzahl([(int) $inaktiv->id], (int) $inaktiv->id));
    }

    /** Fixture: zwei zusagbare Angebote (WhatsApp + Portal), eines zugesagt. */
    private function zweiOffene(string $token): RecEmployee
    {
        $emp = $this->employee($token);
        $this->anfrage($emp, $this->event('WA ' . $token), $this->tag(3));
        $this->anfrage($emp, $this->event('Portal ' . $token), $this->tag(4), ['kanal' => 'portal']);
        [, $zugesagt] = $this->anfrage($emp, $this->event('Zu ' . $token), $this->tag(5));
        RecDispoJobOfferShift::query()->whereKey($zugesagt)->update(['accepted_at' => now()]);

        return $emp;
    }

    /** Fehler beim Zaehlen simulieren: Tabelle kurz weg. */
    private function ohneAngebotsTabelle(\Closure $lauf): mixed
    {
        Capsule::schema()->rename('rec_dispo_job_offer_shifts', 'rec_dispo_job_offer_shifts_weg');
        try {
            return $lauf();
        } finally {
            Capsule::schema()->rename('rec_dispo_job_offer_shifts_weg', 'rec_dispo_job_offer_shifts');
        }
    }

    public function test_portal_startkarte_zaehlt_ueber_den_dienst_und_ueberlebt_fehler(): void
    {
        $emp = $this->zweiOffene('tok-laden-5');
        $methode = new \ReflectionMethod(\Platform\Recruiting\Livewire\Public\PortalShell::class, 'neueAngebote');
        $shell = new \Platform\Recruiting\Livewire\Public\PortalShell();

        $this->assertSame(2, $methode->invoke($shell, $emp));
        $this->assertSame(0, $this->ohneAngebotsTabelle(fn () => $methode->invoke($shell, $emp)), 'Fehler kostet nur die Karte');
    }

    public function test_einsatz_seite_karte_nur_eigenstaendig_und_fehlerfest(): void
    {
        $this->zweiOffene('tok-laden-6');

        $seite = new \Platform\Recruiting\Livewire\Public\EmployeeAssignments();
        $seite->mount('tok-laden-6');
        $this->assertSame(2, $seite->neueAngebote());
        $this->assertSame(0, $this->ohneAngebotsTabelle(fn () => $seite->neueAngebote()), 'Fehler kostet nur die Karte');

        $eingebettet = new \Platform\Recruiting\Livewire\Public\EmployeeAssignments();
        $eingebettet->mount('tok-laden-6', eingebettet: true);
        $this->assertSame(0, $eingebettet->neueAngebote(), 'im Portal steht die Karte schon auf der Startseite');
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
            [$own, 'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php'],
            [$own, 'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php'],
            [$own, 'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php'],
            [$own, 'database/migrations/2026_10_29_000002_add_dispoposten_id_to_rec_dispo_job_shifts.php'],
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
