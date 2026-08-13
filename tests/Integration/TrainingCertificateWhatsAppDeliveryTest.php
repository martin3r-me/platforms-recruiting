<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Services\Comms\WhatsAppMetaService;
use Platform\Integrations\Models\IntegrationsWhatsAppTemplate;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\IssueTrainingCertificateService;
use Platform\Recruiting\Services\TrainingCertificateWhatsAppDelivery;
use Platform\Recruiting\Support\TrainingCertificateWaTemplate;
use Platform\Recruiting\Support\WhatsAppTemplateUrlButtons;

/**
 * Die WhatsApp-Zustellung des Zertifikat-Links — Weg (a), nach dem Commit.
 *
 * DAS IST DIE STELLE, AN DER EIN FEHLER BEIM ABGELEHNTEN BEWERBER ANKOMMT.
 * Ein falsch verschicktes WhatsApp holt kein Test zurueck; deshalb liegt die
 * ganze Entscheidungslogik in TrainingCertificateWhatsAppDelivery und nicht in
 * der Livewire-Komponente (die im Modul nicht instanziierbar ist). In
 * HrDesk/Index.php stehen nur noch Aufruf und Flash — was dort nicht geprueft
 * werden kann, steht als Sichtpruefungsliste im Task-Report.
 *
 * DIE DREI FALSIFIKATOREN, jeder gegen eine Zusage der Spec:
 *
 *  1. §D5 — der Versand laeuft NACH dem Commit, ein Sendefehler kippt die
 *     Ablehnung nicht: testSenderWirftAblehnungBleibtCommittetUndWaSentAtLeer.
 *     Der Send WIRFT dort (nicht: liefert einen Fehler). Seit dem Umbau auf den
 *     direkten Sendepfad (Spec W1) gibt es kein fremdes catch mehr, das etwas
 *     davon abfaengt — das eigene catch der Delivery traegt es allein.
 *  2. §D1 — der Button-Parameter traegt die Zertifikat-uuid, nicht den
 *     Applicant-Token: testButtonParameterTraegtDieZertifikatUuid. Geprueft
 *     wird der INHALT des gesendeten components-Arrays; die vollstaendige URL
 *     bleibt in der Rueckgabe und wird vom ECHTEN UrlGenerator gegen die ECHTE
 *     routes/public.php gebaut.
 *  3. Der Guard ist die SENDEBEDINGUNG, nicht ein Ausloeser: ohne dynamischen
 *     URL-Button an Position 0 geht NICHTS raus
 *     (testTemplateOhneDynamischenButtonWirdNichtVersendet,
 *     testDynamischerButtonAnFalscherPositionSagtWasZuTunIst). Die
 *     Struktur-Erkennung selbst liegt im Unit-Test
 *     WhatsAppTemplateUrlButtonsTest.
 *
 * ZWEI STUBS, UND SIE TEILEN SICH DIE ARBEIT: der Sender-Stub loest nur noch
 * auf (resolveTarget), der Meta-Stub zeichnet den Send auf. Beide sind
 * duck-typed — HoldingTemplateSender ist `final`, und ein echter
 * WhatsAppMetaService braeuchte Meta-Zugang. Der Container prueft bei
 * ->instance() keinen Typ, und die Delivery loest beide ueber app() auf. Damit
 * die Attrappen nicht unbemerkt von den echten Signaturen wegdriften,
 * vergleichen testStubEntsprichtDerEchtenSenderSignatur und
 * testStubSignaturPasstZuSendTemplate sie per Reflection.
 *
 * DER `channel` IST HIER EIN \stdClass, und das traegt nur, weil der Meta-Stub
 * ihn UNTYPISIERT deklariert — diese Klasse hat kein comms_channels-Schema. Der
 * Typnachweis liegt woanders und ist erbracht:
 * HoldingTemplateSenderResolveTargetTest prueft gegen die echte Migration, dass
 * resolveTarget() einen CommsChannel liefert (Spec H11).
 *
 * PROZESSWEITER ZUSTAND — GEMESSEN, und das Ergebnis ist NICHT das aus
 * HrDeskRejectionCertificateTest: hier ist KEINE der Aufraeumzeilen heute
 * load-bearing. Einzeln entfernt (Model::clearBootedModels() im Setup;
 * Facade::clearResolvedInstances() im Setup; beide im tearDownAfterClass)
 * bleiben gefilterter Lauf UND Gesamtlauf gruen — 710 Tests, 2030 Assertions,
 * 0 Fehler, unveraendert.
 *
 * Der Grund ist die Reihenfolge und nur die: diese Klasse ist alphabetisch die
 * LETZTE in tests/Integration, und ihre Vorgaengerin
 * TrainingCertificateRenderTest raeumt in ihrem eigenen Teardown genau diese
 * beiden Dinge auf. Die Zeilen bleiben trotzdem stehen, weil phpunit.xml
 * ausdruecklich vor zufaelliger Reihenfolge warnt: sie tragen an dem Tag, an
 * dem eine Klasse dazwischenrutscht, die nicht aufraeumt. Sie stehen also als
 * Vorsorge — nicht als Notwendigkeit, und dieser Docblock behauptet das auch
 * nicht.
 *
 * Was WIRKLICH getragen werden muss, sind die drei Bindungen (Sender-Stub,
 * Meta-Stub, Log-Attrappe): sie werden in tearDown() UND tearDownAfterClass()
 * vergessen, weil ein leckender Stub in der naechsten Klasse wie ein kaputter
 * Service aussieht.
 */
class TrainingCertificateWhatsAppDeliveryTest extends TestCase
{
    /** Team mit Schalter AN und einem Template mit dynamischem URL-Button an 0. */
    private const TEAM = 3;

    /** Team mit Schalter AN, aber ohne konfiguriertes WhatsApp-Template. */
    private const TEAM_OHNE_TEMPLATE = 4;

    /** Team, dessen Template KEINEN dynamischen URL-Button hat. */
    private const TEAM_TEMPLATE_OHNE_VARIABLE = 5;

    /** Team, dessen konfigurierte Template-ID auf keine Zeile zeigt. */
    private const TEAM_TEMPLATE_FEHLT = 6;

    /** Team, dessen Template den URL-Button an Position 1 statt 0 hat. */
    private const TEAM_BUTTON_FALSCHE_POSITION = 7;

