<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Livewire\Applicant\ApplicantSettingsModal;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVersandweg;

/**
 * Jobs anbieten (Gesamtpruefung C1, 13.11.2026): Schalter und Freigeber sind in
 * Dispo → Einstellungen gesperrt — das Einstellungsfenster der Bewerberliste
 * schreibt aber das ganze settings-JSON zurueck. Beide Schluessel kommen beim
 * Speichern IMMER aus dem gespeicherten Stand: kein gefaelschtes
 * settings[...] aendert sie, ein veraltetes Fenster loescht sie nicht.
 * Harness wie SettingsModalThemenTest.
 */
class SettingsModalVersandwegSperreTest extends TestCase
{
    private const TEAM = 4713;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        $container->instance('auth', self::authStub());
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });
        // addError() holt die geteilten Fehler ueber app('view'), der Beutel liegt
        // in Livewires DataStore (Muster DispoDressSendFormTest).
        $container->instance('view', new class {
            public function getShared(): array
            {
                return [];
            }
        });
        $container->singleton(\Livewire\Mechanisms\DataStore::class);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance('log');
        $c->forgetInstance('view');
        $c->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_applicant_settings')->delete();
        Capsule::table('rec_applicant_settings')->insert(['team_id' => self::TEAM, 'settings' => json_encode([])]);
    }

    public function test_gefaelschte_werte_aendern_schalter_und_freigeber_nicht(): void
    {
        $this->speichereRoh([DispoJobVersandweg::SETTING_AKTIV => true, DispoJobVersandweg::SETTING_FREIGEBER => 'markus@rg.de']);

        $m = $this->offen();
        $m->settings[DispoJobVersandweg::SETTING_AKTIV] = false;
        $m->settings[DispoJobVersandweg::SETTING_FREIGEBER] = 'anna@rg.de';
        $m->settings['comms_topic_text_ask'] = 'Worum geht es?';
        $m->save();

        $roh = $this->roh();
        $this->assertTrue($roh[DispoJobVersandweg::SETTING_AKTIV], 'Schalter bleibt an');
        $this->assertSame('markus@rg.de', $roh[DispoJobVersandweg::SETTING_FREIGEBER], 'Freigeber bleibt');
        $this->assertSame('Worum geht es?', $roh['comms_topic_text_ask'], 'andere Schluessel werden weiter gespeichert');
    }

    public function test_fehlen_beide_schluessel_legt_ein_gefaelschtes_settings_sie_nicht_an(): void
    {
        $m = $this->offen();
        $m->settings[DispoJobVersandweg::SETTING_AKTIV] = true;
        $m->settings[DispoJobVersandweg::SETTING_FREIGEBER] = 'anna@rg.de';
        $m->save();

        $roh = $this->roh();
        $this->assertArrayNotHasKey(DispoJobVersandweg::SETTING_AKTIV, $roh);
        $this->assertArrayNotHasKey(DispoJobVersandweg::SETTING_FREIGEBER, $roh);
    }

    public function test_veraltetes_fenster_loescht_die_schluessel_nicht(): void
    {
        // Fenster geoeffnet, BEVOR der Freigeber eingetragen wurde ...
        $m = $this->offen();
        // ... dann in Dispo → Einstellungen eingetragen ...
        $this->speichereRoh([DispoJobVersandweg::SETTING_AKTIV => true, DispoJobVersandweg::SETTING_FREIGEBER => 'markus@rg.de']);
        // ... und das alte Fenster gespeichert.
        $m->settings['comms_topic_text_ask'] = 'Neu';
        $m->save();

        $roh = $this->roh();
        $this->assertTrue($roh[DispoJobVersandweg::SETTING_AKTIV]);
        $this->assertSame('markus@rg.de', $roh[DispoJobVersandweg::SETTING_FREIGEBER]);
        $this->assertSame('Neu', $roh['comms_topic_text_ask']);
    }

    private function speichereRoh(array $werte): void
    {
        Capsule::table('rec_applicant_settings')->where('team_id', self::TEAM)
            ->update(['settings' => json_encode(array_merge($this->roh(), $werte))]);
    }

    private function offen(): ApplicantSettingsModal
    {
        $m = new ApplicantSettingsModal();
        $m->openSettings();
        $m->modalShow = true;

        return $m;
    }

    private function roh(): array
    {
        return (array) json_decode((string) Capsule::table('rec_applicant_settings')->where('team_id', self::TEAM)->value('settings'), true);
    }

    private static function authStub(): object
    {
        $benutzerListe = new class {
            public function orderBy(string $spalte): self
            {
                return $this;
            }

            public function get(): \Illuminate\Support\Collection
            {
                return new \Illuminate\Support\Collection();
            }
        };

        $team = new class (self::TEAM, $benutzerListe) {
            public function __construct(public int $id, private object $benutzerListe)
            {
            }

            public function users(): object
            {
                return $this->benutzerListe;
            }
        };

        $benutzer = new class ($team) {
            public function __construct(public object $currentTeam)
            {
            }
        };

        return new class ($benutzer) {
            public function __construct(private object $benutzer)
            {
            }

            public function user(): object
            {
                return $this->benutzer;
            }

            public function id(): ?int
            {
                return 42;
            }
        };
    }

    private static function runRealMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_02_12_000002_create_rec_service_hours_table.php'],
            [$own, 'database/migrations/2026_04_29_000001_create_rec_source_platforms_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$own, 'database/migrations/2026_06_12_000001_create_rec_intake_channels_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();
        if ($file === false) {
            throw new \RuntimeException("Keine Datei fuer {$class}");
        }

        $dir = dirname($file);
        while ($dir !== '/' && $dir !== '' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        if (!file_exists($dir . '/composer.json')) {
            throw new \RuntimeException("Paketwurzel von {$class} nicht gefunden");
        }

        return $dir;
    }
}
