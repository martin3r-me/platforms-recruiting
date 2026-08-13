<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Livewire\Applicant\ApplicantSettingsModal;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\IssueTrainingCertificateService;

/**
 * Kommt der Team-Schalter beim Speichern WIRKLICH in der settings-Spalte an?
 *
 * Gemessen wird an der ECHTEN Komponente: `new ApplicantSettingsModal()` und
 * ihre eigenen Methoden `openSettings()` und `save()`, gegen eine echte
 * Settings-Zeile auf SQLite. Keine Reproduktion der beiden Modal-Zeilen — die
 * gab es in Task 13 schon, und sie kann eine Aenderung an `save()` nicht
 * bemerken.
 *
 * WAS DIESER TEST NICHT MESSEN KANN, und der Grund steht im Report: die
 * Livewire-LAUFZEIT. `Livewire::test(...)` braucht die Host-App (gemessen:
 * "A facade root has not been set."), es gibt kein testbench in diesem Modul.
 * Der Schritt vom `wire:model` der Checkbox auf die Property
 * `$settings['issue_training_certificates']` ist damit Framework-Verhalten, das
 * hier nicht laeuft; dieser Test setzt die Property so, wie Livewire sie
 * setzen wuerde, und nagelt alles davor und danach fest. Dass die Checkbox
 * ueberhaupt auf diesen Property-Pfad zeigt, prueft
 * SettingsModalCertificateToggleTest an der gerenderten Ausgabe.
 *
 * Warum das trotzdem die teure Haelfte ist: der Schalter ist die einzige Bremse
 * des Features. Schreibt er nicht, bleibt das Feature fuer immer aus — und der
 * Default `false` verdeckt genau das.
 *
 * PROZESSWEITER ZUSTAND: diese Klasse setzt die Facade-App und bootet
 * Eloquent-Modelle (mit Dispatcher, damit die uuid-Hooks der Nebentabellen
 * feuern). Sie raeumt in tearDownAfterClass die aufgeloesten Facade-Instanzen
 * wieder ab — sonst zeigt ein 'db.schema' aus DIESER Capsule in einer spaeter
 * laufenden Testklasse auf die falsche in-memory-Datenbank (der Fall ist im
 * Docblock von DuplicateMatchQueryTest gemessen beschrieben).
 */
