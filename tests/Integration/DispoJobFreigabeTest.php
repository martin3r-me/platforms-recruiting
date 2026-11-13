<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Jobs\Show;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobFreigabe;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVersandweg;

/**
 * Freigabe „Jobs anbieten“ (Spec 2026-11-13 §6.4, Markus 13.11.2026): nur der
 * Freigeber entscheidet, jede Entscheidung genau einmal, danach genau ein Start
 * von Welle 1. Gezaehlt wird ueber die ueberschreibbare Start-Stelle (kein Bus).
 */
class DispoJobFreigabeTest extends TestCase
{
    private const TEAM = 3;

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
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php',
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
            'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php',
            'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php',
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
            'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Carbon::setTestNow();
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Container::getInstance()->forgetInstance(BusDispatcher::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-11-13 10:00:00');
        foreach (['rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests', 'rec_applicant_settings'] as $t) {
            Capsule::table($t)->delete();
        }
        FreigabeZaehler::$gestartet = [];
        $s = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        $s->setSetting(DispoJobVersandweg::SETTING_AKTIV, true);
        $s->setSetting(DispoJobVersandweg::SETTING_FREIGEBER, ' Chef@Example.de ');
        $s->save();
    }

    private static function user(int $id, string $email): object
    {
        return (object) ['id' => $id, 'email' => $email];
    }

    private static function freigeber(): object
    {
        return self::user(7, 'chef@example.de');
    }

    /** Wartende WhatsApp-Anfrage wie aus Create: Welle 1 liegt in der Tabelle, nicht in der Schlange. */
    private static function anfrage(array $o = [], array $welle = []): RecDispoJobRequest
    {
        $datum = Carbon::now()->addDays(10)->toDateString();
        $r = RecDispoJobRequest::create($o + ['team_id' => self::TEAM, 'filial_nr' => 200, 'zeitraum_von' => $datum, 'zeitraum_bis' => $datum,
            'modus' => RecDispoJobRequest::MODUS_SAMMELN, 'status' => RecDispoJobRequest::STATUS_ENTWURF, 'whatsapp_template_id' => 5,
            'versandweg' => RecDispoJobRequest::WEG_WHATSAPP, 'freigabe_pflicht' => true,
            'freigabe_beantragt_at' => now(), 'freigabe_beantragt_by_user_id' => 2, 'welle_max' => 40]);
        $r->shifts()->create(['rec_dispo_event_id' => 1, 'datum' => $datum, 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 5]);
        $r->waves()->create($welle + ['nr' => 1, 'ma_bloecke' => [200], 'gruppen' => ['alle'], 'nur_passende_taetigkeit' => false]);

        return $r;
    }

    public function test_fremder_ist_nicht_berechtigt_und_nichts_aendert_sich(): void
    {
        $r = self::anfrage();

        $this->assertSame('nicht_berechtigt', FreigabeZaehler::freigeben($r, self::user(9, 'dispo@example.de'), self::TEAM));
        $this->assertSame('nicht_berechtigt', FreigabeZaehler::freigeben($r, null, self::TEAM));
        $this->assertSame('nicht_berechtigt', FreigabeZaehler::ablehnen($r, self::user(9, 'dispo@example.de'), 'nein'));

        $r->refresh();
        $this->assertNull($r->freigegeben_at);
        $this->assertNull($r->freigabe_abgelehnt_at);
        $this->assertTrue($r->wartetAufFreigabe());
        $this->assertSame([], FreigabeZaehler::$gestartet);
    }

    public function test_ohne_hinterlegten_freigeber_darf_niemand_freigeben(): void
    {
        $s = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        $s->setSetting(DispoJobVersandweg::SETTING_FREIGEBER, '');
        $s->save();
        $r = self::anfrage();

        $this->assertSame('nicht_berechtigt', FreigabeZaehler::freigeben($r, self::user(9, ''), self::TEAM));
        $this->assertSame([], FreigabeZaehler::$gestartet);
    }

