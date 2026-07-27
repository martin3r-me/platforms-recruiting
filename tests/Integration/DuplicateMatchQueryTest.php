<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Support\DuplicateApplicantGuard;

/**
 * Integrations-Test der Match-Query (DuplicateApplicantGuard::matchesFor) gegen
 * die ECHTEN Modelle auf SQLite in-memory via Capsule — läuft im regulären
 * Runner (meingedeck/vendor/bin/phpunit -c phpunit.xml), kein Testbench nötig:
 * der Composer-Autoloader des Runners liefert Illuminate + Platform-Klassen.
 */
class DuplicateMatchQueryTest extends TestCase
{
    private const TEAM = 3;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        // LogsActivity-Trait (CrmContact) verlangt config(); Events leer = keine Hooks.
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher feuern die creating-Hooks der Modelle nicht (uuid,
        // public_token) — das echte Schema verlangt sie als NOT NULL.
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

        // CrmContactLink::creating ruft auth()->check() — Stub ohne User.
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });

        // Schema-/DB-Facades auf Capsule verdrahten, damit die ECHTEN
        // Migrations-Dateien unverändert laufen können.
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runRealMigrations();
    }

    protected function setUp(): void
    {
        foreach (['crm_phone_numbers', 'crm_contact_links', 'crm_contacts', 'rec_applicants'] as $table) {
            Capsule::table($table)->delete();
        }
    }

    /**
     * Schema aus den ECHTEN Migrationen der Module (kein handgebautes
     * Schema::create) — ein Prod-Spaltenrename oder Relation-Key-Change schlägt
     * damit hier auf, statt still grün durchzulaufen. Explizite Liste: die
     * Creates der vier beteiligten Tabellen plus alle Migrationen, die deren
     * Schema ändern (inkl. Quer-Tabellen, die add_matching_columns anfasst).
     */
    private static function runRealMigrations(): void
    {
        $modules = dirname(__DIR__, 3); // …/platform/modules

        $files = [
            // CRM: Creates + Alters der drei Kontakt-Tabellen
            'platform-crm/database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php',
            'platform-crm/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
            'platform-crm/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
            'platform-crm/database/migrations/2025_02_18_000001_add_whatsapp_status_to_crm_phone_numbers_table.php',
            'platform-crm/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
            'platform-crm/database/migrations/2026_02_19_230000_add_whatsapp_template_tracking_to_crm_phone_numbers.php',
            // Recruiting: rec_applicants create + alle Schema-Alters darauf
            'platforms-recruiting/database/migrations/2026_02_09_000005_create_rec_applicants_table.php',
            'platforms-recruiting/database/migrations/2026_02_09_000006_create_rec_applicant_posting_table.php',
            'platforms-recruiting/database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php',
            'platforms-recruiting/database/migrations/2026_02_19_000001_add_enrichment_status_to_rec_applicants_table.php',
            'platforms-recruiting/database/migrations/2026_02_19_000002_add_preferred_comms_channel_id_to_rec_applicants_table.php',
            'platforms-recruiting/database/migrations/2026_03_20_000001_add_auto_pilot_reminder_columns_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_12_000002_add_rec_phase_id_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_13_000001_add_is_parked_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_24_000001_add_hr_desk_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_29_000001_create_rec_source_platforms_table.php',
            'platforms-recruiting/database/migrations/2026_04_29_000002_add_source_platform_id_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_29_000003_add_is_unrouted_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_29_000005_add_contract_template_id_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_04_30_000001_add_import_source_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_05_07_000001_add_export_changed_at_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_05_08_000001_add_is_test_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php',
            'platforms-recruiting/database/migrations/2026_06_12_000003_add_matching_columns.php',
            'platforms-recruiting/database/migrations/2026_07_24_000001_add_duplicate_of_to_rec_applicants.php',
        ];

        foreach ($files as $relative) {
            $path = $modules . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private function applicant(array $attrs = []): RecApplicant
    {
        return RecApplicant::create(array_merge([
            'team_id' => self::TEAM,
            'is_active' => true,
            'auto_pilot' => true,
        ], $attrs));
    }

    /** @param array<int, array{international: ?string, is_primary?: bool, is_active?: bool}> $phones */
    private function withPhones(RecApplicant $applicant, array $phones): RecApplicant
    {
        $contact = CrmContact::create([
            'team_id' => self::TEAM, 'is_active' => true, 'first_name' => 'T', 'last_name' => 'T',
        ]);
        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => self::TEAM]);

        foreach ($phones as $p) {
            $contact->phoneNumbers()->create([
                'raw_input' => $p['international'] ?? 'raw',
                'international' => $p['international'],
                'is_primary' => $p['is_primary'] ?? true,
                'is_active' => $p['is_active'] ?? true,
                'phone_type_id' => 1, // NOT NULL im echten Schema
            ]);
        }

        return $applicant;
    }

    public function test_gleiche_e164_primary_matcht_in_beide_richtungen(): void
    {
        $a = $this->withPhones($this->applicant(['auto_pilot_last_reminder_at' => date('Y-m-d H:i:s')]), [['international' => '+491111111111']]);
        $b = $this->withPhones($this->applicant(), [['international' => '+491111111111']]);

        $this->assertSame([$a->id], DuplicateApplicantGuard::matchesFor($b, '+491111111111')->pluck('id')->all());
        $this->assertSame([$b->id], DuplicateApplicantGuard::matchesFor($a, '+491111111111')->pluck('id')->all());
    }

    public function test_gleiche_nummer_als_secondary_matcht(): void
    {
        $a = $this->withPhones($this->applicant(), [['international' => '+491111111111']]);
        $c = $this->withPhones($this->applicant(), [
            ['international' => '+492222222222', 'is_primary' => true],
            ['international' => '+491111111111', 'is_primary' => false],
        ]);

        $this->assertSame([$c->id], DuplicateApplicantGuard::matchesFor($a, '+491111111111')->pluck('id')->all());
        $this->assertSame([$a->id], DuplicateApplicantGuard::matchesFor($c, '+491111111111')->pluck('id')->all());
    }

    public function test_legacy_bestandsformate_matchen_sauberen_input_und_umgekehrt(): void
    {
        $sauber = $this->withPhones($this->applicant(), [['international' => '+491637899743']]);
        $nullNotation = $this->withPhones($this->applicant(), [['international' => '0163 7899743']]);
        $nackt = $this->withPhones($this->applicant(), [['international' => '1637899743']]);
        $slash = $this->withPhones($this->applicant(), [['international' => '0163/78 99.743']]); // ContactIndex-Fallback-Klasse

        $this->assertSame(
            [$nullNotation->id, $nackt->id, $slash->id],
            DuplicateApplicantGuard::matchesFor($sauber, '+491637899743')->pluck('id')->all()
        );
        $this->assertSame(
            [$sauber->id, $nullNotation->id, $nackt->id],
            DuplicateApplicantGuard::matchesFor($slash, '0163/78 99.743')->pluck('id')->all()
        );
    }

    public function test_ortsnetz_49_praefix_kollidiert_nicht_mit_laendercode(): void
    {
        // Person X: Festnetz Leer, zwei Schreibweisen. Person Y: andere Nummer,
        // deren Ziffern X' NSN entsprechen — darf über kein Stripping matchen.
        $x1 = $this->withPhones($this->applicant(), [['international' => '0491234567']]);
        $x2 = $this->withPhones($this->applicant(), [['international' => '+49 491 234567']]);
        $y = $this->withPhones($this->applicant(), [['international' => '+491234567']]);

        $this->assertSame([$x2->id], DuplicateApplicantGuard::matchesFor($x1, '0491234567')->pluck('id')->all());
        $this->assertSame([$x1->id], DuplicateApplicantGuard::matchesFor($x2, '+49 491 234567')->pluck('id')->all());
        $this->assertSame([], DuplicateApplicantGuard::matchesFor($y, '+491234567')->pluck('id')->all());
    }

    public function test_ohne_international_team_inaktiv_rejected_und_inaktive_nummer_matchen_nie(): void
    {
        $kandidat = $this->withPhones($this->applicant(), [['international' => '+491111111111']]);
        $this->withPhones($this->applicant(), [['international' => null]]);
        $this->withPhones($this->applicant(['team_id' => 99]), [['international' => '+491111111111']]);
        $this->withPhones($this->applicant(['is_active' => false]), [['international' => '+491111111111']]);
        $this->withPhones($this->applicant(['rejected_at' => date('Y-m-d H:i:s')]), [['international' => '+491111111111']]);
        $this->withPhones($this->applicant(), [['international' => '+491111111111', 'is_active' => false]]);

        $this->assertSame([], DuplicateApplicantGuard::matchesFor($kandidat, '+491111111111')->pluck('id')->all());
    }

    public function test_kontakt_status_steht_im_match_ergebnis_fuer_die_senior_regel(): void
    {
        $kontaktiert = $this->withPhones(
            $this->applicant(['auto_pilot_last_reminder_at' => date('Y-m-d H:i:s')]),
            [['international' => '+491111111111']]
        );
        $frisch = $this->withPhones($this->applicant(), [['international' => '+491111111111']]);
        $kandidat = $this->withPhones($this->applicant(), [['international' => '+491111111111']]);

        $matches = DuplicateApplicantGuard::matchesFor($kandidat, '+491111111111');

        $this->assertNotNull($matches->firstWhere('id', $kontaktiert->id)->auto_pilot_last_reminder_at);
        $this->assertNull($matches->firstWhere('id', $frisch->id)->auto_pilot_last_reminder_at);
    }

    public function test_kandidat_ohne_versandnummer_liefert_leeres_set(): void
    {
        $kandidat = $this->withPhones($this->applicant(), [['international' => '+491111111111']]);
        $this->withPhones($this->applicant(), [['international' => '+491111111111']]);

        $this->assertTrue(DuplicateApplicantGuard::matchesFor($kandidat, null)->isEmpty());
        $this->assertTrue(DuplicateApplicantGuard::matchesFor($kandidat, '')->isEmpty());
    }

    public function test_duplicate_of_spalte_existiert_im_echten_schema(): void
    {
        $this->assertTrue(
            Capsule::schema()->hasColumn('rec_applicants', 'duplicate_of_applicant_id'),
            'Migration add_duplicate_of_to_rec_applicants fehlt in runRealMigrations() oder ist nicht angelegt'
        );
    }
}
