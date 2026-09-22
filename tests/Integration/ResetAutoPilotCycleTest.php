<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Console\Commands\ResetAutoPilotCycle;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;

/**
 * recruiting:reset-auto-pilot-cycle — holt still fertiggemeldete Bewerbungen
 * zurueck in den Ablauf.
 *
 * Anlass (22.09.2026): 13 Bewerbungen der Sammel-Phase trugen einen
 * Abschluss-Haken aus dem August, gesetzt in der Sekunde, in der der AutoPilot
 * die damals FELDLOSE Phase als „fertig" las (calculateProgress liefert ohne
 * Pflichtfelder 100). Zehn von zwoelf aktiven haben bis heute **keinen
 * einzigen Feldwert** — sieben davon stehen trotzdem auf progress 100. Der
 * Heil-Lauf hat ihre Phase korrigiert, aber der Cron nimmt nur Bewerbungen
 * ohne Haken: sie blieben stehen.
 *
 * Der Reset raeumt genau diesen Haken weg, mitsamt Zustand und Zaehlern. Was
 * danach passiert, entscheidet der Cron aus den ECHTEN Daten — deshalb meldet
 * das Kommando pro Zeile die Vorhersage, damit vor dem Knopfdruck feststeht,
 * wie viele Erstkontakte und wie viele Aufstiege rausgehen.
 */
class ResetAutoPilotCycleTest extends TestCase
{
    private const TEAM = 11;

    private const POSITION = 111;
    private const PHASE_BEWERBUNG = 211;
    private const PHASE_BUCHEN = 212;

    /** Haken gesetzt, KEIN Feldwert -> muss den Erstkontakt bekommen. */
    private const APPLICANT_LEER = 5010;

    /** Haken gesetzt, Feld gefuellt -> steigt auf. */
    private const APPLICANT_VOLLSTAENDIG = 5011;

    /** Kein Haken -> nichts zu tun. */
    private const APPLICANT_OHNE_HAKEN = 5012;