    private const USER = 42;

    /** Basis-URL des Test-Requests, gegen die der UrlGenerator absolut baut. */
    private const BASIS = 'https://app.example';

    private static int $templateMitVariable = 0;
    private static int $templateOhneVariable = 0;
    private static int $templateButtonPositionEins = 0;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        // LogsActivity (CrmContact) verlangt config(); Events leer = keine Hooks.
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher feuern die creating-Hooks nicht (uuid, public_token) —
        // das echte Schema verlangt sie als NOT NULL.
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Model::clearBootedModels();

        // CrmContactLink::creating ruft auth()->check() — Stub ohne User.
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        self::bindEchtenUrlGenerator($container);
        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(HoldingTemplateSender::class);
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstance('log');
    }

    /**
     * Die Log-Attrappe steht schon hier, nicht erst im Test, der sie liest.
     *
     * Grund: fast jeder Fehlerzweig der Delivery schreibt eine Logzeile (Spec
     * W8). Ohne Bindung versuchte der Container, `log` als Klassennamen
     * aufzuloesen, und der Test scheiterte an einer ReflectionException statt an
     * seiner Sache. testDieUrsachenStehenUnterscheidbarImLog bindet sich seine
     * eigene, frische Attrappe.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logStub();
    }

    protected function tearDown(): void
    {
        // Ein leckender Stub sieht in der naechsten Klasse wie ein kaputter
        // Service aus. Deshalb hier UND im tearDownAfterClass — und fuer alle
        // drei Bindungen, nicht nur fuer den Sender.
        Container::getInstance()->forgetInstance(HoldingTemplateSender::class);
        Container::getInstance()->forgetInstance(WhatsAppMetaService::class);
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstance('log');
        parent::tearDown();
    }

    /**
     * Der ECHTE UrlGenerator gegen die ECHTE routes/public.php — kein
     * route()-Stub.
     *
     * Warum das die Muehe wert ist: eine Attrappe wuerde die URL bauen, die der
     * Test erwartet, und ein falscher Parametername (der naheliegende Fehler
     * dieses Tasks: {token} statt {uuid}) waere darin unsichtbar. Der echte
     * Generator wirft bei einem fehlenden Pflichtparameter.
     *
     * Das 'recruiting'-Praefix kommt in der Host-App aus
     * RecruitingServiceProvider::boot() (Route::prefix('recruiting') um
     * loadRoutesFrom(routes/public.php)) und wird hier nachgebaut, damit die
     * geprueften Links denen im Betrieb entsprechen. Route-Name, URI und
     * Parametername selbst sind zusaetzlich in
     * TrainingCertificatePublicRouteTest festgenagelt.
     */
    private static function bindEchtenUrlGenerator(Container $container): void
    {
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);

        $router->prefix('recruiting')->group(function () {
            // require, nicht require_once: die Datei registriert Routen und
            // muss in diesem Prozess einmal wirklich laufen.
            require dirname(__DIR__, 2) . '/routes/public.php';
        });

        // PFLICHT: ->name() laeuft nach dem Hinzufuegen zur Sammlung, die
        // Namensliste ist vorher leer. Ohne diesen Aufruf liefert getByName()
        // fuer JEDE Route null (gemessen in TrainingCertificatePublicRouteTest).
        $router->getRoutes()->refreshNameLookups();

        $container->instance('url', new UrlGenerator(
            $router->getRoutes(),
            Request::create(self::BASIS)
        ));
    }

    // -----------------------------------------------------------------
    // Falsifikator 2: der Button traegt die uuid, nicht den Token
    // -----------------------------------------------------------------

    /**
     * T-3 — DER BELASTBARE TEIL DER TOKEN-ABSICHERUNG.
     *
     * Geprueft wird der INHALT des Button-Parameters: die Zertifikat-uuid, und
     * NICHT die vollstaendige URL und NICHT der Applicant-Token. Die Basis-URL
     * steht bei Meta (Spec H2, im Meta-Manager gemessen) — steht hier eine URL,
     * verschickt Meta einen doppelten Pfad.
     */
    public function testButtonParameterTraegtDieZertifikatUuid(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $sender = $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_SENT, $result['status']);
        $this->assertNull($result['error']);
        $this->assertCount(1, $meta->calls, 'Genau ein Send.');

        $call = $meta->calls[0];
        $this->assertSame('+49 151 1234567', $call['to']);
        $this->assertSame('zert_link_mit_button', $call['templateName']);
        $this->assertSame('de', $call['languageCode']);
        $this->assertFalse($call['isAutoReply'], 'Kein Auto-Reply: HR loest den Versand aus.');

        $buttons = array_values(array_filter(
            $call['components'],
            fn (array $c) => ($c['type'] ?? '') === 'button'
        ));

        $this->assertCount(1, $buttons, 'Genau ein Button-Component.');
        $this->assertSame('url', $buttons[0]['sub_type']);
        $this->assertSame(0, $buttons[0]['index']);
        $this->assertSame(
            [['type' => 'text', 'text' => $zertifikat->uuid]],
            $buttons[0]['parameters'],
            'Nur die uuid — die Basis-URL steht bei Meta.'
        );

        // Und das Gegenteil, ausdruecklich: im gesendeten components-Array steht
        // weder der Applicant-Token noch eine vollstaendige URL.
        //
        // ASSERTIONSZIEL IST GENAU $call['components'] — NICHT der ganze
        // mitgeschnittene Aufruf. Der Stub zeichnet auch 'channel' und
        // 'templateName' auf, und das Fixture-Template traegt in seiner
        // Button-URL ein https://…/recruiting/zertifikat/{{1}}: eine
        // Serialisierung des ganzen Calls wuerde an dieser URL scheitern und
        // damit eine Zusage pruefen, die niemand gemacht hat. Was hier zaehlt,
        // ist der Payload an Meta.
        $payload = (string) json_encode($call['components']);
        $this->assertNotEmpty($applicant->public_token, 'Sonst prueft die naechste Zeile nichts.');
        $this->assertStringNotContainsString((string) $applicant->public_token, $payload);
        $this->assertStringNotContainsString('https://', $payload);

        // Der Link bleibt in der Rueckgabe — HR braucht ihn fuer den Versand
        // von Hand, und die Fehlerzweige geben ihn mit.
        $this->assertSame(self::BASIS . '/recruiting/zertifikat/' . $zertifikat->uuid, $result['link']);

        $this->assertSame(
            [['teamId' => self::TEAM, 'settingsKey' => TrainingCertificateWaTemplate::SETTINGS_KEY]],
            $sender->resolveCalls,
            'Aufgeloest wird genau einmal, mit dem Zertifikat-Settings-Key.'
        );

        // Erfolg wird gestempelt — sonst schickt ein zweiter Durchlauf erneut.
        $this->assertNotNull($zertifikat->refresh()->wa_sent_at);
    }

    /**
     * T-5 — die Anrede geht nicht verloren.
     *
     * Der Body wird weiter von HoldingTemplateComponents::build() gebaut (Spec
     * W3: aufrufen, nicht erweitern). Der naheliegende Umbaufehler ist, beim
     * Wechsel auf den direkten Send nur noch den Button zu schicken.
     */
    public function testBodyMitVornameUndButtonGehenZusammenRaus(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $components = $meta->calls[0]['components'];
        $this->assertSame('body', $components[0]['type'], 'Body zuerst, dann der Button.');
        $this->assertSame(
            [['type' => 'text', 'text' => 'Erika', 'parameter_name' => 'name']],
            $components[0]['parameters']
        );
        $this->assertSame('button', $components[1]['type']);
        $this->assertCount(2, $components);
    }

    /**
     * Der Vorname ist der Name des Kontakts mit der KLEINSTEN contact_id —
     * derselbe, der auf dem Zertifikat steht (IssueTrainingCertificateService
     * ::contactOf, Spec F11). crmContactLinks ist ein morphMany ohne Ordering;
     * mit ->first() haette die Anrede in der Nachricht einen anderen Namen
     * tragen koennen als das Dokument, auf das sie verlinkt.
     */
    public function testVornameKommtVomKontaktMitDerKleinstenIdWieAufDemZertifikat(): void
    {
        $applicant = RecApplicant::create([
            'team_id' => self::TEAM,
            'is_active' => true,
            'is_on_hr_desk' => true,
            'auto_pilot' => false,
        ]);

        $erika = CrmContact::create([
            'team_id' => self::TEAM, 'is_active' => true,
            'first_name' => 'Erika', 'last_name' => 'Mustermann',
        ]);
        $spaeter = CrmContact::create([
            'team_id' => self::TEAM, 'is_active' => true,
            'first_name' => 'Zweitname', 'last_name' => 'Spaeter',
        ]);
        $erika->phoneNumbers()->create([
            'raw_input' => '0151 1234567',
            'international' => '+49 151 1234567',
            'is_primary' => true,
            'is_active' => true,
            'phone_type_id' => 1,
        ]);

        // DIE VERKNUEPFUNGEN IN UMGEKEHRTER REIHENFOLGE, und das ist die
        // Bedingung dafuer, dass dieser Test ueberhaupt etwas beweist: der
        // Kontakt mit der GROESSEREN contact_id wird ZUERST verknuepft. Damit
        // unterscheiden sich ->first() (Einfuegereihenfolge der Verknuepfung,
        // faktisch das, was SQLite ohne ORDER BY liefert) und
        // orderBy('contact_id'). Verknuepft man in natuerlicher Reihenfolge,
        // liefern beide Wege denselben Namen und ein ->first() im Code bliebe
        // gruen.
        $applicant->crmContactLinks()->create(['contact_id' => $spaeter->id, 'team_id' => self::TEAM]);
        $applicant->crmContactLinks()->create(['contact_id' => $erika->id, 'team_id' => self::TEAM]);

        $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            'Erika',
            $this->anredeAusSend($meta),
            'Der Name auf dem Zertifikat kommt aus derselben Wahl (kleinste contact_id) — '
            . 'die Anrede der Nachricht darf keinen anderen Namen tragen.'
        );
    }

    /**
     * Der selbst eingetragene Vorname aus dem Bewerbungsformular
     * (Extra-Field 'vorname') gewinnt ueber den CRM-Kontakt — dasselbe Muster
     * wie die Jugendschutz-Absage (HrDesk/Index.php).
     */
    public function testExtraFieldVornameGewinntUeberDenKontakt(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $applicant->setExtraField('vorname', 'Erika-Maria');
        $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame('Erika-Maria', $this->anredeAusSend($meta));
    }

    // -----------------------------------------------------------------
    // Falsifikator 1: Sendefehler kippt die Ablehnung nicht (§D5)
    // -----------------------------------------------------------------

    /**
     * DER WICHTIGSTE TEST DIESER KLASSE.
     *
     * Ablauf wie im Betrieb: die Ablehnung wird COMMITTET (rejectCase mit
     * Haken, eigene Transaktion, stellt das Zertifikat aus), danach laeuft der
     * Versand — und sendTemplate() WIRFT.
     *
     * Seit dem Umbau auf den direkten Sendepfad (Spec W1) gibt es kein fremdes
     * catch mehr: sendToMany fing Throwables innerhalb seiner
     * Empfaenger-Schleife (`:72-74`), diese Bremse ist weg. Das eigene catch der
     * Delivery traegt den ganzen Send allein — genau dieser Wurf darf die
     * abgeschlossene Ablehnung nicht mehr beruehren.
     *
     * Die Exception wird in eine Variable gefangen und danach geprueft, NICHT
     * per try/fail/catch: PHPUnits AssertionFailedError IST eine
     * \RuntimeException, ein fail() im try landete im eigenen catch und der
     * Test waere gruen, wenn der Wurf ausbleibt.
     */
    public function testSenderWirftAblehnungBleibtCommittetUndWaSentAtLeer(): void
    {
        [$applicant, $case] = $this->fallMitBuchung(self::TEAM);
        $this->senderStub();
        $meta = $this->metaStub(new \RuntimeException('Meta nicht erreichbar (Test-Stub).'));

        // Der Commit.
        (new HrDeskRoutingService())->rejectCase($case, self::USER, 'Papiere reichen nicht.', true);

        $zertifikat = RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->first();
        $this->assertNotNull($zertifikat, 'Vorbedingung: die Ablehnung hat ausgestellt.');

        $gefangen = null;
        $result = null;
        try {
            $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);
        } catch (\Throwable $e) {
            $gefangen = $e;
        }

        $this->assertNull(
            $gefangen,
            'Der Versand ist die Zugabe: er darf nach einer committeten Ablehnung nicht '
            . 'nach oben werfen. Sonst bleibt das Modal offen und HR sieht keine Meldung.'
        );
        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('Meta nicht erreichbar', (string) $result['error']);
        $this->assertStringContainsString('manuell', (string) $result['error']);
        $this->assertSame(1, count($meta->calls), 'sendTemplate wurde aufgerufen — der Wurf kam aus ihm.');

        // DIE ABLEHNUNG STEHT.
        $frisch = RecHrDeskCase::find($case->id);
        $this->assertSame(RecHrDeskCase::STATUS_REJECTED, $frisch->status);
        $this->assertNotNull($frisch->resolved_at);

        $applicant->refresh();
        $this->assertNotNull($applicant->rejected_at);
        $this->assertFalse((bool) $applicant->is_active);
        $this->assertFalse((bool) $applicant->is_on_hr_desk);

        // Das Zertifikat auch — nur der Zeitstempel bleibt leer, damit HR sieht,
        // dass von Hand zugestellt werden muss.
        $this->assertSame(1, RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->count());
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
    }

    /**
     * T-6 — leerer Pflicht-Parameter geht nicht an Meta.
     *
     * Im Sender war das ein stiller `skipped` (HoldingTemplateSender:56-59).
     * Der direkte Pfad hat diese Bremse nicht geerbt und braucht sie
     * ausdruecklich: Meta lehnt leere Pflicht-Parameter ab (131008), und ein
     * garantiert scheiternder Send ist kein Send, den man absetzt.
     */
    public function testLeererVornameFuehrtZuFailedOhneSend(): void
    {
        $applicant = $this->bewerberOhneVornamen(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertSame([], $meta->calls);
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
        $this->assertStringContainsString('herunterladen und manuell senden', (string) $result['error']);
    }

    /**
     * Der andere Fehlerweg: der Send wirft NICHT, sondern die Aufloesung meldet
     * den Fehler als Rueckgabewert (so verhaelt sich resolveConfig bei
     * Konfigurationsfehlern). Ergebnis muss dasselbe sein: kein Stempel, kein
     * Send, eine Meldung mit dem Originalfehler.
     */
    public function testSendefehlerLaesstWaSentAtLeerUndNenntDenGrund(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub(new \RuntimeException('Meta 131026'));

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString(
            'Meta 131026',
            (string) $result['error'],
            'Der Originalfehler von Meta gehoert in die Meldung — sonst weiss HR nicht, was los war.'
        );
        $this->assertStringContainsString('manuell', (string) $result['error']);
        $this->assertCount(1, $meta->calls, 'Versucht wurde es.');
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
    }

    /**
     * Konfigurationsfehler kommen als error-String aus resolveTarget() zurueck —
     * er muss bei HR ankommen.
     *
     * Das Fixture-Team zeigt auf eine Template-ID ohne Zeile; der Sender-Stub
     * loest genauso auf wie das Original und meldet dessen Text
     * (HoldingTemplateSender:143).
     */
    public function testKonfigurationsfehlerDesSendersStehtInDerMeldung(): void
    {
        $applicant = $this->bewerber(self::TEAM_TEMPLATE_FEHLT);
        $zertifikat = $this->zertifikat($applicant);
        $sender = $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        // Eine konfigurierte, aber verschwundene Template-Zeile beantwortet die
        // Aufloesung — der Button-Guard darf sie nicht als "kein URL-Button"
        // fehldiagnostizieren.
        $this->assertCount(1, $sender->resolveCalls);
        $this->assertSame([], $meta->calls);
        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('nicht genehmigt', (string) $result['error']);
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
    }

    // -----------------------------------------------------------------
    // Falsifikator 3: der Guard ist die Sendebedingung
    // -----------------------------------------------------------------

    /**
     * T-2a — kein dynamischer URL-Button: es geht NICHTS raus.
     *
     * Der statische URL-Button im Fixture ist der Punkt: fuenf der sieben
     * Erkennungsstellen im Modul halten ihn fuer fuellbar (Spec H1). Dieser
     * Guard nicht.
     */
    public function testTemplateOhneDynamischenButtonWirdNichtVersendet(): void
    {
        $applicant = $this->bewerber(self::TEAM_TEMPLATE_OHNE_VARIABLE);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            TrainingCertificateWhatsAppDelivery::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
            $result['status']
        );
        $this->assertSame([], $meta->calls, 'Es darf NICHTS rausgehen.');
        $this->assertNull($zertifikat->fresh()->wa_sent_at);

        $meldung = (string) $result['error'];
        $this->assertStringContainsString('zert_ohne_button', $meldung, 'Der Vorlagenname gehoert in die Meldung.');
        $this->assertStringContainsString('URL ohne Variable', $meldung, 'Was gefunden wurde, mit Position.');
        $this->assertStringContainsString('herunterladen und manuell senden', $meldung);
    }

    /**
     * T-2b — Button vorhanden, aber an Position 1.
     *
     * DIE MELDUNG MUSS DIE RICHTIGE ANWEISUNG SAGEN. „Kein URL-Button
     * gefunden" waere hier schlicht falsch und schickt HR in die Suche nach
     * einem Button, den es gibt (Spec W5).
     */
    public function testDynamischerButtonAnFalscherPositionSagtWasZuTunIst(): void
    {
        $applicant = $this->bewerber(self::TEAM_BUTTON_FALSCHE_POSITION);
        $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        // Vorbedingung des Fixtures, ausgesprochen: der dynamische Button sitzt
        // wirklich an 1. Ohne diese Zeile koennte das Fixture stillschweigend zu
        // einem zweiten "gar kein Button"-Fall verkommen.
        $this->assertSame(
            [1],
            WhatsAppTemplateUrlButtons::dynamicIndexes(
                IntegrationsWhatsAppTemplate::find(self::$templateButtonPositionEins)->components
            )
        );

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            TrainingCertificateWhatsAppDelivery::STATUS_TEMPLATE_WITHOUT_URL_BUTTON,
            $result['status']
        );
        $this->assertSame([], $meta->calls);

        $meldung = (string) $result['error'];
        $this->assertStringContainsString('erste Position', $meldung);
        $this->assertStringNotContainsString(
            'keinen URL-Button',
            $meldung,
            'Es gibt einen — er steht nur an der falschen Stelle.'
        );
    }

    /**
     * T-9 — die Ursachen sind im Log unterscheidbar.
     *
     * Geprueft an zwei der vier: Aufloesungsfehler und Wurf beim Send. Wenn
     * diese beiden verschiedene Marker tragen, tragen die anderen zwei es auch
     * — sie stehen im selben Muster, und der Test soll die Bauart festnageln,
     * nicht vier Zeilen abschreiben.
     */
    public function testDieUrsachenStehenUnterscheidbarImLog(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $this->zertifikat($applicant);
        $log = $this->logStub();

        $this->senderStub('WhatsApp-Account nicht aktiv.');
        $this->metaStub();
        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $applicantZwei = $this->bewerber(self::TEAM);
        $this->zertifikat($applicantZwei);
        $this->senderStub();
        $this->metaStub(new \RuntimeException('Meta 131026'));
        (new TrainingCertificateWhatsAppDelivery())->deliver($applicantZwei);

        $this->assertCount(2, $log->lines);
        $this->assertNotSame(
            $log->lines[0]['message'],
            $log->lines[1]['message'],
            'Zwei Ursachen, zwei Meldungen — sonst ist das Log beim Nachsehen wertlos.'
        );
        foreach ($log->lines as $zeile) {
            $this->assertStringContainsString('[TrainingCertificateWhatsAppDelivery]', $zeile['message']);
        }
    }

    // -----------------------------------------------------------------
    // Die Wege, die nichts senden
    // -----------------------------------------------------------------

    /** Ohne Zertifikat gibt es keinen Link — und keinen Send. */
    public function testOhneZertifikatWirdNichtGesendet(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NO_CERTIFICATE, $result['status']);
        $this->assertSame([], $meta->calls);
        $this->assertNull($result['link']);
        // Nicht stumm: der uebergangene Haken (z.B. Team-Schalter aus) muss bei
        // HR ankommen, sonst glaubt sie an eine Zustellung.
        $this->assertNotNull($result['error']);
    }

    /**
     * Ein Zertifikat einer ANDEREN Schulungsart ist nicht dieses Zertifikat.
     * Die Dedup-Dimension der Tabelle ist (Bewerber, Art) — ohne kind-Filter
     * verlinkte der Versand das falsche Dokument, sobald es eine zweite
     * Schulungsart gibt.
     */
    public function testAndereSchulungsartWirdNichtVerschickt(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        RecTrainingCertificate::create([
            'team_id' => self::TEAM,
            'rec_applicant_id' => $applicant->id,
            'kind' => 'irgendeine-andere-art',
            'personalized_content' => '<p>Andere Art</p>',
            'issued_at' => '2026-08-12 09:00:00',
        ]);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NO_CERTIFICATE, $result['status']);
        $this->assertSame([], $meta->calls);
    }

    /** Keine Nummer, kein Send — das PDF geht von Hand raus. */
    public function testOhneTelefonnummerWirdNichtGesendet(): void
    {
        $applicant = $this->bewerber(self::TEAM, mitNummer: false);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NO_PHONE, $result['status']);
        $this->assertSame([], $meta->calls);
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
        $this->assertStringContainsString('manuell', (string) $result['error']);
    }

    /**
     * Ohne konfiguriertes Template wird nichts versendet — aber die Meldung
     * nennt DIESE Einstellung.
     *
     * Warum eigener Zweig und nicht "der Sender sagt es schon": resolveConfig
     * beantwortet jeden Settings-Key mit dem Text "Kein
     * Eingangsbestaetigungs-Template konfiguriert (Einstellungen →
     * Kommunikation)". Bei diesem Aufruf schickte das HR in die falsche
     * Einstellungsseite.
     */
    public function testOhneKonfiguriertesTemplateWirdNichtGesendetAberGemeldet(): void
    {
        $applicant = $this->bewerber(self::TEAM_OHNE_TEMPLATE);
        $zertifikat = $this->zertifikat($applicant);
        $sender = $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NOT_CONFIGURED, $result['status']);
        $this->assertSame([], $meta->calls);
        $this->assertSame(
            [],
            $sender->resolveCalls,
            'Der eigene Zweig liegt VOR der Aufloesung — sonst nennt die Meldung die falsche Einstellung.'
        );
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
        $this->assertStringContainsString('Schulungszertifikat', (string) $result['error']);
        $this->assertStringContainsString('manuell', (string) $result['error']);
    }

    /**
     * ZWEIMAL ZUSTELLEN IST EIN FEHLER, den nur der Bewerber sieht.
     *
     * Der Fall ist erreichbar: ein Bewerber kann zwei offene HR-Faelle haben
     * (HrDeskRejectionCertificateTest::testZweiteAblehnungLegtKeinZweites
     * Zertifikat an belegt genau diesen Weg), und die zweite Ablehnung mit
     * Haken bekommt dasselbe Zertifikat zurueck. Ohne diesen Guard ginge die
     * Nachricht ein zweites Mal raus.
     *
     * Der Guard haengt an wa_sent_at, nicht an einem Zaehler: ein
     * FEHLGESCHLAGENER Versand laesst das Feld leer und bleibt damit
     * wiederholbar — das ist der Wiederholungsweg fuer HR.
     */
    public function testBereitsZugestelltesZertifikatGehtNichtNochmalRaus(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $zertifikat->update(['wa_sent_at' => '2026-08-12 08:00:00']);
        $this->senderStub();
        $meta = $this->metaStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_ALREADY_SENT, $result['status']);
        $this->assertSame([], $meta->calls);
        $this->assertNull($result['error'], 'Kein Fehler — es ist ja zugestellt.');
        $this->assertSame(
            '2026-08-12 08:00:00',
            $zertifikat->refresh()->wa_sent_at->format('Y-m-d H:i:s'),
            'Der Zeitstempel der ersten Zustellung bleibt stehen.'
        );
    }

    // -----------------------------------------------------------------
    // Die Attrappe gegen das Original
    // -----------------------------------------------------------------

    /**
     * Der Stub ist duck-typed (HoldingTemplateSender ist `final`). Diese
     * Assertion ist der Ersatz fuer den Compiler: driftet die echte Signatur,
     * wird dieser Test rot statt die ganze Klasse still an einer Attrappe
     * vorbeizulaufen, die das Original nicht mehr abbildet.
     *
     * GEPRUEFT WERDEN BEIDE METHODEN: resolveTarget, weil die Delivery sie ab
     * jetzt benutzt, UND sendOne, weil der Stub sie als FALLE behaelt — ein
     * Aufruf dort wirft. Driftet sendOne, waere die Falle keine mehr.
     */
    public function testStubEntsprichtDerEchtenSenderSignatur(): void
    {
        $stubObjekt = $this->senderStub();

        $this->assertTrue(
            (new \ReflectionClass(HoldingTemplateSender::class))->isFinal(),
            'Waere der Sender nicht final, gehoerte hier eine Ableitung hin statt einer Attrappe.'
        );

        $namen = fn (\ReflectionMethod $m) => array_map(
            fn (\ReflectionParameter $p) => ($p->getType() ? $p->getType() . ' ' : '') . $p->getName(),
            $m->getParameters()
        );

        foreach (['resolveTarget', 'sendOne'] as $methode) {
            $echt = new \ReflectionMethod(HoldingTemplateSender::class, $methode);
            $stub = new \ReflectionMethod($stubObjekt, $methode);

            $this->assertSame($namen($echt), $namen($stub), $methode . ': Parameter driften nicht.');
            $this->assertSame(
                (string) $echt->getReturnType(),
                (string) $stub->getReturnType(),
                $methode . ': Rueckgabetyp driftet nicht.'
            );
        }
    }

    /**
     * Die Attrappe darf nicht von der echten Signatur wegdriften — sonst
     * behauptet der Test einen Aufruf, den es so nicht gibt.
     *
     * VERGLICHEN WERDEN NUR PARAMETER, nicht der Rueckgabetyp: die echte
     * Methode liefert ein CommsWhatsAppMessage-Model, das hier ohne
     * comms-Tabellen nicht entstehen kann. Dass der Rueckgabewert nicht benutzt
     * wird, ist eine Zusage der Delivery (kein addContext, Spec W6) — sie
     * stempelt nach dem Send nur wa_sent_at.
     */
    public function testStubSignaturPasstZuSendTemplate(): void
    {
        $echt = new \ReflectionMethod(WhatsAppMetaService::class, 'sendTemplate');
        $stub = new \ReflectionMethod($this->metaStub(), 'sendTemplate');

        $this->assertSame(
            array_map(fn ($p) => $p->getName(), $echt->getParameters()),
            array_map(fn ($p) => $p->getName(), $stub->getParameters()),
            'Gleiche Parameternamen in gleicher Reihenfolge — die Delivery ruft benannt auf.'
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Bewerber mit Kontakt "Erika Mustermann" und (optional) einer aktiven
     * Mobilnummer.
     */
    private function bewerber(int $teamId, bool $mitNummer = true): RecApplicant
    {
        $applicant = RecApplicant::create([
            'team_id' => $teamId,
            'is_active' => true,
            'is_on_hr_desk' => true,
            'auto_pilot' => false,
        ]);

        $contact = CrmContact::create([
            'team_id' => $teamId,
            'is_active' => true,
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
        ]);

        if ($mitNummer) {
            $contact->phoneNumbers()->create([
                'raw_input' => '0151 1234567',
                'international' => '+49 151 1234567',
                'national' => '0151 1234567',
                'is_primary' => true,
                'is_active' => true,
                // NOT NULL im echten Schema (FK auf crm_phone_types).
                'phone_type_id' => 1,
            ]);
        }

        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => $teamId]);

        return $applicant;
    }

    /**
     * Bewerber MIT Nummer, aber ohne aufloesbaren Vornamen — fuer T-6.
     *
     * Weder Extra-Field 'vorname' noch ein Kontakt-Vorname: firstName() gibt ''
     * zurueck, und HoldingTemplateComponents::build() macht daraus einen
     * Pflicht-Parameter mit leerem Text (Meta 131008).
     */
    private function bewerberOhneVornamen(int $teamId): RecApplicant
    {
        $applicant = RecApplicant::create([
            'team_id' => $teamId,
            'is_active' => true,
            'is_on_hr_desk' => true,
            'auto_pilot' => false,
        ]);

        $contact = CrmContact::create([
            'team_id' => $teamId,
            'is_active' => true,
            'first_name' => '',
            'last_name' => 'Ohnename',
        ]);
        $contact->phoneNumbers()->create([
            'raw_input' => '0151 1234567',
            'international' => '+49 151 1234567',
            'national' => '0151 1234567',
            'is_primary' => true,
            'is_active' => true,
            'phone_type_id' => 1,
        ]);

        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => $teamId]);

        return $applicant;
    }

    private function zertifikat(RecApplicant $applicant): RecTrainingCertificate
    {
        return RecTrainingCertificate::create([
            'team_id' => (int) $applicant->team_id,
            'rec_applicant_id' => $applicant->id,
            'kind' => RecTrainingCertificate::KIND_SERVICE_BASIS,
            'personalized_content' => '<p>Erika Mustermann</p>',
            'issued_at' => '2026-08-12 09:00:00',
            'issued_by_user_id' => self::USER,
        ]);
    }

    /**
     * Bewerber mit attended-Buchung und offenem HR-Fall — so, dass die
     * Ablehnung mit Haken wirklich ausstellt.
     *
     * @return array{0: RecApplicant, 1: RecHrDeskCase}
     */
    private function fallMitBuchung(int $teamId): array
    {
        $applicant = $this->bewerber($teamId);

        $interview = RecInterview::create([
            'team_id' => $teamId,
            'title' => 'Service-Basisschulung',
            'starts_at' => '2026-07-24 14:00:00',
        ]);
        $interview->interviewers()->attach($this->user('Anna Bergmann'));
        RecInterviewBooking::create([
            'rec_interview_id' => $interview->id,
            'rec_applicant_id' => $applicant->id,
            'team_id' => $teamId,
            'status' => 'attended',
        ]);

        $case = RecHrDeskCase::create([
            'rec_applicant_id' => $applicant->id,
            'team_id' => $teamId,
            'reason' => RecHrDeskCase::REASON_NON_EU_CITIZEN,
            'status' => RecHrDeskCase::STATUS_OPEN,
            'opened_at' => '2026-08-01 10:00:00',
        ]);

        return [$applicant, $case];
    }

    private function user(string $name): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . uniqid() . '@example.org',
        ]);
    }

    /**
     * Die Attrappe des Senders, im Container hinterlegt.
     *
     * SIE LOEST AUF UND SENDET NICHT MEHR. Nach dem Umbau (Spec W1) will die
     * Delivery vom Sender nur noch Template und Kanal; verschickt wird ueber die
     * Meta-Attrappe.
     *
     * @param  ?string  $fehler  Aufloesungsfehler statt Template + Kanal
     */
    private function senderStub(?string $fehler = null): object
    {
        $stub = new class($fehler) {
            /** @var list<array{teamId: int, settingsKey: string}> */
            public array $resolveCalls = [];

            public function __construct(private ?string $fehler) {}

            /**
             * Was die Delivery ab jetzt vom Sender will: Template und Kanal.
             *
             * Das Template kommt aus der ECHTEN Zeile — aufgeloest wie im
             * Original (Settings-Key -> Template-ID -> Zeile), damit der Guard
             * eine echte components-Struktur zu lesen bekommt und jedes
             * Fixture-Team sein eigenes Template mitbringt.
             */
            public function resolveTarget(int $teamId, string $settingsKey = 'comms_holding_template_id'): array
            {
                $this->resolveCalls[] = ['teamId' => $teamId, 'settingsKey' => $settingsKey];

                if ($this->fehler !== null) {
                    return ['error' => $this->fehler, 'template' => null, 'channel' => null];
                }

                $templateId = (int) (RecApplicantSettings::getOrCreateForTeam($teamId)
                    ->getSetting($settingsKey) ?? 0);
                $template = IntegrationsWhatsAppTemplate::find($templateId);

                if ($template === null) {
                    // Woertlich wie HoldingTemplateSender:143 — der Stub loest
                    // auf, also meldet er auch dessen Text.
                    return [
                        'error' => 'Template nicht gefunden oder bei Meta nicht genehmigt.',
                        'template' => null,
                        'channel' => null,
                    ];
                }

                return [
                    'error' => null,
                    'template' => $template,
                    // Der Kanal wird nur durchgereicht — die Attrappe des
                    // Meta-Service hat keinen Typ darauf (Spec W1).
                    'channel' => new \stdClass(),
                ];
            }

            /**
             * FALLE, absichtlich: nach dem Umbau darf die Delivery nicht mehr
             * ueber den Sender verschicken. Ein Aufruf hier ist ein
             * unvollstaendiger Umbau, kein Testfehler.
             */
            public function sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array
            {
                throw new \LogicException(
                    'Der Zertifikat-Versand darf nicht mehr ueber HoldingTemplateSender::sendOne laufen '
                    . '(Spec W1: eigener Sendepfad mit WhatsAppMetaService::sendTemplate).'
                );
            }
        };

        Container::getInstance()->instance(HoldingTemplateSender::class, $stub);

        return $stub;
    }

    /**
     * Attrappe fuer WhatsAppMetaService::sendTemplate.
     *
     * DUCK-TYPED wie der Sender-Stub: der Container prueft bei ->instance()
     * keinen Typ, und die Delivery loest den Service ueber app() auf. Ein
     * echter Service braeuchte Meta-Zugang.
     *
     * KEIN Rueckgabetyp deklariert, und das ist Absicht: die echte Methode
     * liefert ein CommsWhatsAppMessage-Model, das hier ohne comms-Tabellen
     * nicht entstehen kann. Der Rueckgabewert wird von der Delivery nicht
     * benutzt (kein addContext, Spec W6/Q3) — sie stempelt nur wa_sent_at.
     * Genau das haelt testStubSignaturPasstZuSendTemplate fest.
     */
    private function metaStub(?\Throwable $wirft = null): object
    {
        $stub = new class($wirft) {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private ?\Throwable $wirft) {}

            public function sendTemplate(
                $channel,
                string $to,
                string $templateName,
                array $components = [],
                string $languageCode = 'de',
                $sender = null,
                bool $isAutoReply = false,
            ) {
                $this->calls[] = [
                    'channel' => $channel,
                    'to' => $to,
                    'templateName' => $templateName,
                    'components' => $components,
                    'languageCode' => $languageCode,
                    'sender' => $sender,
                    'isAutoReply' => $isAutoReply,
                ];

                if ($this->wirft !== null) {
                    throw $this->wirft;
                }

                return new \stdClass();
            }
        };

        Container::getInstance()->instance(WhatsAppMetaService::class, $stub);

        return $stub;
    }

    /**
     * Log-Attrappe: sammelt Stufe, Nachricht und Kontext.
     *
     * WOZU: vier verschiedene Ursachen fallen auf denselben Statuswert `failed`
     * (Spec W8/B2). Der Statuswert unterscheidet sie nicht, die HR-Meldung
     * schon, und das Log muss es auch — sonst steht beim Nachsehen eine Zeile
     * ohne Unterscheidung.
     */
    private function logStub(): object
    {
        $stub = new class {
            /** @var list<array{level: string, message: string, context: array}> */
            public array $lines = [];

            public function error($message, array $context = []): void
            {
                $this->lines[] = ['level' => 'error', 'message' => (string) $message, 'context' => $context];
            }

            public function warning($message, array $context = []): void
            {
                $this->lines[] = ['level' => 'warning', 'message' => (string) $message, 'context' => $context];
            }

            public function __call($method, $args) {}
        };

        Container::getInstance()->instance('log', $stub);

        // PFLICHT: die Log-Facade merkt sich die aufgeloeste Instanz. Ohne diese
        // Zeile schreibt der zweite Test des Prozesses weiter in die Attrappe
        // des ersten — und der Log-Test saehe null Zeilen.
        Facade::clearResolvedInstance('log');

        return $stub;
    }

    /** Der Vorname, wie er als Body-Parameter an Meta gegangen ist. */
    private function anredeAusSend(object $meta): string
    {
        return (string) $meta->calls[0]['components'][0]['parameters'][0]['text'];
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    /**
     * Schema aus den ECHTEN Migrationen (Muster
     * HrDeskRejectionCertificateTest): fuer diese Tabellen gibt es in
     * tests/Support/TestSchema keine Methode, und zwei Schema-Quellen in einer
     * Klasse zu mischen waere genau die Drift, gegen die TestSchema gebaut ist.
     */
    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(User::class);
        $crm = self::packageRootOf(CrmContact::class);
        $integrations = self::packageRootOf(IntegrationsWhatsAppTemplate::class);
        $own = dirname(__DIR__, 2);

        $files = [
            [$core, 'database/migrations/0001_01_01_000000_create_users_table.php'],
            // Extra-Field-Definitionen/-Werte: getExtraField('vorname') fragt
            // sie bei JEDEM Versand. Ohne die Tabellen waere der Vorname-Zweig
            // nicht durchlaufbar.
            [$core, 'database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php'],
            [$core, 'database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php'],
            [$core, 'database/migrations/2026_02_08_120000_add_is_mandatory_to_core_extra_field_definitions_table.php'],
            [$core, 'database/migrations/2026_02_12_000001_add_llm_verification_to_extra_fields.php'],
            [$core, 'database/migrations/2026_02_12_000002_add_auto_fill_to_extra_fields.php'],
            [$core, 'database/migrations/2026_02_16_000001_add_visibility_config_to_extra_field_definitions.php'],
            [$core, 'database/migrations/2026_03_19_000001_add_description_to_core_extra_field_definitions_table.php'],
            [$crm, 'database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php'],
            [$crm, 'database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php'],
            [$integrations, 'database/migrations/2026_01_17_150000_create_integrations_whatsapp_accounts_table.php'],
            [$integrations, 'database/migrations/2026_02_12_000001_create_integrations_whatsapp_templates_table.php'],
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_02_09_000007_create_rec_auto_pilot_logs_table.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_04_14_000001_create_rec_interview_tables.php'],
            [$own, 'database/migrations/2026_04_24_000001_add_hr_desk_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_04_24_000003_create_rec_hr_desk_cases_table.php'],
            [$own, 'database/migrations/2026_07_24_000001_add_seat_released_at_to_rec_interview_bookings.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }

        $userId = (int) Capsule::table('users')->insertGetId([
            'name' => 'Template-Eigner',
            'email' => 'template.eigner@example.org',
        ]);
        $accountId = (int) Capsule::table('integrations_whatsapp_accounts')->insertGetId([
            'uuid' => 'acc-' . uniqid(),
            'phone_number' => '+49 100 0000000',
            'title' => 'Test-Account',
            'active' => true,
            'user_id' => $userId,
        ]);

        // Das richtig gebaute Template: Anrede im Body, dynamischer URL-Button
        // an Position 0. Die Basis-URL steht bei Meta (Spec H2) — hier steht
        // sie nur, damit die Struktur echt aussieht; geprueft wird der
        // Parameter, nicht die URL.
        self::$templateMitVariable = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-mit-button',
            'name' => 'zert_link_mit_button',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => 'Hallo {{name}}, dein Zertifikat liegt bereit.',
                    'example' => ['body_text_named_params' => [
                        ['param_name' => 'name', 'example' => 'Max'],
                    ]],
                ],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Zertifikat oeffnen',
                     'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}'],
                ]],
            ],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;

        // Der Guard-Fall: gar kein dynamischer Button. Der statische URL-Button
        // ist absichtlich drin — er ist genau der, den fuenf der sieben
        // Erkennungsstellen im Modul fuer fuellbar halten (Spec H1).
        self::$templateOhneVariable = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-ohne-button',
            'name' => 'zert_ohne_button',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hallo {{name}}, wir kuemmern uns.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'URL', 'text' => 'Website', 'url' => 'https://mitarbeiter.rheingedeck.de/karriere'],
                ]],
            ],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;

        // Der zweite Guard-Fall, und der mit der anderen Anweisung: Button
        // vorhanden, nur an Position 1.
        self::$templateButtonPositionEins = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-button-pos-1',
            'name' => 'zert_button_pos_1',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hallo {{name}}.'],
                ['type' => 'BUTTONS', 'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Danke'],
                    ['type' => 'URL', 'text' => 'Zertifikat',
                     'url' => 'https://mitarbeiter.rheingedeck.de/recruiting/zertifikat/{{1}}'],
                ]],
            ],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => [
                IssueTrainingCertificateService::SETTING_ENABLED => true,
                TrainingCertificateWaTemplate::SETTINGS_KEY => self::$templateMitVariable,
            ],
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM_OHNE_TEMPLATE,
            'settings' => [IssueTrainingCertificateService::SETTING_ENABLED => true],
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM_TEMPLATE_OHNE_VARIABLE,
            'settings' => [
                IssueTrainingCertificateService::SETTING_ENABLED => true,
                TrainingCertificateWaTemplate::SETTINGS_KEY => self::$templateOhneVariable,
            ],
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM_TEMPLATE_FEHLT,
            'settings' => [
                IssueTrainingCertificateService::SETTING_ENABLED => true,
                // Zeigt bewusst auf keine Zeile: dieselbe Lage wie ein bei Meta
                // geloeschtes Template.
                TrainingCertificateWaTemplate::SETTINGS_KEY => 999999,
            ],
        ]);
        RecApplicantSettings::create([
            'team_id' => self::TEAM_BUTTON_FALSCHE_POSITION,
            'settings' => [
                IssueTrainingCertificateService::SETTING_ENABLED => true,
                TrainingCertificateWaTemplate::SETTINGS_KEY => self::$templateButtonPositionEins,
            ],
        ]);

        CoreExtraFieldDefinition::create([
            'team_id' => self::TEAM,
            'context_type' => RecApplicant::class,
            'context_id' => null,
            'name' => 'vorname',
            'label' => 'Vorname',
            'type' => 'text',
            'order' => 0,
        ]);
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException(
            'Paketwurzel nicht gefunden: von ' . $class . ' aufwaerts liegt kein '
            . 'Verzeichnis mit database/migrations.'
        );
    }
}
