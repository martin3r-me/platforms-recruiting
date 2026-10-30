<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Recruiting\Jobs\SendDispoJobWave;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOfferSender;

/** Welle-Job: plant beim Start neu, sendet ueber den ECHTEN Sender (Meta-Stub), schliesst die Welle. */
class SendDispoJobWaveJobTest extends TestCase
{
    private object $stub;
    private string $tag;

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
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => 0], 'filialen' => [200 => ['code' => 'MGL', 'name' => 'Mönchengladbach']]],
        ]));
        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('config');
        $c->forgetInstance(WhatsAppMetaService::class);
        $c->forgetInstance(DispoChannelResolver::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['integrations_whatsapp_templates', 'rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests',
            'crm_contact_links', 'rec_employees', 'rec_employee_hr_data', 'rec_positions', 'rec_dispo_events', 'rec_dispo_assignments'] as $t) {
            Capsule::table($t)->delete();
        }

        $this->stub = new class {
            public int $calls = 0;
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                $this->calls++;
                return (object) ['id' => 7000 + $this->calls, 'status' => 'sent'];
            }
        };
        Container::getInstance()->instance(WhatsAppMetaService::class, $this->stub);
        Container::getInstance()->instance(DispoChannelResolver::class, new class {
            public function resolveForEvent($event) { return new \Platform\Crm\Models\CommsChannel(); }
        });
    }

    private function employee(string $first, string $phone): int
    {
        return (int) Capsule::table('rec_employees')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => 3, 'first_name' => $first, 'last_name' => 'Muster', 'is_active' => true,
            'phone' => $phone, 'cost_center' => '201', 'portal_token' => 'tok-' . $first, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0:RecDispoJobRequest,1:RecDispoJobWave,2:string} */
    private function anfrage(array $wave = [], ?array $components = null): array
    {
        $components ??= [['type' => 'BODY', 'text' => 'Hi {{name}}'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Ansehen', 'url' => 'https://x/jobs/{{1}}']]]];
        $templateId = (int) Capsule::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-' . mt_rand(), 'external_id' => 'ext-' . mt_rand(), 'user_id' => 1, 'whatsapp_account_id' => 1,
            'name' => 't_jobs', 'language' => 'de', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode($components), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $datum = Carbon::now()->addDays(10)->toDateString();
        $request = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => $datum, 'zeitraum_bis' => $datum,
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF, 'whatsapp_template_id' => $templateId]);
        $request->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $w = $request->waves()->create($wave + ['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false]);

        return [$request, $w, $datum];
    }

    private function cache(): Repository
    {
        return new Repository(new ArrayStore());
    }

    private function sender(): DispoJobOfferSender
    {
        return new DispoJobOfferSender();
    }

    public function test_welle_plant_beim_start_neu_sendet_und_schliesst(): void
    {
        [$request, $wave, $datum] = $this->anfrage();
        $anna = $this->employee('Anna', '0176 12345678');
        $ben = $this->employee('Ben', '0176 87654321');
        $event = RecDispoEvent::create(['einsatz_ref' => 'E1']);
        RecDispoAssignment::create(['ds_ref' => 'd1', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'rec_employee_id' => $ben, 'datum' => $datum, 'status_id' => 1, 'taetigkeit' => 'Zapfer']);
        $cache = $this->cache();

        $job = new SendDispoJobWave($wave->id, 3, 9);
        $job->handle($cache, $this->sender());

        $wave->refresh();
        $this->assertNotNull($wave->started_at);
        $this->assertNotNull($wave->finished_at);
        $this->assertSame(1, $this->stub->calls);
        $this->assertSame(1, $wave->summary['sent']);
        $this->assertSame(1, $wave->summary['skipped_toepfe']['alle_tage_gebucht']);
        $this->assertTrue($wave->summary['done']);
        $this->assertSame(RecDispoJobRequest::STATUS_LAEUFT, $request->fresh()->status);
        $this->assertSame([$anna], RecDispoJobOffer::pluck('anchor_employee_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($cache->get(SendDispoJobWave::cacheKey($wave->job_uuid))['done']);
    }

    /** Spec 2026-10-30 §2: bei Gleichstand zuerst, wer am laengsten keine Anfrage bekam — nicht die kleinere Anker-ID. */
    public function test_welle_schreibt_zuerst_an_wer_am_laengsten_keine_anfrage_bekam(): void
    {
        [$request, $wave] = $this->anfrage();
        $request->update(['welle_max' => 1]);
        $anna = $this->employee('Anna', '0176 12345678');
        $ben = $this->employee('Ben', '0176 87654321');
        // Anna wurde in einer anderen Anfrage schon einmal angeschrieben, Ben nie.
        RecDispoJobOffer::create(['wave_id' => 999, 'request_id' => 999, 'rec_employee_id' => $anna, 'anchor_employee_id' => $anna, 'sent_at' => now()->subDays(3)]);

        $job = new SendDispoJobWave($wave->id, 3, 9);
        $job->handle($this->cache(), $this->sender());

        $wave->refresh();
        $this->assertSame([$ben], RecDispoJobOffer::where('request_id', $request->id)->pluck('anchor_employee_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, $wave->summary['rest'], 'Anna kommt in der naechsten Welle dran');
    }

    public function test_geplante_welle_in_der_zukunft_sendet_nichts(): void
    {
        [, $wave] = $this->anfrage(['scheduled_for' => Carbon::now()->addMinutes(30)]);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertNull($wave->fresh()->started_at);
        $this->assertSame(0, $this->stub->calls);
    }

    public function test_geloeschte_welle_sendet_nichts(): void
    {
        (new SendDispoJobWave(999, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertSame(0, $this->stub->calls);
    }

    public function test_schon_gestartete_welle_laeuft_nicht_zweimal(): void
    {
        [, $wave] = $this->anfrage(['started_at' => Carbon::now()->subMinute()]);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertSame(0, $this->stub->calls);
        $this->assertNull($wave->fresh()->finished_at);
    }

    public function test_fehlgeschlagene_welle_wird_geschlossen(): void
    {
        [, $wave] = $this->anfrage(['started_at' => Carbon::now()->subMinute(), 'job_uuid' => 'u-1']);
        $cache = $this->cache();

        (new SendDispoJobWave($wave->id, 3, 9))->markFailed($cache, 'Timeout');

        $wave->refresh();
        $this->assertNotNull($wave->finished_at);
        $this->assertTrue($wave->summary['done']);
        $this->assertContains('Timeout', $wave->summary['errors']);
        $this->assertTrue($cache->get(SendDispoJobWave::cacheKey('u-1'))['done']);
    }

    public function test_fehlgeschlagene_welle_ohne_job_uuid_wird_trotzdem_geschlossen(): void
    {
        [, $wave] = $this->anfrage();

        (new SendDispoJobWave($wave->id, 3, 9))->markFailed($this->cache(), 'Boom');

        $this->assertNotNull($wave->fresh()->finished_at);
        $this->assertContains('Boom', $wave->fresh()->summary['errors']);
    }

    public function test_start_setzt_started_at_und_job_uuid_zusammen(): void
    {
        [, $wave] = $this->anfrage();

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $wave->refresh();
        $this->assertNotNull($wave->started_at);
        $this->assertNotEmpty($wave->job_uuid);
    }

    /** M3: Vorlage ohne Link-Button — die Welle endet sofort mit einer Fehlerzeile, nichts geht raus. */
    public function test_vorlage_ohne_link_button_sendet_nichts_und_schliesst(): void
    {
        [, $wave] = $this->anfrage([], [['type' => 'BODY', 'text' => 'Hi {{name}}']]);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $wave->refresh();
        $this->assertSame(0, $this->stub->calls);
        $this->assertNotNull($wave->started_at);
        $this->assertNotNull($wave->finished_at);
        $this->assertTrue($wave->summary['done']);
        $this->assertSame(0, $wave->summary['sent']);
        $this->assertCount(1, $wave->summary['errors']);
        $this->assertStringStartsWith('Vorlage passt nicht: ', $wave->summary['errors'][0]);
        $this->assertSame(0, RecDispoJobOffer::count());
    }

    public function test_nicht_freigegebene_vorlage_sendet_nichts(): void
    {
        [$request, $wave] = $this->anfrage();
        Capsule::table('integrations_whatsapp_templates')->where('id', $request->whatsapp_template_id)->update(['status' => 'REJECTED']);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertSame(0, $this->stub->calls);
        $this->assertStringStartsWith('Vorlage passt nicht: ', $wave->fresh()->summary['errors'][0]);
    }

    /** T6: Anfrage geschlossen — der Job beansprucht die Welle nicht und sendet nichts. */
    public function test_geschlossene_anfrage_sendet_nichts(): void
    {
        [$request, $wave] = $this->anfrage();
        $request->update(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN, 'closed_at' => Carbon::now()]);
        $this->employee('Anna', '0176 12345678');

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $this->assertNull($wave->fresh()->started_at);
        $this->assertSame(0, $this->stub->calls);
        $this->assertSame(0, RecDispoJobOffer::count());
    }

    /** I2c: dieselbe Schicht in einer anderen Anfrage schon zugesagt → nicht noch einmal angeboten. */
    public function test_anderswo_zugesagte_schicht_wird_nicht_angeboten(): void
    {
        [$request, $wave, $datum] = $this->anfrage();
        $anna = $this->employee('Anna', '0176 12345678');
        $andere = RecDispoJobRequest::create(['team_id' => 3, 'filial_nr' => 200, 'zeitraum_von' => $datum, 'zeitraum_bis' => $datum,
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $shift = $andere->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $w = $andere->waves()->create(['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false]);
        $w->offers()->create(['request_id' => $andere->id, 'rec_employee_id' => $anna, 'anchor_employee_id' => $anna])
            ->shifts()->create(['shift_id' => $shift->id, 'accepted_at' => Carbon::now()]);

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());

        $wave->refresh();
        $this->assertSame(0, $this->stub->calls);
        $this->assertSame(1, $wave->summary['skipped_toepfe']['schon_zugesagt']);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        $crm = self::packageRootOf(CrmContactLink::class);
        $integrations = self::packageRootOf(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class);
        $files = [
            [$own, 'database/migrations/2026_02_09_000001_create_rec_positions_table.php'],
            [$own, 'database/migrations/2026_05_21_000006_add_cost_center_to_rec_positions.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php'],
            [$own, 'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php'],
            [$own, 'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own, 'database/migrations/2026_05_21_000004_add_linen_package_to_hr_data.php'],
            [$own, 'database/migrations/2026_09_15_000001_add_dispo_taetigkeiten_to_hr_data.php'],
            [$own, 'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php'],
            [$own, 'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php'],
            [$own, 'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php'],
            [$own, 'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php'],
            [$own, 'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php'],
            [$own, 'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
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
        $dir = dirname((string) (new \ReflectionClass($class))->getFileName());
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    public function test_welle_mit_status_ma_schreibt_go_nicht_an_und_alte_welle_nimmt_beide(): void
    {
        [, $wave] = $this->anfrage(['stati' => ['MA']]);
        $this->employee('Anna', '0176 12345678'); // ohne hrData = GO

        (new SendDispoJobWave($wave->id, 3, 9))->handle($this->cache(), $this->sender());
        $this->assertSame(0, $this->stub->calls, 'nur MA gewaehlt: GO bleibt aussen vor');
        $this->assertSame(0, array_sum($wave->fresh()->summary['skipped_toepfe']), 'Status ist Grundmenge, kein Topf');

        [, $alt] = $this->anfrage();
        $this->assertNull($alt->fresh()->stati);
        (new SendDispoJobWave($alt->id, 3, 9))->handle($this->cache(), $this->sender());
        $this->assertSame(1, $this->stub->calls, 'alte Welle ohne Status = GO + MA');
    }

    /** Job, der die Folgewelle nur anlegt statt sie in die Warteschlange zu geben (kein Bus im Test). */
    private function jobOhneBus(int $waveId): SendDispoJobWave
    {
        return new class($waveId, 3, 9) extends SendDispoJobWave {
            public array $gestartet = [];
            protected function folgewelleStarten(\Platform\Recruiting\Models\RecDispoJobWave $wave): void
            {
                $this->gestartet[] = (int) $wave->id;
            }
        };
    }

    public function test_folgewelle_entsteht_mit_abstand_und_gleichen_filtern(): void
    {
        Carbon::setTestNow('2026-10-27 10:00:00');
        [$request, $wave] = $this->anfrage(['stati' => ['GO'], 'firmen' => ['RG']]);
        $request->update(['welle_max' => 1, 'welle_abstand_minuten' => 360]);
        $this->employee('Anna', '0176 12345678');
        $this->employee('Ben', '0176 87654321');

        $job = $this->jobOhneBus($wave->id);
        $job->handle($this->cache(), $this->sender());

        $this->assertSame(1, $this->stub->calls, 'Obergrenze 1');
        $wave->refresh();
        $this->assertSame(1, $wave->summary['rest']);
        $this->assertSame(1, $wave->summary['skipped_toepfe']['spaeter']);
        $naechste = $request->waves()->where('nr', 2)->first();
        $this->assertNotNull($naechste, 'Folgewelle angelegt');
        $this->assertTrue((bool) $naechste->auto);
        $this->assertSame('2026-10-27 16:00:00', $naechste->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame(['GO'], $naechste->stati);
        $this->assertSame(['RG'], $naechste->firmen);
        $this->assertSame([200], $naechste->ma_bloecke);
        $this->assertSame([(int) $naechste->id], $job->gestartet);
        $this->assertSame(2, $wave->summary['naechste_welle_nr']);
        Carbon::setTestNow();
    }

    public function test_keine_folgewelle_ohne_abstand_oder_ohne_rest(): void
    {
        [$request, $wave] = $this->anfrage();
        $request->update(['welle_max' => 1, 'welle_abstand_minuten' => null]);
        $this->employee('Anna', '0176 12345678');
        $this->employee('Ben', '0176 87654321');
        $job = $this->jobOhneBus($wave->id);
        $job->handle($this->cache(), $this->sender());
        $this->assertSame(1, $request->waves()->count(), 'ohne Abstand keine Folgewelle');
        $this->assertSame([], $job->gestartet);

        [$request2, $wave2] = $this->anfrage();
        $request2->update(['welle_max' => 10, 'welle_abstand_minuten' => 60]);
        $job2 = $this->jobOhneBus($wave2->id);
        $job2->handle($this->cache(), $this->sender());
        $this->assertSame(1, $request2->waves()->count(), 'alle passten in die Welle: kein Rest, keine Folgewelle');
    }

    public function test_auto_welle_bei_gedecktem_bedarf_sendet_nichts_und_kettet_nicht(): void
    {
        [$request, $wave] = $this->anfrage();
        $request->update(['welle_max' => 1, 'welle_abstand_minuten' => 60]);
        $shift = $request->shifts()->first();
        $shift->update(['gesucht' => 1]);
        $anna = $this->employee('Anna', '0176 12345678');
        $this->employee('Ben', '0176 87654321');
        // Anna hat (aus einer frueheren Welle) schon zugesagt: Bedarf 1 von 1 gedeckt.
        $alt = $request->waves()->create(['nr' => 0, 'ma_bloecke' => [200], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false, 'started_at' => now(), 'finished_at' => now()]);
        $offer = RecDispoJobOffer::create(['wave_id' => $alt->id, 'request_id' => $request->id, 'rec_employee_id' => $anna, 'anchor_employee_id' => $anna, 'sent_at' => now()]);
        \Platform\Recruiting\Models\RecDispoJobOfferShift::create(['offer_id' => $offer->id, 'shift_id' => $shift->id, 'accepted_at' => now()]);
        $wave->update(['auto' => true]);

        $job = $this->jobOhneBus($wave->id);
        $job->handle($this->cache(), $this->sender());

        $this->assertSame(0, $this->stub->calls);
        $this->assertSame('bedarf_gedeckt', $wave->fresh()->summary['gestoppt']);
        $this->assertNotNull($wave->fresh()->finished_at);
        $this->assertSame(2, $request->waves()->count(), 'keine dritte Welle');
    }

    public function test_von_hand_gedeckte_schicht_wird_nicht_angeboten(): void
    {
        [$request, $wave] = $this->anfrage();
        $request->shifts()->first()->update(['gedeckt_manuell_at' => now(), 'closed_at' => now()]);
        $this->employee('Anna', '0176 12345678');
        $job = $this->jobOhneBus($wave->id);
        $job->handle($this->cache(), $this->sender());
        $this->assertSame(0, $this->stub->calls);
        $this->assertSame('bedarf_gedeckt', $wave->fresh()->summary['gestoppt']);
    }

    public function test_keine_zweite_kette_wenn_schon_eine_welle_wartet_und_auto_welle_stoppt_bei_automatik_aus(): void
    {
        [$request, $wave] = $this->anfrage();
        $request->update(['welle_max' => 1, 'welle_abstand_minuten' => 60]);
        $this->employee('Anna', '0176 12345678');
        $this->employee('Ben', '0176 87654321');
        $wartend = $request->waves()->create(['nr' => 2, 'ma_bloecke' => [200], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false, 'auto' => true, 'scheduled_for' => now()->addHour()]);

        $job = $this->jobOhneBus($wave->id);
        $job->handle($this->cache(), $this->sender());
        $this->assertSame(2, $request->waves()->count(), 'die wartende Welle reicht, keine dritte');
        $this->assertSame([], $job->gestartet);

        $request->update(['welle_abstand_minuten' => null]);
        $wartend->update(['scheduled_for' => now()->subMinute()]);
        $this->jobOhneBus($wartend->id)->handle($this->cache(), $this->sender());
        $this->assertSame('automatik_aus', $wartend->fresh()->summary['gestoppt']);
        $this->assertSame(1, $this->stub->calls, 'die gestoppte Automatik-Welle hat nichts gesendet');
    }
}
