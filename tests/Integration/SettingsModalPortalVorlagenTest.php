<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Livewire\Applicant\ApplicantSettingsModal;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\PortalVorlagen;

/**
 * Sektion „Mitarbeiterportal" im Einstellungsfenster (Reiter Kommunikation):
 * acht WhatsApp-Vorlagen plus der Schalter Einsatz-Pruefung (die neunte,
 * „Offene Aufgaben — Erinnerung", ist entfallen, Markus 12.10.2026).
 *
 * Gemessen an der ECHTEN Komponente (openSettings/save gegen SQLite, Muster
 * SettingsModalToggleWriteTest) und am ECHTEN Kompilat der Blade: die
 * Komponenten-Tag-Stufe ist neutralisiert (wie BladeCompileIntegrityTest), die
 * x-ui-Tags bleiben also als Text stehen — ihre Attribute (name, wire:model,
 * label) sind damit in der Ausgabe pruefbar, die Echos darin laufen echt.
 *
 * WAS DIESER TEST NICHT MESSEN KANN: den Browser-Weg des Selects
 * (Alpine/@entangle im Searchable-Modus ab 20 Optionen). Er setzt die
 * Property so, wie Livewire sie setzen wuerde, und nagelt Speichern,
 * Spaltenwert und Wiederoeffnen fest. Dass jeder Schluessel schon VOR dem
 * Oeffnen existiert (Vorbedingung des entangle), prueft der letzte Test hier
 * selbst — SettingsModalEntangleKeysTest sieht die Schleifen-Bindungen nicht,
 * weil deren Schluessel erst beim Rendern entsteht.
 */
class SettingsModalPortalVorlagenTest extends TestCase
{
    private const TEAM = 4712;

    private const BLADE = 'resources/views/livewire/applicant/applicant-settings-modal.blade.php';

    /** Die neun Vorlagen-Schluessel in der Reihenfolge der Sektion (seit 19.10.2026 mit der Einladung zum Konto). */
    private const SCHLUESSEL = [
        PortalVorlagen::CODE_PASSWORT,
        PortalVorlagen::CODE_NUMMERNWECHSEL,
        PortalVorlagen::CODE_NOTFALL,
        PortalVorlagen::HINWEIS_ALTE_NUMMER,
        PortalVorlagen::AUFGABEN_NEU,
        PortalVorlagen::EINLADUNG,
        'proof_reminder_wa_template_id',
        'document_wa_template_id',
        'employee_contract_wa_template_id',
    ];