    public function test_freigeber_gibt_frei_genau_ein_start_zweiter_aufruf_nicht_wartend(): void
    {
        $r = self::anfrage();
        $welle = (int) $r->waves()->value('id');

        $this->assertSame('ok', FreigabeZaehler::freigeben($r, self::freigeber(), self::TEAM));
        $r->refresh();
        $this->assertSame('2026-11-13 10:00:00', $r->freigegeben_at->format('Y-m-d H:i:s'));
        $this->assertSame(7, $r->freigegeben_by_user_id);
        $this->assertTrue($r->darfSenden());
        $this->assertFalse($r->wartetAufFreigabe());
        $this->assertSame([[$welle, null]], FreigabeZaehler::$gestartet);

        // Review Focus 2: Doppelklick / zweiter Tab startet nicht noch einmal.
        $this->assertSame('nicht_wartend', FreigabeZaehler::freigeben($r, self::freigeber(), self::TEAM));
        $this->assertSame('nicht_wartend', FreigabeZaehler::freigeben(RecDispoJobRequest::find($r->id), self::freigeber(), self::TEAM));
        $this->assertCount(1, FreigabeZaehler::$gestartet);

        // Ablehnen nach Freigabe aendert nichts mehr.
        $this->assertSame('nicht_wartend', FreigabeZaehler::ablehnen($r, self::freigeber(), 'doch nicht'));
        $r->refresh();
        $this->assertNull($r->freigabe_abgelehnt_at);
        $this->assertNull($r->freigabe_ablehnungsgrund);
    }

    public function test_geplanter_start_in_der_vergangenheit_startet_sofort(): void
    {
        // Review Focus 1: „in 30 Minuten“ beim Anlegen, freigegeben erst am naechsten Tag.
        $r = self::anfrage([], ['scheduled_for' => Carbon::now()->subDay()]);

        $this->assertSame('ok', FreigabeZaehler::freigeben($r, self::freigeber(), self::TEAM));
        $welle = RecDispoJobWave::query()->where('request_id', $r->id)->first();
        $this->assertNull($welle->scheduled_for);
        $this->assertSame([[(int) $welle->id, null]], FreigabeZaehler::$gestartet);
    }

