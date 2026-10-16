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

/**
 * Themen-Auswahl (WhatsApp) im Einstellungsfenster — Speichern an der ECHTEN
 * Komponente gegen SQLite (Harness wie SettingsModalPortalVorlagenTest).
 * Schlussreview m5: Login-URL nur https://, Texte hoechstens 1000 Zeichen
 * (Meta-Body-Grenze 1024 inkl. Abwesenheits-Vorspann); sonst null + Meldung.
 */
class SettingsModalThemenTest extends TestCase
{
    private const TEAM = 4712;

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

    public function test_gueltige_werte_werden_gespeichert_und_das_fenster_schliesst(): void
    {
        $m = $this->offen();
        $m->settings['comms_topic_enabled'] = 'false';
        $m->settings['comms_topic_quiet_hours'] = '99';
        $m->settings['comms_topic_lohn_url'] = '  https://example.test/lohn-login  ';
        $m->settings['comms_topic_text_ask'] = str_repeat('a', 1000);
        $m->settings['comms_topic_text_lohn_ok'] = '   ';
        $m->save();

        $roh = $this->roh();
        $this->assertFalse($roh['comms_topic_enabled']);
        $this->assertSame(48, $roh['comms_topic_quiet_hours']);
        $this->assertSame('https://example.test/lohn-login', $roh['comms_topic_lohn_url']);
        $this->assertSame(str_repeat('a', 1000), $roh['comms_topic_text_ask']);
        $this->assertNull($roh['comms_topic_text_lohn_ok']);
        $this->assertFalse($m->getErrorBag()->any());
        $this->assertFalse($m->modalShow);
    }

    public function test_url_ohne_https_wird_null_mit_meldung(): void
    {
        foreach (['http://example.test/lohn', 'example.test/lohn', 'javascript:alert(1)', 'https://'] as $url) {
            $m = $this->offen();
            $m->settings['comms_topic_lohn_url'] = $url;
            $m->settings['comms_topic_text_ask'] = 'Worum geht es?';
            $m->save();

            $this->assertNull($this->roh()['comms_topic_lohn_url'], $url);
            $this->assertSame('Worum geht es?', $this->roh()['comms_topic_text_ask'], $url . ': der Rest wird gespeichert.');
            $this->assertStringContainsString('https://', (string) $m->getErrorBag()->first('settings.comms_topic_lohn_url'), $url);
            $this->assertTrue($m->modalShow, $url . ': das Fenster bleibt offen, damit die Meldung sichtbar ist.');
        }
    }

    public function test_zu_langer_text_wird_null_mit_meldung(): void
    {
        foreach (['comms_topic_text_ask', 'comms_topic_text_lohn_offen', 'comms_topic_text_lohn_ok'] as $k) {
            $m = $this->offen();
            $m->settings[$k] = str_repeat('ä', 1001);
            $m->save();

            $this->assertNull($this->roh()[$k], $k);
            $this->assertStringContainsString('1000 Zeichen', (string) $m->getErrorBag()->first('settings.' . $k), $k);
            $this->assertTrue($m->modalShow, $k);
        }
    }

    public function test_erklaertext_nennt_alle_drei_themen(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $start = (int) strpos($blade, 'Themen-Auswahl für Mitarbeiter (WhatsApp)');
        $abschnitt = substr($blade, $start, 1200);
        foreach (['Disposition', 'Lohn &amp; Gehalt', 'HR allgemein'] as $thema) {
            $this->assertStringContainsString($thema, $abschnitt, $thema);
        }
        foreach (['comms_topic_text_ask', 'comms_topic_text_lohn_offen', 'comms_topic_text_lohn_ok'] as $k) {
            $this->assertStringContainsString("@error('settings." . $k . "')", $blade, $k);
        }
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
