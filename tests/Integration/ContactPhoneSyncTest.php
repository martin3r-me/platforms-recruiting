<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;

/**
 * Akte -> Kontakt-Nummern-Abgleich (Vorfall RG19734, 04.09.): weicht der
 * Kontakt von der Akten-Nummer ab, wird die alte Nummer deaktiviert (nicht
 * geloescht) und die Akten-Nummer als neue primaere Mobilnummer angelegt.
 * Passende Kontakte bleiben unangetastet, Dry-Run schreibt nichts.
 */
class ContactPhoneSyncTest extends TestCase
{
    private const TEAM = 801;

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
        $container->instance('config', new ConfigRepository([]));

        // Prod registriert den Morph-Alias (Kurzform in phoneable_type) — der
        // Save einer Nummer touch't den Kontakt und braucht die Aufloesung.
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(['crm_contact' => \Platform\Crm\Models\CrmContact::class]);

        self::runMigrations();

        Capsule::table('crm_phone_types')->insert([
            'uuid' => 'pt-mobile', 'name' => 'Mobil', 'code' => 'MOBILE', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_employees', 'crm_contacts', 'crm_contact_links', 'crm_phone_numbers'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    /** @return array{employee: RecEmployee, contact_id: int} */
    private function fixture(string $aktenNummer, ?string $kontaktNummer): array
    {
        $employee = RecEmployee::create([
            'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster',
            'personnel_number' => 'RG1', 'phone' => $aktenNummer, 'portal_token' => 'tok-' . mt_rand(), 'is_active' => true,
        ]);
        $contactId = (int) Capsule::table('crm_contacts')->insertGetId([
            'uuid' => 'c-' . mt_rand(), 'first_name' => 'Erika', 'last_name' => 'Muster',
            'team_id' => self::TEAM, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Capsule::table('crm_contact_links')->insert([
            'uuid' => 'l-' . mt_rand(), 'contact_id' => $contactId, 'team_id' => self::TEAM,
            'created_by_user_id' => 1, 'linkable_id' => $employee->id,
            'linkable_type' => RecEmployee::class, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($kontaktNummer !== null) {
            Capsule::table('crm_phone_numbers')->insert([
                'uuid' => 'pn-' . mt_rand(), 'phoneable_type' => 'crm_contact', 'phoneable_id' => $contactId,
                'raw_input' => $kontaktNummer, 'international' => $kontaktNummer,
                'phone_type_id' => 1, 'is_primary' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['employee' => $employee, 'contact_id' => $contactId];
    }

    public function test_mismatched_contact_number_is_replaced_and_old_one_deactivated(): void
    {
        $f = $this->fixture('+4917623996500', '+4915170323050');

        $r = (new ContactPhoneSync())->syncEmployee($f['employee']);

        $this->assertSame('synced', $r['status']);
        $rows = Capsule::table('crm_phone_numbers')->where('phoneable_id', $f['contact_id'])->get();
        $this->assertCount(2, $rows, 'Alte Nummer bleibt als deaktivierte Historie stehen.');
        $active = $rows->where('is_active', 1)->values();
        $this->assertCount(1, $active);
        $this->assertSame('+4917623996500', (string) $active[0]->international);
        $this->assertSame(1, (int) $active[0]->is_primary);
    }

    public function test_matching_contact_number_is_left_alone_even_in_other_format(): void
    {
        $f = $this->fixture('+4917623996500', '0176 23996500');

        $r = (new ContactPhoneSync())->syncEmployee($f['employee']);

        $this->assertSame('match', $r['status'], 'Formatunterschied ist kein Mismatch (Ziffern-Vergleich).');
        $this->assertSame(1, Capsule::table('crm_phone_numbers')->where('phoneable_id', $f['contact_id'])->count());
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $f = $this->fixture('+4917623996500', '+4915170323050');

        $r = (new ContactPhoneSync())->syncEmployee($f['employee'], true);

        $this->assertSame('synced', $r['status']);
        $rows = Capsule::table('crm_phone_numbers')->where('phoneable_id', $f['contact_id'])->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->is_active, 'Dry-Run deaktiviert nichts.');
    }

    public function test_contact_without_any_number_gets_the_akten_number(): void
    {
        $f = $this->fixture('+4917623996500', null);

        $r = (new ContactPhoneSync())->syncEmployee($f['employee']);

        $this->assertSame('synced', $r['status']);
        $row = Capsule::table('crm_phone_numbers')->where('phoneable_id', $f['contact_id'])->first();
        $this->assertNotNull($row);
        $this->assertSame('+4917623996500', (string) $row->international);
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(\Platform\Crm\Models\CrmContact::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$crm, 'database/migrations/2024_01_01_000011_create_crm_phone_types_table.php'],
            [$crm, 'database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php'],
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
