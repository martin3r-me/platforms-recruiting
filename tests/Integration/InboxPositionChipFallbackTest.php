<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Abschluss-Durchsicht, Befund 6: der Stellen-Chip in Inbox::contextChips()
 * nutzte bisher NUR applicant->position (rec_position_id) — bei Altbestand
 * ohne dieses Feld blieb der Chip leer, obwohl die Bewerbung ueber eine
 * Anzeige (postings) einer Stelle zugeordnet ist. Fix: ersatzweise die erste
 * Stelle aus positions().
 *
 * Testet die exakte Ausdrucks-Logik aus Inbox::contextChips()
 * ($applicant->position ?: $applicant->positions()->first()) direkt auf dem
 * Modell — ohne Livewire, da contextChips() ueber $this->selectedRow
 * (#[Computed]) laeuft und damit denselben Livewire-Boot braucht wie
 * windowOpen() vor dessen Extraktion (siehe InboxWindowOpenTest-Docblock).
 */
class InboxPositionChipFallbackTest extends TestCase
{
    private const TEAM = 896;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

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

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    public function test_altbestand_ohne_rec_position_id_zeigt_erste_stelle_aus_positions(): void
    {
        $positionId = (int) Capsule::table('rec_positions')->insertGetId([
            'uuid' => 'uuid-position-alt', 'team_id' => self::TEAM, 'title' => 'Kuechenhilfe',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $postingId = (int) Capsule::table('rec_postings')->insertGetId([
            'uuid' => 'uuid-posting-alt', 'rec_position_id' => $positionId, 'team_id' => self::TEAM,
            'title' => 'Kuechenhilfe (Anzeige)', 'status' => 'published', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $applicantId = (int) Capsule::table('rec_applicants')->insertGetId([
            'uuid' => 'uuid-applicant-altbestand', 'team_id' => self::TEAM,
            'rec_position_id' => null, // Altbestand: kein direktes Feld gesetzt.
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => $applicantId, 'rec_posting_id' => $postingId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $applicant = RecApplicant::query()->with(['position', 'postings.position'])->findOrFail($applicantId);

        $this->assertNull($applicant->position, 'Vorbedingung: kein rec_position_id gesetzt.');

        // Exakt die Fallback-Logik aus Inbox::contextChips().
        $position = $applicant->position ?: $applicant->positions()->first();

        $this->assertNotNull($position, 'Ersatzweise muss die erste Stelle aus positions() greifen.');
        $this->assertSame('Kuechenhilfe', $position->title);
    }

    public function test_rec_position_id_hat_vorrang_vor_positions(): void
    {
        $directPositionId = (int) Capsule::table('rec_positions')->insertGetId([
            'uuid' => 'uuid-position-direkt', 'team_id' => self::TEAM, 'title' => 'Service',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $anzeigePositionId = (int) Capsule::table('rec_positions')->insertGetId([
            'uuid' => 'uuid-position-anzeige', 'team_id' => self::TEAM, 'title' => 'Andere Anzeige',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $postingId = (int) Capsule::table('rec_postings')->insertGetId([
            'uuid' => 'uuid-posting-direkt', 'rec_position_id' => $anzeigePositionId, 'team_id' => self::TEAM,
            'title' => 'Andere Anzeige', 'status' => 'published', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $applicantId = (int) Capsule::table('rec_applicants')->insertGetId([
            'uuid' => 'uuid-applicant-direkt', 'team_id' => self::TEAM,
            'rec_position_id' => $directPositionId,
            'progress' => 0, 'is_active' => true, 'auto_pilot' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => $applicantId, 'rec_posting_id' => $postingId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $applicant = RecApplicant::query()->with(['position', 'postings.position'])->findOrFail($applicantId);

        $position = $applicant->position ?: $applicant->positions()->first();

        $this->assertSame(
            'Service',
            $position->title,
            'rec_position_id (die tatsaechliche Stelle der Bewerbung) hat Vorrang vor der Anzeigen-Stelle.',
        );
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);

        $files = [
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_02_09_000001_create_rec_positions_table.php'],
            [$own, 'database/migrations/2026_02_09_000002_create_rec_postings_table.php'],
            [$own, 'database/migrations/2026_02_09_000006_create_rec_applicant_posting_table.php'],
            // rec_position_id kam erst per spaeterer ALTER-Migration dazu
            // (siehe Docblock von RecApplicant::position()).
            [$own, 'database/migrations/2026_08_18_000001_add_rec_position_id_to_rec_applicants.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }

        // RecApplicant::postings() selektiert withPivot(['matched_via', 'match_confidence']).
        // Die volle Migration (2026_06_12_000003_add_matching_columns.php) fasst
        // zusaetzlich rec_source_platforms an, das hier nicht migriert ist —
        // deshalb nur die beiden fuer positions() noetigen Spalten direkt.
        Capsule::schema()->table('rec_applicant_posting', function ($table) {
            $table->string('matched_via', 30)->nullable();
            $table->string('match_confidence', 10)->nullable();
        });
    }
}
