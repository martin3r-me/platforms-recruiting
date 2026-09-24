<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Console\Commands\SendProofReminders;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Der Fristenlauf — die heikelste Stelle des Nachweise-Pakets. Zwei Fehler aus
 * dem Bestand duerfen sich hier nicht wiederholen:
 *
 *  1. RecEmployee::sendPortalNotification() stempelt einen von Meta
 *     ABGELEHNTEN Versand als Erfolg. test_von_meta_abgelehnter_versand_...
 *     und test_werfender_versand_... halten fest: reminded_at wird
 *     AUSSCHLIESSLICH nach einem sendTemplate() mit status !== 'failed'
 *     gesetzt.
 *  2. sendManualTemplate haengt einen Formular-Token an JEDEN URL-Knopf (Fall
 *     Theo Wirtz). test_der_knopf_traegt_nur_den_portal_token haelt fest,
 *     dass der Button-Parameter exakt der Portal-Token ist — nichts
 *     concateniert, nichts Fremdes.
 *
 * Template + Kanal sind ueber HoldingTemplateSender gestubbt (die Klasse ist
 * final, echter Meta-Zugang wird hier nicht gebraucht) — Muster
 * NoAssignmentCampaignSenderTest. WhatsAppMetaService ist ebenfalls
 * duck-typed gestubbt und zeichnet jeden Aufruf auf.
 */
final class SendProofRemindersTest extends TestCase
{
    private Capsule $capsule;
    private string $employeeMorph;
    private string $contactMorph;
    private ProofReminderMetaFake $meta;

