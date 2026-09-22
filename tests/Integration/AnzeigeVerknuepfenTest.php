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
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosting;

/**
 * Die gemeinsame Tuer: RecApplicant::anzeigeVerknuepfen().
 *
 * Anlass (22.09.2026): 48 Bewerbungen standen in der Phase der Sammel-Stelle
 * „Sonstiges", obwohl ihre Anzeige laengst zu einer echten Stelle gehoerte.
 * Der Grund ist der Alltagsgriff im Dashboard: Dort wird die neue Anzeige per
 * syncWithoutDetaching ANGEHAENGT, die Sammel-Anzeige bleibt stehen — und der
 * Abgleich ruehrt die Phase bei zwei Anzeigen nicht an, weil sie mehrdeutig
 * waere. Ergebnis: Stelle richtig, Bearbeitungsschritt falsch, und im
 * Dashboard sieht man den Unterschied nicht.
 *
 * Belegt am lebenden Fall #3281 (Peter Kraemer): beworben am 26.08., die
 * Stelle „Teamleiter / Serviceleiter" entstand erst am 01.09. — er wurde also
 * nachtraeglich umgehaengt und blieb im alten Schritt haengen. Fuenf weitere
 * Bewerbungen an derselben Anzeige, die NACH dem 01.09. kamen, stehen korrekt.
 *
 * Zwei Regeln, beide hier festgenagelt:
 *  1. Sammel-Anzeige → echte Anzeige: die Sammel-Anzeige ist ein Platzhalter
 *     und verschwindet; Stelle, Phase und Feldwerte ziehen mit.
 *  2. Zwei ECHTE Anzeigen: nichts wird entfernt und die Phase bleibt, wo sie
 *     ist — welcher Standort gilt, entscheidet dann ein Mensch.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr).
 */
class AnzeigeVerknuepfenTest extends TestCase
{
    private const TEAM = 10;

    private const POSITION_SAMMEL = 101;
    private const POSITION_GLADBACH = 102;
    private const POSITION_KOELN = 103;

    private const PHASE_SAMMEL = 201;
    private const PHASE_GLADBACH_1 = 202;
    private const PHASE_GLADBACH_2 = 203;
    private const PHASE_KOELN_1 = 204;

    private const POSTING_SAMMEL = 1001;
    private const POSTING_GLADBACH = 1002;
    private const POSTING_KOELN = 1003;

    /** haengt an der Sammel-Anzeige, steht in der Sammel-Phase. */
    private const APPLICANT_AUS_SAMMELSTELLE = 4010;

    /** haengt an einer echten Anzeige und bekommt eine zweite echte dazu. */
    private const APPLICANT_ZWEI_ECHTE = 4012;

    private const HEUTE = '2026-09-22 10:00:00';

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
                // nicht benutzt: dieser Test ruft nur Model-Methoden auf
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

    public function test_sammel_anzeige_weicht_der_echten_und_die_phase_zieht_mit(): void
    {
        $applicant = RecApplicant::find(self::APPLICANT_AUS_SAMMELSTELLE);

        // Vorflug: Ausgangslage ist wirklich der kaputte Zustand.
        $this->assertSame(self::PHASE_SAMMEL, (int) $applicant->rec_phase_id);
        $this->assertSame([self::POSTING_SAMMEL], $applicant->postings()->pluck('rec_postings.id')->all());

        $applicant->anzeigeVerknuepfen(RecPosting::find(self::POSTING_GLADBACH));

        $frisch = RecApplicant::find(self::APPLICANT_AUS_SAMMELSTELLE);

        $this->assertSame(
            [self::POSTING_GLADBACH],
            $frisch->postings()->pluck('rec_postings.id')->all(),
            'die Sammel-Anzeige ist ein Platzhalter und verschwindet, die echte bleibt allein stehen'
        );
        $this->assertSame(
            self::POSITION_GLADBACH,
            (int) $frisch->rec_position_id,
            'die Stelle folgt der echten Anzeige'
        );
        $this->assertSame(
            self::PHASE_GLADBACH_1,
            (int) $frisch->rec_phase_id,
            'der Bearbeitungsschritt zieht mit — gleiche Ordnungszahl in der neuen Stelle'
        );

        $wert = Capsule::table('core_extra_field_values')
            ->where('fieldable_id', self::APPLICANT_AUS_SAMMELSTELLE)
            ->first();

        $this->assertNotNull($wert, 'der Feldwert existiert weiterhin');
        $this->assertSame(
            self::definitionId(self::PHASE_GLADBACH_1, 'vorname'),
            (int) $wert->definition_id,
            'der Feldwert haengt jetzt an der Felddefinition der neuen Stelle, nicht mehr an der der Sammelstelle'
        );
    }

    public function test_zwei_echte_anzeigen_lassen_die_phase_unangetastet(): void
    {
        $applicant = RecApplicant::find(self::APPLICANT_ZWEI_ECHTE);

        $this->assertSame(self::PHASE_GLADBACH_2, (int) $applicant->rec_phase_id, 'Vorflug: steht im zweiten Schritt');

        $applicant->anzeigeVerknuepfen(RecPosting::find(self::POSTING_KOELN));

        $frisch = RecApplicant::find(self::APPLICANT_ZWEI_ECHTE);

        $this->assertSame(
            [self::POSTING_GLADBACH, self::POSTING_KOELN],
            $frisch->postings()->orderBy('rec_postings.id')->pluck('rec_postings.id')->all(),
            'eine ECHTE Anzeige wird nie entfernt — sie sagt, woher die Bewerbung kam'
        );
        $this->assertSame(
            self::PHASE_GLADBACH_2,
            (int) $frisch->rec_phase_id,
            'bei zwei echten Anzeigen ist der Standort mehrdeutig: die Phase bleibt stehen, ein Mensch entscheidet'
        );
    }

