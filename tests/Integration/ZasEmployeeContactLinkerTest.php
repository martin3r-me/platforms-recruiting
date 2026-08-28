<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasContactLinkReport;
use Platform\Recruiting\Services\Zas\ZasEmployeeContactLinker;

/**
 * Linker-Kaskade (Runde 4, #0): mehrdeutige E-Mail wird mit Telefon geschnitten
 * bzw. ueber einen bereits verlinkten Namensvetter eingeengt, statt sofort
 * "mehrdeutig" abzubrechen. Ende zu Ende gegen ECHTE Migrationen (recruiting +
 * crm), kein Testbench — Muster DispoIdentityResolverTest.
 */
class ZasEmployeeContactLinkerTest extends TestCase
{
    private const TEAM = 501;

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

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['crm_contact_links', 'crm_email_addresses', 'crm_phone_numbers', 'crm_contacts', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    // ---- Fixtures -------------------------------------------------------

    private function employee(string $first, string $last, ?string $email, ?string $phone, string $pnr = 'RG1'): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => $first, 'last_name' => $last,
            'email' => $email, 'phone' => $phone, 'personnel_number' => $pnr,
            'portal_token' => 'tok-' . $pnr . '-' . mt_rand(), 'is_active' => true,
        ]);
    }

    private function contact(string $first, string $last, ?string $email = null, ?string $phoneE164 = null, bool $active = true): int
    {
        $id = (int) Capsule::table('crm_contacts')->insertGetId([
            'uuid' => 'c-' . mt_rand(), 'first_name' => $first, 'last_name' => $last,
            'team_id' => self::TEAM, 'is_active' => $active, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($email !== null) {
            Capsule::table('crm_email_addresses')->insert([
                'uuid' => 'e-' . mt_rand(), 'emailable_id' => $id, 'emailable_type' => (new CrmContact())->getMorphClass(),
                'email_address' => $email, 'email_type_id' => 1, 'is_primary' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($phoneE164 !== null) {
            Capsule::table('crm_phone_numbers')->insert([
                'uuid' => 'p-' . mt_rand(), 'phoneable_id' => $id, 'phoneable_type' => (new CrmContact())->getMorphClass(),
                'raw_input' => $phoneE164, 'international' => $phoneE164,
                'national' => $phoneE164, 'country_code' => 'DE', 'phone_type_id' => 1,
                'is_primary' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $id;
    }

    private function link(int $employeeId, int $contactId): void
    {
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'lnk-' . $employeeId . '-' . $contactId, 'contact_id' => $contactId, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => $employeeId,
            'linkable_type' => (new RecEmployee())->getMorphClass(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- Tests ----------------------------------------------------------

    public function test_unique_email_links_as_before(): void
    {
        $c = $this->contact('Markus', 'Ammerer', 'm@example.de');
        $d = (new ZasEmployeeContactLinker())->decide($this->employee('Markus', 'Ammerer', 'M@Example.de', null));

        $this->assertSame('link', $d['action']);
        $this->assertSame($c, $d['contact_id']);
        $this->assertSame('email', $d['matched_by']);
    }

    public function test_ambiguous_email_is_narrowed_by_phone(): void
    {
        $right = $this->contact('Markus', 'Ammerer', 'm@example.de', '+491721111111');
        $this->contact('Markus', 'Ammerer', 'm@example.de'); // Duplikat ohne Nummer

        $d = (new ZasEmployeeContactLinker())->decide($this->employee('Markus', 'Ammerer', 'm@example.de', '0172 1111111'));

        $this->assertSame('link', $d['action']);
        $this->assertSame($right, $d['contact_id']);
        $this->assertSame('email+phone', $d['matched_by']);
    }

    public function test_ambiguous_email_and_phone_prefers_contact_linked_to_active_namesake(): void
    {
        $rg = $this->employee('Markus', 'Ammerer', 'm@example.de', '0172 1111111', 'RG1187');
        $right = $this->contact('Markus', 'Ammerer', 'm@example.de', '+491721111111');
        $this->contact('Markus', 'Ammerer', 'm@example.de', '+491721111111'); // gleiche Daten, nirgends verlinkt
        $this->link($rg->id, $right);

        $ma = $this->employee('Markus', 'Ammerer', 'm@example.de', '0172 1111111', 'MA1000009898');
        $d = (new ZasEmployeeContactLinker())->decide($ma);

        $this->assertSame('link', $d['action']);
        $this->assertSame($right, $d['contact_id']);
        $this->assertSame('email+namesake', $d['matched_by']);
    }

    public function test_ambiguous_phone_without_email_is_narrowed_by_linked_namesake(): void
    {
        $rg = $this->employee('Markus', 'Ammerer', null, '0172 1111111', 'RG1187');
        $right = $this->contact('Markus', 'Ammerer', null, '+491721111111');
        $this->contact('Markus', 'Ammerer', null, '+491721111111'); // gleiche Daten, nirgends verlinkt
        $this->link($rg->id, $right);

        $ma = $this->employee('Markus', 'Ammerer', null, '0172 1111111', 'MA1000009898');
        $d = (new ZasEmployeeContactLinker())->decide($ma);

        $this->assertSame('link', $d['action']);
        $this->assertSame($right, $d['contact_id']);
        $this->assertSame('phone+namesake', $d['matched_by']);
    }

    public function test_both_ambiguous_without_namesake_still_skips(): void
    {
        $this->contact('Markus', 'Ammerer', 'm@example.de', '+491721111111');
        $this->contact('Markus', 'Ammerer', 'm@example.de', '+491721111111');

        $d = (new ZasEmployeeContactLinker())->decide($this->employee('Markus', 'Ammerer', 'm@example.de', '0172 1111111'));

        $this->assertSame('skip', $d['action']);
        $this->assertStringStartsWith('mehrdeutig:', $d['reason']);
    }

    public function test_name_guard_still_blocks_unique_match(): void
    {
        $this->contact('Petra', 'Sammelpostfach', 'm@example.de');

        $d = (new ZasEmployeeContactLinker())->decide($this->employee('Markus', 'Ammerer', 'm@example.de', null));

        $this->assertSame('skip', $d['action']);
        $this->assertStringStartsWith('Name passt nicht:', $d['reason']);
    }

    public function test_no_match_creates(): void
    {
        $d = (new ZasEmployeeContactLinker())->decide($this->employee('Neu', 'Mensch', 'neu@example.de', '0172 2222222'));

        $this->assertSame('create', $d['action']);
        $this->assertSame('neu@example.de', $d['email']);
    }

    public function test_report_lists_open_cases_with_state_and_reason(): void
    {
        // pending (link): eindeutige E-Mail
        $this->contact('Anna', 'Link', 'anna@example.de');
        $this->employee('Anna', 'Link', 'anna@example.de', null, 'RG10');
        // skip: Name passt nicht
        $this->contact('Petra', 'Sammelpostfach', 'shared@example.de');
        $this->employee('Bert', 'Skip', 'shared@example.de', null, 'RG11');
        // bereits verlinkt -> taucht NICHT auf
        $done = $this->employee('Carl', 'Done', null, null, 'RG12');
        $this->link($done->id, $this->contact('Carl', 'Done'));

        $report = (new ZasContactLinkReport(new ZasEmployeeContactLinker()))->openCases(self::TEAM);

        $this->assertSame(2, $report['total']);
        $byPnr = collect($report['rows'])->keyBy('personnel_number');
        $this->assertSame('pending', $byPnr['RG10']['state']);
        $this->assertStringContainsString('verknüpft', $byPnr['RG10']['reason']);
        $this->assertSame('skip', $byPnr['RG11']['state']);
        $this->assertStringStartsWith('Name passt nicht:', $byPnr['RG11']['reason']);
        $this->assertArrayNotHasKey('RG12', $byPnr->all());
    }

    // ---- Schema ---------------------------------------------------------

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContact::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$crm, 'database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php'],
            [$crm, 'database/migrations/2024_01_01_000015_create_crm_email_addresses_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        $dir = dirname((string) $file);
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }
        return $dir;
    }
}