    private const BODY = ['type' => 'BODY', 'text' => 'Hallo {{name}}, dein Nachweis laeuft bald ab.'];
    private const BUTTON = [
        'type' => 'BUTTONS',
        'buttons' => [['type' => 'URL', 'text' => 'Jetzt hochladen', 'url' => 'https://mitarbeiter.example/portal/{{1}}']],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

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

        $this->employeeMorph = (new RecEmployee())->getMorphClass();
        $this->contactMorph = (new CrmContact())->getMorphClass();

        $this->createSchema();

        $this->meta = new ProofReminderMetaFake();
        $container->instance(WhatsAppMetaService::class, $this->meta);
        $container->instance(HoldingTemplateSender::class, $this->holdingStub());
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Container::getInstance()->forgetInstance(HoldingTemplateSender::class);
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $s = $this->capsule->schema();

        $s->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $s->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64);
            $t->integer('team_id')->nullable();
            $t->integer('rec_employee_id');
            $t->string('person_key', 64)->nullable();
            $t->string('proof_type_code', 40);
            $t->integer('file_id')->nullable();
            $t->integer('file_back_id')->nullable();
            $t->date('valid_until')->nullable();
            $t->integer('version')->default(1);
            $t->timestamp('superseded_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->integer('confirmed_by_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('uploaded_via', 20)->default('employee');
            $t->integer('uploaded_by_user_id')->nullable();
            $t->timestamps();
        });

        $s->create('crm_contact_links', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('contact_id');
            $t->integer('company_id')->nullable();
            $t->integer('linkable_id');
            $t->string('linkable_type');
            $t->integer('team_id')->nullable();
            $t->integer('created_by_user_id')->nullable();
            $t->timestamps();
        });

        $s->create('crm_contacts', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('middle_name')->nullable();
            $t->string('nickname')->nullable();
            $t->timestamps();
        });

        $s->create('crm_phone_numbers', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('phoneable_type')->nullable();
            $t->integer('phoneable_id')->nullable();
            $t->string('raw_input')->nullable();
            $t->string('international')->nullable();
            $t->string('national')->nullable();
            $t->string('country_code')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_primary')->default(false);
            $t->timestamps();
        });

        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('type', 30);
            $t->text('summary')->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function ma(array $attr = []): RecEmployee
    {
        $ma = RecEmployee::create(array_merge([
            'uuid'            => 'ma-uuid-' . bin2hex(random_bytes(6)),
            'team_id'         => 10,
            'first_name'      => 'Kevin',
            'last_name'       => 'Muster',
            'portal_token'    => 'PORTAL-TOKEN-' . bin2hex(random_bytes(6)),
            'portal_v2_since' => '2026-09-20 08:00:00',
            'is_active'       => true,
        ], $attr));

        // Telefonnummer aus der (in jedem Test bei 1 startenden) rec_employees-id
        // abgeleitet — deterministisch je Test, ohne Kollisionsrisiko durch einen
        // methoden-statischen Zaehler, der ueber Testmethoden hinweg persistieren
        // wuerde (PHP-Funktions-Statics sind an die Methode gebunden, nicht an
        // die Testinstanz).
        $contactId = Capsule::table('crm_contacts')->insertGetId([
            'first_name' => $ma->first_name, 'last_name' => $ma->last_name,
        ]);
        Capsule::table('crm_contact_links')->insert([
            'contact_id' => $contactId, 'linkable_id' => $ma->id, 'linkable_type' => $this->employeeMorph,
        ]);
        Capsule::table('crm_phone_numbers')->insert([
            'phoneable_type' => $this->contactMorph, 'phoneable_id' => $contactId,
            'international' => $this->phoneFor($ma->id),
            'is_active' => true, 'is_primary' => true,
        ]);

        return $ma->fresh();
    }

    private function phoneFor(int $employeeId): string
    {
        return '+49176' . str_pad((string) $employeeId, 6, '0', STR_PAD_LEFT);
    }

    private function proof(RecEmployee $ma, string $code, string $validUntil, ?string $remindedAt = null): int
    {
        return (int) Capsule::table('rec_employee_proofs')->insertGetId([
            'uuid'             => 'proof-' . bin2hex(random_bytes(8)),
            'team_id'          => $ma->team_id,
            'rec_employee_id'  => $ma->id,
            'proof_type_code'  => $code,
            'valid_until'      => $validUntil,
            'reminded_at'      => $remindedAt,
            'superseded_at'    => null,
            'version'          => 1,
            'uploaded_via'     => 'employee',
        ]);
    }

    private function remindedAtOf(int $proofId): ?string
    {
        $row = Capsule::table('rec_employee_proofs')->find($proofId);

        return $row->reminded_at ?? null;
    }

    /** @param array<string, mixed> $templateOverrides */
    private function holdingStub(?string $error = null, array $templateOverrides = []): object
    {
        $tpl = new IntegrationsWhatsAppTemplate(array_merge([
            'name' => 'nachweis_erinnerung', 'language' => 'de', 'status' => 'APPROVED',
            'components' => [self::BODY, self::BUTTON],
        ], $templateOverrides));
        $tpl->id = 501;
        $channel = (object) ['id' => 77, 'sender_identifier' => '+49160000'];

        return new class($tpl, $channel, $error) {
            /** @var list<array{teamId:int, settingsKey:string}> */
            public array $calls = [];

            public function __construct(private $tpl, private $channel, private ?string $err) {}

            public function resolveTarget(int $teamId, string $settingsKey): array
            {
                $this->calls[] = ['teamId' => $teamId, 'settingsKey' => $settingsKey];

                return $this->err !== null
                    ? ['error' => $this->err, 'template' => null, 'channel' => null]
                    : ['error' => null, 'template' => $this->tpl, 'channel' => $this->channel];
            }
        };
    }

    /** @return array{0: int, 1: string} [exitCode, komplette Konsolenausgabe] */
    private function runCommand(array $options = []): array
    {
        $command = new SendProofReminders();
        $command->setLaravel(new ProofReminderFakeLaravel());

        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        return [$exitCode, $output->fetch()];
    }

    // -----------------------------------------------------------------
    // Pflicht 1: Trockenlauf schreibt nichts
    // -----------------------------------------------------------------

    public function test_trockenlauf_schreibt_nichts_und_sendet_nichts(): void
    {
        $ma = $this->ma();
        $proofId = $this->proof($ma, 'ausweis', now()->addDays(10)->toDateString());

        [$exitCode] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(SendProofReminders::SUCCESS, $exitCode);
        $this->assertNull($this->remindedAtOf($proofId));
        $this->assertSame([], $this->meta->calls, 'Trockenlauf darf sendTemplate() nicht aufrufen.');
    }

    // -----------------------------------------------------------------
    // Pflicht 2: gescheiterter Versand setzt keinen Marker
    // -----------------------------------------------------------------

    public function test_von_meta_abgelehnter_versand_setzt_keinen_marker(): void
    {
        // Der teuerste Fehler: sendTemplate() KEHRT NORMAL ZURUECK (kein
        // Wurf), aber $message->status ist 'failed' — genau das Muster von
        // RecEmployee::sendPortalNotification, das den Fehler bisher
        // verschluckt.
        $this->meta->status = 'failed';

        $ma = $this->ma();
        $proofId = $this->proof($ma, 'ausweis', now()->addDays(10)->toDateString());

        [$exitCode, $ausgabe] = $this->runCommand();

        $this->assertSame(SendProofReminders::SUCCESS, $exitCode);
        $this->assertCount(1, $this->meta->calls, 'Der Versand wurde versucht.');
        $this->assertNull($this->remindedAtOf($proofId), 'Ein abgelehnter Versand darf reminded_at NICHT setzen.');
        $this->assertStringContainsString('failed', $ausgabe);
    }

    public function test_werfender_versand_setzt_ebenfalls_keinen_marker(): void
    {
        // Ergaenzung zum Pflichtfall: sendTemplate() wirft (z.B. Netzwerk),
        // statt einen Fehlerstatus zurueckzugeben — zweiter Fehlerpfad im
        // Sender (catch-Block), derselbe Vertrag.
        $this->meta->throw = true;

        $ma = $this->ma();
        $proofId = $this->proof($ma, 'ausweis', now()->addDays(10)->toDateString());

        [$exitCode] = $this->runCommand();

        $this->assertSame(SendProofReminders::SUCCESS, $exitCode);
        $this->assertNull($this->remindedAtOf($proofId));
    }

    // -----------------------------------------------------------------
    // Pflicht 3: erfolgreicher Versand setzt den Marker genau einmal
    // -----------------------------------------------------------------

    public function test_erfolgreicher_versand_setzt_den_marker_genau_einmal(): void
    {
        $ma = $this->ma();
        $proofId = $this->proof($ma, 'ausweis', now()->addDays(10)->toDateString());

        [$exitCode1] = $this->runCommand();
        $this->assertSame(SendProofReminders::SUCCESS, $exitCode1);
        $this->assertCount(1, $this->meta->calls);
        $ersterStempel = $this->remindedAtOf($proofId);
        $this->assertNotNull($ersterStempel);

        // Zweiter Lauf (z.B. der naechste Tag): plan() schliesst den Nachweis
        // jetzt aus (reminded_at gesetzt) — keine zweite Nachricht.
        [$exitCode2] = $this->runCommand();
        $this->assertSame(SendProofReminders::SUCCESS, $exitCode2);
        $this->assertCount(1, $this->meta->calls, 'Der zweite Lauf darf keine weitere WhatsApp verschicken.');
        $this->assertSame($ersterStempel, $this->remindedAtOf($proofId));
    }

    // -----------------------------------------------------------------
    // Pflicht 4: der Knopf traegt nur den Portal-Token
    // -----------------------------------------------------------------

    public function test_der_knopf_traegt_nur_den_portal_token(): void
    {
        $ma = $this->ma(['portal_token' => 'REINER-PORTAL-TOKEN']);
        $this->proof($ma, 'ausweis', now()->addDays(10)->toDateString());

        $this->runCommand();

        $this->assertCount(1, $this->meta->calls);
        $components = $this->meta->calls[0]['components'];

        $buttonComponents = array_values(array_filter($components, fn ($c) => ($c['type'] ?? null) === 'button'));
        $this->assertCount(1, $buttonComponents, 'Genau ein Button-Parameter fuer den einen dynamischen URL-Knopf.');

        // Kein Formular-Token, kein Fremd-Parameter, keine Verkettung — Fall
        // Theo Wirtz. Der Parameter ist EXAKT der Portal-Token.
        $this->assertSame(
            ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'REINER-PORTAL-TOKEN']]],
            $buttonComponents[0]
        );
    }

    // -----------------------------------------------------------------
    // Welches Portal der Knopf oeffnet: nur portal_v2_since, per Default
    // -----------------------------------------------------------------

    public function test_ohne_neues_portal_wird_standardmaessig_uebersprungen(): void
    {
        $altesPortal = $this->ma(['portal_v2_since' => null]);
        $this->proof($altesPortal, 'ausweis', now()->addDays(10)->toDateString());

        $neuesPortal = $this->ma(['portal_v2_since' => '2026-09-20 08:00:00']);
        $this->proof($neuesPortal, 'ausweis', now()->addDays(10)->toDateString());

        $this->runCommand();

        $this->assertCount(1, $this->meta->calls, 'Nur der Mitarbeiter mit portal_v2_since bekommt eine Nachricht.');
        $this->assertSame($this->phoneFor($neuesPortal->id), $this->meta->calls[0]['to']);
    }

    public function test_auch_altes_portal_hebt_das_ueberspringen_auf(): void
    {
        $altesPortal = $this->ma(['portal_v2_since' => null]);
        $this->proof($altesPortal, 'ausweis', now()->addDays(10)->toDateString());

        $this->runCommand(['--auch-altes-portal' => true]);

        $this->assertCount(1, $this->meta->calls);
    }

    // -----------------------------------------------------------------
    // Genau eine WhatsApp je Person, auch bei zwei faelligen Nachweisen
    // -----------------------------------------------------------------

    public function test_zwei_faellige_nachweise_ergeben_nur_eine_whatsapp(): void
    {
        $ma = $this->ma();
        // Dringender (naeher an heute) muss gewinnen.
        $dringend = $this->proof($ma, 'ausweis', now()->addDays(3)->toDateString());
        $spaeter = $this->proof($ma, 'aufenthaltstitel', now()->addDays(20)->toDateString());

        $this->runCommand();

        $this->assertCount(1, $this->meta->calls, 'Je Person genau eine WhatsApp — auch bei zwei faelligen Nachweisen.');
        $this->assertNotNull($this->remindedAtOf($dringend), 'Der dringendere Nachweis bekommt die Erinnerung.');
        $this->assertNull($this->remindedAtOf($spaeter), 'Der zweite Nachweis bleibt fuer den naechsten Lauf offen.');
    }

    // -----------------------------------------------------------------
    // Team-Filter
    // -----------------------------------------------------------------

    public function test_team_filter_grenzt_auf_das_team_ein(): void
    {
        $team10 = $this->ma(['team_id' => 10]);
        $this->proof($team10, 'ausweis', now()->addDays(10)->toDateString());

        $team20 = $this->ma(['team_id' => 20]);
        $this->proof($team20, 'ausweis', now()->addDays(10)->toDateString());

        $this->runCommand(['--team' => '10']);

        $this->assertCount(1, $this->meta->calls);
    }

    // -----------------------------------------------------------------
    // Stichtag wird durchgereicht (Bremse gegen die Altbestands-Welle)
    // -----------------------------------------------------------------

    public function test_stichtag_wird_an_den_planer_durchgereicht(): void
    {
        $stichtag = now()->addDays(3)->toDateString();

        $vorDemStichtag = $this->ma();
        $this->proof($vorDemStichtag, 'ausweis', now()->addDays(2)->toDateString());

        $abDemStichtag = $this->ma();
        $this->proof($abDemStichtag, 'ausweis', now()->addDays(4)->toDateString());

        $this->runCommand(['--stichtag' => $stichtag]);

        $this->assertCount(1, $this->meta->calls, 'Nur die Frist ab dem Stichtag darf erinnert werden.');
        $this->assertSame($this->phoneFor($abDemStichtag->id), $this->meta->calls[0]['to']);
    }

    // -----------------------------------------------------------------
    // --limit
    // -----------------------------------------------------------------

    public function test_limit_nimmt_die_dringendsten_zuerst(): void
    {
        $dringend = $this->ma();
        $this->proof($dringend, 'ausweis', now()->addDays(1)->toDateString());

        $weniger_dringend = $this->ma();
        $this->proof($weniger_dringend, 'ausweis', now()->addDays(15)->toDateString());

        $this->runCommand(['--limit' => '1']);

        $this->assertCount(1, $this->meta->calls);
        $this->assertSame($this->phoneFor($dringend->id), $this->meta->calls[0]['to'], 'Der dringendste Fall gewinnt bei --limit.');
    }
}

