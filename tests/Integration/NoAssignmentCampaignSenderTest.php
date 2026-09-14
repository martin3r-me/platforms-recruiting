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
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignSender;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;

/**
 * Sammelversand „ohne Einsatz" (Clara, 14.09.2026): EIN Template mit hoechstens
 * dem Vornamen als Variable an EINE Person. Kein Link, kein Token — deshalb die
 * schlanke Schwester von NewDatesCampaignSender statt einer Erweiterung: dessen
 * SENDEBEDINGUNG ist der dynamische URL-Button, und genau den hat dieses
 * Template nicht.
 *
 * Zwei Attrappen wie nebenan (HoldingTemplateSender ist final, ein echter
 * WhatsAppMetaService braeuchte Meta-Zugang), beide ueber den Container.
 */
final class NoAssignmentCampaignSenderTest extends TestCase
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
        Capsule::table('crm_contacts')->insert(['id' => 500, 'first_name' => 'Paulina', 'last_name' => 'Marenin']);
        Capsule::table('crm_contact_links')->insert(['contact_id' => 500, 'linkable_id' => $a->id, 'linkable_type' => $this->applicantMorph]);
        if ($phone) {
            Capsule::table('crm_phone_numbers')->insert(['phoneable_type' => $this->contactMorph, 'phoneable_id' => 500, 'raw_input' => '0176', 'international' => '+4917672283401', 'is_active' => true, 'is_primary' => true]);
        }
        return $a->fresh();
    }

    /** @param array<string,mixed> $template */
    private function sender(array $template, ?string $resolveError = null): NoAssignmentCampaignSender
    {
        $tpl = new IntegrationsWhatsAppTemplate(array_merge(['name' => 'rueckmeldung_start', 'language' => 'de', 'status' => 'APPROVED'], $template));
        $tpl->id = 88;
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

        return new NoAssignmentCampaignSender();
    }

    private const BODY_NAME = ['type' => 'BODY', 'text' => 'Hallo {{name}}, wir haben nichts mehr von dir gehört.'];

    public function testNameVariableWirdZumVornamenUndDasLogHaeltDieSchulungFest(): void
    {
        $a = $this->applicant();

        $r = $this->sender(['components' => [self::BODY_NAME]])->send($a, 88, 57, 'uuid-1', 42);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_SENT, $r['status']);
        $this->assertCount(1, $this->meta->calls);
        $call = $this->meta->calls[0];
        $this->assertSame('+4917672283401', $call['to']);
        $this->assertSame('rueckmeldung_start', $call['templateName']);
        $this->assertSame('body', $call['components'][0]['type']);
        $this->assertSame('Paulina', $call['components'][0]['parameters'][0]['text']);
        $this->assertCount(1, $call['components'], 'Kein Button-Component — dieses Template traegt keinen Link.');

        $log = RecAutoPilotLog::where('rec_applicant_id', $a->id)->where('type', NoAssignmentCampaignSender::LOG_TYPE)->first();
        $this->assertNotNull($log);
        $this->assertSame('uuid-1', $log->details['campaign']);
        $this->assertSame(57, $log->details['interview_id']);
        $this->assertSame('rueckmeldung_start', $log->details['template']);
        $this->assertSame(42, $log->details['sent_by']);
    }

    public function testTemplateOhneVariableSendetOhneKomponenten(): void
    {
        $a = $this->applicant();

        $r = $this->sender(['components' => [['type' => 'BODY', 'text' => 'Melde dich bitte kurz bei uns.']]])->send($a, 88, 57, 'uuid-2', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_SENT, $r['status']);
        $this->assertSame([], $this->meta->calls[0]['components']);
    }

    public function testOhneTelefonKeinVersandKeinLog(): void
    {
        $a = $this->applicant(phone: false);

        $r = $this->sender(['components' => [self::BODY_NAME]])->send($a, 88, 57, 'u', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_NO_PHONE, $r['status']);
        $this->assertCount(0, $this->meta->calls);
        $this->assertSame(0, RecAutoPilotLog::count());
    }

    /**
     * Wie beim Schwester-Sender: jede Body-Variable ausser dem Vornamen wuerde
     * HoldingTemplateComponents::build() mit dem MUSTER-Text aus dem
     * Meta-Beispiel fuellen — erfolgreich, ohne Fehler, ohne Logzeile.
     */
    public function testFremdeBodyVariableWirdVerweigertOhneVersand(): void
    {
        $a = $this->applicant();

        $r = $this->sender(['components' => [['type' => 'BODY', 'text' => 'Hallo {{name}}, am {{termin}} war deine Schulung.']]])
            ->send($a, 88, 57, 'u', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_TEMPLATE_WITH_FOREIGN_VARS, $r['status']);
        $this->assertStringContainsString('termin', (string) $r['error']);
        $this->assertCount(0, $this->meta->calls);
        $this->assertSame(0, RecAutoPilotLog::count());
    }

    /**
     * Ein dynamischer URL-Button braucht einen Parameter, den dieser Sender
     * nicht hat (kein Token, kein Link). Meta lehnt den Send sonst ab oder —
     * schlimmer — schickt einen Button ins Leere. Also vorher stoppen.
     */
    public function testTemplateMitDynamischemButtonWirdVerweigert(): void
    {
        $a = $this->applicant();

        $r = $this->sender(['components' => [
            self::BODY_NAME,
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Öffnen', 'url' => 'https://mitarbeiter.rheingedeck.de/form/{{1}}']]],
        ]])->send($a, 88, 57, 'u', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_TEMPLATE_WITH_DYNAMIC_BUTTON, $r['status']);
        $this->assertCount(0, $this->meta->calls);
        $this->assertSame(0, RecAutoPilotLog::count());
    }

    public function testStatischerButtonIstErlaubt(): void
    {
        $a = $this->applicant();

        $r = $this->sender(['components' => [
            self::BODY_NAME,
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Zur Website', 'url' => 'https://rheingedeck.de']]],
        ]])->send($a, 88, 57, 'u', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_SENT, $r['status']);
        $this->assertCount(1, $this->meta->calls);
    }

    public function testAufloesungsFehlerWirdDurchgereicht(): void
    {
        $a = $this->applicant();

        $r = $this->sender(['components' => [self::BODY_NAME]], 'Kein aktiver WhatsApp-Kanal für den Account.')
            ->send($a, 88, 57, 'u', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_NOT_CONFIGURED, $r['status']);
        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $r['error']);
    }

    /**
     * VORABPRUEFUNG (14.09.2026): die drei Ablehnungsgruende, die am TEMPLATE
     * haengen und nicht an der Person, sind auch ohne Empfaenger beantwortbar.
     * Das Modal fragt sie einmal beim Klick — sonst laeuft ein Job los, der
     * jedem Einzelnen dieselbe Fehlerzeile zurueckgibt.
     *
     * Dieselbe Methode benutzt send() selbst; der Waechter im Sendepfad wird
     * dadurch nicht ersetzt, sondern nur vorgezogen (die Guard-Tests oben
     * bleiben deshalb bestehen).
     */
    public function testCheckTemplateMeldetDieTemplateFehlerOhneZuSenden(): void
    {
        $fremdeVariable = $this->sender(['components' => [['type' => 'BODY', 'text' => 'Hallo {{name}}, am {{termin}}.']]])
            ->checkTemplate(3, 88);
        $this->assertStringContainsString('termin', (string) $fremdeVariable);

        $dynamischerButton = $this->sender(['components' => [
            self::BODY_NAME,
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Öffnen', 'url' => 'https://x.de/{{1}}']]],
        ]])->checkTemplate(3, 88);
        $this->assertStringContainsString('URL-Button', (string) $dynamischerButton);

        $nichtKonfiguriert = $this->sender(['components' => [self::BODY_NAME]], 'Kein aktiver WhatsApp-Kanal für den Account.')
            ->checkTemplate(3, 88);
        $this->assertSame('Kein aktiver WhatsApp-Kanal für den Account.', $nichtKonfiguriert);

        $this->assertCount(0, $this->meta->calls, 'Die Vorabprüfung sendet nichts.');
        $this->assertSame(0, RecAutoPilotLog::count(), 'Und schreibt nichts ins Log.');
    }

    public function testCheckTemplateSchweigtBeimPassendenTemplate(): void
    {
        $this->assertNull($this->sender(['components' => [self::BODY_NAME]])->checkTemplate(3, 88));
        $this->assertNull(
            $this->sender(['components' => [
                self::BODY_NAME,
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'Website', 'url' => 'https://rheingedeck.de']]],
            ]])->checkTemplate(3, 88),
            'Ein statischer Button ist kein Grund zur Ablehnung.',
        );
    }

    public function testMetaFehlerIstFailedMitErrorLogOhneKampagnenLog(): void
    {
        $a = $this->applicant();
        $this->meta->throw = true;

        $r = $this->sender(['components' => [self::BODY_NAME]])->send($a, 88, 57, 'u', null);

        $this->assertSame(NoAssignmentCampaignSender::STATUS_FAILED, $r['status']);
        $this->assertStringContainsString('Meta 131026', (string) $r['error']);
        $this->assertSame(0, RecAutoPilotLog::where('type', NoAssignmentCampaignSender::LOG_TYPE)->count());
        $this->assertSame(1, RecAutoPilotLog::where('type', 'error')->count());
    }
}