class SettingsModalToggleWriteTest extends TestCase
{
    private const TEAM = 4711;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher feuern die creating-Hooks nicht — rec_service_hours,
        // rec_source_platforms und rec_intake_channels haben uuid NOT NULL.
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        $container->instance('auth', self::authStub());
        Facade::setFacadeApplication($container);

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_applicant_settings')->delete();
    }

    /**
     * DER Fall, um den es geht: eine BESTEHENDE Zeile ohne den Schluessel.
     * Dort traegt allein DEFAULT_SETTINGS, und `getSetting()` liest
     * `$settings[$key] ?? $default ?? DEFAULT_SETTINGS[$key]`. Nach einem
     * Speichern mit Haken muss der Schluessel ROH in der Spalte stehen —
     * sonst haengt der Live-Zustand weiter am Default, und ein spaeterer
     * Default-Wechsel dreht ihn still um.
     */
    public function testHakenSetzenSchreibtDenSchluesselRohInDieSpalte(): void
    {
        $this->bestehendeZeileOhneDenSchluessel();

        $vorher = $this->rohesJson();
        $this->assertStringNotContainsString(
            IssueTrainingCertificateService::SETTING_ENABLED,
            $vorher,
            'Vorbedingung verletzt: die Zeile kennt den Schluessel schon'
        );

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();

        $this->assertFalse(
            $modal->settings[IssueTrainingCertificateService::SETTING_ENABLED],
            'Das Formular zeigt fuer eine Zeile ohne den Schluessel nicht AUS an'
        );

        // Das macht im Betrieb Livewire, wenn der Haken gesetzt wird.
        $modal->settings[IssueTrainingCertificateService::SETTING_ENABLED] = true;
        $modal->save();

        $nachher = $this->rohesJson();
        $this->assertStringContainsString(
            '"' . IssueTrainingCertificateService::SETTING_ENABLED . '":true',
            $nachher,
            "Der Schluessel steht nicht mit true in der Spalte:\n{$nachher}"
        );

        $this->assertFalse($modal->modalShow, 'Das Modal bleibt nach dem Speichern offen');
    }

    /**
     * Der Weg, an dem alles haengt, zu Ende gemessen: nach dem Speichern sagt
     * das GATE DES SERVICES ja — dieselbe Methode, die die HR-Absage und die
     * Mitarbeiter-Anlage fragen. Ohne diese Assertion belegt der Test nur, dass
     * irgendein JSON-Schnipsel in der Spalte steht.
     */
    public function testNachDemSpeichernSagtDasGateDesServicesJa(): void
    {
        $this->bestehendeZeileOhneDenSchluessel();

        $service = new IssueTrainingCertificateService();
        $this->assertFalse($service->isEnabledForTeam(self::TEAM), 'Vorbedingung verletzt: das Gate steht schon offen');

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        $modal->settings[IssueTrainingCertificateService::SETTING_ENABLED] = true;
        $modal->save();

        $this->assertTrue(
            $service->isEnabledForTeam(self::TEAM),
            'Das Gate des Services sieht den gespeicherten Haken nicht'
        );
    }

    /**
     * Die Gegenrichtung, und sie ist der Abschaltweg aus §C3: Haken weg,
     * Speichern, Gate zu. Ohne diesen Fall koennte der Schalter ein
     * Einbahn-Schalter sein.
     */
    public function testHakenWiederEntfernenSchliesstDasGate(): void
    {
        Capsule::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode([IssueTrainingCertificateService::SETTING_ENABLED => true]),
        ]);

        $service = new IssueTrainingCertificateService();
        $this->assertTrue($service->isEnabledForTeam(self::TEAM), 'Vorbedingung verletzt: das Gate ist zu');

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        $this->assertTrue(
            $modal->settings[IssueTrainingCertificateService::SETTING_ENABLED],
            'Das Formular zeigt den gespeicherten Haken nicht an'
        );

        $modal->settings[IssueTrainingCertificateService::SETTING_ENABLED] = false;
        $modal->save();

        $this->assertStringContainsString(
            '"' . IssueTrainingCertificateService::SETTING_ENABLED . '":false',
            $this->rohesJson(),
            'Der ausgeschaltete Schalter steht nicht als false in der Spalte'
        );
        $this->assertFalse($service->isEnabledForTeam(self::TEAM), 'Das Gate bleibt offen, obwohl der Haken weg ist');
    }

    /**
     * Ein Speichern OHNE Haken schreibt den Schluessel trotzdem — als false.
     * Grund ist `array_merge(DEFAULT_SETTINGS, …)` in `openSettings()`: die
     * Komponente schreibt immer das ganze Array zurueck. Damit haengt ein Team,
     * das die Einstellungen einmal gespeichert hat, nicht mehr am Default.
     */
    public function testSpeichernOhneHakenSchreibtDenSchluesselAlsFalse(): void
    {
        $this->bestehendeZeileOhneDenSchluessel();

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        $modal->save();

        $this->assertStringContainsString(
            '"' . IssueTrainingCertificateService::SETTING_ENABLED . '":false',
            $this->rohesJson(),
            'Der Schluessel fehlt nach einem Speichern ohne Haken — dann traegt weiter allein der Default'
        );
    }

    /** Und beim naechsten Oeffnen ist der Haken da, wo der Bediener ihn gelassen hat. */
    public function testDerHakenKommtBeimNaechstenOeffnenZurueck(): void
    {
        $this->bestehendeZeileOhneDenSchluessel();

        $erstes = new ApplicantSettingsModal();
        $erstes->openSettings();
        $erstes->settings[IssueTrainingCertificateService::SETTING_ENABLED] = true;
        $erstes->save();

        $zweites = new ApplicantSettingsModal();
        $zweites->openSettings();

        $this->assertTrue(
            $zweites->settings[IssueTrainingCertificateService::SETTING_ENABLED],
            'Beim zweiten Oeffnen steht der Haken nicht mehr'
        );
    }

    /**
     * Eine bestehende Zeile OHNE den Schluessel — der Zustand jedes Teams, das
     * die Einstellungen vor diesem Feature einmal gespeichert hat.
     */
    private function bestehendeZeileOhneDenSchluessel(): void
    {
        Capsule::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode([
                'auto_pilot_enabled'  => true,
                'use_informal_address' => true,
            ]),
        ]);
    }

    /** Der Spaltenwert, ungefiltert und ohne Model/Cast dazwischen. */
    private function rohesJson(): string
    {
        $wert = Capsule::table('rec_applicant_settings')
            ->where('team_id', self::TEAM)
            ->value('settings');

        return (string) $wert;
    }

    /**
     * Auth-Stub: `openSettings()` liest `Auth::user()->currentTeam->id` und die
     * Team-Benutzer. Beides ist nicht der Gegenstand dieses Tests — die
     * Benutzerliste bleibt leer.
     */
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
        };
    }

    /**
     * Schema aus den ECHTEN Migrationen: ein Spaltenrename in Produktion
     * schlaegt hier auf, statt still gruen durchzulaufen. Gebraucht werden die
     * Tabellen, die `openSettings()` anfasst — die Settings-Zeile selbst plus
     * die drei Nebenlisten (Service-Zeiten, Quellen, Eingangskanaele).
     */
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

    /**
     * Wurzel des Composer-Pakets einer geladenen Klasse: von ihrer Datei aus
     * nach oben, bis eine composer.json liegt. Damit findet der Test das
     * Nachbarmodul unabhaengig davon, ob es als vendor-Paket oder im
     * Geschwister-Layout liegt.
     */
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
