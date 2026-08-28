<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContactLink;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * DispoIdentityResolver ist die EINZIGE Tuer vom Dispo-Code zu
 * crm_contact_links (Spec 2026-08-28): mehrere aktive Mitarbeiter-
 * Datensaetze desselben Teams am selben CRM-Kontakt sind EINE Person
 * (z. B. Personalnummern RG… und MA… derselben Person). Ende zu Ende
 * gegen ECHTE Migrationen (recruiting + crm), kein Testbench — Muster
 * DispoEscalateCommandTest.
 */
class DispoIdentityResolverTest extends TestCase
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
        Capsule::table('crm_contact_links')->delete();
        Capsule::table('rec_employees')->delete();
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

    private function employee(string $pnr, bool $active = true, int $team = self::TEAM): int
    {
        return (int) RecEmployee::create([
            'team_id' => $team, 'first_name' => 'Markus', 'last_name' => 'Ammerer',
            'personnel_number' => $pnr, 'phone' => '+49 172 1', 'portal_token' => 'tok-' . $pnr, 'is_active' => $active,
        ])->id;
    }

    public function test_two_active_records_on_same_contact_are_one_group(): void
    {
        $rg = $this->employee('RG1'); $ma = $this->employee('MA1');
        $this->link($rg, 900); $this->link($ma, 900);

        $groups = (new DispoIdentityResolver())->groupsFor([$rg]);

        $this->assertSame([$rg, $ma], $groups[$rg]);
        $this->assertSame([$rg, $ma], (new DispoIdentityResolver())->groupFor($ma));
    }

    public function test_unlinked_or_inactive_or_foreign_team_stay_single(): void
    {
        $solo = $this->employee('RG2');
        $rg = $this->employee('RG3'); $maInactive = $this->employee('MA3', false); $maOtherTeam = $this->employee('MA33', true, 999);
        $this->link($rg, 901); $this->link($maInactive, 901); $this->link($maOtherTeam, 901);

        $r = new DispoIdentityResolver();
        $this->assertSame([$solo], $r->groupFor($solo));
        $this->assertSame([$rg], $r->groupFor($rg));
        $this->assertSame([$maInactive], $r->groupFor($maInactive), 'Inaktiver angefragter MA -> nur er selbst.');
    }

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContactLink::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
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

    /** Wurzel des Composer-Pakets einer geladenen Klasse (Modulmuster). */
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
