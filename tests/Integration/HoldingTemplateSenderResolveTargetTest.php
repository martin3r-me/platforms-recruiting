<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * resolveTarget() — die Aufloesung Settings -> Template -> Account -> Kanal,
 * lesend und ohne zu senden.
 *
 * WARUM DIESE METHODE UEBERHAUPT EXISTIERT: der Zertifikat-Versand ruft
 * WhatsAppMetaService::sendTemplate() direkt (Spec W1) und braucht dafuer
 * Template und Kanal. Die Kette nachzubauen waere die achte Kopie im Modul; sie
 * aus dem Sender herauszuziehen hiesse, einen Pfad anzufassen, der auch
 * Holding-Bestaetigung, OOO-Auto-Reply und Voice-Note-Antwort traegt (Spec Q2).
 * Also: lesender Zugang, kein Umbau.
 *
 * GEPRUEFT WIRD GEGEN DIE ECHTEN MIGRATIONEN, nicht gegen ein handgebautes
 * Schema: die Methode lebt von Spaltennamen (`sender_identifier`, `active`,
 * `is_active`), und genau die soll ein Rename in einem Nachbarmodul hier rot
 * machen statt still gruen laufen zu lassen.
 *
 * PROZESSWEITER ZUSTAND: diese Klasse setzt Container-Instanzen und eine
 * Facade-Wurzel und raeumt beide selbst wieder weg. Der Schaden traefe sonst
 * SPAETERE Testklassen und faellt nur im Gesamtlauf auf (Modulmuster, siehe
 * TrainingCertificateWhatsAppDeliveryTest).
 */
class HoldingTemplateSenderResolveTargetTest extends TestCase
{
    private const TEAM = 71;

    private const SETTINGS_KEY = 'training_certificate_wa_template_id';

    /** Nummer des WhatsApp-Accounts — der Kanal wird darueber gefunden. */
    private const ACCOUNT_NUMMER = '+49 100 5550000';