    private const HEUTE = '2026-09-22 12:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $schema = $capsule->getConnection()->getSchemaBuilder();
        foreach (['teams', 'users', 'hcm_job_titles', 'comms_channels'] as $fremd) {
            if (!$schema->hasTable($fremd)) {
                $schema->create($fremd, fn ($table) => $table->id());
            }
        }
        if (!$schema->hasTable('crm_contact_links')) {
            $schema->create('crm_contact_links', function ($table) {
                $table->id();
                $table->string('linkable_type');
                $table->unsignedBigInteger('linkable_id');
                $table->timestamps();
            });
        }

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
                // nicht benutzt
            }
        });

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Carbon::setTestNow();
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::setzeBestandAufAusgangszustand();
    }

    public function test_haken_faellt_und_die_vorhersage_trennt_erstkontakt_von_aufstieg(): void
    {
        $bericht = (new ResetAutoPilotCycleProbe())->probeReset(
            [self::APPLICANT_LEER, self::APPLICANT_VOLLSTAENDIG, self::APPLICANT_OHNE_HAKEN],
            (string) self::TEAM,
            dryRun: false,
        );

        $this->assertSame(2, $bericht['zurueckgesetzt'], 'nur die beiden mit Haken');
        $this->assertSame(1, $bericht['ohneHaken'], 'die dritte Bewerbung war nie fertiggemeldet');
        $this->assertSame(1, $bericht['erstkontakt'], 'ohne Feldwerte beginnt der Ablauf von vorn');
        $this->assertSame(1, $bericht['aufstieg'], 'vollstaendig heisst: der naechste Lauf schiebt weiter');

        // Die Zaehler allein genuegen nicht: bei einem Fall je Sorte bleibt
        // 1:1 auch dann stehen, wenn die Vorhersage vertauscht ist (per
        // Mutation geprueft). Darum an der ZEILE festnageln, wer was bekommt.
        $zeilen = implode("\n", $bericht['zeilen']);
        $this->assertMatchesRegularExpression(
            '/#' . self::APPLICANT_LEER . '\s.*bekommt den Erstkontakt/',
            $zeilen,
            'die Bewerbung ohne Feldwerte faengt von vorn an'
        );
        $this->assertMatchesRegularExpression(
            '/#' . self::APPLICANT_VOLLSTAENDIG . '\s.*steigt beim nächsten Lauf auf/u',
            $zeilen,
            'die vollstaendige Bewerbung wird weitergeschoben'
        );

        $leer = RecApplicant::find(self::APPLICANT_LEER);
        $this->assertNull($leer->auto_pilot_completed_at, 'der Abschluss-Haken ist weg');
        $this->assertNull($leer->auto_pilot_state_id);
        $this->assertSame(0, (int) $leer->auto_pilot_reminder_count);
        $this->assertNull($leer->auto_pilot_last_reminder_at);
        $this->assertSame(
            0,
            (int) $leer->progress,
            'die eingefrorene 100 aus der feldlosen Phase faellt auf den wahren Stand'
        );

        $unberuehrt = RecApplicant::find(self::APPLICANT_OHNE_HAKEN);
        $this->assertSame(70, (int) $unberuehrt->progress, 'ohne Haken wird nichts angefasst');

        $this->assertSame(
            2,
            Capsule::table('rec_auto_pilot_logs')->where('type', 'cycle_reset')->count(),
            'jeder Reset hinterlaesst eine Spur in der Bewerberakte'
        );
    }

    public function test_dry_run_meldet_dieselben_zahlen_und_schreibt_nichts(): void
    {
        $vorher = self::schnappschuss();

        $dry = (new ResetAutoPilotCycleProbe())->probeReset(
            [self::APPLICANT_LEER, self::APPLICANT_VOLLSTAENDIG, self::APPLICANT_OHNE_HAKEN],
            (string) self::TEAM,
            dryRun: true,
        );

        $this->assertSame($vorher, self::schnappschuss(), 'im Trockenlauf aendert sich nichts');
        $this->assertGreaterThan(0, $dry['zurueckgesetzt'], 'Vorflug: der Bestand loest ueberhaupt etwas aus');

        $echt = (new ResetAutoPilotCycleProbe())->probeReset(
            [self::APPLICANT_LEER, self::APPLICANT_VOLLSTAENDIG, self::APPLICANT_OHNE_HAKEN],
            (string) self::TEAM,
            dryRun: false,
        );

        foreach (['geprueft', 'zurueckgesetzt', 'ohneHaken', 'erstkontakt', 'aufstieg'] as $zaehler) {
            $this->assertSame($echt[$zaehler], $dry[$zaehler], "Zaehler '{$zaehler}' muss uebereinstimmen");
        }
    }

    public function test_fremdes_team_wird_nicht_angefasst(): void
    {
        $bericht = (new ResetAutoPilotCycleProbe())->probeReset(
            [self::APPLICANT_LEER],
            teamId: '999',
            dryRun: false,
        );

        $this->assertSame(0, $bericht['zurueckgesetzt']);
        $this->assertSame(1, $bericht['fremd']);
        $this->assertNotNull(
            RecApplicant::find(self::APPLICANT_LEER)->auto_pilot_completed_at,
            'die Bewerbung eines fremden Teams bleibt unberuehrt'
        );
    }

    private static function schnappschuss(): array
    {
        return [
            'applicants' => Capsule::table('rec_applicants')
                ->whereIn('id', [self::APPLICANT_LEER, self::APPLICANT_VOLLSTAENDIG, self::APPLICANT_OHNE_HAKEN])
                ->orderBy('id')
                ->get(['id', 'auto_pilot_completed_at', 'auto_pilot_state_id', 'auto_pilot_reminder_count', 'progress'])
                ->map(fn ($r) => (array) $r)->all(),
            'logs' => Capsule::table('rec_auto_pilot_logs')->count(),
        ];
    }

    private static function setzeBestandAufAusgangszustand(): void
    {
        Capsule::table('rec_applicants')->where('id', self::APPLICANT_LEER)->update([
            'auto_pilot_completed_at' => '2026-08-19 12:43:32',
            'auto_pilot_state_id' => 6,
            'auto_pilot_reminder_count' => 3,
            'auto_pilot_last_reminder_at' => '2026-08-18 09:00:00',
            'progress' => 100,
        ]);
        Capsule::table('rec_applicants')->where('id', self::APPLICANT_VOLLSTAENDIG)->update([
            'auto_pilot_completed_at' => '2026-09-04 19:14:30',
            'auto_pilot_state_id' => 6,
            'auto_pilot_reminder_count' => 1,
            'progress' => 100,
        ]);
        Capsule::table('rec_applicants')->where('id', self::APPLICANT_OHNE_HAKEN)->update([
            'auto_pilot_completed_at' => null,
            'auto_pilot_state_id' => null,
            'auto_pilot_reminder_count' => 0,
            'progress' => 70,
        ]);

        Capsule::table('rec_auto_pilot_logs')->delete();
    }

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
            'id' => self::POSITION, 'uuid' => 'rac-pos-111', 'team_id' => self::TEAM,
            'title' => 'Duesseldorf allgemein', 'location' => 'Duesseldorf', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_BEWERBUNG, 'uuid' => 'rac-ph-211', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION, 'name' => 'Bewerbung', 'order' => 1,
             'completion_type' => 'fields', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_BUCHEN, 'uuid' => 'rac-ph-212', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION, 'name' => 'Schulung buchen', 'order' => 2,
             'completion_type' => 'booking', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        CoreExtraFieldDefinition::query()->insert([
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_BEWERBUNG,
             'name' => 'vorname', 'label' => 'Vorname', 'type' => 'text',
             'is_required' => 1, 'order' => 1, 'options' => null,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        $definition = (int) CoreExtraFieldDefinition::query()
            ->where('context_id', self::PHASE_BEWERBUNG)->where('name', 'vorname')->value('id');

        foreach ([self::APPLICANT_LEER, self::APPLICANT_VOLLSTAENDIG, self::APPLICANT_OHNE_HAKEN] as $id) {
            Capsule::table('rec_applicants')->insert([
                'id' => $id, 'uuid' => 'rac-app-' . $id, 'team_id' => self::TEAM,
                'applied_at' => '2026-08-14', 'rec_phase_id' => self::PHASE_BEWERBUNG,
                'rec_position_id' => self::POSITION, 'is_test' => 0, 'is_active' => 1,
                'auto_pilot' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Nur der Vollstaendige hat den Wert — daran haengt die Vorhersage.
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => $definition,
            'fieldable_type' => (new RecApplicant())->getMorphClass(),
            'fieldable_id' => self::APPLICANT_VOLLSTAENDIG,
            'value' => 'Hotak',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

/** Probe-Muster: die reine Logik ohne Artisan-Lebenszyklus. */
final class ResetAutoPilotCycleProbe extends ResetAutoPilotCycle
{
    /** @return array{geprueft:int,zurueckgesetzt:int,ohneHaken:int,fremd:int,erstkontakt:int,aufstieg:int,zeilen:list<string>} */
    public function probeReset(array $ids, ?string $teamId, bool $dryRun): array
    {
        return $this->zuruecksetzen($ids, $teamId, $dryRun);
    }
}
