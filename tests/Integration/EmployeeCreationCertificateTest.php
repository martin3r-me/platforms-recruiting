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
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\CreateEmployeeFromApplicantService;
use Platform\Recruiting\Services\IssueTrainingCertificateService;

/**
 * Weg (b): das Zertifikat entsteht bei der Mitarbeiter-Anlage, ohne dass jemand
 * etwas anhakt — und vor allem: die Mitarbeiter-Anlage haengt nicht daran.
 *
 * DIE ZWEI TESTS, UM DIE ES GEHT:
 *
 *  - testSchalterAusLaesstDieAnlageBisAufEinenQuerySoWieHeute ist der
 *    Charakterisierungs-Test. Dieser Task haengt einen Hook in einen
 *    BESTEHENDEN Ablauf; ein Eingriff, der im Normalfall etwas verschiebt,
 *    faellt niemandem auf, weil Mitarbeiter ja weiter angelegt werden. Er
 *    prueft deshalb das QUERY-PROTOKOLL und nicht nur den Zustand danach.
 *  - testAusstellungsfehlerLaesstDenMitarbeiterStehen ist der Falsifikator der
 *    Entscheidung "Ausstellung HINTER dem Commit": ein Mitarbeiter ohne
 *    Zertifikat ist ein legitimer Zustand, keine Mitarbeiter-Anlage wegen eines
 *    Defekts im Zertifikat-Pfad ist keiner.
 *
 * WAS "HINTER DEM COMMIT" HIER MESSBAR HEISST. Der Query-Log von Laravel
 * enthaelt keine BEGIN/COMMIT/SAVEPOINT-Statements (gemessen), die Lage des
 * Aufrufs ist darueber also nicht pruefbar. Gemessen wird sie stattdessen an
 * DB::transactionLevel() zum Zeitpunkt des Aufrufs, aufgezeichnet von einem
 * Stub-Service:
 *
 *   isEnabledForTeam()  Level 0  = die Transaktion der Anlage ist zu (committet)
 *   issue()             Level 1  = die Ausstellung laeuft in ihrem EIGENEN
 *                                  Savepoint (siehe unten)
 *
 * UND DER FALL, DER DIE ZUSAGE EINSCHRAENKT — gemessen, nicht erschlossen:
 * DirectHire\Index::createEmployee() ruft createOrUpdate() INNERHALB einer
 * eigenen DB::transaction() (dort Zeile 297-326). Dann ist die innere
 * Transaktion nur ein Savepoint, und "hinter dem Commit" ist der Hook dort
 * NICHT: gemessen Level 1 statt 0. Genau deshalb liegt um issue() ein eigener
 * Savepoint — dasselbe Mittel mit derselben Begruendung, das in diesem Service
 * schon den Kontaktbuch-Sync umschliesst: ein gefangener Fehler darf die
 * Transaktion des Aufrufers nicht vergiften (abort-on-error-Engines). Belegt
 * wird das von testFremdeTransaktionUeberlebtEinenAusstellungsfehler.
 *
 * PROZESSWEITER ZUSTAND, und was davon wirklich traegt — jede Zeile einzeln
 * entfernt und der GESAMTLAUF gemessen, nicht erschlossen. Alphabetisch laeuft
 * vor dieser Klasse EmployeeContactListSyncTest, die eine eigene in-memory-DB
 * aufbaut und die Boot-Caches nicht aufraeumt:
 *
 *  - Model::clearBootedModels() im SETUP ist LOAD-BEARING. Ohne die Zeile
 *    brechen alle sechs Tests im Gesamtlauf mit "NOT NULL constraint failed:
 *    rec_applicants.uuid" und bleiben im GEFILTERTEN Lauf gruen. Eloquents
 *    $booted-Cache ist statisch: wer eine Modellklasse zuerst ohne
 *    Event-Dispatcher bootet, laesst deren creating-Hooks (uuid, portal_token)
 *    fuer alle spaeteren Klassen still ausfallen.
 *  - Facade::clearResolvedInstances() im SETUP ist LOAD-BEARING. Ohne die Zeile
 *    zeigt die Log-Facade auf das Log-Objekt der vorigen Klasse, die
 *    Aufzeichnung unten bleibt leer, und die beiden Falsifikatoren scheitern an
 *    der fehlenden Log-Zeile — also genau an der Aussage, die sie belegen
 *    sollen.
 *  - Im TEARDOWN tragen beide Zeilen HEUTE nicht (einzeln entfernt, Gesamtlauf
 *    unveraendert gruen). Sie stehen fuer den Tag, an dem eine Klasse folgt,
 *    die in ihrem Setup nicht aufraeumt.
 *  - leereExtraFieldCache() im Teardown ist LOAD-BEARING, siehe dort.
 */
