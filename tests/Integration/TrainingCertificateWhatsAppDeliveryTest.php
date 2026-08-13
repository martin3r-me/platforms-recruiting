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
 *     Der Sender WIRFT dort (nicht: liefert einen Fehler), weil das der Fall
 *     ist, den sendToManys internes catch NICHT deckt — resolveConfig laeuft
 *     davor und fasst die Datenbank an.
 *  2. §D1 — der Link traegt die Zertifikat-uuid, nicht den Applicant-Token:
 *     testVersandTraegtDieZertifikatUuidUndNichtDenApplicantToken. Geprueft
 *     wird die tatsaechlich uebergebene URL, gebaut vom ECHTEN UrlGenerator
 *     gegen die ECHTE routes/public.php.
 *  3. G7/G8 — kein URL-Button, sondern eine Body-Variable: der Versand
 *     uebergibt genau einen namedValue, und der Guard verweigert Templates
 *     ohne diese Variable (testTemplateOhneBodyVariableWirdNichtVersendet).
 *     Die Verklammerung mit dem Builder liegt im Unit-Test
 *     WhatsAppTemplateBodyVariablesTest.
 *
 * DER SENDER-STUB IST DUCK-TYPED, weil HoldingTemplateSender `final` ist —
 * ableiten geht nicht, und ein echter Sender braeuchte den WhatsAppMetaService
 * samt Meta-Zugang. Der Container prueft bei ->instance() keinen Typ, und die
 * Delivery loest den Sender ueber app() auf. Damit die Attrappe nicht
 * unbemerkt von der echten Signatur wegdriftet, vergleicht
 * testStubEntsprichtDerEchtenSenderSignatur beide per Reflection.
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
 * Was WIRKLICH getragen werden muss, ist die Stub-Bindung: sie wird in
 * tearDown() UND tearDownAfterClass() vergessen, weil ein leckender Sender-Stub
 * in der naechsten Klasse wie ein kaputter Service aussieht.
 */
class TrainingCertificateWhatsAppDeliveryTest extends TestCase
{
    /** Team mit Schalter AN und einem Template, das {{zertifikat_link}} hat. */
    private const TEAM = 3;

    /** Team mit Schalter AN, aber ohne konfiguriertes WhatsApp-Template. */
    private const TEAM_OHNE_TEMPLATE = 4;

    /** Team, dessen Template die Body-Variable NICHT hat. */
    private const TEAM_TEMPLATE_OHNE_VARIABLE = 5;

    /** Team, dessen konfigurierte Template-ID auf keine Zeile zeigt. */
    private const TEAM_TEMPLATE_FEHLT = 6;

    private const USER = 42;

    /** Basis-URL des Test-Requests, gegen die der UrlGenerator absolut baut. */
    private const BASIS = 'https://app.example';