/**
 * Duck-typed WhatsAppMetaService-Attrappe: zeichnet jeden Aufruf auf, kann
 * werfen oder einen von Meta ABGELEHNTEN Status simulieren (status='failed'
 * bei normaler Rueckkehr — genau das Muster des Bestandsfehlers).
 */
final class ProofReminderMetaFake
{
    /** @var list<array{to:string, templateName:string, components:array, languageCode:string}> */
    public array $calls = [];
    public bool $throw = false;
    public string $status = 'sent';

    public function sendTemplate($channel, string $to, string $templateName, array $components = [], string $languageCode = 'de', $sender = null, bool $isAutoReply = false): object
    {
        if ($this->throw) {
            throw new \RuntimeException('Meta-Verbindung fehlgeschlagen (Test)');
        }

        $this->calls[] = compact('to', 'templateName', 'components', 'languageCode');

        return (object) [
            'id' => count($this->calls),
            'status' => $this->status,
            'meta_payload' => $this->status === 'failed' ? ['error' => ['message' => 'Meta 131026 (Test)']] : [],
            'thread' => null,
        ];
    }
}

/**
 * Minimaler Ersatz fuer die volle Laravel-Application (Muster
 * ArchiveOldConversationsFakeLaravel): Illuminate\Console\Command::run()
 * braucht runningUnitTests(), sonst nichts — das Modul bootet in
 * Integrationstests bewusst keine volle Application.
 */
final class ProofReminderFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