    private static object $log;

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
        self::$log = new class {
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = ['nachricht' => (string) ($a[0] ?? ''), 'daten' => (array) ($a[1] ?? [])];
            }
        };
        $container->instance('log', self::$log);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_applicant_settings')->delete();
        self::$log->zeilen = [];
    }

    public function testAlleNeunVorlagenUndDerSchalterWerdenGespeichertUndWiederGeladen(): void
    {
        $this->zeile(['auto_pilot_enabled' => true]);

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        // So setzt Livewire den Wert eines Selects: als String.
        foreach (self::SCHLUESSEL as $i => $key) {
            $modal->settings[$key] = (string) (101 + $i);
        }
        $modal->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] = true;
        $modal->save();

        $roh = $this->roheSettings();
        foreach (self::SCHLUESSEL as $i => $key) {
            $this->assertSame(101 + $i, $roh[$key] ?? 'fehlt', "{$key} steht nicht als int in der Spalte");
        }
        $this->assertTrue($roh[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);
        $this->assertTrue(PortalVorlagen::einsatzPruefungAktiv(self::TEAM));

        $neu = new ApplicantSettingsModal();
        $neu->openSettings();
        foreach (self::SCHLUESSEL as $i => $key) {
            $this->assertSame(101 + $i, $neu->settings[$key], "{$key} kommt beim Wiederoeffnen nicht zurueck");
        }
        $this->assertTrue($neu->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);
    }

    public function testLeereAuswahlWirdNullNichtLeererString(): void
    {
        $this->zeile(array_fill_keys(self::SCHLUESSEL, 55));

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        foreach (self::SCHLUESSEL as $i => $key) {
            $modal->settings[$key] = $i % 2 === 0 ? '' : '0';
        }
        $modal->save();

        $roh = $this->roheSettings();
        foreach (self::SCHLUESSEL as $key) {
            $this->assertArrayHasKey($key, $roh);
            $this->assertNull($roh[$key], "{$key} ist nicht null");
        }
    }

    /** Review-Fokus 4: Speichern ohne die Portal-Felder anzufassen laesst die IDs stehen (und macht sie zu int). */
    public function testSpeichernOhneAnfassenLaesstDieIdsStehen(): void
    {
        $vorher = [];
        foreach (self::SCHLUESSEL as $i => $key) {
            $vorher[$key] = $i % 2 === 0 ? 200 + $i : (string) (200 + $i);
        }
        $this->zeile($vorher + [PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV => true]);

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        $modal->save();

        $roh = $this->roheSettings();
        foreach (self::SCHLUESSEL as $i => $key) {
            $this->assertSame(200 + $i, $roh[$key], "{$key} hat das Speichern nicht ueberlebt");
        }
        $this->assertTrue($roh[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);
    }

    public function testSchalterWirdStriktBool(): void
    {
        $this->zeile([]);

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        $modal->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] = 'false';
        $modal->save();
        $this->assertFalse($this->roheSettings()[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);

        $modal = new ApplicantSettingsModal();
        $modal->openSettings();
        $modal->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] = '1';
        $modal->save();
        $this->assertTrue($this->roheSettings()[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);
    }

    public function testKippenDesSchaltersStehtImLogNurBeiAenderung(): void
    {
        $this->zeile([]);

        $an = new ApplicantSettingsModal();
        $an->openSettings();
        $an->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] = true;
        $an->save();

        $gleich = new ApplicantSettingsModal();
        $gleich->openSettings();
        $gleich->save();

        $aus = new ApplicantSettingsModal();
        $aus->openSettings();
        $aus->settings[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] = false;
        $aus->save();

        $zeilen = array_values(array_filter(
            self::$log->zeilen,
            fn ($z) => $z['nachricht'] === 'recruiting.einsatz_pruefung.schalter'
        ));
        $this->assertCount(2, $zeilen);
        $this->assertSame(['team_id' => self::TEAM, 'user_id' => 42, 'an' => true], $zeilen[0]['daten']);
        $this->assertSame(['team_id' => self::TEAM, 'user_id' => 42, 'an' => false], $zeilen[1]['daten']);
    }

    public function testKommunikationsReiterZeigtDieSektionMitAllenAchtSelectsUndDemSchalter(): void
    {
        $html = $this->rendere('comms', [['id' => 1, 'label' => 'vorlage_a (de)']]);

        $this->assertStringContainsString('Mitarbeiterportal', $html);
        $this->assertStringContainsString(
            'WhatsApp-Vorlagen und Schalter für das neue Mitarbeiterportal. Ohne gewählte Vorlage geht die jeweilige Nachricht nicht raus — der Grund steht im Log.',
            $html
        );

        $positionen = [];
        foreach (self::SCHLUESSEL as $key) {
            $this->assertSame(1, substr_count($html, 'name="settings.' . $key . '"'), "Select {$key} fehlt oder doppelt");
            $this->assertSame(1, substr_count($html, 'wire:model.live="settings.' . $key . '"'), "Binding {$key} fehlt");
            $this->assertStringContainsString('label="' . PortalVorlagen::FELDNAMEN[$key] . '"', $html, "Label {$key}");
            $positionen[] = strpos($html, 'name="settings.' . $key . '"');
        }
        $sortiert = $positionen;
        sort($sortiert);
        $this->assertSame($sortiert, $positionen, 'Die Felder stehen nicht in der festgelegten Reihenfolge');

        $sektion = substr($html, (int) strpos($html, '>Mitarbeiterportal<'));
        $this->assertSame(1, substr_count($sektion, 'nullLabel="– wie Dokumente –"'));
        $this->assertSame(8, substr_count($sektion, 'nullLabel="– Template wählen –"'), 'Acht Felder mit „– Template wählen –"');
        // Einladung zum Konto (19.10.2026): Knopf auf die Konto-Anlage, Rueckfall ohne Vorlage.
        $this->assertStringContainsString('Genau ein URL-Knopf mit Variable auf …/recruiting/konto/anlegen/&#123;&#123;1&#125;&#125;', $html);
        $this->assertStringContainsString('Leer gelassen zeigt HR Link und Code wie bisher nur an.', $html);
        // Seit 14.10.2026: AUTHENTICATION „Code kopieren" zuerst, UTILITY mit {{code}} als Altform.
        $this->assertStringContainsString('Vorlage der Kategorie AUTHENTICATION mit Knopf „Code kopieren"', $html);
        $this->assertStringContainsString('UTILITY-Vorlagen mit &#123;&#123;code&#125;&#125; (Pflicht), optional Vorname, ohne Knopf mit Variable funktionieren weiter.', $html);
        $this->assertStringContainsString('Geht nur raus, wenn der Notfall-Weg freigeschaltet ist.', $html);
        $this->assertStringContainsString('Ohne Platzhalter oder nur mit Vorname. Nennt die neue Nummer bewusst nicht.', $html);
        $this->assertStringContainsString('&#123;&#123;anzahl&#125;&#125;, optional Vorname, und ein URL-Knopf, dessen Variable am Ende steht.', $html);
        // Der Hinweis der entfallenen Erinnerungs-Vorlage ist weg (Markus 12.10.2026).
        $this->assertStringNotContainsString('&#123;&#123;anzahl&#125;&#125; und &#123;&#123;datum&#125;&#125;', $html);
        $this->assertStringNotContainsString('aufgaben_erinnerung_wa_template_id', $html);
        $this->assertStringContainsString('&#123;&#123;nachweis&#125;&#125;', $html, 'Fristenlauf-Hinweis nennt die Platzhalter');

        $this->assertMatchesRegularExpression('/type="checkbox"\s+wire:model="settings\.einsatz_pruefung_aktiv"/', $html);
        $this->assertStringContainsString('Einsatz-Prüfung läuft', $html);
        $this->assertStringContainsString('Erst einschalten, wenn der Nachweis-Umzug und der Trockenlauf erledigt sind.', $html);
        $this->assertStringNotContainsString('Keine genehmigten WhatsApp-Vorlagen gefunden.', $html);
        $this->assertStringNotContainsString('{{', $sektion, 'Ungerendertes Blade-Echo in der Sektion');
    }

    public function testOhneVorlagenStehtDerSatzStattDerSelectsUndDerSchalterBleibt(): void
    {
        $html = $this->rendere('comms', []);

        $this->assertStringContainsString('Mitarbeiterportal', $html);
        $this->assertStringContainsString('Keine genehmigten WhatsApp-Vorlagen gefunden.', $html);
        foreach (self::SCHLUESSEL as $key) {
            $this->assertStringNotContainsString('name="settings.' . $key . '"', $html);
        }
        $this->assertMatchesRegularExpression('/wire:model="settings\.einsatz_pruefung_aktiv"/', $html);
    }

    public function testAllgemeinReiterHatDokumenteUndVertragNichtMehrDasZertifikatAberSchon(): void
    {
        $html = $this->rendere('general', [['id' => 1, 'label' => 'vorlage_a (de)']]);

        $this->assertStringContainsString('name="settings.training_certificate_wa_template_id"', $html);
        $this->assertStringNotContainsString('settings.document_wa_template_id', $html);
        $this->assertStringNotContainsString('settings.employee_contract_wa_template_id', $html);
        $this->assertStringNotContainsString('Mitarbeiterportal', $html);
    }

    /** Vorbedingung des entangle im Browser: jeder Schluessel existiert schon VOR openSettings(). */
    public function testJederSchluesselExistiertVorDemOeffnen(): void
    {
        $frisch = new ApplicantSettingsModal();
        foreach ([...self::SCHLUESSEL, PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV] as $key) {
            $this->assertArrayHasKey($key, $frisch->settings);
        }
        $this->assertSame(array_keys(PortalVorlagen::FELDNAMEN), self::SCHLUESSEL, 'FELDNAMEN und Sektion laufen auseinander');
    }

    // -----------------------------------------------------------------

    /**
     * Kompiliert die ECHTE Datei (Komponenten-Tags neutralisiert) und fuehrt
     * sie mit `$this` = Probe aus. Die Probe ersetzt die Computed Properties,
     * die ohne Livewire-Laufzeit nicht aufloesen, durch schlichte Properties.
     */
    private function rendere(string $tab, array $vorlagen): string
    {
        $probe = new class extends ApplicantSettingsModal {
            public array $availableWhatsAppTemplates = [];
            public array $availableStatuses = [];
            public string $metaButtonUrl = 'https://example.test/z/{{1}}';
        };
        $probe->availableWhatsAppTemplates = $vorlagen;
        $probe->activeTab = $tab;

        $compiler = new class(new Filesystem(), sys_get_temp_dir()) extends BladeCompiler {
            protected function compileComponentTags($value)
            {
                return $value;
            }
        };
        $php = $compiler->compileString((string) file_get_contents(dirname(__DIR__, 2) . '/' . self::BLADE));

        $datei = sys_get_temp_dir() . '/settings-portal-' . getmypid() . '-' . uniqid() . '.php';
        file_put_contents($datei, $php);

        $daten = [
            '__env'        => new ViewFactory(new EngineResolver(), new FileViewFinder(new Filesystem(), []), new Dispatcher()),
            'activeTab'    => $tab,
            'settings'     => $probe->settings,
            'teamUsers'    => [],
            'serviceHours' => [],
            // Livewire teilt $errors mit jeder Komponenten-Ansicht (@error der Themen-Texte).
            'errors'       => new \Illuminate\Support\ViewErrorBag(),
        ];

        $lauf = function (string $__pfad, array $__daten): string {
            extract($__daten);
            ob_start();
            try {
                include $__pfad;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        };

        try {
            return \Closure::bind($lauf, $probe, get_class($probe))($datei, $daten);
        } finally {
            @unlink($datei);
        }
    }

    private function zeile(array $settings): void
    {
        Capsule::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode($settings),
        ]);
    }

    private function roheSettings(): array
    {
        return (array) json_decode((string) Capsule::table('rec_applicant_settings')
            ->where('team_id', self::TEAM)
            ->value('settings'), true);
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