    private static function definitionId(int $phaseId, string $name): int
    {
        return (int) CoreExtraFieldDefinition::query()
            ->where('context_type', RecPhase::class)
            ->where('context_id', $phaseId)
            ->where('name', $name)
            ->value('id');
    }

    private static function setzeBestandAufAusgangszustand(): void
    {
        Capsule::table('rec_applicants')->where('id', self::APPLICANT_AUS_SAMMELSTELLE)->update([
            'rec_position_id' => self::POSITION_SAMMEL,
            'rec_phase_id' => self::PHASE_SAMMEL,
            'owned_by_user_id' => null,
            'is_unrouted' => 0,
        ]);
        Capsule::table('rec_applicants')->where('id', self::APPLICANT_ZWEI_ECHTE)->update([
            'rec_position_id' => self::POSITION_GLADBACH,
            'rec_phase_id' => self::PHASE_GLADBACH_2,
            'owned_by_user_id' => null,
            'is_unrouted' => 0,
        ]);

        Capsule::table('rec_applicant_posting')->delete();
        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APPLICANT_AUS_SAMMELSTELLE, 'rec_posting_id' => self::POSTING_SAMMEL,
             'applied_at' => '2026-09-01', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['rec_applicant_id' => self::APPLICANT_ZWEI_ECHTE, 'rec_posting_id' => self::POSTING_GLADBACH,
             'applied_at' => '2026-09-01', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);

        Capsule::table('core_extra_field_values')->delete();
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => self::definitionId(self::PHASE_SAMMEL, 'vorname'),
            'fieldable_type' => (new RecApplicant())->getMorphClass(),
            'fieldable_id' => self::APPLICANT_AUS_SAMMELSTELLE,
            'value' => 'Ali',
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        Capsule::table('rec_auto_pilot_logs')->delete();
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
            ['id' => self::POSITION_SAMMEL, 'uuid' => 'avk-pos-101', 'team_id' => self::TEAM,
             'title' => 'Sonstiges', 'location' => '', 'is_active' => 1, 'is_sammelstelle' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_GLADBACH, 'uuid' => 'avk-pos-102', 'team_id' => self::TEAM,
             'title' => 'Moenchengladbach allgemein', 'location' => 'Moenchengladbach', 'is_active' => 1, 'is_sammelstelle' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_KOELN, 'uuid' => 'avk-pos-103', 'team_id' => self::TEAM,
             'title' => 'Koeln allgemein', 'location' => 'Koeln', 'is_active' => 1, 'is_sammelstelle' => 0,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_SAMMEL, 'uuid' => 'avk-ph-201', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_SAMMEL, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_GLADBACH_1, 'uuid' => 'avk-ph-202', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_GLADBACH, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_GLADBACH_2, 'uuid' => 'avk-ph-203', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_GLADBACH, 'name' => 'Schulung buchen', 'order' => 2,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::PHASE_KOELN_1, 'uuid' => 'avk-ph-204', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_KOELN, 'name' => 'Bewerbung', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Gleicher Feldname in beiden Stellen — daran haengt der Wertetransport.
        CoreExtraFieldDefinition::query()->insert([
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_SAMMEL,
             'name' => 'vorname', 'label' => 'Vorname', 'type' => 'text',
             'is_required' => 1, 'order' => 1, 'options' => null,
             'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'context_type' => RecPhase::class, 'context_id' => self::PHASE_GLADBACH_1,
             'name' => 'vorname', 'label' => 'Vorname', 'type' => 'text',
             'is_required' => 1, 'order' => 1, 'options' => null,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_SAMMEL, 'uuid' => 'avk-pstg-1001', 'rec_position_id' => self::POSITION_SAMMEL,
             'team_id' => self::TEAM, 'title' => 'Sonstiges', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_GLADBACH, 'uuid' => 'avk-pstg-1002', 'rec_position_id' => self::POSITION_GLADBACH,
             'team_id' => self::TEAM, 'title' => 'Servicekraefte Gladbach', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_KOELN, 'uuid' => 'avk-pstg-1003', 'rec_position_id' => self::POSITION_KOELN,
             'team_id' => self::TEAM, 'title' => 'Servicekraefte Koeln', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT_AUS_SAMMELSTELLE, 'uuid' => 'avk-app-4010', 'team_id' => self::TEAM,
             'applied_at' => '2026-09-01', 'rec_phase_id' => self::PHASE_SAMMEL, 'rec_position_id' => self::POSITION_SAMMEL,
             'is_test' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_ZWEI_ECHTE, 'uuid' => 'avk-app-4012', 'team_id' => self::TEAM,
             'applied_at' => '2026-09-01', 'rec_phase_id' => self::PHASE_GLADBACH_2, 'rec_position_id' => self::POSITION_GLADBACH,
             'is_test' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
