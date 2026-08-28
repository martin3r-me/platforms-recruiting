<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * Der Sender wird gegen eine Attrappe des HoldingTemplateSender (Kanal-
 * Aufloesung) und eine Attrappe des WhatsAppMetaService geprueft — die echte
 * Aufloesung ist in HoldingTemplateSenderResolveTargetTest belegt.
 *
 * Geprueft: Body ohne Variablen bleibt leer, {{name}} wird zum Vornamen, der
 * URL-Button traegt den Personen-Token, das Log traegt Kampagne + Segment, und
 * Fehlerwege liefern den richtigen Status ohne Log.
 *
 * ZWEI STUBS, DUCK-TYPED (Muster TrainingCertificateWhatsAppDeliveryTest):
 * HoldingTemplateSender ist final, ein echter WhatsAppMetaService braeuchte
 * Meta-Zugang. Beide werden ueber den Container gebunden und vom Sender per
 * app() aufgeloest, statt sie in den Konstruktor zu tippen.
 *
 * Schema-Hinweis (Ruling task-6/task-7): rec_applicant.crmContactLinks() ist
 * ein morphMany auf crm_contact_links (linkable_id/linkable_type), nicht die
 * im Original-Brief skizzierte rec_applicant_contact_links-Tabelle.
 * crm_contacts.full_name ist ein Accessor, keine Spalte. Morph-Typwerte kommen
 * aus getMorphClass() statt hartkodiert.
 */
