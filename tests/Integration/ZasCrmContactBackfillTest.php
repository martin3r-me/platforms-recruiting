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
use Platform\Recruiting\Console\Commands\ZasCrmContactBackfill;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeContactLinker;

/**
 * Kill-Switch + Team-Anker des stuendlichen CRM-Abgleichs (Runde 4
 * Final-Review). Probe-Muster wie DispoEscalateCommandTest: die Auswahl-/
 * Kaskaden-Schleife ist als backfill($linker, $opts, $emit) aus handle()
 * herausgehoben (keine $this->option()/$this->line()) und wird hier ohne
 * Artisan-Lebenszyklus direkt aufgerufen; handle() bleibt ein Wrapper, der
 * nur Optionen einsammelt und den Exit-Code bildet.
 *
 * Ende zu Ende gegen ECHTE Migrationen (recruiting + crm), kein Testbench.
 */
class ZasCrmContactBackfillTest extends TestCase
{
    private const TEAM = 601;
    private const OTHER_TEAM = 602;

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

        // Log-Attrappe (kein echtes Laravel-Bootstrap): der Fail-closed-Zweig
        // ruft Log::warning() — ohne Bindung sonst ReflectionException
        // "Class log does not exist" (Facade-Cache-Pitfall).
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });
        Facade::clearResolvedInstance('log');

        self::runMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        // Attrappe wieder aus dem GETEILTEN Container nehmen — sonst bekommt jede
        // spaetere Testklasse des Prozesses still einen No-op-Logger.
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['crm_contact_links', 'crm_email_addresses', 'crm_phone_numbers', 'crm_contacts', 'rec_applicant_settings', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    // ---- Fixtures -------------------------------------------------------

    private function employee(string $pnr, int $teamId = self::TEAM): RecEmployee
    {
        return RecEmployee::create([
            'team_id' => $teamId, 'first_name' => 'Neu', 'last_name' => 'Mensch',
            'email' => null, 'phone' => null, 'personnel_number' => $pnr,
            'portal_token' => 'tok-' . $pnr, 'is_active' => true,
        ]);
    }

    /** @return array{0: array<string,mixed>, 1: list<string>} counts + gesammelte Ausgabe */
    private function backfill(array $opts): array
    {
        $lines = [];
        $emit = function (string $level, string $text = '') use (&$lines): void {
            $lines[] = $level . '|' . $text;
        };

        $counts = (new ZasCrmContactBackfill())->backfill(new ZasEmployeeContactLinker(), $opts, $emit);

        return [$counts, $lines];
    }

    private function switchOff(int $teamId): void
    {
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $settings->setSetting('dispo_contact_backfill_enabled', false);
        $settings->save();
    }

    // ---- Tests ----------------------------------------------------------

    public function test_team_option_limits_the_selection(): void
    {
        $this->employee('RG-T1', self::TEAM);
        $this->employee('RG-T2', self::OTHER_TEAM);

        [$counts, ] = $this->backfill(['dry-run' => true, 'team' => self::TEAM]);

        $this->assertTrue($counts['ran']);
        $this->assertSame(1, $counts['total'], 'Nur MA des angefragten Teams.');
        $this->assertSame(1, $counts['create']);

        // Gegenprobe ohne --team: beide MA sind in der Auswahl.
        [$all, ] = $this->backfill(['dry-run' => true]);
        $this->assertSame(2, $all['total']);
    }

    public function test_scheduled_run_uses_the_anchor_team_and_runs_when_the_setting_is_missing(): void
    {
        $this->employee('RG-S1', self::TEAM);
        $this->employee('RG-S2', self::OTHER_TEAM);

        // Keine Einstellung gesetzt -> Default AN.
        [$counts, ] = $this->backfill(['dry-run' => true, 'scheduled' => true]);

        $this->assertTrue($counts['ran']);
        $this->assertSame(1, $counts['total'], 'Scheduler ankert auf recruiting.zas.inbound_team_id.');
    }

    public function test_scheduled_run_is_a_noop_when_the_switch_is_off(): void
    {
        $this->employee('RG-S3', self::TEAM);
        $this->switchOff(self::TEAM);

        [$counts, $lines] = $this->backfill(['dry-run' => true, 'scheduled' => true]);

        $this->assertFalse($counts['ran'], 'Abgeschaltet: keine Query, keine Entscheidung.');
        $this->assertSame(0, $counts['total']);
        $this->assertSame(['line|Automatischer Abgleich deaktiviert (Disposition → Einstellungen)'], $lines);

        // Der Aufruf von Hand bleibt unbeeinflusst.
        [$manual, ] = $this->backfill(['dry-run' => true]);
        $this->assertTrue($manual['ran']);
        $this->assertSame(1, $manual['total']);
    }

    public function test_scheduled_run_is_a_noop_without_anchor_team(): void
    {
        $this->employee('RG-S4', self::TEAM);

        $config = Container::getInstance()->make('config');
        $config->set('recruiting.zas.inbound_team_id', null);

        try {
            [$counts, $lines] = $this->backfill(['dry-run' => true, 'scheduled' => true]);
        } finally {
            $config->set('recruiting.zas.inbound_team_id', self::TEAM);
        }

        $this->assertFalse($counts['ran'], 'Ohne Anker-Team fail-closed statt Lauf ueber alle Mandanten.');
        $this->assertSame(0, $counts['total']);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Kein ZAS-Anker-Team konfiguriert', $lines[0]);
    }

    // ---- Schema ---------------------------------------------------------

    private static function runMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CrmContact::class);

        $files = [
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            [$own, 'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
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