class EmployeeCreationCertificateTest extends TestCase
{
    /** Team MIT eingeschaltetem Schalter (Settings-Zeile im Schema-Setup). */
    private const TEAM = 3;

    /**
     * Team OHNE Settings-Zeile — Schalter aus ueber DEFAULT_SETTINGS, der
     * Zustand jedes heute existierenden Teams.
     *
     * NUR fuer den Query-Protokoll-Test, und das ist der Grund: bei der ersten
     * Anlage in einem Team legt der Kontaktbuch-Sync die Settings-Zeile per
     * firstOrCreate an (select + insert). Ein zweiter Test auf demselben Team
     * haette deshalb eine andere Query-Zahl. Wer hier einen zweiten Test
     * anhaengt, macht die Zahl reihenfolgeabhaengig.
     */
    private const TEAM_AUS = 4;

    /** Eigenes Team fuer den Fremd-Transaktions-Test, gleiche Begruendung. */
    private const TEAM_FREMD = 5;

    /**
     * Queries einer Mitarbeiter-Anlage in einem Team OHNE Settings-Zeile,
     * GEMESSEN gegen den Stand VOR diesem Task (3b89315) mit derselben Fixture:
     * 22. Mit dem Hook und ausgeschaltetem Schalter: 23.
     *
     * Der eine dazugekommene Query ist der Settings-Lookup des Hooks, und er
     * ist NICHT vermeidbar: Weg (b) hat keine UI, der Team-Schalter ist die
     * einzige Bremse, und ihn zu lesen kostet einen Query. "Query fuer Query
     * wie vor dem Umbau" (die Zusage von Task 11, wo eine Checkbox vorher
     * abbog) ist hier also nicht erreichbar — die pruefbare Zusage ist
     * "genau ein Query mehr, und zwar dieser".
     *
     * Die Zahl faengt jeden weiteren Query, den spaeter jemand in den
     * Normalfall einschleppt. Aendert sich der Ablauf absichtlich, gehoert die
     * neue Zahl hierher und die Begruendung in den Commit.
     */
    private const QUERIES_VOR_DEM_HOOK = 22;
    private const QUERIES_SCHALTER_AUS = 23;

    /**
     * Der Idempotenz-Pfad: existiert der Mitarbeiter schon, steigt
     * createOrUpdate() vor allem anderen aus — ein einziger Query. Gemessen
     * gegen 3b89315 und nach dem Umbau unveraendert.
     *
     * Das ist kein Randfall: ZasReExportByBookingDate ruft createOrUpdate()
     * ueber eine ganze Liste, dort ist dieser Pfad der Normalfall. Ein Hook
     * VOR dem Idempotenz-Return wuerde jeden Durchlauf verteuern, ohne je
     * etwas anderes zu tun als das bestehende Zertifikat nachzuschlagen.
     */
    private const QUERIES_ZWEITER_AUFRUF = 1;

    /** Aufzeichnung des Stub-Service: Methode => DB::transactionLevel(). */
    private static array $level = [];

