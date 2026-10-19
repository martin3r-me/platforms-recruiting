<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CommsChannel;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Services\Comms\EinladungSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Support\EinmalcodeVorlagen;
use Platform\Recruiting\Support\PortalVorlagen;

/**
 * Einladung zum Konto per WhatsApp-Vorlage — Muster DokumentHinweisSenderTest:
 * echte Tabellen fuer Einstellungen, Kanal, Account und Vorlage; Meta und Log
 * als Attrappe.
 *
 * DIE META-ATTRAPPE LEGT DIE ZEILEN AN, DIE DAS CRM ANLEGT: eine Zeile in
 * comms_whatsapp_messages mit template_params/meta_payload und eine in
 * comms_logs mit details.template_params — genau so, wie
 * WhatsAppMetaService::handleSendResponse() sie schreibt (Arrays per Cast als
 * JSON). Nur so ist pruefbar, dass der Einladungscode danach NICHT mehr im
 * gespeicherten Verlauf steht.
 */
final class EinladungSenderTest extends TestCase
{
    private const TEAM = 3;
    private const NUMMER = '+4915111111111';
    private const CODE = 'K7MX4QPN';
    private const VORLAGE = 'ma_portal_einladung';
    private const URL = 'https://mitarbeiter.rheingedeck.de/recruiting/konto/anlegen/{{1}}';
    private const JETZT = '2026-10-19 09:00:00';

    private Capsule $capsule;
    private object $meta;
    private object $log;
    private int $kontoId;
    private int $vorlageId;
    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $this->log = new class {
            public array $zeilen = [];
            public function __call($m, $a)
            {
                $this->zeilen[] = ['stufe' => $m, 'nachricht' => (string) ($a[0] ?? ''), 'daten' => (array) ($a[1] ?? [])];
            }
        };
        $container->instance('log', $this->log);
        $container->instance('config', new ConfigRepository([]));
        $this->meta = $this->metaAttrappe();
        $container->instance(WhatsAppMetaService::class, $this->meta);
        $container->instance(HoldingTemplateSender::class, new HoldingTemplateSender(new class extends WhatsAppMetaService {}));

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        EinmalcodeVorlagen::vergiss();