    private static int $templateId = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        // Jeder Test darf den Kanal umschalten; der naechste faengt aktiv an.
        Capsule::table('comms_channels')->update(['is_active' => true]);
        parent::tearDown();
    }

    private function sender(): HoldingTemplateSender
    {
        // Die Attrappe wird nie aufgerufen — resolveTarget sendet nicht. Sie
        // steht nur da, weil der Konstruktor sie verlangt.
        $whatsApp = new class extends \Platform\Crm\Services\Comms\WhatsAppMetaService {};

        return new HoldingTemplateSender($whatsApp);
    }

    public function testAufloesungLiefertTemplateUndKanal(): void
    {
        $target = $this->sender()->resolveTarget(self::TEAM, self::SETTINGS_KEY);

        $this->assertNull($target['error'], 'Vollstaendig konfiguriert -> kein Fehler.');
        $this->assertInstanceOf(IntegrationsWhatsAppTemplate::class, $target['template']);
        $this->assertSame(self::$templateId, (int) $target['template']->id);
        $this->assertInstanceOf(CommsChannel::class, $target['channel']);
        $this->assertSame(self::ACCOUNT_NUMMER, $target['channel']->sender_identifier);
    }

    /**
     * Der Kanal ist die letzte Stufe der Kette und die einzige, die im Betrieb
     * unabhaengig vom Template umgelegt wird (Kanal deaktiviert, Nummer
     * gewechselt). Deshalb dieser Negativfall und nicht ein anderer.
     */
    public function testOhneAktivenKanalKommtDerFehlerStringZurueck(): void
    {
        Capsule::table('comms_channels')->update(['is_active' => false]);

        $target = $this->sender()->resolveTarget(self::TEAM, self::SETTINGS_KEY);

        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $target['error']);
        $this->assertNull($target['template']);
        $this->assertNull($target['channel']);
    }

    /**
     * Ein leerer Settings-Key faellt in denselben Fehler wie im Sendeweg — und
     * die Meldung nennt das Eingangsbestaetigungs-Template, egal welcher
     * Schluessel gefragt wurde. Genau deshalb behaelt der Zertifikat-Versand
     * seinen eigenen not_configured-Zweig VOR dieser Aufloesung (Spec W2).
     */
    public function testUnkonfiguriertesTeamMeldetDenGenerischenText(): void
    {
        $target = $this->sender()->resolveTarget(9999, self::SETTINGS_KEY);

        $this->assertNotNull($target['error']);
        $this->assertStringContainsString('Eingangsbestätigungs-Template', $target['error']);
    }

    /**
     * resolveConfig() bleibt private und in seiner Signatur unveraendert.
     *
     * Der Zugang ist ausdruecklich EIN Durchreicher, kein Umbau: sobald jemand
     * resolveConfig oeffnet oder seine Parameter aendert, ist das eine Aenderung
     * an einem Pfad mit drei fremden Aufrufern und soll auffallen.
     */
    public function testResolveConfigBleibtPrivat(): void
    {
        $methode = new \ReflectionMethod(HoldingTemplateSender::class, 'resolveConfig');

        $this->assertTrue($methode->isPrivate(), 'resolveConfig darf nicht oeffentlich werden.');
        $this->assertSame(2, $methode->getNumberOfParameters());

        $zugang = new \ReflectionMethod(HoldingTemplateSender::class, 'resolveTarget');
        $this->assertTrue($zugang->isPublic());
        $this->assertSame(
            ['teamId', 'settingsKey'],
            array_map(fn ($p) => $p->getName(), $zugang->getParameters()),
            'Gleiche Parameter wie resolveConfig, gleiche Reihenfolge.'
        );
    }

    public function testResolveTemplateLiefertKanalZurTemplateId(): void
    {
        $target = $this->sender()->resolveTemplate(self::TEAM, self::$templateId);

        $this->assertNull($target['error']);
        $this->assertInstanceOf(IntegrationsWhatsAppTemplate::class, $target['template']);
        $this->assertSame(self::$templateId, (int) $target['template']->id);
        $this->assertInstanceOf(CommsChannel::class, $target['channel']);
        $this->assertSame(self::ACCOUNT_NUMMER, $target['channel']->sender_identifier);
    }

    public function testResolveTemplateUnbekannteIdMeldetFehler(): void
    {
        $target = $this->sender()->resolveTemplate(self::TEAM, 999999);

        $this->assertSame('Template nicht gefunden oder bei Meta nicht genehmigt.', $target['error']);
        $this->assertNull($target['template']);
        $this->assertNull($target['channel']);
    }

    public function testResolveTemplateOhneKanalMeldetFehler(): void
    {
        Capsule::table('comms_channels')->update(['is_active' => false]);

        $target = $this->sender()->resolveTemplate(self::TEAM, self::$templateId);

        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $target['error']);
    }

    /**
     * Schema aus den ECHTEN Migrationen (Modulmuster). comms_channels traegt
     * Fremdschluessel auf `teams` und `comms_provider_connections`; die Tabellen
     * fehlen hier und muessen es auch nicht geben — sqlite erzwingt die
     * Referenz nicht. Nachgemessen im Bestand: SettingsModalToggleWriteTest:308
     * laedt dieselbe Migration ohne diese Tabellen.
     *
     * `user_id` ist an integrations_whatsapp_accounts UND _templates NOT NULL
     * ohne Default (Migration `:31` bzw. `:26`) — die Spalte wird deshalb
     * mitgegeben, ohne `users`-Tabelle: die Referenz erzwingt sqlite nicht,
     * die NOT-NULL-Regel schon. Gleiches Vorgehen wie
     * TrainingCertificateWhatsAppDeliveryTest, dort mit echter users-Zeile.
     */
    private static function runRealMigrations(): void
    {
        $own = dirname(__DIR__, 2);
        $crm = self::packageRootOf(CommsChannel::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);

        $files = [
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }

        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-resolve-target',
            'phone_number' => self::ACCOUNT_NUMMER,
            'title' => 'Test-Account',
            'active' => true,
            'user_id' => 1,
        ]);

        self::$templateId = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-resolve-target',
            'name' => 'zert_button',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hallo {{name}}']],
            'whatsapp_account_id' => $accountId,
            'user_id' => 1,
        ])->id;

        Capsule::table('comms_channels')->insert([
            'team_id' => self::TEAM,
            'type' => 'whatsapp',
            'provider' => 'whatsapp_meta',
            'sender_identifier' => self::ACCOUNT_NUMMER,
            'is_active' => true,
        ]);

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => [self::SETTINGS_KEY => self::$templateId],
        ]);
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
