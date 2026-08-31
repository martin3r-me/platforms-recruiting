<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Services\ApplicationMatchingService;

/**
 * DER FALL: Eine Ausschreibung laeuft ab (Enddatum) oder wird inaktiv gesetzt,
 * waehrend die Anzeige draussen weiterlaeuft. Bewerbungen tragen weiter deren
 * RG-Referenzcode im Betreff. Bislang endeten sie als blosser Inbox-Vorschlag —
 * und von dort in der Praxis auf der Sammel-Ausschreibung "Sonstiges", obwohl
 * der Code die Stelle eindeutig benennt (Beleg: Bewerber 3187, Gamescom-Anzeige
 * mit Enddatum 24.08. bei Bewerbung am 26.08.).
 *
 * NEU: Der Code traegt die Stelle. Zeigt er auf eine geschlossene Ausschreibung,
 * faengt die AELTESTE OFFENE Ausschreibung derselben Stelle den Bewerber auf.
 * Das ist auf allen vier "allgemein"-Stellen genau die jeweilige
 * "Initiativ via Webseite"-Ausschreibung (39, 41, 40, 38 — alle vor den
 * Kampagnen-Anzeigen angelegt).
 *
 * "Geschlossen" heisst hier bewusst alles, was RecPosting::scopeOpen()
 * ausschliesst: abgelaufenes closes_at, is_active = 0, Status != published.
 * Deshalb bleibt der Fallback intakt, wenn abgelaufene Ausschreibungen
 * nachtraeglich inaktiv gesetzt werden.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, feste Uhr) — Kopf aus
 * PositionSwitchPostingChoiceTest uebernommen, nur der Bestand ist neu.
 */
class PostingRefClosedFallbackTest extends TestCase
{
    private const TEAM = 9;

    /** Stelle mit Auffangtopf (entspricht "Koeln allgemein"). */
    private const POSITION_KOELN = 91;
    private const POSTING_INITIATIV = 910;   // aelteste offene  -> der Auffangtopf
    private const POSTING_KAMPAGNE = 911;    // juengere offene
    private const POSTING_ABGELAUFEN = 912;  // closes_at in der Vergangenheit
    private const POSTING_INAKTIV = 913;     // is_active = 0

    /** Stelle ohne jede offene Ausschreibung (entspricht "Duesseldorf - Messe"). */
    private const POSITION_MESSE = 92;
    private const POSTING_MESSE_ZU = 920;

    private const SOURCE_PLATFORM = 90;
    private const CHANNEL = 900;

    /** Nach dem closes_at der abgelaufenen Ausschreibung (23.08.). */
    private const HEUTE = '2026-08-26 10:00:00';

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