final class NewDatesCampaignSenderTest extends TestCase
{
    private Capsule $capsule;
    private object $meta;
    private string $applicantMorph;
    private string $contactMorph;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Str', false)) {
            class_alias(\Illuminate\Support\Str::class, 'Str');
        }

        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $this->applicantMorph = (new RecApplicant())->getMorphClass();
        $this->contactMorph = (new CrmContact())->getMorphClass();

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('auto_pilot')->default(true);
            $t->integer('rec_phase_id')->nullable(); $t->integer('rec_position_id')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        // Schema wie primaryContactPhone() es tatsaechlich liest (Ruling
        // task-6/7): morphMany 'linkable' auf crm_contact_links, morphMany
        // 'phoneable' auf crm_phone_numbers.
        $s->create('crm_contact_links', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('contact_id');
            $t->integer('company_id')->nullable(); $t->integer('linkable_id'); $t->string('linkable_type');
            $t->integer('team_id')->nullable(); $t->integer('created_by_user_id')->nullable(); $t->timestamps();
        });
        $s->create('crm_contacts', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('first_name')->nullable();
            $t->string('last_name')->nullable(); $t->string('middle_name')->nullable(); $t->string('nickname')->nullable();
            $t->timestamps();
        });
        $s->create('crm_phone_numbers', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('phoneable_type')->nullable();
            $t->integer('phoneable_id')->nullable(); $t->string('raw_input')->nullable(); $t->string('international')->nullable();
            $t->string('national')->nullable(); $t->string('country_code')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_primary')->default(false); $t->timestamps();
        });
        // Extra-Felder liest der Vorname-Fallback nicht — wir nehmen den CRM-Vornamen.

        $this->meta = new class {
            public array $calls = [];
            public bool $throw = false;
            public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
            {
                if ($this->throw) {
                    throw new \RuntimeException('Meta 131026');
                }
                $this->calls[] = compact('to', 'templateName', 'components', 'languageCode');
                return (object) ['id' => count($this->calls), 'thread' => null];
            }
        };
        $container->instance(WhatsAppMetaService::class, $this->meta);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Container::getInstance()->forgetInstance(HoldingTemplateSender::class);
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(bool $phone = true): RecApplicant
    {
        $a = RecApplicant::forceCreate(['team_id' => 3]);
        Capsule::table('crm_contacts')->insert(['id' => 500, 'first_name' => 'Lea', 'last_name' => 'Paulsen']);
        Capsule::table('crm_contact_links')->insert(['contact_id' => 500, 'linkable_id' => $a->id, 'linkable_type' => $this->applicantMorph]);
        if ($phone) {
            Capsule::table('crm_phone_numbers')->insert(['phoneable_type' => $this->contactMorph, 'phoneable_id' => 500, 'raw_input' => '0176', 'international' => '+4917672283401', 'is_active' => true, 'is_primary' => true]);
        }
        return $a->fresh();
    }

    /** @param array<string,mixed> $template Attribute des Templates (components etc.) */
    private function sender(array $template, ?string $resolveError = null): NewDatesCampaignSender
    {
        $tpl = new IntegrationsWhatsAppTemplate(array_merge(['name' => 'neue_termine_b', 'language' => 'de', 'status' => 'APPROVED'], $template));
        $tpl->id = 77;
        $channel = (object) ['id' => 9, 'sender_identifier' => '+49100'];
        $holding = new class($tpl, $channel, $resolveError) {
            public function __construct(private $tpl, private $channel, private ?string $err) {}
            public function resolveTemplate(int $teamId, int $templateId): array
            {
                return $this->err !== null
                    ? ['error' => $this->err, 'template' => null, 'channel' => null]
                    : ['error' => null, 'template' => $this->tpl, 'channel' => $this->channel];
            }
        };
        Container::getInstance()->instance(HoldingTemplateSender::class, $holding);

        return new NewDatesCampaignSender(fn (RecApplicant $a) => 'tok' . $a->id);
    }

    private const BUTTON_B = ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Termine ansehen', 'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/interviews/{{1}}']]];

    public function testOhneBodyVariablenNurButtonMitToken(): void
    {
        $a = $this->applicant();
        $sender = $this->sender(['components' => [['type' => 'BODY', 'text' => 'Huhu, es sind neue Termine online!'], self::BUTTON_B]]);

        $r = $sender->send($a, 77, 'B', 'uuid-1', 42);

        $this->assertSame(NewDatesCampaignSender::STATUS_SENT, $r['status']);
        $this->assertCount(1, $this->meta->calls);
        $call = $this->meta->calls[0];
        $this->assertSame('+4917672283401', $call['to']);
        $this->assertSame('neue_termine_b', $call['templateName']);
        $this->assertSame([[
            'type' => 'button', 'sub_type' => 'url', 'index' => 0,
            'parameters' => [['type' => 'text', 'text' => 'tok' . $a->id]],
        ]], $call['components'], 'Kein Body-Component, wenn das Template keine Variablen hat.');

        $log = RecAutoPilotLog::where('rec_applicant_id', $a->id)->where('type', 'campaign_sent')->first();
        $this->assertNotNull($log);
        $this->assertSame('uuid-1', $log->details['campaign']);
        $this->assertSame('B', $log->details['segment']);
        $this->assertSame('neue_termine_b', $log->details['template']);
        $this->assertSame(42, $log->details['sent_by']);
    }

    public function testNameVariableWirdZumVornamen(): void
    {
        $a = $this->applicant();
        $sender = $this->sender(['components' => [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, neue Termine!', 'example' => ['body_text_named_params' => [['param_name' => 'name', 'example' => 'Max']]]],
            self::BUTTON_B,
        ]]);

        $r = $sender->send($a, 77, 'B', 'uuid-2', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_SENT, $r['status']);
        $body = $this->meta->calls[0]['components'][0];
        $this->assertSame('body', $body['type']);
        $this->assertSame('Lea', $body['parameters'][0]['text']);
        $this->assertSame('name', $body['parameters'][0]['parameter_name']);
        $this->assertSame('button', $this->meta->calls[0]['components'][1]['type']);
    }

    public function testOhneTelefonKeinVersandKeinLog(): void
    {
        $a = $this->applicant(phone: false);
        $r = $this->sender(['components' => [self::BUTTON_B]])->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_NO_PHONE, $r['status']);
        $this->assertCount(0, $this->meta->calls);
        $this->assertSame(0, RecAutoPilotLog::count());
    }

    public function testTemplateOhneDynamischenButtonWirdVerweigert(): void
    {
        $a = $this->applicant();
        $r = $this->sender(['components' => [['type' => 'BODY', 'text' => 'x'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://rheingedeck.de/fest']]]]])->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_TEMPLATE_WITHOUT_URL_BUTTON, $r['status']);
        $this->assertCount(0, $this->meta->calls);
    }

    public function testAufloesungsFehlerWirdDurchgereicht(): void
    {
        $a = $this->applicant();
        $r = $this->sender(['components' => [self::BUTTON_B]], 'Kein aktiver WhatsApp-Kanal für den Account.')->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_NOT_CONFIGURED, $r['status']);
        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $r['error']);
    }

    public function testMetaFehlerIstFailedMitErrorLogOhneCampaignLog(): void
    {
        $a = $this->applicant();
        $this->meta->throw = true;
        $r = $this->sender(['components' => [self::BUTTON_B]])->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_FAILED, $r['status']);
        $this->assertStringContainsString('Meta 131026', (string) $r['error']);
        $this->assertSame(0, RecAutoPilotLog::where('type', 'campaign_sent')->count());
        $this->assertSame(1, RecAutoPilotLog::where('type', 'error')->count(), 'Fehler wird als error-Log festgehalten.');
    }

    /**
     * Final-Review: eine Body-Variable ausser dem Vornamen wuerde
     * HoldingTemplateComponents::build() mit dem Meta-Beispieltext fuellen —
     * erfolgreich, ohne Fehler, ohne Logzeile. Der Guard muss das VOR dem
     * Versand abfangen: kein Meta-Call, kein Log (weder campaign_sent noch
     * error).
     */
    public function testFremdeBodyVariableWirdVerweigertOhneVersandOhneLog(): void
    {
        $a = $this->applicant();
        $sender = $this->sender(['components' => [
            ['type' => 'BODY', 'text' => 'Hallo {{name}}, Termin {{termin}}'],
            self::BUTTON_B,
        ]]);

        $r = $sender->send($a, 77, 'B', 'u', null);

        $this->assertSame(NewDatesCampaignSender::STATUS_TEMPLATE_WITH_FOREIGN_VARS, $r['status']);
        $this->assertStringContainsString('termin', (string) $r['error']);
        $this->assertCount(0, $this->meta->calls);
        $this->assertSame(0, RecAutoPilotLog::count());
    }
}
