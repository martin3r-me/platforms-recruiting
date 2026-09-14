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
use Platform\Recruiting\Services\IncomingApplicationService;

/**
 * Bestandscheck des Inbound (IncomingApplicationService::findExistingApplicantByContact)
 * gegen die ECHTEN Modelle auf SQLite in-memory via Capsule — Aufbau bewusst
 * baugleich zu DuplicateMatchQueryTest, inklusive der dort dokumentierten
 * Dispatcher- und Facade-Fallen.
 *
 * Hintergrund (Fall Jana Derichs, 11.09.2026): Der Lookup filterte auf
 * is_active=true. Das Flag traegt in diesem System aber drei gegensaetzliche
 * Bedeutungen — "abgelehnt" (RecApplicant::executeMinorRejection,
 * HrDeskRoutingService), "fertig eingestellt" (CreateEmployeeFromApplicantService
 * setzt es beim MA-Anlegen auf false, rein als Dashboard-Aufraeumen) und "Spam
 * verworfen" (Inbox). Ein inaktiver Bewerber war damit fuer den Dedup
 * unsichtbar, und die naechste Nachricht erzeugte eine zweite Akte.
 *
 * Der Ausschluss abgelehnter Bewerber haengt an rejected_at und bleibt
 * unveraendert — genau dafuer steht der zweite Test.
 *
 * Die Methode ist private: ein oeffentlicher Einstieg (handleInboundMessage)
 * wuerde Team-, User- und Channel-Fixtures aus platforms-core mitziehen und die
 * Query hinter Intake-Gate und HCM-Check verstecken. Der Test greift deshalb
 * bewusst per Reflection genau auf die Query zu, um die er geht.
 */
class InboundApplicantLookupTest extends TestCase
{
    private const TEAM = 3;
    private const PHONE = '+4915775286778';

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
        Model::clearBootedModels();
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
        // Migrations-Dateien unveraendert laufen koennen.
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runRealMigrations();
    }

    /** Siehe DuplicateMatchQueryTest: Schema-Facade bleibt sonst prozessweit gecacht. */
    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['crm_phone_numbers', 'crm_contact_links', 'crm_contacts', 'rec_applicants'] as $table) {
            Capsule::table($table)->delete();
        }
    }

    public function test_findet_inaktiven_bewerber_der_nicht_abgelehnt_ist(): void
    {
        $applicant = $this->applicantWithPhone(['is_active' => false]);

        $this->assertSame(
            $applicant->id,
            $this->lookup(self::PHONE)?->id,
            'Ein inaktiver, nicht abgelehnter Bewerber muss gefunden werden — sonst legt '
            . 'der Inbound eine Dublette an (Fall Jana Derichs).'
        );
    }

    public function test_findet_abgelehnten_bewerber_nicht(): void
    {
        $this->applicantWithPhone(['is_active' => false, 'rejected_at' => '2026-09-01 10:00:00']);

        $this->assertNull(
            $this->lookup(self::PHONE),
            'Abgelehnte Bewerber muessen ausgeschlossen bleiben — rejected_at traegt diesen Schutz.'
        );
    }

    public function test_findet_aktiven_bewerber_weiterhin(): void
    {
        $applicant = $this->applicantWithPhone(['is_active' => true]);

        $this->assertSame($applicant->id, $this->lookup(self::PHONE)?->id);
    }

    public function test_findet_bewerber_eines_fremden_teams_nicht(): void
    {
        $this->applicantWithPhone(['is_active' => false, 'team_id' => self::TEAM + 1]);

        $this->assertNull($this->lookup(self::PHONE));
    }

    private function lookup(string $identifier): ?RecApplicant
    {
        $service = new IncomingApplicationService();

        $method = new \ReflectionMethod($service, 'findExistingApplicantByContact');
        $method->setAccessible(true);

        return $method->invoke($service, $identifier, self::TEAM);
    }

    private function applicantWithPhone(array $attrs = []): RecApplicant
    {
        $applicant = RecApplicant::create(array_merge([
            'team_id' => self::TEAM,
            'is_active' => true,
            'auto_pilot' => true,
        ], $attrs));

        $contact = CrmContact::create([
            'team_id' => $applicant->team_id, 'is_active' => true, 'first_name' => 'Jana', 'last_name' => 'D',
        ]);
        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => $applicant->team_id]);

        $contact->phoneNumbers()->create([
            'raw_input' => self::PHONE,
            'international' => self::PHONE,
            'is_primary' => true,
            'is_active' => true,
            'phone_type_id' => 1, // NOT NULL im echten Schema
        ]);

        return $applicant;
    }

    /** Schema aus den ECHTEN Migrationen — identische Liste wie DuplicateMatchQueryTest. */
    private static function runRealMigrations(): void
    {
        $ownModule = dirname(__DIR__, 2);
        $modules = self::findModulesRoot();

        $files = [
            'platform-crm/database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php',
            // Der Bestandscheck sucht ueber E-Mail UND Telefon — anders als die
            // reine Telefon-Query in DuplicateMatchQueryTest.
            'platform-crm/database/migrations/2024_01_01_000015_create_crm_email_addresses_table.php',
            'platform-crm/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
            'platform-crm/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
            'platform-crm/database/migrations/2025_02_18_000001_add_whatsapp_status_to_crm_phone_numbers_table.php',
            'platform-crm/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
            'platform-crm/database/migrations/2026_02_19_230000_add_whatsapp_template_tracking_to_crm_phone_numbers.php',
            'platform-crm/database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php',
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
            $path = str_starts_with($relative, 'platforms-recruiting/')
                ? $ownModule . '/' . substr($relative, strlen('platforms-recruiting/'))
                : $modules . '/' . $relative;

            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function findModulesRoot(): string
    {
        $dir = __DIR__;

        for ($i = 0; $i < 10; $i++) {
            $dir = dirname($dir);

            if (is_dir($dir . '/platform-crm') && is_dir($dir . '/platforms-recruiting')) {
                return $dir;
            }

            if ($dir === dirname($dir)) {
                break;
            }
        }

        throw new \RuntimeException('Modules-Root nicht gefunden ab ' . __DIR__);
    }
}