    public function test_geplanter_start_in_der_zukunft_bleibt_verzoegert(): void
    {
        $r = self::anfrage([], ['scheduled_for' => Carbon::now()->addMinutes(20)]);

        $this->assertSame('ok', FreigabeZaehler::freigeben($r, self::freigeber(), self::TEAM));
        $welle = RecDispoJobWave::query()->where('request_id', $r->id)->first();
        $this->assertSame('2026-11-13 10:20:00', $welle->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame([[(int) $welle->id, '2026-11-13 10:20:00']], FreigabeZaehler::$gestartet);
    }

    public function test_geschlossene_und_pflichtfreie_anfrage_ist_nicht_wartend(): void
    {
        $zu = self::anfrage(['closed_at' => now(), 'status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN]);
        $this->assertSame('nicht_wartend', FreigabeZaehler::freigeben($zu, self::freigeber(), self::TEAM));

        $ohne = self::anfrage(['freigabe_pflicht' => false]);
        $this->assertSame('nicht_wartend', FreigabeZaehler::freigeben($ohne, self::freigeber(), self::TEAM));
        $this->assertNull($ohne->fresh()->freigegeben_at);
        $this->assertSame([], FreigabeZaehler::$gestartet);
    }

    public function test_ablehnen_speichert_grund_gekuerzt_und_startet_nichts(): void
    {
        $r = self::anfrage();

        $this->assertSame('ok', FreigabeZaehler::ablehnen($r, self::freigeber(), '   ' . str_repeat('ä', 300) . '  '));
        $r->refresh();
        $this->assertSame(str_repeat('ä', 255), $r->freigabe_ablehnungsgrund);
        $this->assertSame(7, $r->freigabe_abgelehnt_by_user_id);
        $this->assertNotNull($r->freigabe_abgelehnt_at);
        $this->assertTrue($r->freigabeAbgelehnt());
        $this->assertFalse($r->darfSenden());
        $this->assertSame([], FreigabeZaehler::$gestartet);

        // Freigeben nach Ablehnung: nicht mehr wartend.
        $this->assertSame('nicht_wartend', FreigabeZaehler::freigeben($r, self::freigeber(), self::TEAM));
        $this->assertSame('nicht_wartend', FreigabeZaehler::ablehnen($r, self::freigeber(), 'nochmal'));
        $this->assertSame([], FreigabeZaehler::$gestartet);
    }

    public function test_ablehnen_ohne_grund_speichert_null(): void
    {
        $r = self::anfrage();
        $this->assertSame('ok', FreigabeZaehler::ablehnen($r, self::freigeber(), '   '));
        $this->assertNull($r->fresh()->freigabe_ablehnungsgrund);
    }

    public function test_ins_portal_nur_aus_abgelehnt(): void
    {
        $wartend = self::anfrage();
        $this->assertSame('nicht_wartend', FreigabeZaehler::insPortal($wartend, self::TEAM, 9));
        $this->assertFalse($wartend->fresh()->istPortal());
        $this->assertSame([], FreigabeZaehler::$gestartet);

        $r = self::anfrage([], ['scheduled_for' => Carbon::now()->subHour()]);
        FreigabeZaehler::ablehnen($r, self::freigeber(), 'lieber Portal');
        $this->assertSame('ok', FreigabeZaehler::insPortal($r, self::TEAM, 9));
        $r->refresh();
        $this->assertTrue($r->istPortal());
        $this->assertTrue($r->darfSenden());
        $this->assertFalse($r->freigabeAbgelehnt());
        $this->assertSame('lieber Portal', $r->freigabe_ablehnungsgrund, 'Ablehnung bleibt als Verlauf');
        $this->assertNotNull($r->freigabe_abgelehnt_at);
        $welle = (int) $r->waves()->value('id');
        $this->assertSame([[$welle, null]], FreigabeZaehler::$gestartet);

        // Zweiter Klick: nichts mehr zu tun.
        $this->assertSame('nicht_wartend', FreigabeZaehler::insPortal($r, self::TEAM, 9));
        $this->assertCount(1, FreigabeZaehler::$gestartet);

        // Geschlossene abgelehnte Anfrage geht nicht mehr ins Portal.
        $zu = self::anfrage();
        FreigabeZaehler::ablehnen($zu, self::freigeber(), '');
        $zu->update(['closed_at' => now(), 'status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN]);
        $this->assertSame('nicht_wartend', FreigabeZaehler::insPortal($zu, self::TEAM, 9));
        $this->assertCount(1, FreigabeZaehler::$gestartet);
    }

    public function test_wartend_zaehlt_nur_offene_unentschiedene_des_teams(): void
    {
        self::anfrage();
        self::anfrage();
        self::anfrage(['closed_at' => now(), 'status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN]);
        self::anfrage(['status' => RecDispoJobRequest::STATUS_ABGESCHLOSSEN]);
        self::anfrage(['freigegeben_at' => now()]);
        self::anfrage(['freigabe_abgelehnt_at' => now()]);
        self::anfrage(['freigabe_pflicht' => false]);
        self::anfrage(['team_id' => 99]);

        $this->assertSame(2, DispoJobFreigabe::wartend(self::TEAM));
        $this->assertSame(2, DispoJobFreigabe::wartend(), 'ohne Team: inbound_team_id');
        $this->assertSame(1, DispoJobFreigabe::wartend(99));
    }

    // ------------------------------------------------------------------
    // Gesamtpruefung I2/I3 (13.11.2026)
    // ------------------------------------------------------------------

    public function test_freigeben_ohne_wartende_welle_meldet_keine_welle_und_baut_keine_nach(): void
    {
        $r = self::anfrage();
        RecDispoJobWave::query()->where('request_id', $r->id)->delete(); // Altfall: vor der Sperre abgebrochen

        $this->assertSame('keine_welle', FreigabeZaehler::freigeben($r, self::freigeber(), self::TEAM));
        $r->refresh();
        $this->assertNotNull($r->freigegeben_at, 'Entscheidung bleibt gespeichert');
        $this->assertTrue($r->darfSenden());
        $this->assertSame(0, RecDispoJobWave::query()->where('request_id', $r->id)->count(), 'keine Welle nachgebaut');
        $this->assertSame([], FreigabeZaehler::$gestartet);
    }

    public function test_ins_portal_ohne_wartende_welle_meldet_keine_welle(): void
    {
        $r = self::anfrage();
        FreigabeZaehler::ablehnen($r, self::freigeber(), '');
        RecDispoJobWave::query()->where('request_id', $r->id)->delete();

        $this->assertSame('keine_welle', FreigabeZaehler::insPortal($r, self::TEAM, 9));
        $this->assertTrue($r->fresh()->istPortal(), 'Entscheidung bleibt gespeichert');
        $this->assertSame(0, RecDispoJobWave::query()->where('request_id', $r->id)->count());
        $this->assertSame([], FreigabeZaehler::$gestartet);
    }

    public function test_show_abbrechen_verweigert_wartende_und_abgelehnte_welle(): void
    {
        $r = self::anfrage();
        $welle = (int) $r->waves()->value('id');
        $show = self::show($r);

        $show->abbrechen($welle);
        $this->assertSame('Die Anfrage wartet auf Freigabe.', $show->fehler);
        $this->assertSame(1, RecDispoJobWave::query()->whereKey($welle)->count());

        FreigabeZaehler::ablehnen($r, self::freigeber(), '');
        $show->abbrechen($welle);
        $this->assertSame('Die Freigabe wurde abgelehnt.', $show->fehler);
        $this->assertSame(1, RecDispoJobWave::query()->whereKey($welle)->count());

        // Nach „Stattdessen im Portal“ darf gesendet werden — dann geht Abbrechen wieder.
        FreigabeZaehler::insPortal($r, self::TEAM, 9);
        $show->abbrechen($welle);
        $this->assertSame('', $show->fehler);
        $this->assertSame(0, RecDispoJobWave::query()->whereKey($welle)->count());
    }

    public function test_show_freigeben_ohne_wartende_welle_sagt_es(): void
    {
        $r = self::anfrage();
        RecDispoJobWave::query()->where('request_id', $r->id)->delete();
        $show = self::show($r);
        self::mitAuth(self::freigeber());

        $show->freigeben();
        $this->assertSame('', $show->fehler);
        $this->assertSame('Freigegeben. Es gibt keine wartende Welle — bitte über ‚Neue Welle‘ starten.', $show->hinweis);
    }

    public function test_show_freigeben_nennt_kuenftigen_start(): void
    {
        $r = self::anfrage([], ['scheduled_for' => Carbon::parse('2026-11-14 09:00:00')]);
        $show = self::show($r);
        self::mitAuth(self::freigeber());
        $this->assertSame('2026-11-14 09:00', $show->startGeplant()?->format('Y-m-d H:i'));

        $show->freigeben();
        $this->assertSame('Freigegeben. Welle 1 startet am 14.11. um 09:00 Uhr.', $show->hinweis);
    }

    public function test_show_freigeben_ohne_kuenftigen_start_wie_bisher(): void
    {
        $r = self::anfrage([], ['scheduled_for' => Carbon::now()->subHour()]);
        $show = self::show($r);
        self::mitAuth(self::freigeber());
        $this->assertNull($show->startGeplant());

        $show->freigeben();
        $this->assertSame('Freigegeben. Welle 1 startet.', $show->hinweis);
    }

    private static function show(RecDispoJobRequest $r): Show
    {
        $show = new class extends Show {
            public function __get($name)
            {
                return method_exists($this, $name) ? $this->{$name}() : parent::__get($name);
            }

            public function __unset($name)
            {
            }
        };
        $show->requestId = (int) $r->id;

        return $show;
    }

    /** auth()->user() fuer Show; Bus nur als Zaehler, damit ein echter Start nicht ins Leere dispatcht. */
    private static function mitAuth(object $user): void
    {
        $c = Container::getInstance();
        $c->instance(AuthFactory::class, new class ($user) implements AuthFactory {
            public function __construct(private object $user)
            {
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function user()
            {
                return $this->user;
            }

            public function id()
            {
                return $this->user->id ?? null;
            }
        });
        $c->instance(BusDispatcher::class, new class {
            public function dispatch($job)
            {
            }
        });
    }

    public function test_show_neue_welle_legt_bei_wartender_anfrage_keine_welle_an(): void
    {
        $r = self::anfrage();
        // Ohne die Sperre liefe neueWelle() in die Auswahlpruefung (leere Filialliste) — der Test wird rot mit anderem Text.
        $show = new class extends Show {
            public function __get($name)
            {
                return method_exists($this, $name) ? $this->{$name}() : parent::__get($name);
            }

            public function blockOptionen(): array
            {
                return [];
            }
        };
        $show->requestId = (int) $r->id;
        $show->neueWelle();
        $this->assertSame('Die Anfrage wartet auf Freigabe.', $show->fehler);
        $this->assertSame(1, RecDispoJobWave::query()->where('request_id', $r->id)->count());

        FreigabeZaehler::ablehnen($r, self::freigeber(), '');
        $show->neueWelle();
        $this->assertSame('Die Freigabe wurde abgelehnt.', $show->fehler);
        $this->assertSame(1, RecDispoJobWave::query()->where('request_id', $r->id)->count());
    }
}

/** Zaehlt Starts statt sie in die Warteschlange zu legen. */
class FreigabeZaehler extends DispoJobFreigabe
{
    /** @var list<array{0:int, 1:?string}> */
    public static array $gestartet = [];

    protected static function welleStarten(RecDispoJobWave $wave, int $teamId, ?int $userId): void
    {
        self::$gestartet[] = [(int) $wave->id, $wave->scheduled_for?->format('Y-m-d H:i:s')];
    }
}
