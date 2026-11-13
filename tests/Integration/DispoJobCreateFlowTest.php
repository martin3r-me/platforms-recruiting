<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Jobs\Create;
use Platform\Recruiting\Jobs\SendDispoJobWave;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoBedarf;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobShift;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfrageAnlegen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnfragen;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobSchichten;

/** Anlegen einer Anfrage (Schritt 3) und das Auto-Abschliessen der Uebersicht. Muster DispoJobTablesTest. */
class DispoJobCreateFlowTest extends TestCase
{
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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => []]]));
        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php', // Create::events() filtert nach Filiale
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php', // Schalter + Vorlagen je Anlass
            'database/migrations/2026_10_13_000001_create_rec_dispo_job_tables.php',
            'database/migrations/2026_10_20_000001_add_stati_to_rec_dispo_job_waves.php',
            'database/migrations/2026_10_27_000001_add_wellen_to_rec_dispo_job_tables.php',
            'database/migrations/2026_11_13_000001_add_versandweg_freigabe_to_rec_dispo_job_tables.php',
            'database/migrations/2026_10_13_000002_add_anlass_to_rec_dispo_job_requests.php',
            'database/migrations/2026_10_14_000001_add_bis_to_rec_dispo_job_shifts.php',
            'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php',
            'database/migrations/2026_10_29_000002_add_dispoposten_id_to_rec_dispo_job_shifts.php',
            'database/migrations/2026_10_30_000001_add_zas_exported_at_to_rec_dispo_job_offer_shifts.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Carbon::setTestNow();
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private static function daten(array $o = []): array
    {
        return $o + [
            'filial_nr' => 200, 'von' => '2026-10-13', 'bis' => '2026-11-09', 'modus' => RecDispoJobRequest::MODUS_SAMMELN,
            'anlass' => 'dringend', 'template_id' => 7, 'bloecke' => [200, null], 'gruppen' => ['0', '1-4'], 'nur_passend' => true, 'stati' => ['GO'], 'firmen' => ['MA'], 'welle_max' => 20, 'welle_abstand_minuten' => 360, 'minuten' => 0,
            'schichten' => [
                ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'bis' => '23:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 20],
                ['event' => 5, 'datum' => '2026-10-25', 'von' => '', 'taetigkeit' => 'Service', 'gesucht' => 3],
            ],
        ];
    }

    public function test_schicht_optionen_laden_die_endzeit_aus_zas(): void
    {
        $this->assertContains('bis', Create::SCHICHT_SPALTEN);

        $event = RecDispoEvent::create(['einsatz_ref' => 'BIS1']);
        foreach (['a' => '23:00', 'b' => '23:00', 'c' => '22:30'] as $ref => $bis) {
            RecDispoAssignment::create(['ds_ref' => 'bis-' . $ref, 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'x', 'datum' => '2026-10-24',
                'von' => '17:00', 'bis' => $bis, 'taetigkeit' => 'Zapfer', 'status_id' => 1]);
        }

        // Genau die Spaltenliste der Komponente — fehlt 'bis', kommt die Endzeit leer an.
        $rows = RecDispoAssignment::query()->where('rec_dispo_event_id', $event->id)->get(Create::SCHICHT_SPALTEN);
        $optionen = DispoJobSchichten::optionen($rows, []);

        $this->assertSame('23:00', $optionen['2026-10-24'][0]['bis']);
    }

    public function test_legt_anfrage_schichten_und_welle_1_an(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(), 3, 42);

        $fresh = RecDispoJobRequest::find($request->id);
        $this->assertSame(3, (int) $fresh->team_id);
        $this->assertSame(200, $fresh->filial_nr);
        $this->assertSame(RecDispoJobRequest::STATUS_ENTWURF, $fresh->status);
        $this->assertSame(42, (int) $fresh->created_by_user_id);
        $this->assertSame(7, $fresh->whatsapp_template_id, 'die beim Anlegen aufgeloeste Vorlage');
        $this->assertSame('dringend', $fresh->anlass);
        $this->assertSame('2026-10-13', $fresh->zeitraum_von->format('Y-m-d'));
        $this->assertSame('2026-11-09', $fresh->zeitraum_bis->format('Y-m-d'));

        $shifts = RecDispoJobShift::where('request_id', $request->id)->orderBy('datum')->get();
        $this->assertCount(2, $shifts);
        $this->assertSame(['17:00', 20, 'Zapfer'], [$shifts[0]->von, $shifts[0]->gesucht, $shifts[0]->taetigkeit]);
        $this->assertSame('', $shifts[1]->von, 'leerer Beginn als Leerstring, nie null');
        $this->assertSame('23:00', $shifts[0]->bis);
        $this->assertSame('', $shifts[1]->bis, 'ohne Ende: Leerstring, nie null');

        $waves = RecDispoJobWave::where('request_id', $request->id)->get();
        $this->assertCount(1, $waves);
        $this->assertSame(1, $waves[0]->nr);
        $this->assertSame([200, null], $waves[0]->ma_bloecke);
        $this->assertSame(['0', '1-4'], $waves[0]->gruppen);
        $this->assertTrue($waves[0]->nur_passende_taetigkeit);
        $this->assertSame(['GO'], $waves[0]->stati, 'Statusauswahl wird je Welle gespeichert');
        $this->assertSame(['MA'], $waves[0]->firmen, 'Gesellschaft je Welle');
        $this->assertFalse((bool) $waves[0]->auto);
        $this->assertSame(20, $fresh->welle_max);
        $this->assertSame(360, $fresh->welle_abstand_minuten);
        $this->assertNull($waves[0]->scheduled_for, 'jetzt senden');
        $this->assertNull($waves[0]->started_at);
        $this->assertNotEmpty($waves[0]->job_uuid, 'Fortschritts-Schluessel steht vor dem Start fest');
    }

    public function test_spaeter_senden_setzt_scheduled_for(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['minuten' => 30]), 3, null);

        $wave = RecDispoJobWave::where('request_id', $request->id)->first();
        $this->assertSame('2026-10-12 10:30:00', $wave->scheduled_for->format('Y-m-d H:i:s'));
    }

    public function test_gesucht_0_wird_nicht_gespeichert_und_doppelte_schicht_zusammengefasst(): void
    {
        $daten = self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 2],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '18:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 0],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => ' Zapfer ', 'gesucht' => 9],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => null, 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]);
        $request = DispoJobAnfrageAnlegen::anlegen($daten, 3, null);

        $rows = RecDispoJobShift::where('request_id', $request->id)->orderBy('von')->get()
            ->map(fn ($s) => [$s->von, $s->taetigkeit, $s->gesucht])->all();
        $this->assertSame([['', 'Zapfer', 1], ['17:00', 'Zapfer', 9]], $rows, 'letztes gesucht gewinnt');
    }

    /** Markus 06.11. / Review Fund 4: zwei „andere“ Schichten 17–23 und 17–03 ohne Posten bleiben zwei Schichten. */
    public function test_gleicher_beginn_mit_anderem_ende_bleibt_zwei_schichten(): void
    {
        $daten = self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'bis' => '23:00', 'taetigkeit' => 'Servicekräfte', 'gesucht' => 30],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'bis' => '03:00', 'taetigkeit' => 'Servicekräfte', 'gesucht' => 40],
        ]]);
        $request = DispoJobAnfrageAnlegen::anlegen($daten, 3, null);

        $rows = RecDispoJobShift::where('request_id', $request->id)->orderBy('bis')->get()
            ->map(fn ($s) => [$s->von, $s->bis, $s->gesucht])->all();
        $this->assertSame([['17:00', '03:00', 40], ['17:00', '23:00', 30]], $rows);
    }

    public function test_ohne_gesuchte_schicht_wird_nichts_angelegt(): void
    {
        try {
            DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
                ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 0],
            ]]), 3, null);
            $this->fail('erwartet: InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(0, RecDispoJobRequest::count());
    }

    public function test_abgelaufene_laufende_anfragen_werden_abgeschlossen(): void
    {
        $alt = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-11', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 3, null);
        $alt->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $heute = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-10', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
            ['event' => 5, 'datum' => '2026-10-12', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 3, null);
        $heute->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $fremd = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-01', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 4, null);
        $fremd->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        $entwurf = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-01', 'von' => '17:00', 'taetigkeit' => 'Zapfer', 'gesucht' => 1],
        ]]), 3, null);

        $this->assertSame(2, DispoJobAnfragen::abgelaufeneSchliessen(3));

        $alt->refresh();
        $this->assertSame(RecDispoJobRequest::STATUS_ABGESCHLOSSEN, $alt->status);
        $this->assertSame('2026-10-12 10:00:00', $alt->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame(RecDispoJobRequest::STATUS_ABGESCHLOSSEN, $entwurf->refresh()->status, 'geplante Welle einer abgelaufenen Anfrage startet nie mehr');
        $this->assertSame(RecDispoJobRequest::STATUS_LAEUFT, $heute->refresh()->status, 'letzter Tag heute = noch offen');
        $this->assertNull($fremd->refresh()->closed_at, 'anderes Team bleibt unberuehrt');
        $this->assertSame(0, DispoJobAnfragen::abgelaufeneSchliessen(3), 'zweiter Lauf aendert nichts');
    }

    /** Bedarfsliste (Spec 2026-10-29): ein Posten ohne Person erscheint in Schritt 2 — ueber genau die Spaltenliste der Komponente. */
    public function test_schicht_optionen_zeigen_posten_ohne_person_aus_der_bedarfsliste(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'BED1']);
        RecDispoAssignment::create(['ds_ref' => 'bed-a', 'rec_dispo_event_id' => $event->id, 'pnr_raw' => 'RG18424', 'datum' => '2026-11-03',
            'von' => '07:30', 'bis' => '14:00', 'taetigkeit' => 'Servicekräfte', 'status_id' => 1]);
        RecDispoBedarf::create(['rec_dispo_event_id' => $event->id, 'dispoposten_id' => '126158', 'datum' => '2026-11-03', 'von' => '07:30', 'bis' => '14:00',
            'zeiten' => [['von' => '07:30', 'bis' => '14:00']], 'taetigkeit' => 'Servicekräfte', 'anzahl' => 1, 'status_id' => 1]);
        RecDispoBedarf::create(['rec_dispo_event_id' => $event->id, 'dispoposten_id' => '126159', 'datum' => '2026-11-03', 'von' => '14:00', 'bis' => '23:30',
            'zeiten' => [['von' => '14:00', 'bis' => '23:30']], 'taetigkeit' => 'Servicekräfte', 'anzahl' => 6, 'status_id' => 1]);
        RecDispoBedarf::create(['rec_dispo_event_id' => $event->id, 'dispoposten_id' => '126999', 'datum' => '2026-11-03', 'von' => '14:00', 'bis' => '23:30',
            'zeiten' => [], 'taetigkeit' => 'Storniert', 'anzahl' => 6, 'status_id' => RecDispoBedarf::STATUS_STORNO]);

        $rows = RecDispoAssignment::query()->where('rec_dispo_event_id', $event->id)->get(Create::SCHICHT_SPALTEN);
        $bedarfe = RecDispoBedarf::query()->where('rec_dispo_event_id', $event->id)->where('status_id', '!=', RecDispoBedarf::STATUS_STORNO)->get(Create::BEDARF_SPALTEN);
        $optionen = DispoJobSchichten::optionen($rows, [], $bedarfe);

        $this->assertSame(
            [['07:30', 'Servicekräfte', 1, 0, '126158'], ['14:00', 'Servicekräfte', 0, 6, '126159']],
            array_map(fn ($s) => [$s['von'], $s['taetigkeit'], $s['zeilen'], $s['offen'], $s['dispoposten']], $optionen['2026-11-03']),
        );
    }

    public function test_posten_nummer_landet_an_der_job_schicht(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '17:00', 'bis' => '23:00', 'taetigkeit' => 'Zapfer', 'dispoposten' => '126159', 'gesucht' => 2],
            ['event' => 5, 'datum' => '2026-10-25', 'von' => '', 'taetigkeit' => 'Service', 'gesucht' => 3], // andere Schicht: kein Posten
        ]]), 3, 42);

        $shifts = RecDispoJobShift::query()->where('request_id', $request->id)->orderBy('datum')->get();
        $this->assertSame(['126159', null], $shifts->pluck('dispoposten_id')->all());
    }

    /** Review I-1: zwei Posten mit gleichem Beginn und gleicher Taetigkeit sind zwei Schichten — der Unique-Index traegt die Posten-Nummer. */
    public function test_zwei_posten_gleicher_beginn_werden_zwei_schichten(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['schichten' => [
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '14:00', 'bis' => '23:30', 'taetigkeit' => 'Service', 'dispoposten' => '1', 'gesucht' => 1],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '14:00', 'bis' => '18:00', 'taetigkeit' => 'Service', 'dispoposten' => '2', 'gesucht' => 3],
            ['event' => 5, 'datum' => '2026-10-24', 'von' => '14:00', 'bis' => '', 'taetigkeit' => 'Service', 'gesucht' => 2], // ohne Posten: eigene Schicht
        ]]), 3, 42);

        $shifts = RecDispoJobShift::query()->where('request_id', $request->id)->orderBy('id')->get();
        $this->assertSame([['1', 1], ['2', 3], [null, 2]], $shifts->map(fn ($s) => [$s->dispoposten_id, $s->gesucht])->all());
    }

    /** Versandweg + Freigabe-Pflicht beim Anlegen (Markus 13.11.2026, Spec 6.2/6.4). */
    public function test_anlegen_ohne_versandweg_bleibt_whatsapp_ohne_pflicht(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(), 3, 42)->fresh();

        $this->assertSame(RecDispoJobRequest::WEG_WHATSAPP, $request->versandweg);
        $this->assertFalse($request->freigabe_pflicht);
        $this->assertNull($request->freigabe_beantragt_at);
        $this->assertNull($request->freigabe_beantragt_by_user_id);
    }

    public function test_anlegen_im_portal_ohne_vorlage_speichert_keine_vorlage(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['versandweg' => 'portal', 'template_id' => 0]), 3, 42)->fresh();

        $this->assertSame(RecDispoJobRequest::WEG_PORTAL, $request->versandweg);
        $this->assertNull($request->whatsapp_template_id, 'template_id <= 0 = keine Vorlage');
        $this->assertFalse($request->freigabe_pflicht);
    }

    public function test_anlegen_mit_freigabepflicht_haelt_den_antrag_fest(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['anlass' => 'vier_wochen', 'freigabe_pflicht' => true]), 3, 42)->fresh();

        $this->assertTrue($request->freigabe_pflicht);
        $this->assertSame('2026-10-12 10:00:00', $request->freigabe_beantragt_at->format('Y-m-d H:i:s'));
        $this->assertSame(42, $request->freigabe_beantragt_by_user_id);
        $this->assertNull($request->freigegeben_at);
        $this->assertTrue($request->wartetAufFreigabe());
    }

    public function test_unbekannter_versandweg_faellt_auf_whatsapp(): void
    {
        $request = DispoJobAnfrageAnlegen::anlegen(self::daten(['versandweg' => 'brieftaube']), 3, null)->fresh();

        $this->assertSame(RecDispoJobRequest::WEG_WHATSAPP, $request->versandweg);
    }

    // ------------------------------------------------------------------
    // Anlegen ueber die Komponente mit Versandweg (Markus 13.11.2026, Spec 6.2)
    // ------------------------------------------------------------------

    /** @var object{jobs: list<object>} */
    private object $bus;

    /**
     * Komponente in Schritt 3 mit einer gueltigen Auswahl: eine VA der Filiale 200
     * am 14.10., eine „andere“ Schicht. Login, Sitzung und Bus sind Attrappen;
     * Schalter und Vorlagen kommen ECHT aus rec_applicant_settings.
     *
     * @param  array<string,mixed>  $settings
     */
    private function anlegenMit(array $settings, string $anlass, string $versandweg): Create
    {
        foreach (['rec_dispo_bedarfe', 'rec_dispo_events', 'rec_applicant_settings'] as $t) {
            Capsule::table($t)->delete();
        }
        $container = Container::getInstance();
        $container->make('config')->set('recruiting.zas.inbound_team_id', 3);
        RecApplicantSettings::create(['team_id' => 3, 'settings' => $settings + RecApplicantSettings::DEFAULT_SETTINGS]);
        $container->instance(AuthFactory::class, new class implements AuthFactory {
            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function id()
            {
                return 42;
            }
        });
        $container->instance('session', new class {
            public function put($key, $value = null)
            {
            }

            public function get($key, $default = null)
            {
                return $default;
            }
        });
        $this->bus = new class {
            public array $jobs = [];

            public function dispatch($job)
            {
                $this->jobs[] = $job;
            }
        };
        $container->instance(BusDispatcher::class, $this->bus);

        $va = RecDispoEvent::create(['einsatz_ref' => 'WEG1', 'name' => 'Messe', 'filial_nr' => 200, 'starts_on' => '2026-10-14']);
        RecDispoBedarf::create(['rec_dispo_event_id' => $va->id, 'dispoposten_id' => '1', 'datum' => '2026-10-14', 'von' => '17:00', 'bis' => '23:00',
            'zeiten' => [['von' => '17:00', 'bis' => '23:00']], 'taetigkeit' => 'Service', 'anzahl' => 2, 'status_id' => 1]);

        $c = new class extends Create {
            public ?string $weiterleitung = null;

            public function __get($name)
            {
                return method_exists($this, $name) ? $this->{$name}() : parent::__get($name);
            }

            public function __unset($name)
            {
            }

            public function blockOptionen(): array
            {
                return [['block' => 200, 'label' => 'DUS', 'nummern' => [], 'count' => 1]];
            }

            public function taetigkeitKatalog(): array
            {
                return ['Service'];
            }

            /** Keine Integrations-Tabelle im Test: jede hinterlegte ID ist eine freigegebene, gueltige Vorlage. */
            public function vorlagen(): array
            {
                $out = [];
                foreach (array_filter($this->anlassVorlagen()) as $id) {
                    $out[$id] = (object) ['id' => $id, 'name' => 'v' . $id, 'status' => 'APPROVED', 'components' => [
                        ['type' => 'BODY', 'text' => 'Hey {{name}}'],
                        ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Ansehen', 'url' => 'https://x/jobs/{{1}}']]],
                    ]];
                }

                return $out;
            }

            public function redirectRoute($name, $parameters = [], $absolute = true, $navigate = false)
            {
                $this->weiterleitung = $name;
            }
        };
        $c->schritt = 3;
        $c->filialNr = '200';
        $c->von = '2026-10-13';
        $c->bis = '2026-10-20';
        $c->eventIds = [(string) $va->id];
        $c->andere = [['event' => $va->id, 'datum' => '2026-10-14', 'von' => '17:00', 'bis' => '23:00', 'taetigkeit' => 'Service', 'gesucht' => '2']];
        $c->maBloecke = ['200'];
        $c->anlass = $anlass;
        $c->versandweg = $versandweg;

        return $c;
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        foreach ([AuthFactory::class, BusDispatcher::class, 'session'] as $n) {
            $container->forgetInstance($n);
        }
        parent::tearDown();
    }

    /** @return list<int> Wellen-IDs der eingereihten Jobs */
    private function eingereiht(): array
    {
        return array_map(fn ($j) => $j->waveId, array_values(array_filter($this->bus->jobs, fn ($j) => $j instanceof SendDispoJobWave)));
    }

    private function angelegt(Create $c): RecDispoJobRequest
    {
        $this->assertSame('', $c->fehler, 'Anlegen darf nicht scheitern');
        $this->assertSame('recruiting.dispo.jobs.show', $c->weiterleitung);
        $this->assertSame(1, RecDispoJobRequest::count());

        return RecDispoJobRequest::query()->firstOrFail();
    }

    private static function welleId(RecDispoJobRequest $r): int
    {
        return (int) RecDispoJobWave::query()->where('request_id', $r->id)->where('nr', 1)->value('id');
    }

    public function test_schalter_aus_legt_whatsapp_ohne_pflicht_an_und_reiht_welle_ein(): void
    {
        $c = $this->anlegenMit(['dispo_job_template_dringend_id' => 7], 'dringend', 'whatsapp');
        $c->anlegenUndSenden();

        $r = $this->angelegt($c);
        $this->assertSame(['whatsapp', false, 7], [$r->versandweg, (bool) $r->freigabe_pflicht, $r->whatsapp_template_id]);
        $this->assertNull($r->freigabe_beantragt_at);
        $this->assertSame([self::welleId($r)], $this->eingereiht());
    }

    public function test_schalter_aus_und_manipuliertes_portal_bleibt_whatsapp(): void
    {
        $c = $this->anlegenMit(['dispo_job_template_vier_wochen_id' => 7], 'vier_wochen', 'portal');
        $c->anlegenUndSenden();

        $r = $this->angelegt($c);
        $this->assertSame(['whatsapp', false, 7], [$r->versandweg, (bool) $r->freigabe_pflicht, $r->whatsapp_template_id]);
        $this->assertSame([self::welleId($r)], $this->eingereiht());
    }

    public function test_schalter_an_portal_ohne_vorlage_legt_an_und_reiht_welle_ein(): void
    {
        $c = $this->anlegenMit(['dispo_job_versandweg_aktiv' => true], 'naechste_woche', 'portal');
        $c->anlegenUndSenden();

        $r = $this->angelegt($c);
        $this->assertSame(['portal', false], [$r->versandweg, (bool) $r->freigabe_pflicht]);
        $this->assertNull($r->whatsapp_template_id, 'Portal braucht keine Vorlage');
        $this->assertSame([self::welleId($r)], $this->eingereiht());
    }

    public function test_schalter_an_whatsapp_beantragt_freigabe_und_reiht_nichts_ein(): void
    {
        $c = $this->anlegenMit(['dispo_job_versandweg_aktiv' => true, 'dispo_job_template_vier_wochen_id' => 7], 'vier_wochen', 'whatsapp');
        $c->anlegenUndSenden();

        $r = $this->angelegt($c);
        $this->assertSame(['whatsapp', true, 7], [$r->versandweg, (bool) $r->freigabe_pflicht, $r->whatsapp_template_id]);
        $this->assertSame('2026-10-12 10:00:00', $r->freigabe_beantragt_at->format('Y-m-d H:i:s'));
        $this->assertSame(42, (int) $r->freigabe_beantragt_by_user_id);
        $this->assertGreaterThan(0, self::welleId($r), 'Welle 1 ist angelegt');
        $this->assertSame([], $this->eingereiht(), 'erst die Freigabe startet Welle 1');
    }

    public function test_schalter_an_whatsapp_ohne_vorlage_wird_abgewiesen(): void
    {
        $c = $this->anlegenMit(['dispo_job_versandweg_aktiv' => true], 'naechste_woche', 'whatsapp');
        $c->anlegenUndSenden();

        $this->assertNotSame('', $c->fehler);
        $this->assertSame(0, RecDispoJobRequest::count());
        $this->assertSame([], $this->eingereiht());
    }

    public function test_schalter_an_dringend_und_manipuliertes_portal_bleibt_whatsapp_ohne_pflicht(): void
    {
        $c = $this->anlegenMit(['dispo_job_versandweg_aktiv' => true, 'dispo_job_template_dringend_id' => 7], 'dringend', 'portal');
        $c->anlegenUndSenden();

        $r = $this->angelegt($c);
        $this->assertSame(['whatsapp', false, 7], [$r->versandweg, (bool) $r->freigabe_pflicht, $r->whatsapp_template_id]);
        $this->assertSame([self::welleId($r)], $this->eingereiht());
    }
}
