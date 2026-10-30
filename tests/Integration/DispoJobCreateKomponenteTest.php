<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Jobs\Create;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoBedarf;
use Platform\Recruiting\Models\RecDispoEvent;

/**
 * Bedarfsliste (Spec 2026-10-29 §5/§7) GEGEN DIE KOMPONENTE, nicht gegen eine
 * nachgebaute Abfrage (Review I-2): `events()` muss eine VA listen, die im
 * Zeitraum nur offene Posten und keine einzige Person hat, und
 * `schichtOptionen()` muss diese Posten liefern. Die Computed-Methoden werden
 * direkt aufgerufen (Muster JobOffersAnfrageSeiteTest), Livewire selbst ist
 * dafuer nicht noetig.
 */
class DispoJobCreateKomponenteTest extends TestCase
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
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_09_04_000001_add_decline_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_10_29_000001_create_rec_dispo_bedarfe_table.php',
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
        Carbon::setTestNow('2026-10-29 12:00:00');
        foreach (['rec_dispo_bedarfe', 'rec_dispo_assignments', 'rec_dispo_events'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    /**
     * Ausserhalb von Livewire gibt es keine Computed-Magie: `$this->gewaehlteEvents`
     * innerhalb der Komponente wuerde werfen. Die Attrappe leitet den Zugriff auf
     * die Methode um — der Produktionscode bleibt unveraendert.
     */
    private static function komponente(array $eventIds = []): Create
    {
        $c = new class extends Create {
            public function __get($name)
            {
                return method_exists($this, $name) ? $this->{$name}() : parent::__get($name);
            }

            /** Computed-Cache gibt es hier nicht; unset() auf die Methode ist ein No-op. */
            public function __unset($name)
            {
            }

            /** Statt der Einstellungen (keine Settings-Tabelle im Test): alle drei Anlaesse haben eine Vorlage. */
            public function anlassVorlagen(): array
            {
                return ['vier_wochen' => 3, 'naechste_woche' => 2, 'dringend' => 1];
            }
        };
        $c->filialNr = '100';
        $c->von = '2026-11-02';
        $c->bis = '2026-11-04';
        $c->eventIds = $eventIds;

        return $c;
    }

    private static function posten(RecDispoEvent $e, string $nr, string $datum, string $von, string $bis, string $taet, int $anzahl, array $o = []): RecDispoBedarf
    {
        return RecDispoBedarf::create($o + ['rec_dispo_event_id' => $e->id, 'dispoposten_id' => $nr, 'datum' => $datum, 'von' => $von, 'bis' => $bis,
            'zeiten' => [['von' => $von, 'bis' => $bis]], 'taetigkeit' => $taet, 'anzahl' => $anzahl, 'status_id' => 1]);
    }

    /** Der Anlassfall: VA RG20407 hat im Zeitraum Posten, aber (noch) keine Personenzeile. */
    public function test_va_nur_mit_bedarf_ist_in_schritt_1_waehlbar_und_schritt_2_zeigt_die_posten(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG20407', 'name' => 'Broich Connected', 'filial_nr' => 100, 'starts_on' => '2026-11-02', 'ends_on' => '2026-11-04']);
        self::posten($va, '126159', '2026-11-03', '14:00', '23:30', 'Servicekräfte', 6);
        self::posten($va, '126215', '2026-11-04', '07:30', '18:00', 'Broich Theke', 2);

        $c = self::komponente();
        $events = $c->events();
        $this->assertSame([$va->id], $events->keys()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, (int) $events->first()->einbuchungen);
        $this->assertSame(2, (int) $events->first()->posten, 'Anzahl Posten fuer die Zeile in Schritt 1');

        $c = self::komponente([$va->id]);
        $optionen = $c->schichtOptionen();
        $this->assertSame(['2026-11-03', '2026-11-04'], array_keys($optionen[$va->id]));
        $this->assertSame(
            [['14:00', 'Servicekräfte', 0, 6, '126159']],
            array_map(fn ($s) => [$s['von'], $s['taetigkeit'], $s['zeilen'], $s['offen'], $s['dispoposten']], $optionen[$va->id]['2026-11-03']),
        );
        $this->assertSame('126215', $optionen[$va->id]['2026-11-04'][0]['dispoposten']);
        $this->assertMatchesRegularExpression('/^s[0-9a-f]{16}$/', $optionen[$va->id]['2026-11-03'][0]['key']);
    }

    /** Spec 2026-10-30 §1: der Anlass belegt den Zeitraum in Schritt 1 vor — nur solange von/bis unangetastet sind und der Einstieg nicht ueber eine VA kam. */
    public function test_anlass_belegt_den_zeitraum_in_schritt_1_vor(): void
    {
        // heute = Do 29.10.2026 (setUp)
        $c = self::komponente();
        $c->anlassWaehlen('naechste_woche');
        $this->assertSame(['naechste_woche', '2026-11-02', '2026-11-08'], [$c->anlass, $c->von, $c->bis]);

        $c->anlassWaehlen('dringend');
        $this->assertSame(['2026-10-30', '2026-10-30'], [$c->von, $c->bis]);

        $c->anlassWaehlen('vier_wochen');
        $this->assertSame(['2026-11-02', '2026-11-30'], [$c->von, $c->bis]);

        // Anlass ohne Vorlage: nichts passiert.
        $c->anlassWaehlen('irgendwas');
        $this->assertSame(['vier_wochen', '2026-11-02', '2026-11-30'], [$c->anlass, $c->von, $c->bis]);
    }

    public function test_zeitraum_von_hand_oder_ueber_eine_va_bleibt_beim_anlasswechsel_stehen(): void
    {
        $c = self::komponente();
        $c->von = '2026-12-01';
        $c->bis = '2026-12-05';
        $c->updated('von');
        $c->anlassWaehlen('naechste_woche');
        $this->assertSame(['naechste_woche', '2026-12-01', '2026-12-05'], [$c->anlass, $c->von, $c->bis], 'Handaenderung gewinnt (Markus: Anpassung von Hand)');

        $c = self::komponente();
        $c->event = 7;
        $c->anlassWaehlen('naechste_woche');
        $this->assertSame(['2026-11-02', '2026-11-04'], [$c->von, $c->bis], 'ueber eine VA gelten deren Tage');

        $c = self::komponente();
        $c->schritt = 3;
        $c->wellenAngefasst = true; // sonst rechnet Schritt 3 die Vorschau (braucht Login) — hier geht es nur um den Zeitraum
        $c->anlassWaehlen('dringend');
        $this->assertSame(['2026-11-02', '2026-11-04'], [$c->von, $c->bis], 'in Schritt 3 stehen die Schichten schon fest');
    }

    public function test_andere_filiale_zeitraum_storno_und_verschwundene_posten_bleiben_draussen(): void
    {
        $fremd = RecDispoEvent::create(['einsatz_ref' => 'F1', 'name' => 'Andere Filiale', 'filial_nr' => 200, 'starts_on' => '2026-11-03']);
        self::posten($fremd, '1', '2026-11-03', '14:00', '23:30', 'Service', 6);

        $spaeter = RecDispoEvent::create(['einsatz_ref' => 'S1', 'name' => 'Ausserhalb', 'filial_nr' => 100, 'starts_on' => '2026-11-20']);
        self::posten($spaeter, '2', '2026-11-20', '14:00', '23:30', 'Service', 6);

        $nurStorno = RecDispoEvent::create(['einsatz_ref' => 'X1', 'name' => 'Nur Storno', 'filial_nr' => 100, 'starts_on' => '2026-11-03']);
        self::posten($nurStorno, '3', '2026-11-03', '14:00', '23:30', 'Service', 6, ['status_id' => RecDispoBedarf::STATUS_STORNO]);

        $nurWeg = RecDispoEvent::create(['einsatz_ref' => 'W1', 'name' => 'Nur verschwunden', 'filial_nr' => 100, 'starts_on' => '2026-11-03']);
        self::posten($nurWeg, '4', '2026-11-03', '14:00', '23:30', 'Service', 6, ['missing_since' => '2026-10-28 00:00:00']);

        $echt = RecDispoEvent::create(['einsatz_ref' => 'E1', 'name' => 'Echt', 'filial_nr' => 100, 'starts_on' => '2026-11-03']);
        self::posten($echt, '5', '2026-11-03', '14:00', '23:30', 'Service', 6);
        self::posten($echt, '6', '2026-11-03', '18:00', '22:00', 'Service', 6, ['status_id' => RecDispoBedarf::STATUS_STORNO]);
        self::posten($echt, '7', '2026-11-03', '18:00', '22:00', 'Theke', 2, ['missing_since' => '2026-10-28 00:00:00']);

        $c = self::komponente();
        $this->assertSame([$echt->id], $c->events()->keys()->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, (int) $c->events()->first()->posten, 'Storno und verschwundene Posten zaehlen nicht');

        $c = self::komponente([$echt->id]);
        $this->assertSame(['5'], array_map(fn ($s) => $s['dispoposten'], $c->schichtOptionen()[$echt->id]['2026-11-03']));
    }

    /** Personen und Posten zusammen, wie bei RG20407 am 03.11. (drei Servicekraefte-Posten, eine Person um 07:30). */
    public function test_personen_werden_ueber_die_komponente_ihrem_posten_zugeordnet(): void
    {
        $va = RecDispoEvent::create(['einsatz_ref' => 'RG20407', 'name' => 'Broich Connected', 'filial_nr' => 100, 'starts_on' => '2026-11-03']);
        self::posten($va, '126158', '2026-11-03', '07:30', '14:00', 'Servicekräfte', 1);
        self::posten($va, '126159', '2026-11-03', '14:00', '23:30', 'Servicekräfte', 6);
        self::posten($va, '126205', '2026-11-03', '18:00', '22:00', 'Servicekräfte', 6);
        RecDispoAssignment::create(['ds_ref' => '933770', 'rec_dispo_event_id' => $va->id, 'pnr_raw' => 'RG18424', 'datum' => '2026-11-03',
            'von' => '07:30', 'bis' => '14:00', 'taetigkeit' => 'Servicekräfte', 'status_id' => 1]);

        $c = self::komponente([$va->id]);
        $this->assertSame(
            [['07:30', 1, 0, '126158'], ['14:00', 0, 6, '126159'], ['18:00', 0, 6, '126205']],
            array_map(fn ($s) => [$s['von'], $s['zeilen'], $s['offen'], $s['dispoposten']], $c->schichtOptionen()[$va->id]['2026-11-03']),
        );
    }
}