        $schema = $this->capsule->schema();
        $schema->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->string('phone')->nullable();
        });
        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('first_name')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
        });
        // Die beiden CRM-Tabellen, in die handleSendResponse() schreibt — nur
        // die Spalten, um die es hier geht.
        $schema->create('comms_whatsapp_messages', function ($t) {
            $t->increments('id');
            $t->string('template_name')->nullable();
            $t->text('template_params')->nullable();
            $t->text('meta_payload')->nullable();
            $t->string('status')->nullable();
        });
        $schema->create('comms_logs', function ($t) {
            $t->increments('id');
            $t->integer('message_id')->nullable();
            $t->text('details')->nullable();
        });
        $this->echteMigrationen();
        $this->kontoUndKanal();
        $this->vorlageId = $this->vorlageAnlegen();
        $this->einstellungen([PortalVorlagen::EINLADUNG => $this->vorlageId]);

        $this->personId = (int) DB::table('rec_persons')->insertGetId(['team_id' => self::TEAM, 'phone' => self::NUMMER]);
        DB::table('rec_employees')->insert([
            'team_id' => self::TEAM, 'rec_person_id' => $this->personId, 'first_name' => 'Gregor',
            'is_active' => 1, 'portal_v2_since' => self::JETZT,
        ]);
    }

    protected function tearDown(): void
    {
        EinmalcodeVorlagen::vergiss();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['log', 'config', 'db', 'db.schema', WhatsAppMetaService::class, HoldingTemplateSender::class] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // ------------------------------------------------------------ Erfolg

    public function test_gesendet_mit_vorname_im_rumpf_und_code_im_knopf(): void
    {
        $status = (new EinladungSender())->sende($this->personId, self::CODE);

        $this->assertSame(EinladungSender::STATUS_GESENDET, $status);
        $this->assertCount(1, $this->meta->calls);
        $call = $this->meta->calls[0];
        $this->assertSame(self::NUMMER, $call['to']);
        $this->assertSame(self::VORLAGE, $call['templateName'], 'Name aus der gewaehlten Vorlage');
        $this->assertSame('de', $call['languageCode']);
        $this->assertSame([
            ['type' => 'body', 'parameters' => [['type' => 'text', 'parameter_name' => 'name', 'text' => 'Gregor']]],
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => self::CODE]]],
        ], $call['components']);
    }

    public function test_vorlage_ohne_platzhalter_schickt_nur_den_knopf(): void
    {
        $this->komponenten([
            ['type' => 'BODY', 'text' => 'Dein Konto ist bereit.'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Konto anlegen', 'url' => self::URL]]],
        ]);

        $this->assertSame(EinladungSender::STATUS_GESENDET, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertSame([
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => self::CODE]]],
        ], $this->meta->calls[0]['components']);
    }

    public function test_positioneller_vorname_geht_ohne_parameter_name_raus_und_knopf_index_stimmt(): void
    {
        $this->komponenten([
            ['type' => 'BODY', 'text' => 'Hallo {{1}}, dein Konto ist bereit.'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                ['type' => 'URL', 'text' => 'Konto anlegen', 'url' => self::URL],
            ]],
        ]);

        $this->assertSame(EinladungSender::STATUS_GESENDET, (new EinladungSender())->sende($this->personId, self::CODE));
        $components = $this->meta->calls[0]['components'];
        $this->assertSame([['type' => 'text', 'text' => 'Gregor']], $components[0]['parameters']);
        $this->assertSame(1, $components[1]['index']);
    }

    // ------------------------------------------------------------ nicht konfiguriert / Kanal

    public function test_ohne_einstellung_nicht_konfiguriert_und_kein_versand(): void
    {
        $this->einstellungen([]);

        $this->assertSame(EinladungSender::STATUS_NICHT_KONFIGURIERT, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
        $this->assertStringContainsString('Einladung zum Konto', json_encode($this->log->zeilen, JSON_UNESCAPED_UNICODE), 'Log nennt das Feld');
    }

    public function test_nicht_genehmigte_vorlage_ist_nicht_konfiguriert(): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update(['status' => 'PENDING']);

        $this->assertSame(EinladungSender::STATUS_NICHT_KONFIGURIERT, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_genehmigte_vorlage_ohne_aktiven_kanal_ist_kein_kanal(): void
    {
        DB::table('comms_channels')->update(['is_active' => false]);

        $this->assertSame(EinladungSender::STATUS_KEIN_KANAL, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
    }

    // ------------------------------------------------------------ Vorlage untauglich

    /** @return array<string, array{0: list<array<string, mixed>>}> */
    public static function untauglicheVorlagen(): array
    {
        $body = ['type' => 'BODY', 'text' => 'Hallo {{name}}'];
        $knopf = static fn (string $url) => ['type' => 'URL', 'text' => 'Konto anlegen', 'url' => $url];

        return [
            'fremder Platzhalter' => [[
                ['type' => 'BODY', 'text' => 'Hallo {{name}}, gilt bis {{datum}}.'],
                ['type' => 'BUTTONS', 'buttons' => [$knopf(self::URL)]],
            ]],
            'kein dynamischer Knopf' => [[
                $body,
                ['type' => 'BUTTONS', 'buttons' => [$knopf('https://mitarbeiter.rheingedeck.de/recruiting/konto/anlegen')]],
            ]],
            'gar kein Knopf' => [[$body]],
            'zwei dynamische Knoepfe' => [[
                $body,
                ['type' => 'BUTTONS', 'buttons' => [$knopf(self::URL), $knopf('https://mitarbeiter.rheingedeck.de/recruiting/konto/anlegen/x{{1}}')]],
            ]],
            'Kopfzeile mit Variable' => [[
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Hallo {{1}}'],
                $body,
                ['type' => 'BUTTONS', 'buttons' => [$knopf(self::URL)]],
            ]],
            'Knopf fuehrt woanders hin' => [[
                $body,
                ['type' => 'BUTTONS', 'buttons' => [$knopf('https://meingedeck.de/recruiting/mitarbeiter/neu/{{1}}')]],
            ]],
            'Code-kopieren-Vorlage' => [[
                ['type' => 'BODY', 'text' => '*{{1}}* ist dein Bestaetigungscode.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Code kopieren']]],
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untauglicheVorlagen')]
    public function test_untaugliche_vorlage_verschickt_nichts(array $komponenten): void
    {
        $this->komponenten($komponenten);

        $this->assertSame(EinladungSender::STATUS_VORLAGE_UNTAUGLICH, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
        $grund = $this->logZeilen()[0]['daten']['grund'] ?? '';
        $this->assertStringContainsString(self::VORLAGE, $grund, 'Grund nennt die Vorlage');
    }

    // ------------------------------------------------------------ Fehlschlag

    public function test_meta_status_failed_ist_fehlgeschlagen_und_der_verlauf_trotzdem_geschwaerzt(): void
    {
        $this->meta->lehntAb();

        $this->assertSame(EinladungSender::STATUS_FEHLGESCHLAGEN, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCodeNichtGespeichert();
        $this->assertStringNotContainsString(self::CODE, json_encode($this->log->zeilen), 'Meta zitiert den Wert — nicht ins Log');
    }

    public function test_ausnahme_ist_fehlgeschlagen_und_der_code_steht_nicht_im_log(): void
    {
        $this->meta->wirft(new \RuntimeException('insert into comms_whatsapp_messages values (' . self::CODE . ')'));

        $this->assertSame(EinladungSender::STATUS_FEHLGESCHLAGEN, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertStringNotContainsString(self::CODE, json_encode($this->log->zeilen));
        $this->assertSame('warning', $this->logZeilen()[0]['stufe']);
    }

    public function test_ohne_lesbare_nummer_kein_versand(): void
    {
        DB::table('rec_persons')->where('id', $this->personId)->update(['phone' => 'keine']);

        $this->assertSame(EinladungSender::STATUS_FEHLGESCHLAGEN, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_ohne_vorname_bei_namens_platzhalter_kein_versand(): void
    {
        DB::table('rec_employees')->update(['first_name' => null]);

        $this->assertSame(EinladungSender::STATUS_FEHLGESCHLAGEN, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
    }

    // ------------------------------------------------------------ Pilot

    /**
     * MUTATIONSPROBE: entfernt man in EinladungSender::sende() die Abfrage
     * PilotKreis::personHatNeuesPortal(), wird dieser Test rot (die Vorlage
     * ist gueltig, der Versand ginge raus).
     */
    public function test_nicht_im_pilot_bekommt_nichts(): void
    {
        DB::table('rec_employees')->update(['portal_v2_since' => null]);

        $this->assertSame(EinladungSender::STATUS_NICHT_IM_PILOT, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
    }

    public function test_umgestellte_aber_inaktive_anstellung_zaehlt_nicht_als_pilot(): void
    {
        DB::table('rec_employees')->update(['is_active' => 0]);

        $this->assertSame(EinladungSender::STATUS_NICHT_IM_PILOT, (new EinladungSender())->sende($this->personId, self::CODE));
        $this->assertCount(0, $this->meta->calls);
    }

    // ------------------------------------------------------------ Verlauf

    public function test_code_steht_nicht_im_gespeicherten_verlauf(): void
    {
        $this->assertSame(EinladungSender::STATUS_GESENDET, (new EinladungSender())->sende($this->personId, self::CODE));

        $this->assertCodeNichtGespeichert();
        // Struktur bleibt, damit der Chat den Satz noch rendern kann.
        $params = json_decode((string) DB::table('comms_whatsapp_messages')->value('template_params'), true);
        $this->assertSame('name', $params[0]['parameters'][0]['parameter_name']);
        $this->assertSame(EinmalcodeVorlagen::MASKE, $params[1]['parameters'][0]['text'], 'Knopf-Parameter geschwaerzt');
        $this->assertSame('button', $params[1]['type']);
        $this->assertStringNotContainsString(self::CODE, json_encode($this->log->zeilen), 'nie der Code im Log');
    }

    /** Zweite Linie: die Chat-Anzeige erkennt die Einladung auch selbst (Struktur und Einstellung). */
    public function test_die_chat_schwaerzung_erkennt_die_einladungs_vorlage(): void
    {
        $definition = json_decode((string) DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->value('components'), true);

        $this->assertTrue(EinmalcodeVorlagen::istCodeVorlage('irgendein_name', $definition), 'Struktur: Knopf auf /konto/anlegen/');
        $this->assertTrue(EinmalcodeVorlagen::istCodeVorlage(self::VORLAGE, null), 'Einstellung: im Feld Einladung zum Konto gewaehlt');
        $this->assertFalse(EinmalcodeVorlagen::istCodeVorlage('t_wo_bist', [['type' => 'BODY', 'text' => 'Hi {{1}}']]));
    }

    public function test_attrappe_passt_zur_signatur_von_send_template(): void
    {
        $echt = array_map(fn ($p) => $p->getName(), (new \ReflectionMethod(WhatsAppMetaService::class, 'sendTemplate'))->getParameters());
        $attrappe = array_map(fn ($p) => $p->getName(), (new \ReflectionMethod($this->meta, 'sendTemplate'))->getParameters());

        $this->assertSame($echt, $attrappe);
    }

    // ------------------------------------------------------------ Hilfen

    private function assertCodeNichtGespeichert(): void
    {
        $nachricht = DB::table('comms_whatsapp_messages')->first();
        $this->assertNotNull($nachricht);
        $this->assertStringNotContainsString(self::CODE, (string) $nachricht->template_params);
        $this->assertStringNotContainsString(self::CODE, (string) $nachricht->meta_payload);
        $log = DB::table('comms_logs')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString(self::CODE, (string) $log->details);
        $this->assertSame(self::VORLAGE, json_decode((string) $log->details, true)['template_name'], 'der Rest des Protokolls bleibt');
    }

    /** @return list<array{stufe:string, nachricht:string, daten:array}> */
    private function logZeilen(): array
    {
        return array_values(array_filter($this->log->zeilen, fn ($z) => $z['nachricht'] === 'recruiting.konto.einladung_versand'));
    }

    private function komponenten(array $komponenten): void
    {
        DB::table('integrations_whatsapp_templates')->where('id', $this->vorlageId)->update(['components' => json_encode($komponenten)]);
    }

    private function metaAttrappe(): object
    {
        return new class {
            public array $calls = [];
            private bool $lehntAb = false;
            private ?\Throwable $wirft = null;
            public function lehntAb(): void { $this->lehntAb = true; }
            public function wirft(\Throwable $e): void { $this->wirft = $e; }
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false)
            {
                $this->calls[] = compact('channel', 'to', 'templateName', 'components', 'languageCode');
                if ($this->wirft !== null) {
                    throw $this->wirft;
                }
                // Wie handleSendResponse(): Nachricht + Protokoll mit den rohen
                // components; bei Ablehnung zitiert Meta den Wert.
                $payload = $this->lehntAb
                    ? ['error' => ['message' => 'Parameter ' . ($components[array_key_last($components)]['parameters'][0]['text'] ?? '') . ' ungueltig']]
                    : ['messages' => [['id' => 'wamid.1']]];
                $status = $this->lehntAb ? 'failed' : 'sent';
                $id = (int) DB::table('comms_whatsapp_messages')->insertGetId([
                    'template_name' => $templateName, 'template_params' => json_encode($components),
                    'meta_payload' => json_encode($payload), 'status' => $status,
                ]);
                DB::table('comms_logs')->insert([
                    'message_id' => $id,
                    'details' => json_encode(['template_name' => $templateName, 'template_params' => $components, 'meta_response' => $payload]),
                ]);

                return (object) ['id' => $id, 'status' => $status, 'meta_payload' => $payload];
            }
        };
    }

    private function echteMigrationen(): void
    {
        $eigen = dirname(__DIR__, 2);
        $crm = $this->paketWurzel(CommsChannel::class);
        $integrations = $this->paketWurzel(IntegrationsWhatsAppTemplate::class);
        foreach ([
            [$eigen, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$crm, 'database/migrations/2026_01_14_000003_create_comms_channels_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
        ] as [$wurzel, $relativ]) {
            (require $wurzel . '/' . $relativ)->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        return dirname((new \ReflectionClass($klasse))->getFileName(), 3);
    }

    private function kontoUndKanal(): void
    {
        $this->kontoId = (int) DB::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-einl', 'phone_number' => '+49 160 5552003', 'title' => 'Recruiting-WABA', 'active' => true, 'user_id' => 1,
        ]);
        DB::table('comms_channels')->insert([
            'team_id' => self::TEAM, 'name' => 'Recruiting WhatsApp', 'type' => 'whatsapp', 'provider' => 'whatsapp_meta',
            'sender_identifier' => '+49 160 5552003', 'is_active' => true,
            'meta' => json_encode(['integrations_whatsapp_account_id' => $this->kontoId]),
            'created_at' => self::JETZT, 'updated_at' => self::JETZT,
        ]);
    }

    private function einstellungen(array $weitere): void
    {
        DB::table('rec_applicant_settings')->where('team_id', self::TEAM)->delete();
        DB::table('rec_applicant_settings')->insert([
            'team_id'  => self::TEAM,
            'settings' => json_encode(array_merge(['auto_pilot_wa_account_id' => $this->kontoId], $weitere)),
        ]);
    }

    private function vorlageAnlegen(): int
    {
        return (int) DB::table('integrations_whatsapp_templates')->insertGetId([
            'uuid' => 'tpl-einl', 'external_id' => 'ext-einl', 'name' => self::VORLAGE, 'language' => 'de',
            'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => json_encode([
                ['type' => 'BODY', 'text' => 'Hallo {{name}}, für dich ist dein neues Mitarbeiterportal von RheinGedeck bereit.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Konto anlegen', 'url' => self::URL]]],
            ]),
            'whatsapp_account_id' => $this->kontoId, 'user_id' => 1,
            'created_at' => self::JETZT, 'updated_at' => self::JETZT,
        ]);
    }
}