    /** @var list<array{level: string, message: string, context: array}> */
    private static array $logZeilen = [];

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        // LogsActivity (CrmContact) verlangt config(); Events leer = keine Hooks.
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        // Ohne Dispatcher feuern die creating-Hooks nicht (uuid, portal_token) —
        // das echte Schema verlangt sie als NOT NULL.
        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);

        // Der Log ist in diesem Task der EINZIGE Meldekanal (Weg b hat keine
        // Seite, auf der eine Meldung erscheinen koennte) — also wird er
        // mitgemessen, nicht stillgelegt.
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
                EmployeeCreationCertificateTest::merkeLog($name, $args);
            }
        });

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
        $container->alias(\Illuminate\Contracts\Auth\Factory::class, 'auth');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
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
        Container::getInstance()->forgetInstance(IssueTrainingCertificateService::class);
        self::leereExtraFieldCache();
    }

    /**
     * DIESE ZEILE IST LOAD-BEARING, und sie war es nicht offensichtlich —
     * gemessen: ohne sie brechen PlaceholderResolutionPinTest::
     * test_applicant_extra_field_geburtsort_kommt_als_text und
     * TrainingCertificateWhatsAppDeliveryTest::
     * testExtraFieldVornameGewinntUeberDenKontakt im GESAMTLAUF (beide erwarten
     * einen Extra-Field-Wert und bekommen ''), waehrend sie gefiltert gruen
     * bleiben.
     *
     * Ursache: HasExtraFields cacht die Definitionen STATISCH unter
     * "Klasse:id". Diese Klasse ist die erste, die
     * collectExtraFieldValuesByName() ueber den ganzen
     * createOrUpdate()-Pfad laufen laesst und damit RecApplicant:1..n mit einer
     * LEEREN Definitionsliste im Cache hinterlaesst. Die spaeteren Klassen legen
     * in ihrer eigenen in-memory-DB wieder einen Bewerber #1 an — und bekommen
     * unseren leeren Cache-Eintrag.
     *
     * Reflection, weil der Trait nur clearExtraFieldDefinitionsCache() pro
     * Instanz anbietet: die Instanzen sind hier laengst weg, die statischen
     * Eintraege nicht.
     */
    private static function leereExtraFieldCache(): void
    {
        foreach (['extraFieldDefinitionsCache', 'extraFieldInheritanceStack'] as $name) {
            $property = new \ReflectionProperty(RecApplicant::class, $name);
            $property->setValue(null, []);
        }
    }

    protected function setUp(): void
    {
        self::$level = [];
        self::$logZeilen = [];
    }

    protected function tearDown(): void
    {
        // Eine leckende Stub-Bindung sieht in der naechsten Klasse wie ein
        // kaputter Service aus.
        Container::getInstance()->forgetInstance(IssueTrainingCertificateService::class);
    }

    public static function merkeLog(string $level, array $args): void
    {
        self::$logZeilen[] = [
            'level' => $level,
            'message' => (string) ($args[0] ?? ''),
            'context' => (array) ($args[1] ?? []),
        ];
    }

    // -----------------------------------------------------------------
    // Der Charakterisierungs-Test
    // -----------------------------------------------------------------

    /**
     * SCHALTER AUS: der Mitarbeiter wird angelegt wie bisher, und der Ablauf
     * bekommt genau einen Query dazu — den Settings-Lookup.
     *
     * Die vier Query-Assertions haengen an verschiedenen Fehlern:
     *  - rec_training_certificates: der Zertifikat-Zweig laeuft ueberhaupt an.
     *  - rec_interview_bookings: die attended-Pruefung laeuft VOR dem
     *    Schalter. Das ist der teure Fehler, weil er nichts kaputt macht —
     *    nur ein zusaetzliches exists() pro Anlage in jedem Team, das das
     *    Feature nie einschaltet.
     *  - drei Settings-Queries: zwei vom Kontaktbuch-Sync (select + insert der
     *    fehlenden Zeile), einer vom Hook. Ein vierter waere ein doppelter
     *    Lookup im Hook.
     *  - die Gesamtzahl: alles andere, was jemand hier einbaut.
     */
    public function testSchalterAusLaesstDieAnlageBisAufEinenQuerySoWieHeute(): void
    {
        $this->assertFalse(
            RecApplicantSettings::where('team_id', self::TEAM_AUS)->exists(),
            'Vorbedingung: dieses Team hat noch keine Settings-Zeile (sonst zaehlt der Test falsch).'
        );

        $applicant = $this->bewerberMitSchulung(self::TEAM_AUS);

        $queries = $this->mitQueryProtokoll(function () use ($applicant, &$employee) {
            $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);
        });

        // Der Zustand danach — unveraendert gegenueber heute.
        $this->assertInstanceOf(RecEmployee::class, $employee);
        $this->assertSame(self::TEAM_AUS, (int) $employee->team_id);
        $this->assertSame((int) $applicant->id, (int) $employee->rec_applicant_id);
        $this->assertFalse((bool) $applicant->refresh()->is_active);
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));

        // Und der Weg dorthin.
        $this->assertSame([], $this->queriesAuf($queries, 'rec_training_certificates'));
        $this->assertSame(
            [],
            $this->queriesAuf($queries, 'rec_interview_bookings'),
            'Der Schalter ist das erste Gate: ohne ihn darf die attended-Pruefung nicht laufen.'
        );

        $settingsQueries = $this->queriesAuf($queries, 'rec_applicant_settings');
        $this->assertCount(3, $settingsQueries, "Erwartet: select + insert (Kontaktbuch-Sync) + select (Hook).\n" . implode("\n", $settingsQueries));
        $this->assertStringStartsWith('select', $settingsQueries[2]);

        $this->assertSame(
            self::QUERIES_SCHALTER_AUS,
            count($queries),
            "Die Mitarbeiter-Anlage hat einen Query dazubekommen (vor dem Hook: "
            . self::QUERIES_VOR_DEM_HOOK . "):\n" . implode("\n", array_column($queries, 'query'))
        );

        $this->assertSame([], self::$logZeilen, 'Ein ausgeschalteter Schalter ist kein Fehler.');
    }

    /**
     * Der Idempotenz-Pfad bleibt ein einziger Query: der Hook sitzt HINTER dem
     * frueh zurueckkehrenden Zweig, nicht davor.
     */
    public function testZweiterAufrufBleibtEinEinzigerQuery(): void
    {
        $applicant = $this->bewerberMitSchulung(self::TEAM);
        $service = new CreateEmployeeFromApplicantService();
        $service->createOrUpdate($applicant, null);

        $queries = $this->mitQueryProtokoll(function () use ($service, $applicant) {
            $service->createOrUpdate($applicant, null);
        });

        $this->assertSame(
            self::QUERIES_ZWEITER_AUFRUF,
            count($queries),
            "Der Idempotenz-Pfad hat Queries dazubekommen:\n" . implode("\n", array_column($queries, 'query'))
        );
        $this->assertSame([], $this->queriesAuf($queries, 'rec_applicant_settings'));
        $this->assertSame(1, $this->zertifikatAnzahl($applicant), 'Und genau ein Zertifikat, nicht zwei.');
    }

    // -----------------------------------------------------------------
    // Der Auftrag
    // -----------------------------------------------------------------

    /**
     * Die Gegenprobe zum Falsifikator: ein erfolgreicher Lauf hat BEIDES —
     * Mitarbeiter und Zertifikat — und niemand hat etwas angehakt.
     */
    public function testSchalterAnUndSchulungBesuchtStelltAus(): void
    {
        $applicant = $this->bewerberMitSchulung(self::TEAM);

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);

        $this->assertInstanceOf(RecEmployee::class, $employee);
        $this->assertNotNull(RecEmployee::find($employee->id));

        $this->assertSame(1, $this->zertifikatAnzahl($applicant));
        $cert = RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->first();
        $this->assertSame(RecTrainingCertificate::KIND_SERVICE_BASIS, $cert->kind);
        $this->assertSame(self::TEAM, (int) $cert->team_id);
        $this->assertNull($cert->issued_by_user_id, 'Weg (b) laeuft ohne angemeldeten Benutzer.');
        $this->assertNull($cert->wa_sent_at, 'Weg (b) verschickt nichts — die Portal-Einladung traegt das Zertifikat.');
        $this->assertStringContainsString('Erika Mustermann', (string) $cert->personalized_content);
        $this->assertStringContainsString('24.07.2026', (string) $cert->personalized_content);

        $this->assertSame([], self::$logZeilen);
    }

    /**
     * KEINE SCHULUNG, KEIN ZERTIFIKAT — und kein Fehler. Direkteinstellungen
     * und ZAS-Importe haben keine attended-Buchung; ein Zertifikat waere dort
     * ein Dokument mit leerem Datum und leerem Schulungsleiter, also eine
     * falsche Aussage in Papierform.
     */
    public function testOhneAttendedBuchungWirdNichtAusgestellt(): void
    {
        $applicant = $this->bewerberMitSchulung(self::TEAM, 'registered');

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);

        $this->assertNotNull(RecEmployee::find($employee->id));
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));
        $this->assertSame([], self::$logZeilen, 'Keine Schulung ist kein Fehlerfall.');
    }

    // -----------------------------------------------------------------
    // Der Falsifikator
    // -----------------------------------------------------------------

    /**
     * AUSSTELLUNG WIRFT → MITARBEITER IST TROTZDEM DA UND COMMITTET.
     *
     * Drei Dinge muessen gleichzeitig gelten:
     *  - createOrUpdate() wirft nicht (ein Zertifikat-Defekt reisst die Anlage
     *    nicht mit),
     *  - der Mitarbeiter steht in der DB und die Transaktion ist zu,
     *  - der Fehler ist nicht stumm: eine Log-Zeile mit eigenem Marker und der
     *    Bewerber-ID.
     *
     * Und der teilweise Schreibvorgang des Stubs ist WEG: die Ausstellung
     * laeuft in ihrem eigenen Savepoint, ein halb geschriebenes Zertifikat
     * bleibt nicht liegen.
     *
     * Der Fehler kommt aus einer Container-Bindung, nicht aus kaputten Daten:
     * so trifft er genau die Stelle, um die es geht, und keinen Nachbarn.
     */
    public function testAusstellungsfehlerLaesstDenMitarbeiterStehen(): void
    {
        $applicant = $this->bewerberMitSchulung(self::TEAM);
        $this->bindeStub(werfend: true);

        $employee = (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);

        $this->assertInstanceOf(RecEmployee::class, $employee, 'createOrUpdate() darf nicht werfen.');
        $this->assertNotNull(RecEmployee::find($employee->id), 'Der Mitarbeiter steht in der DB.');
        $this->assertSame(0, DB::transactionLevel(), 'Keine offene Transaktion mehr.');

        // HINTER DEM COMMIT: beim Gate war die Transaktion der Anlage zu.
        $this->assertSame(
            0,
            self::$level['isEnabledForTeam'] ?? null,
            'Der Hook laeuft in der Transaktion der Anlage statt dahinter.'
        );
        // Und die Ausstellung selbst hatte ihren eigenen Savepoint.
        $this->assertSame(1, self::$level['issue'] ?? null);

        // Der Teil-Schreibvorgang des Stubs ist zurueckgerollt.
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));

        $this->assertCount(1, self::$logZeilen);
        $zeile = self::$logZeilen[0];
        $this->assertSame('error', $zeile['level'], 'Log::error, nicht warning: ein Zertifikat, das nicht entsteht, ist ein Fehler.');
        $this->assertStringContainsString('Zertifikat', $zeile['message']);
        $this->assertStringNotContainsString(
            'evaluationTransfer',
            $zeile['message'],
            'Eigener Marker — nicht der des Bewertungs-Transfers.'
        );
        $this->assertSame((int) $applicant->id, (int) $zeile['context']['applicant_id']);
        $this->assertStringContainsString('Test-Stub', (string) $zeile['context']['error']);
    }

    /**
     * DER FALL DIRECTHIRE: der Aufrufer haelt selbst eine Transaktion offen
     * (DirectHire\Index::createEmployee(), Zeile 297-326). Dann ist der Hook
     * NICHT hinter einem Commit — gemessen Level 1 statt 0 — und ein
     * gefangener Fehler wuerde auf abort-on-error-Engines die Transaktion des
     * Aufrufers vergiften. Der eigene Savepoint um issue() ist genau dagegen.
     *
     * Gemessen wird deshalb das, worauf es dort ankommt: die aeussere
     * Transaktion committet, der Mitarbeiter ist danach da, das halb
     * geschriebene Zertifikat nicht.
     *
     * WAS DIESER TEST NICHT ZEIGT: dass Postgres die aeussere Transaktion
     * ueberlebt. SQLite vertraegt einen gefangenen Statement-Fehler auch ohne
     * Savepoint; die Notwendigkeit des Savepoints ist fuer Postgres
     * ERSCHLOSSEN, nicht gemessen. Was hier gemessen wird, ist dass er da ist
     * und richtig greift.
     */
    public function testFremdeTransaktionUeberlebtEinenAusstellungsfehler(): void
    {
        $applicant = $this->bewerberMitSchulung(self::TEAM_FREMD);
        RecApplicantSettings::create([
            'team_id' => self::TEAM_FREMD,
            'settings' => [IssueTrainingCertificateService::SETTING_ENABLED => true],
        ]);
        $this->bindeStub(werfend: true);

        $employee = DB::transaction(function () use ($applicant) {
            return (new CreateEmployeeFromApplicantService())->createOrUpdate($applicant, null);
        });

        $this->assertSame(0, DB::transactionLevel());
        $this->assertNotNull(RecEmployee::find($employee->id), 'Die aeussere Transaktion hat committet.');
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));

        $this->assertSame(
            1,
            self::$level['isEnabledForTeam'] ?? null,
            'Belegt die Einschraenkung: bei einem Aufrufer mit eigener Transaktion ist "hinter dem Commit" nicht erreichbar.'
        );
        $this->assertSame(2, self::$level['issue'] ?? null, 'Die Ausstellung liegt in einem eigenen Savepoint darin.');

        $this->assertCount(1, self::$logZeilen);
        $this->assertSame('error', self::$logZeilen[0]['level']);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Bindet einen Stub des Ausstellungs-Service, der bei jedem Aufruf
     * DB::transactionLevel() aufzeichnet — die einzige messbare Aussage
     * darueber, wo der Hook sitzt (der Query-Log kennt keine
     * Transaktions-Statements).
     *
     * Der werfende Stub schreibt VOR dem Wurf eine halbe Zeile, damit der
     * Savepoint etwas zurueckzurollen hat; ohne diesen Schreibvorgang waere
     * "Savepoint greift" nicht pruefbar, sondern nur behauptet.
     */
    private function bindeStub(bool $werfend): void
    {
        Container::getInstance()->instance(
            IssueTrainingCertificateService::class,
            new class($werfend) extends IssueTrainingCertificateService {
                public function __construct(private bool $werfend) {}

                public function isEnabledForTeam(int $teamId): bool
                {
                    EmployeeCreationCertificateTest::merkeLevel('isEnabledForTeam');

                    return parent::isEnabledForTeam($teamId);
                }

                public function issue(RecApplicant $applicant, ?int $issuedByUserId): RecTrainingCertificate
                {
                    EmployeeCreationCertificateTest::merkeLevel('issue');

                    if (!$this->werfend) {
                        return parent::issue($applicant, $issuedByUserId);
                    }

                    RecTrainingCertificate::create([
                        'team_id' => (int) $applicant->team_id,
                        'rec_applicant_id' => (int) $applicant->id,
                        'kind' => RecTrainingCertificate::KIND_SERVICE_BASIS,
                        'personalized_content' => '<p>halb geschrieben</p>',
                        'issued_at' => \Carbon\Carbon::now(),
                    ]);

                    throw new \RuntimeException('Ausstellung kaputt (Test-Stub).');
                }
            }
        );
    }

    public static function merkeLevel(string $methode): void
    {
        self::$level[$methode] = DB::transactionLevel();
    }

    /**
     * Ein Bewerber, bei dem eine Ausstellung MOEGLICH waere: Kontakt (fuer den
     * Namen), Termin mit Leiter (fuer Datum und Schulungsleiter), Buchung im
     * angegebenen Status.
     */
    private function bewerberMitSchulung(int $teamId, string $status = 'attended'): RecApplicant
    {
        $applicant = RecApplicant::create([
            'team_id' => $teamId,
            'is_active' => true,
            // auto_pilot bewusst false: mit true zieht der saving-Guard des
            // Modells calculateProgress() und das Query-Protokoll bekommt
            // Fremd-Queries.
            'auto_pilot' => false,
        ]);

        $contact = CrmContact::create([
            'team_id' => $teamId,
            'is_active' => true,
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
        ]);
        $applicant->crmContactLinks()->create(['contact_id' => $contact->id, 'team_id' => $teamId]);

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
            'status' => $status,
        ]);

        return $applicant;
    }

    private function user(string $name): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . uniqid() . '@example.org',
        ]);
    }

    private function zertifikatAnzahl(RecApplicant $applicant): int
    {
        return RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicant->id)
            ->count();
    }

    /**
     * Fuehrt $fn aus und liefert das Query-Protokoll NUR dieses Aufrufs.
     *
     * flushQueryLog() vorher ist Pflicht, nicht Hygiene: die Fixture laeuft
     * ueber dieselbe Verbindung, ihre Inserts stuenden sonst im Protokoll.
     *
     * @return list<array{query: string, bindings: array, time: float}>
     */
    private function mitQueryProtokoll(callable $fn): array
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $fn();
        } finally {
            $connection->disableQueryLog();
        }

        return $connection->getQueryLog();
    }

    /**
     * @param  list<array{query: string, bindings: array, time: float}>  $queries
     * @return list<string>
     */
    private function queriesAuf(array $queries, string $tabelle): array
    {
        return array_values(array_filter(
            array_column($queries, 'query'),
            fn (string $sql) => str_contains($sql, $tabelle)
        ));
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    /**
     * Schema aus den ECHTEN Migrationen. Die eigenen per glob, und das ist eine
     * Abweichung von HrDeskRejectionCertificateTest (dort eine Liste von 14):
     * createOrUpdate() schreibt quer durch rec_employees, rec_employee_hr_data
     * und rec_applicants, deren Spalten auf ueber dreissig Migrationen
     * verteilt sind. Eine handgepflegte Liste wuerde genau dann still falsch,
     * wenn eine neue Spalte dazukommt, die der Service befuellt — sie fehlt in
     * SQLite, und der Insert kippt in einen try/catch-Zweig statt in einen
     * roten Test. Mit glob ist das Schema per Konstruktion das des Moduls.
     *
     * Aus Fremdpaketen nur, was der Pfad wirklich anfasst — dort waere ein
     * glob eine Abhaengigkeit auf die vollstaendige Migrationsgeschichte
     * zweier anderer Pakete (137 + 61 Dateien, vier davon MySQL-only).
     *
     * Wurzel-Aufloesung per Reflection, weil platforms-core NICHT als
     * Geschwister der Module liegt.
     */
    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(User::class);
        $crm = self::packageRootOf(CrmContact::class);
        $own = dirname(__DIR__, 2);

        $fremd = [
            $core . '/database/migrations/0001_01_01_000000_create_users_table.php',
            // getExtraFieldDefinitions() liest beide Tabellen; ohne sie faellt
            // collectExtraFieldValuesByName() in seinen catch-Zweig und der
            // Test wuerde einen Pfad messen, den die Produktion nicht laeuft.
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
            $crm . '/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
            $crm . '/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
            $crm . '/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
        ];
        // Die Fallback-Kette fuer email/phone am Mitarbeiter.
        foreach (glob($crm . '/database/migrations/*email_address*.php') as $file) {
            $fremd[] = $file;
        }
        foreach (glob($crm . '/database/migrations/*phone_number*.php') as $file) {
            $fremd[] = $file;
        }

        $eigene = glob($own . '/database/migrations/*.php');
        sort($eigene);

        foreach (array_merge($fremd, $eigene) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => [IssueTrainingCertificateService::SETTING_ENABLED => true],
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
