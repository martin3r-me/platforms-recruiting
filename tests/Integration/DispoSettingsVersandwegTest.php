<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Settings;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVersandweg;

/**
 * Schalter + Freigeber (Spec 2026-11-13, 6.1/6.3) GEGEN DIE KOMPONENTE: save() laeuft echt,
 * nur der angemeldete Nutzer wird ueber nutzer() gesetzt. Plus die Zaehlung ohneNeuesPortal.
 */
class DispoSettingsVersandwegTest extends TestCase
{
    private const TEAM = 7;

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
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]]]));

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php',
            'database/migrations/2026_02_09_000001_create_rec_positions_table.php',
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php',
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
        ] as $rel) {
            (require $own . '/' . $rel)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['rec_applicant_settings', 'rec_employee_hr_data', 'rec_employees'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    private static function komponente(?string $email, array $props = []): Settings
    {
        $c = new class extends Settings {
            public mixed $testNutzer = null;

            protected function nutzer(): mixed
            {
                return $this->testNutzer;
            }

            /** Die Validierung braucht die Livewire-Laufzeit und ist nicht Gegenstand dieses Tests. */
            public function validate($rules = null, $messages = [], $attributes = [], $dataOverrides = [])
            {
                return [];
            }

            public function __get($name)
            {
                return method_exists($this, $name) ? $this->{$name}() : parent::__get($name);
            }

            public function __unset($name)
            {
            }
        };
        $c->testNutzer = $email === null ? null : (object) ['email' => $email];
        $c->escalationTime1 = '14:00';
        $c->escalationTime2 = '15:00';
        $c->escalationTime3 = '16:00';
        foreach ($props as $k => $v) {
            $c->{$k} = $v;
        }

        return $c;
    }

    private static function einstellungen(array $werte = []): RecApplicantSettings
    {
        $s = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        foreach ($werte as $k => $v) {
            $s->setSetting($k, $v);
        }
        $s->save();

        return $s;
    }

    private static function frisch(): RecApplicantSettings
    {
        return RecApplicantSettings::where('team_id', self::TEAM)->first();
    }

    public function test_ohne_freigeber_darf_jeder_aendern(): void
    {
        self::einstellungen();
        $c = self::komponente('anna@rg.de', ['versandwegAktiv' => '1', 'freigeberEmail' => ' Markus@RG.de ', 'escalationTime1' => '13:30']);
        $this->assertFalse($c->versandwegGesperrt());
        $c->save();

        $s = self::frisch();
        $this->assertTrue(DispoJobVersandweg::aktiv($s));
        $this->assertSame('markus@rg.de', DispoJobVersandweg::freigeberEmail($s));
        $this->assertSame('13:30', $s->getSetting('dispo_escalation_time_1'));
    }

    public function test_fremder_aendert_schalter_und_freigeber_nicht_den_rest_schon(): void
    {
        self::einstellungen([DispoJobVersandweg::SETTING_AKTIV => true, DispoJobVersandweg::SETTING_FREIGEBER => 'markus@rg.de']);
        $c = self::komponente('anna@rg.de', ['versandwegAktiv' => '', 'freigeberEmail' => 'anna@rg.de', 'escalationTime1' => '13:30']);
        $this->assertTrue($c->versandwegGesperrt());
        $c->save();

        $s = self::frisch();
        $this->assertTrue(DispoJobVersandweg::aktiv($s), 'Schalter bleibt an');
        $this->assertSame('markus@rg.de', DispoJobVersandweg::freigeberEmail($s), 'Freigeber bleibt');
        $this->assertSame('13:30', $s->getSetting('dispo_escalation_time_1'), 'uebrige Einstellungen werden gespeichert');
        $this->assertSame('1', $c->versandwegAktiv);
        $this->assertSame('markus@rg.de', $c->freigeberEmail, 'Ansicht zeigt wieder den gespeicherten Stand');
    }

    public function test_freigeber_aendert_beides(): void
    {
        self::einstellungen([DispoJobVersandweg::SETTING_AKTIV => true, DispoJobVersandweg::SETTING_FREIGEBER => 'markus@rg.de']);
        $c = self::komponente(' MARKUS@rg.de ', ['versandwegAktiv' => '', 'freigeberEmail' => 'clara@rg.de']);
        $this->assertFalse($c->versandwegGesperrt());
        $c->save();

        $s = self::frisch();
        $this->assertFalse(DispoJobVersandweg::aktiv($s));
        $this->assertSame('clara@rg.de', DispoJobVersandweg::freigeberEmail($s));
    }

    public function test_freigeber_leeren_gibt_die_einstellung_wieder_frei(): void
    {
        self::einstellungen([DispoJobVersandweg::SETTING_FREIGEBER => 'markus@rg.de']);
        self::komponente('markus@rg.de', ['freigeberEmail' => '  '])->save();

        $s = self::frisch();
        $this->assertNull(DispoJobVersandweg::freigeberEmail($s));
        $this->assertTrue(DispoJobVersandweg::darfEinstellungenAendern(DispoJobVersandweg::freigeberEmail($s), (object) ['email' => 'jeder@rg.de']));
    }

    private function mitarbeiter(array $o = [], ?string $status = null): int
    {
        $id = (int) Capsule::table('rec_employees')->insertGetId($o + [
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => self::TEAM, 'first_name' => 'E', 'last_name' => 'M',
            'is_active' => true, 'portal_token' => 't' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($status !== null) {
            Capsule::table('rec_employee_hr_data')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $o['team_id'] ?? self::TEAM,
                'rec_employee_id' => $id, 'export_status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_ohne_neues_portal_zaehlt_nur_aktiv_go_ma_und_leeres_portal_v2_since(): void
    {
        $this->mitarbeiter([], 'GO');                                                    // zaehlt
        $this->mitarbeiter([], 'MA');                                                    // zaehlt
        $this->mitarbeiter([]);                                                          // ohne HR-Datensatz = GO, zaehlt
        $this->mitarbeiter(['portal_v2_since' => '2026-10-01 00:00:00'], 'GO');          // umgestellt
        $this->mitarbeiter(['is_active' => false], 'GO');                                // inaktiv
        $this->mitarbeiter([], 'INAKTIV');                                               // anderer Status
        $this->mitarbeiter(['team_id' => 99], 'GO');                                     // anderes Team

        $this->assertSame(3, DispoJobVersandweg::ohneNeuesPortal(self::TEAM));
    }
}