        // Mini-Shims fuer die fremden Tabellen, auf die Migrationen dieses Moduls
        // per constrained() zeigen. comms_channels braucht hier zusaetzlich
        // team_id, weil der Kanal als echtes Model geladen wird.
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('teams', fn ($table) => $table->id());
        $schema->create('users', fn ($table) => $table->id());
        $schema->create('hcm_job_titles', fn ($table) => $table->id());
        $schema->create('comms_channels', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->softDeletes();
        });

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Carbon::setTestNow();
    }

    public function test_referenz_auf_per_enddatum_geschlossene_ausschreibung_faellt_auf_aelteste_offene_der_stelle(): void
    {
        $result = $this->match('Neue Bewerbung [RG-AAAA]: Isa');

        $this->assertNotNull($result, 'Referenz-Code muss aufgeloest werden');
        $this->assertSame(self::POSTING_INITIATIV, (int) $result->posting->id,
            'aelteste offene Ausschreibung der Stelle, nicht die juengere Kampagne');
        $this->assertSame('position_fallback', $result->via);
        $this->assertTrue($result->isAssignable(),
            'der Bewerber wird zugeordnet, nicht nur vorgeschlagen');
    }

    public function test_referenz_auf_inaktive_ausschreibung_faellt_ebenfalls_auf_die_stelle(): void
    {
        // Der Grund, warum abgelaufene Ausschreibungen gefahrlos inaktiv gesetzt
        // werden koennen: is_active = 0 laeuft durch denselben Zweig wie ein
        // abgelaufenes closes_at.
        $result = $this->match('Neue Bewerbung [RG-BBBB]: Marcel');

        $this->assertNotNull($result);
        $this->assertSame(self::POSTING_INITIATIV, (int) $result->posting->id);
        $this->assertSame('position_fallback', $result->via);
    }

    public function test_stelle_ohne_offene_ausschreibung_bleibt_beim_inbox_vorschlag(): void
    {
        // Messe-Fall: kein Auffangtopf vorhanden. Bewusst KEIN Auto-Assign auf
        // irgendeine fremde Stelle — der Eingang bleibt sichtbar in der Inbox.
        $result = $this->match('Neue Bewerbung [RG-CCCC]: Aya');

        $this->assertNotNull($result);
        $this->assertSame(self::POSTING_MESSE_ZU, (int) $result->posting->id);
        $this->assertSame('suggestion', $result->via);
        $this->assertFalse($result->isAssignable());
    }

    public function test_referenz_auf_offene_ausschreibung_bleibt_unveraendert(): void
    {
        $result = $this->match('Neue Bewerbung [RG-DDDD]: Timon');

        $this->assertNotNull($result);
        $this->assertSame(self::POSTING_KAMPAGNE, (int) $result->posting->id);
        $this->assertSame('external_ref', $result->via);
    }

    private function match(string $subject)
    {
        return (new ApplicationMatchingService())->matchDeterministic(
            CommsChannel::find(self::CHANNEL),
            null,
            $subject,
            null,
        );
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(\Platform\Core\Models\CoreExtraFieldDefinition::class);

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

        Capsule::table('comms_channels')->insert([
            ['id' => self::CHANNEL, 'team_id' => self::TEAM, 'type' => 'email', 'name' => 'Bewerbungen'],
        ]);

        Capsule::table('rec_positions')->insert([
            ['id' => self::POSITION_KOELN, 'uuid' => 'fpos-91', 'team_id' => self::TEAM,
             'title' => 'Koeln allgemein', 'location' => 'Koeln', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_MESSE, 'uuid' => 'fpos-92', 'team_id' => self::TEAM,
             'title' => 'Duesseldorf - Messe', 'location' => 'Duesseldorf', 'is_active' => 1,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            // Reihenfolge der IDs ist der Kern des Tests: 910 ist die aelteste offene.
            self::posting(self::POSTING_INITIATIV, self::POSITION_KOELN,
                'Koeln - Initiativ via Webseite', 'published', 1, null),
            self::posting(self::POSTING_KAMPAGNE, self::POSITION_KOELN,
                'Servicekraefte I Eventgastronomie I Koeln', 'published', 1, null),
            self::posting(self::POSTING_ABGELAUFEN, self::POSITION_KOELN,
                'Gamescom Messe 2026 I Kassenkraefte', 'published', 1, '2026-08-23 22:00:00'),
            self::posting(self::POSTING_INAKTIV, self::POSITION_KOELN,
                'Alte Koeln-Anzeige', 'published', 0, null),
            self::posting(self::POSTING_MESSE_ZU, self::POSITION_MESSE,
                'Caravan Messe 2026 I Zapfer', 'published', 1, '2026-08-25 22:00:00'),
        ]);

        Capsule::table('rec_source_platforms')->insert([
            ['id' => self::SOURCE_PLATFORM, 'uuid' => 'fsrc-90', 'team_id' => self::TEAM,
             'name' => 'Referenz-Code', 'match_pattern' => '@@referenz-code-niemals-absender@@',
             'ref_parser' => 'ref_code', 'is_active' => 1, 'priority' => 999,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_posting_external_refs')->insert([
            self::ref('fref-1', 'RG-AAAA', self::POSTING_ABGELAUFEN),
            self::ref('fref-2', 'RG-BBBB', self::POSTING_INAKTIV),
            self::ref('fref-3', 'RG-CCCC', self::POSTING_MESSE_ZU),
            self::ref('fref-4', 'RG-DDDD', self::POSTING_KAMPAGNE),
        ]);
    }

    private static function posting(int $id, int $positionId, string $title, string $status, int $isActive, ?string $closesAt): array
    {
        return [
            'id' => $id, 'uuid' => 'fpost-' . $id, 'team_id' => self::TEAM,
            'rec_position_id' => $positionId, 'title' => $title, 'activity' => null,
            'status' => $status, 'is_active' => $isActive, 'published_at' => null,
            'closes_at' => $closesAt, 'bedarf' => null, 'bewerbungs_faktor' => null,
            'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ];
    }

    private static function ref(string $uuid, string $code, int $postingId): array
    {
        return [
            'uuid' => $uuid, 'rec_posting_id' => $postingId,
            'rec_source_platform_id' => self::SOURCE_PLATFORM, 'external_ref' => $code,
            'team_id' => self::TEAM, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ];
    }
}