    private static int $templateMitVariable = 0;
    private static int $templateOhneVariable = 0;

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
    }

    protected function tearDown(): void
    {
        // Ein leckender Stub sieht in der naechsten Klasse wie ein kaputter
        // Sender aus. Deshalb hier UND im tearDownAfterClass.
        Container::getInstance()->forgetInstance(HoldingTemplateSender::class);
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
    // Falsifikator 2: der Link traegt die uuid, nicht den Token
    // -----------------------------------------------------------------

    /**
     * Geprueft wird der INHALT der uebergebenen URL, nicht dass eine uebergeben
     * wurde. Dazu: der Settings-Key, der Vorname, die Nummer, und dass es
     * GENAU EINEN namedValue gibt (kein Button-Parameter, keine Extras).
     */
    public function testVersandTraegtDieZertifikatUuidUndNichtDenApplicantToken(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $stub = $this->senderStub(['sent' => 1, 'failed' => 0, 'skipped' => 0, 'error' => null, 'template' => 'zert_link']);

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_SENT, $result['status']);
        $this->assertNull($result['error']);

        $this->assertCount(1, $stub->calls, 'Genau ein Send.');
        $call = $stub->calls[0];

        $this->assertSame(self::TEAM, $call['teamId']);
        $this->assertSame('+49 151 1234567', $call['phone']);
        $this->assertSame('Erika', $call['firstName']);
        $this->assertSame(TrainingCertificateWaTemplate::SETTINGS_KEY, $call['settingsKey']);
        $this->assertFalse($call['isAutoReply'], 'Kein Auto-Reply: HR loest den Versand aus.');

        // GENAU EINE Body-Variable, und ihr Wert ist der Link.
        $this->assertSame(
            [TrainingCertificateWaTemplate::BODY_VARIABLE],
            array_keys($call['namedValues'])
        );
        $link = $call['namedValues'][TrainingCertificateWaTemplate::BODY_VARIABLE];

        $this->assertSame(
            self::BASIS . '/recruiting/zertifikat/' . $zertifikat->uuid,
            $link,
            'Die vollstaendige URL, gebaut vom echten UrlGenerator gegen routes/public.php.'
        );
        $this->assertSame($link, $result['link']);

        // Und das Gegenteil, ausdruecklich: der Applicant-Token oeffnet
        // Bewerbungsformular und Vertrags-PDFs. Er darf hier nicht auftauchen —
        // auch nicht zusaetzlich.
        $this->assertNotEmpty($applicant->public_token, 'Sonst prueft die naechste Zeile nichts.');
        $this->assertStringNotContainsString((string) $applicant->public_token, $link);
        $this->assertStringContainsString((string) $zertifikat->uuid, $link);

        // Erfolg wird gestempelt — sonst schickt ein zweiter Durchlauf erneut.
        $this->assertNotNull($zertifikat->refresh()->wa_sent_at);
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
        $stub = $this->senderStub();

        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            'Erika',
            $stub->calls[0]['firstName'],
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
        $stub = $this->senderStub();

        (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame('Erika-Maria', $stub->calls[0]['firstName']);
    }

    // -----------------------------------------------------------------
    // Falsifikator 1: Sendefehler kippt die Ablehnung nicht (§D5)
    // -----------------------------------------------------------------

    /**
     * DER WICHTIGSTE TEST DIESER KLASSE.
     *
     * Ablauf wie im Betrieb: die Ablehnung wird COMMITTET (rejectCase mit
     * Haken, eigene Transaktion, stellt das Zertifikat aus), danach laeuft der
     * Versand — und der Sender WIRFT.
     *
     * Der Wurf ist absichtlich nicht "sent = 0": sendToMany faengt Throwables
     * nur INNERHALB der Empfaenger-Schleife (`:72-74`). Alles davor —
     * resolveConfig mit Settings-, Template-, Account- und Kanal-Query — laeuft
     * ungeschuetzt. Genau dieser Wurf darf die abgeschlossene Ablehnung nicht
     * mehr beruehren.
     *
     * Die Exception wird in eine Variable gefangen und danach geprueft, NICHT
     * per try/fail/catch: PHPUnits AssertionFailedError IST eine
     * \RuntimeException, ein fail() im try landete im eigenen catch und der
     * Test waere gruen, wenn der Wurf ausbleibt.
     */
    public function testSenderWirftAblehnungBleibtCommittetUndWaSentAtLeer(): void
    {
        [$applicant, $case] = $this->fallMitBuchung(self::TEAM);
        $stub = $this->senderStub(null, new \RuntimeException('Meta nicht erreichbar (Test-Stub).'));

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
        $this->assertSame(1, count($stub->calls), 'Der Sender wurde aufgerufen — der Wurf kam aus ihm.');

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
     * Der andere Fehlerweg: der Sender wirft NICHT, sondern meldet den Fehler
     * als Rueckgabewert (so verhaelt sich resolveConfig bei
     * Konfigurationsfehlern und sendToMany bei einem gescheiterten Send).
     * Ergebnis muss dasselbe sein: kein Stempel, eine Meldung mit dem
     * Originalfehler.
     */
    public function testSendefehlerLaesstWaSentAtLeerUndNenntDenGrund(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $zertifikat = $this->zertifikat($applicant);
        $this->senderStub(['sent' => 0, 'failed' => 1, 'skipped' => 0, 'error' => null, 'template' => 'zert_link']);

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('manuell', (string) $result['error']);
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
    }

    /** Konfigurationsfehler kommen als error-String zurueck — er muss bei HR ankommen. */
    public function testKonfigurationsfehlerDesSendersStehtInDerMeldung(): void
    {
        $applicant = $this->bewerber(self::TEAM_TEMPLATE_FEHLT);
        $zertifikat = $this->zertifikat($applicant);
        $stub = $this->senderStub([
            'sent' => 0, 'failed' => 0, 'skipped' => 0,
            'error' => 'Template nicht gefunden oder bei Meta nicht genehmigt.',
            'template' => null,
        ]);

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        // Eine konfigurierte, aber verschwundene Template-Zeile beantwortet der
        // Sender selbst — der Variablen-Guard darf sie nicht als "keine
        // Body-Variable" fehldiagnostizieren.
        $this->assertCount(1, $stub->calls);
        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_FAILED, $result['status']);
        $this->assertStringContainsString('nicht genehmigt', (string) $result['error']);
        $this->assertNull($zertifikat->refresh()->wa_sent_at);
    }

    // -----------------------------------------------------------------
    // Falsifikator 3: Body-Variable, kein Button
    // -----------------------------------------------------------------

    /**
     * Ein Template OHNE {{zertifikat_link}} wird NICHT versendet.
     *
     * Ohne diesen Guard geht die Nachricht raus — mit dem BEISPIELTEXT des
     * Templates an der Stelle der Variablen (HoldingTemplateComponents `:45`),
     * also ohne Link. Der Send gilt als gelungen, wa_sent_at wird gestempelt,
     * und beim abgelehnten Bewerber landet eine Nachricht, die auf nichts zeigt.
     * Das Dropdown im Einstellungs-Modal listet JEDES genehmigte Template, der
     * Fehlgriff ist also einen Klick weit weg.
     */
    public function testTemplateOhneBodyVariableWirdNichtVersendet(): void
    {
        $applicant = $this->bewerber(self::TEAM_TEMPLATE_OHNE_VARIABLE);
        $zertifikat = $this->zertifikat($applicant);
        $stub = $this->senderStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(
            TrainingCertificateWhatsAppDelivery::STATUS_TEMPLATE_WITHOUT_VARIABLE,
            $result['status']
        );
        $this->assertSame([], $stub->calls, 'Kein Send — nicht "Send mit falschem Inhalt".');
        $this->assertNull($zertifikat->refresh()->wa_sent_at);

        $meldung = (string) $result['error'];
        $this->assertStringContainsString(TrainingCertificateWaTemplate::BODY_VARIABLE, $meldung);
        $this->assertStringContainsString('ohne_variable', $meldung, 'Der Template-Name gehoert in die Meldung.');
        $this->assertStringContainsString('manuell', $meldung);
    }

    // -----------------------------------------------------------------
    // Die Wege, die nichts senden
    // -----------------------------------------------------------------

    /** Ohne Zertifikat gibt es keinen Link — und keinen Send. */
    public function testOhneZertifikatWirdNichtGesendet(): void
    {
        $applicant = $this->bewerber(self::TEAM);
        $stub = $this->senderStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NO_CERTIFICATE, $result['status']);
        $this->assertSame([], $stub->calls);
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
        $stub = $this->senderStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NO_CERTIFICATE, $result['status']);
        $this->assertSame([], $stub->calls);
    }

    /** Keine Nummer, kein Send — das PDF geht von Hand raus. */
    public function testOhneTelefonnummerWirdNichtGesendet(): void
    {
        $applicant = $this->bewerber(self::TEAM, mitNummer: false);
        $zertifikat = $this->zertifikat($applicant);
        $stub = $this->senderStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NO_PHONE, $result['status']);
        $this->assertSame([], $stub->calls);
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
        $stub = $this->senderStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_NOT_CONFIGURED, $result['status']);
        $this->assertSame([], $stub->calls);
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
        $stub = $this->senderStub();

        $result = (new TrainingCertificateWhatsAppDelivery())->deliver($applicant);

        $this->assertSame(TrainingCertificateWhatsAppDelivery::STATUS_ALREADY_SENT, $result['status']);
        $this->assertSame([], $stub->calls);
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
     */
    public function testStubEntsprichtDerEchtenSenderSignatur(): void
    {
        $echt = new \ReflectionMethod(HoldingTemplateSender::class, 'sendOne');
        $stub = new \ReflectionMethod($this->senderStub(), 'sendOne');

        $this->assertTrue(
            (new \ReflectionClass(HoldingTemplateSender::class))->isFinal(),
            'Waere der Sender nicht final, gehoerte hier eine Ableitung hin statt einer Attrappe.'
        );

        $namen = fn (\ReflectionMethod $m) => array_map(
            fn (\ReflectionParameter $p) => ($p->getType() ? $p->getType() . ' ' : '') . $p->getName(),
            $m->getParameters()
        );

        $this->assertSame($namen($echt), $namen($stub));
        $this->assertSame(
            (string) $echt->getReturnType(),
            (string) $stub->getReturnType()
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
     * @param  array<string, mixed>|null  $result  Rueckgabe von sendOne
     * @param  \Throwable|null  $wirft  statt zurueckzugeben werfen
     */
    private function senderStub(?array $result = null, ?\Throwable $wirft = null): object
    {
        $stub = new class($result ?? ['sent' => 1, 'failed' => 0, 'skipped' => 0, 'error' => null, 'template' => 'zert_link'], $wirft) {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private array $result, private ?\Throwable $wirft) {}

            public function sendOne(int $teamId, string $phone, string $firstName, string $settingsKey = 'comms_holding_template_id', array $namedValues = [], bool $isAutoReply = false): array
            {
                $this->calls[] = [
                    'teamId' => $teamId,
                    'phone' => $phone,
                    'firstName' => $firstName,
                    'settingsKey' => $settingsKey,
                    'namedValues' => $namedValues,
                    'isAutoReply' => $isAutoReply,
                ];

                if ($this->wirft !== null) {
                    throw $this->wirft;
                }

                return $this->result;
            }
        };

        Container::getInstance()->instance(HoldingTemplateSender::class, $stub);

        return $stub;
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

        self::$templateMitVariable = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-mit-variable',
            'name' => 'zert_link_mit_variable',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Hallo {{name}}, hier ist dein Zertifikat: {{zertifikat_link}}',
                'example' => ['body_text_named_params' => [
                    ['param_name' => 'name', 'example' => 'Max'],
                    ['param_name' => 'zertifikat_link', 'example' => 'https://example.org/beispiel'],
                ]],
            ]],
            'whatsapp_account_id' => $accountId,
            'user_id' => $userId,
        ])->id;

        self::$templateOhneVariable = (int) IntegrationsWhatsAppTemplate::create([
            'external_id' => 'ext-ohne-variable',
            'name' => 'zert_ohne_variable',
            'language' => 'de',
            'status' => 'APPROVED',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Hallo {{name}}, wir kuemmern uns um deine Bewerbung.',
            ]],
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
