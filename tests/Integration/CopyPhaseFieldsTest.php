<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Console\Commands\CopyPhaseFields;
use Platform\Recruiting\Models\RecPhase;

/**
 * recruiting:copy-phase-fields — Felder einer Phase in eine andere bestehende
 * Phase uebernehmen, abgeglichen ueber den Feldnamen.
 *
 * Die Eigenschaft, auf die es ankommt, ist die IDEMPOTENZ: das Kommando muss
 * nach jeder Aenderung an der Quell-Phase erneut laufen koennen, ohne Felder zu
 * verdoppeln. Sonst driftet die Sammel-Stelle vom Original weg, sobald dort ein
 * Feld ergaenzt wird — und genau das Wegdriften waere der Grund, warum die
 * Werte beim naechsten Umschluesseln wieder Luecken haetten.
 *
 * Zweitens muss `options` mitkommen: dort steckt die Lookup-Konfiguration, und
 * ein Lookup-Feld ohne sie waere ein leeres Dropdown.
 */
class CopyPhaseFieldsTest extends TestCase
{
    private const TEAM = 11;

    private const POSITION_QUELLE = 111;
    private const POSITION_ZIEL = 112;

    private const PHASE_QUELLE = 1111;
    private const PHASE_ZIEL = 1112;

    private const HEUTE = '2026-09-07 09:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('teams', fn ($table) => $table->id());
        $schema->create('users', fn ($table) => $table->id());
        $schema->create('hcm_job_titles', fn ($table) => $table->id());
        $schema->create('comms_channels', fn ($table) => $table->id());

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    public function test_kopiert_felder_samt_lookup_konfiguration(): void
    {
        $this->fuehreAus();

        $ziel = self::zielFelder();

        $this->assertSame(
            ['beschaftigungsort', 'geburtsdatum', 'vorname'],
            $ziel->keys()->sort()->values()->all(),
            'alle drei Quell-Felder liegen in der Ziel-Phase'
        );

        $this->assertSame('date', $ziel['geburtsdatum']->type, 'der Typ kommt mit');
        $this->assertSame(
            ['lookup_id' => 1, 'multiple' => true],
            $ziel['beschaftigungsort']->options,
            'die Lookup-Konfiguration kommt mit — ohne sie waere das Dropdown leer'
        );
        $this->assertSame(1, (int) $ziel['vorname']->is_required, 'die Pflicht-Eigenschaft kommt mit');
    }

    public function test_zweiter_lauf_verdoppelt_nichts_und_zieht_aenderungen_nach(): void
    {
        $this->fuehreAus();

        // Quelle aendert sich, wie es passiert, wenn HR ein Label anpasst.
        CoreExtraFieldDefinition::query()
            ->where('context_id', self::PHASE_QUELLE)
            ->where('name', 'vorname')
            ->update(['label' => 'Rufname']);

        $this->fuehreAus();

        $ziel = self::zielFelder();

        $this->assertCount(3, $ziel, 'kein Feld ist verdoppelt');
        $this->assertSame('Rufname', $ziel['vorname']->label, 'die Aenderung ist nachgezogen');
    }

    public function test_felder_die_es_nur_im_ziel_gibt_bleiben_stehen(): void
    {
        CoreExtraFieldDefinition::create([
            'team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_ZIEL,
            'name' => 'nur_im_ziel', 'label' => 'Nur im Ziel', 'type' => 'text',
            'is_required' => false, 'order' => 99,
        ]);

        $this->fuehreAus();

        $this->assertArrayHasKey(
            'nur_im_ziel',
            self::zielFelder()->all(),
            'das Kommando loescht nichts, was es nur im Ziel gibt'
        );
    }

    public function test_dry_run_schreibt_nicht(): void
    {
        // Ziel leerraeumen, damit ein versehentlicher Write sofort auffaellt.
        CoreExtraFieldDefinition::query()->where('context_id', self::PHASE_ZIEL)->delete();

        $this->fuehreAus(dryRun: true);

        $this->assertCount(0, self::zielFelder(), 'der Trockenlauf legt nichts an');
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();

        // Jeder Test faengt mit leerer Ziel-Phase an — sonst haengt das Ergebnis
        // an der Ausfuehrungsreihenfolge (siehe Hinweis in phpunit.xml).
        CoreExtraFieldDefinition::query()->where('context_id', self::PHASE_ZIEL)->delete();
        CoreExtraFieldDefinition::query()
            ->where('context_id', self::PHASE_QUELLE)
            ->where('name', 'vorname')
            ->update(['label' => 'Vorname']);
    }

    private function fuehreAus(bool $dryRun = false): void
    {
        (new CopyPhaseFieldsProbe())->probeKopiere(
            RecPhase::find(self::PHASE_QUELLE),
            RecPhase::find(self::PHASE_ZIEL),
            $dryRun,
        );
    }

    /** @return \Illuminate\Support\Collection<string,CoreExtraFieldDefinition> */
    private static function zielFelder(): \Illuminate\Support\Collection
    {
        return CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)
            ->where('context_id', self::PHASE_ZIEL)
            ->get()
            ->keyBy('name');
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => self::POSITION_QUELLE, 'uuid' => 'cpfpos-111', 'team_id' => self::TEAM,
             'title' => 'Duesseldorf allgemein', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_ZIEL, 'uuid' => 'cpfpos-112', 'team_id' => self::TEAM,
             'title' => 'Sonstiges', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_QUELLE, 'uuid' => 'cpfph-1111', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_QUELLE, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_ZIEL, 'uuid' => 'cpfph-1112', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_ZIEL, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Drei Felder, die die relevanten Sorten abdecken: Text mit Pflicht,
        // Datum (der Wert, an dem der Jugendschutz-Riegel haengt) und ein
        // Lookup mit Konfiguration in options.
        CoreExtraFieldDefinition::query()->insert([
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_QUELLE,
             'name' => 'vorname', 'label' => 'Vorname', 'type' => 'text',
             'is_required' => 1, 'order' => 1, 'options' => null,
             'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_QUELLE,
             'name' => 'geburtsdatum', 'label' => 'Geburtsdatum', 'type' => 'date',
             'is_required' => 1, 'order' => 2, 'options' => null,
             'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_QUELLE,
             'name' => 'beschaftigungsort', 'label' => 'Beschaeftigungsort', 'type' => 'lookup',
             'is_required' => 1, 'order' => 3, 'options' => json_encode(['lookup_id' => 1, 'multiple' => true]),
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}

/** Probe-Muster (siehe DispoEscalateCommandTest): die Engine-Logik ohne Artisan-Lebenszyklus. */
final class CopyPhaseFieldsProbe extends CopyPhaseFields
{
    /** @return array{quelleLeer:bool, neu:string[], aktualisiert:string[], nurImZiel:string[]} */
    public function probeKopiere(RecPhase $from, RecPhase $to, bool $dryRun = false): array
    {
        return $this->kopiere($from, $to, $dryRun);
    }
}
