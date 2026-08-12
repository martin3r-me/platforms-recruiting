<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\IssueTrainingCertificateService;

/**
 * Die Ausstellung: Gate, Snapshot, Idempotenz.
 *
 * ABGRENZUNG: der reine Textaufbau steckt in
 * tests/Unit/TrainingCertificateContentTest.php (laravel-frei). Hier steht
 * alles, was eine DB braucht — und zwar genau die drei Dinge, an denen diese
 * Klasse still falsch sein kann: welche Buchung die Werte liefert, ob das
 * Team-Setting bremst, und ob eine zweite Ausstellung das bereits zugestellte
 * Dokument anfasst.
 *
 * ABWEICHUNG VOM TASK-BRIEF (Schema), begruendet: das Schema kommt aus den
 * ECHTEN Migrationen, TestSchema::trainingCertificates() wird NICHT benutzt.
 * Zwei Gruende. (1) Fuer die anderen acht Tabellen (Bewerber, Settings,
 * CRM-Kontakt samt Verknuepfung, users, Termine, Buchungen, Interviewer-Pivot)
 * gibt es in TestSchema ueberhaupt keine Methode — die echten Migrationen sind
 * dort die einzige Quelle, und dann zwei Quellen in einer Klasse zu mischen
 * waere die Drift, gegen die TestSchema gebaut wurde. (2) Die Migration
 * 2026_08_12_000002 war bis hierher von keinem Test ausgefuehrt; sie hier
 * laufen zu lassen prueft sie mit. Die Wurzel-Aufloesung per Reflection ist
 * uebernommen aus PlaceholderResolutionPinTest (Begruendung dort:
 * platforms-core liegt NICHT als Geschwister der Module).
 *
 * PROZESSWEITER ZUSTAND: Facade::clearResolvedInstances() in Setup UND
 * Teardown, plus Model::clearBootedModels(). Beides ist statisch und faellt NUR
 * im Gesamtlauf auf, nie im gefilterten. Muster:
 * PlaceholderResolutionPinTest. Welche der Aufrufe heute wirklich tragen und
 * welche Hygiene sind, steht gemessen an den Aufrufen selbst — nicht als
 * Sammelbehauptung hier.
 *
 * Fixtures loeschen nichts zwischen den Tests, sondern legen pro Test neue
 * Bewerber an — gleiche Begruendung wie im Pin-Test (HasExtraFields cacht
 * statisch unter "Klasse:id", wiederverwendete IDs wuerden Definitionssaetze
 * vermischen). Zaehlungen laufen deshalb IMMER gegen einen Bewerber, nie
 * gegen die ganze Tabelle.
 */
class IssueTrainingCertificateServiceTest extends TestCase
{
    /** Team MIT eingeschaltetem Schalter. */
    private const TEAM = 3;

    /** Team OHNE Settings-Zeile — der Normalfall "nie konfiguriert". */
    private const TEAM_OHNE_SETTINGS = 4;

    /** Team mit Settings-Zeile, in der der Schalter ausdruecklich false ist. */
    private const TEAM_AUSGESCHALTET = 5;

    /**
     * Team mit BESTEHENDER Settings-Zeile, in der der Schluessel gar nicht
     * vorkommt — der Zustand JEDES heute existierenden Teams, weil die Zeilen
     * lange vor diesem Schalter angelegt wurden.
     */
    private const TEAM_ALTBESTAND = 6;

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

        // PFLICHT, gemessen: Model::$booted ist prozessweit statisch. Hat eine
        // frueher laufende Klasse eines dieser Modelle ohne Dispatcher gebootet,
        // sind dessen creating-Hooks toter Code. Ohne diese Zeile bricht der
        // GESAMTLAUF mit 12 Fehlern ("NOT NULL constraint failed:
        // rec_applicants.uuid"), waehrend der gefilterte Lauf gruen bleibt.
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

        // Facade::$resolvedInstance ist statisch und wird von
        // setFacadeApplication() NICHT geleert: eine frueher laufende Klasse
        // kann 'db'/'db.schema' auf ihre eigene, inzwischen weggeworfene
        // in-memory-DB zwischengespeichert haben.
        Facade::clearResolvedInstances();

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        // Diese Klasse bootet Modelle gegen ihren eigenen Dispatcher und bindet
        // Facades an ihre eigene Capsule; beides hinterlassen heisst, die
        // naechste Testklasse arbeitet auf einer geschlossenen in-memory-DB.
        //
        // EHRLICHKEIT ZUM STATUS, damit niemand daraus eine Notwendigkeit liest:
        // dieses Aufraeumen ist HEUTE nicht load-bearing. Gemessen — beide
        // Zeilen entfernt, Gesamtlauf gruen (647 Tests), weil die einzige
        // Klasse, die nach dieser laeuft (PlaceholderResolutionPinTest,
        // alphabetisch nach 'I'), in ihrem eigenen Setup dasselbe aufraeumt.
        // Load-bearing ist dagegen das clearBootedModels() im SETUP oben:
        // ohne es bricht der Gesamtlauf mit 12 Fehlern
        // ("NOT NULL constraint failed: rec_applicants.uuid"), gefiltert
        // bleibt er gruen. Hier stehen die Zeilen als Hygiene fuer den Tag, an
        // dem eine Klasse dazukommt, die nicht selbst aufraeumt.
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
    }

    // -----------------------------------------------------------------
    // Das Gate
    // -----------------------------------------------------------------

    /**
     * Der Schalter ist der einzige Weg, das Feature stillzulegen, ohne zu
     * deployen (mit festem HTML gibt es kein default_certificate_template_id
     * mehr). Er MUSS in DEFAULT_SETTINGS stehen und false sein: nur dann
     * materialisiert getOrCreateForTeam() ihn fuer neue Teams, und nur dann
     * findet ihn das Einstellungen-Modal, das seine Felder aus dieser Liste
     * kennt.
     */
    public function testSchalterStehtMitFalseInDenDefaultSettings(): void
    {
        $this->assertArrayHasKey(
            IssueTrainingCertificateService::SETTING_ENABLED,
            RecApplicantSettings::DEFAULT_SETTINGS
        );
        $this->assertFalse(
            RecApplicantSettings::DEFAULT_SETTINGS[IssueTrainingCertificateService::SETTING_ENABLED],
            'Default ist AUS: ein Team, das nichts konfiguriert hat, stellt keine Zertifikate aus.'
        );
    }

    /**
     * Ohne Settings-Zeile (der Normalfall fuer jedes Team, das nie etwas
     * konfiguriert hat) bremst der Default. Wichtig ist die zweite Assertion:
     * es darf auch keine halbe Zeile entstehen. Ein Guard, der erst NACH dem
     * Insert prueft, waere hier gruen und in Produktion falsch.
     */
    public function testOhneSettingsZeileWirdNichtAusgestellt(): void
    {
        $applicant = $this->applicant(self::TEAM_OHNE_SETTINGS);
        $this->contact($applicant, 'Erika', 'Mustermann');

        // Gefangene Exception in einer Variablen statt try/fail/catch: PHPUnits
        // eigene AssertionFailedError ist eine \RuntimeException (gemessen), ein
        // catch(\RuntimeException) wuerde also das fail() dieses Tests
        // mitschlucken und den Fehlschlag als Wortlaut-Problem melden.
        $gefangen = null;
        try {
            (new IssueTrainingCertificateService())->issue($applicant, null);
        } catch (\RuntimeException $e) {
            $gefangen = $e;
        }

        $this->assertNotNull($gefangen, 'Erwartet: RuntimeException, weil der Schalter aus ist.');
        $this->assertStringContainsString(
            IssueTrainingCertificateService::SETTING_ENABLED,
            $gefangen->getMessage(),
            'Die Meldung muss den Schalter nennen — sonst sucht HR im Code statt in den Einstellungen.'
        );

        $this->assertSame(0, $this->certificateCount($applicant));
    }

    /** Ausdruecklich false in der Zeile bremst genauso wie eine fehlende Zeile. */
    public function testAusdruecklichAusgeschalteterSchalterBremstEbenfalls(): void
    {
        RecApplicantSettings::create([
            'team_id' => self::TEAM_AUSGESCHALTET,
            'settings' => [IssueTrainingCertificateService::SETTING_ENABLED => false],
        ]);

        $applicant = $this->applicant(self::TEAM_AUSGESCHALTET);
        $this->contact($applicant, 'Erika', 'Mustermann');

        $this->expectException(\RuntimeException::class);

        (new IssueTrainingCertificateService())->issue($applicant, null);
    }

    /**
     * DER LIVE-FALL, und er war beim ersten Anlauf ungesichert: eine
     * BESTEHENDE Settings-Zeile, in der der Schluessel FEHLT. So sieht heute
     * jedes Team aus — die Zeilen sind lange vor diesem Schalter entstanden,
     * und getOrCreateForTeam() ergaenzt fehlende Schluessel nicht.
     *
     * Gemessen an dieser Stelle: der Default-Parameter von getSetting() wird
     * genau hier gelesen, nur hier. Mit `true` statt `false` waere das Feature
     * fuer JEDES bestehende Team still eingeschaltet — bei einem Schalter, der
     * ausdruecklich standardmaessig aus sein soll. Ohne diesen Test blieb die
     * Suite bei dieser Mutation gruen.
     */
    public function testBestehendeZeileOhneDenSchluesselIstAus(): void
    {
        RecApplicantSettings::create([
            'team_id' => self::TEAM_ALTBESTAND,
            'settings' => ['use_informal_address' => true],
        ]);

        $service = new IssueTrainingCertificateService();

        $this->assertFalse($service->isEnabledForTeam(self::TEAM_ALTBESTAND));

        $applicant = $this->applicant(self::TEAM_ALTBESTAND);
        $this->contact($applicant, 'Erika', 'Mustermann');

        $this->expectException(\RuntimeException::class);

        $service->issue($applicant, null);
    }

    /**
     * Die Vorab-Frage fuer Aufrufer ohne UI (Weg b: die MA-Anlage) — sie muss
     * dieselbe Quelle lesen wie der Guard in issue(), sonst gibt es zwei
     * Wahrheiten ueber "Feature an".
     */
    public function testIstFuerTeamAktivBeantwortetBeideRichtungen(): void
    {
        $service = new IssueTrainingCertificateService();

        $this->assertTrue($service->isEnabledForTeam(self::TEAM));
        $this->assertFalse($service->isEnabledForTeam(self::TEAM_OHNE_SETTINGS));
    }

    // -----------------------------------------------------------------
    // Die Ausstellung
    // -----------------------------------------------------------------

    /**
     * Der Hauptfall, und er nagelt drei Dinge auf einmal fest:
     *
     *  1. Die Zeile: uuid, kind, team, Aussteller, issued_at gesetzt,
     *     wa_sent_at leer (der Versand ist ein eigener Schritt).
     *  2. Die Auswahlregel: von zwei 'attended'-Buchungen gewinnt die mit dem
     *     SPAETEREN Termin, nicht das juengere Insert — und eine 'no_show'-
     *     Buchung mit noch spaeterem Termin zaehlt gar nicht.
     *  3. Den PRODUZENTEN-Vertrag zu TrainingLeaderResolver: zwei Interviewer
     *     stehen als "Anna Bergmann, Bea Klein" im Dokument. Der stille
     *     Nachbarfehler waere ->interviewers->all() statt
     *     ->interviewers->pluck('name')->all(): dann stuende JSON auf dem
     *     Zertifikat (Model::__toString() liefert toJson()).
     */
    public function testAusstellungLegtZeileMitSnapshotUndUuidAn(): void
    {
        $applicant = $this->applicant();
        $this->contact($applicant, 'Erika', 'Mustermann');
        // Juengstes Insert, aber FRUEHERER Termin (Umbuchungsfall).
        $this->booking($applicant, '2026-06-02 09:00:00', ['Falscher Leiter'], 'attended');
        $this->booking($applicant, '2026-07-24 14:00:00', ['Anna Bergmann', 'Bea Klein'], 'attended');
        // Spaeterer Termin, aber nicht teilgenommen.
        $this->booking($applicant, '2026-08-01 14:00:00', ['Nie Erschienen'], 'no_show');

        $cert = (new IssueTrainingCertificateService())->issue($applicant, 7);

        $this->assertNotEmpty($cert->uuid);
        $this->assertSame(RecTrainingCertificate::KIND_SERVICE_BASIS, $cert->kind);
        $this->assertSame(self::TEAM, (int) $cert->team_id);
        $this->assertSame(7, (int) $cert->issued_by_user_id);
        $this->assertNotNull($cert->issued_at);
        $this->assertNull($cert->wa_sent_at);

        $content = (string) $cert->personalized_content;
        $this->assertStringContainsString('Erika Mustermann', $content);
        $this->assertStringContainsString('24.07.2026', $content);
        $this->assertStringContainsString('Anna Bergmann, Bea Klein', $content);
        $this->assertStringNotContainsString('Falscher Leiter', $content);
        $this->assertStringNotContainsString('Nie Erschienen', $content);
        // Kein JSON aus einem Model — der Nachbarfehler oben.
        $this->assertStringNotContainsString('{"id"', $content);
        $this->assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $content);
    }

    /**
     * Ein Bewerber ohne 'attended'-Buchung bekommt ein Zertifikat mit LEEREN
     * Feldern, keine Exception. Das ist die Policy von TrainingLeaderResolver
     * und sie gilt hier weiter: ein fehlender Schulungsleiter ist ein legitimes
     * Dokument, kein Fehlerfall — ein leeres Feld ist besser als ein falsches.
     * Wer daraus einen Vollstaendigkeits-Guard macht, blockiert die Ausstellung
     * fuer genau die Faelle, in denen HR nachtraegt.
     */
    public function testOhneAttendedBuchungWirdMitLeerenFeldernAusgestellt(): void
    {
        $applicant = $this->applicant();
        $this->contact($applicant, 'Erika', 'Mustermann');
        $this->booking($applicant, '2026-07-24 14:00:00', ['Anna Bergmann'], 'registered');

        $cert = (new IssueTrainingCertificateService())->issue($applicant, null);

        $content = (string) $cert->personalized_content;
        $this->assertStringContainsString('Erika Mustermann', $content);
        $this->assertStringContainsString('<div class="leiter"></div>', $content);
        $this->assertStringNotContainsString('Anna Bergmann', $content);
        $this->assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $content);
        // Weg (b) stellt ohne angemeldeten Benutzer aus.
        $this->assertNull($cert->issued_by_user_id);
    }

    /**
     * Der Snapshot ist der Grund, warum das Dokument stabil bleibt: er haelt
     * die variablen Werte zum Zeitpunkt der Ausstellung fest. Deshalb aendert
     * eine zweite Ausstellung NICHTS — auch dann nicht, wenn inzwischen eine
     * spaetere Buchung mit einem anderen Schulungsleiter dazugekommen ist. Ein
     * updateOrCreate an dieser Stelle waere der stille Schaden: ein bereits
     * zugestelltes Zertifikat traegt dann ein anderes Datum als die Kopie beim
     * Bewerber.
     */
    public function testZweiteAusstellungIstNormalfallUndAendertDenSnapshotNicht(): void
    {
        $applicant = $this->applicant();
        $this->contact($applicant, 'Erika', 'Mustermann');
        $this->booking($applicant, '2026-07-24 14:00:00', ['Anna Bergmann'], 'attended');

        $service = new IssueTrainingCertificateService();
        $erste = $service->issue($applicant, 7);

        // Nachgetragene, spaetere Schulung mit anderem Leiter.
        $this->booking($applicant, '2026-08-10 14:00:00', ['Neue Leiterin'], 'attended');

        $zweite = $service->issue($applicant, 99);

        $this->assertSame($erste->id, $zweite->id);
        $this->assertSame(1, $this->certificateCount($applicant));
        $this->assertStringContainsString('Anna Bergmann', (string) $zweite->personalized_content);
        $this->assertStringNotContainsString('Neue Leiterin', (string) $zweite->personalized_content);
        $this->assertStringContainsString('24.07.2026', (string) $zweite->personalized_content);
        $this->assertSame(7, (int) $zweite->issued_by_user_id, 'Der erste Aussteller bleibt stehen.');
    }

    /** Die Dedup-Dimension ist (Bewerber, Art) — ein anderer Bewerber ist unberuehrt. */
    public function testZweiterBewerberBekommtSeinEigenesZertifikat(): void
    {
        $a = $this->applicant();
        $this->contact($a, 'Erika', 'Mustermann');
        $b = $this->applicant();
        $this->contact($b, 'Klaus', 'Beispiel');

        $service = new IssueTrainingCertificateService();
        $certA = $service->issue($a, null);
        $certB = $service->issue($b, null);

        $this->assertNotSame($certA->id, $certB->id);
        $this->assertSame(1, $this->certificateCount($a));
        $this->assertSame(1, $this->certificateCount($b));
        $this->assertStringContainsString('Klaus Beispiel', (string) $certB->personalized_content);
    }

    /**
     * `kind` ist die Dedup-Dimension, nicht der Bewerber allein. Auf
     * unique(rec_applicant_id) runterzugehen waere der naheliegende Reflex und
     * wuerde die zweite Schulungsart verbauen — die soll ein Deploy mit einem
     * zweiten HTML-Block kosten, keinen Schemawechsel. Deshalb hier direkt am
     * Model: der Service kennt (noch) nur eine Art.
     */
    public function testZweiteSchulungsartBleibtMoeglich(): void
    {
        $applicant = $this->applicant();
        $this->contact($applicant, 'Erika', 'Mustermann');

        (new IssueTrainingCertificateService())->issue($applicant, null);

        RecTrainingCertificate::create([
            'team_id' => self::TEAM,
            'rec_applicant_id' => $applicant->id,
            'kind' => 'kueche-basis',
            'personalized_content' => '<p>zweite Art</p>',
            // Carbon direkt, nicht now(): der Helper haengt am
            // Application-Container, den dieser Test bewusst nicht hat.
            'issued_at' => Carbon::now(),
        ]);

        $this->assertSame(2, $this->certificateCount($applicant));
    }

    /**
     * Die Gegenrichtung zum Test darueber, und sie sichert den kind-Filter in
     * der Bestandspruefung: hat der Bewerber schon ein Zertifikat EINER ANDEREN
     * Art, muss die Ausstellung trotzdem laufen. Ohne den Filter kaeme die
     * fremde Zeile zurueck — der Bewerber bekaeme das Kuechen-Zertifikat als
     * Service-Zertifikat ausgehaendigt, und zwar still.
     */
    public function testAndereSchulungsartBlockiertDieAusstellungNicht(): void
    {
        $applicant = $this->applicant();
        $this->contact($applicant, 'Erika', 'Mustermann');

        RecTrainingCertificate::create([
            'team_id' => self::TEAM,
            'rec_applicant_id' => $applicant->id,
            'kind' => 'kueche-basis',
            'personalized_content' => '<p>andere Art</p>',
            'issued_at' => Carbon::now(),
        ]);

        $cert = (new IssueTrainingCertificateService())->issue($applicant, null);

        $this->assertSame(RecTrainingCertificate::KIND_SERVICE_BASIS, $cert->kind);
        $this->assertStringContainsString('Erika Mustermann', (string) $cert->personalized_content);
        $this->assertSame(2, $this->certificateCount($applicant));
    }

    /**
     * Die Kontaktwahl ist DETERMINISTISCH (kleinste contact_id), nicht
     * ->first(): crmContactLinks ist ein morphMany ohne Ordering, die
     * Reihenfolge ist also nicht garantiert. Auf einem Dokument, das den Namen
     * des Bewerbers traegt, darf nicht die Einfuegereihenfolge der
     * Verknuepfungen entscheiden, welcher Name gedruckt wird.
     *
     * Fixture bewusst gegenlaeufig: die Verknuepfung zum SPAETER angelegten
     * Kontakt (groessere contact_id) wird ZUERST eingefuegt. Ein ->first()
     * greift damit den falschen.
     */
    public function testKontaktwahlIstDeterministischKleinsteContactId(): void
    {
        $applicant = $this->applicant();
        $klein = CrmContact::create([
            'team_id' => self::TEAM,
            'is_active' => true,
            'first_name' => 'Erika',
            'last_name' => 'Kleinere-Id',
        ]);
        $gross = CrmContact::create([
            'team_id' => self::TEAM,
            'is_active' => true,
            'first_name' => 'Erika',
            'last_name' => 'Groessere-Id',
        ]);

        $this->assertGreaterThan($klein->id, $gross->id, 'Fixture-Annahme: aufsteigende IDs.');

        $applicant->crmContactLinks()->create(['contact_id' => $gross->id, 'team_id' => self::TEAM]);
        $applicant->crmContactLinks()->create(['contact_id' => $klein->id, 'team_id' => self::TEAM]);

        $cert = (new IssueTrainingCertificateService())->issue($applicant, null);

        $this->assertStringContainsString('Erika Kleinere-Id', (string) $cert->personalized_content);
        $this->assertStringNotContainsString('Groessere-Id', (string) $cert->personalized_content);
    }

    /**
     * Ohne verknuepften Kontakt bleibt das Namensfeld leer — dieselbe Policy
     * wie bei Datum und Leiter, und derselbe Grund: die Ausstellung laeuft auch
     * ueber Weg (b) ohne UI, ein Abbruch dort wuerde die MA-Anlage mitreissen.
     */
    public function testOhneKontaktBleibtDasNamensfeldLeer(): void
    {
        $applicant = $this->applicant();

        $cert = (new IssueTrainingCertificateService())->issue($applicant, null);

        $content = (string) $cert->personalized_content;
        $this->assertStringContainsString('<div class="val"> </div>', $content);
        $this->assertDoesNotMatchRegularExpression('/\{\{[^{}]+\}\}/', $content);
    }

    /**
     * Die Huelle ist NICHT im Snapshot. Sie wird beim Rendern aufgeloest, wie
     * der Firmenstempel bei Vertraegen — sonst lagen ~550 KB Base64 pro Zeile
     * in personalized_content. Der Unit-Test prueft dasselbe am Textaufbau;
     * hier steht es gegen das, was WIRKLICH in der Spalte landet.
     */
    public function testGespeicherterSnapshotEnthaeltKeineAssets(): void
    {
        $applicant = $this->applicant();
        $this->contact($applicant, 'Erika', 'Mustermann');

        (new IssueTrainingCertificateService())->issue($applicant, null);

        $gespeichert = (string) RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicant->id)
            ->value('personalized_content');

        $this->assertStringNotContainsString('base64', $gespeichert);
        $this->assertStringNotContainsString('@font-face', $gespeichert);
        $this->assertLessThan(4000, strlen($gespeichert), 'Der Snapshot ist Text, keine Assets.');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function applicant(int $teamId = self::TEAM): RecApplicant
    {
        return RecApplicant::create(['team_id' => $teamId, 'is_active' => true]);
    }

    private function contact(RecApplicant $applicant, string $first, string $last): CrmContact
    {
        $contact = CrmContact::create([
            'team_id' => (int) $applicant->team_id,
            'is_active' => true,
            'first_name' => $first,
            'last_name' => $last,
        ]);

        $applicant->crmContactLinks()->create([
            'contact_id' => $contact->id,
            'team_id' => (int) $applicant->team_id,
        ]);

        return $contact;
    }

    /**
     * Buchung samt eigenem Termin und Interviewern. Eigener Termin pro Buchung,
     * weil rec_interview_bookings unique(rec_interview_id, rec_applicant_id)
     * hat.
     *
     * @param list<string> $leaderNames
     */
    private function booking(RecApplicant $applicant, string $startsAt, array $leaderNames, string $status): RecInterviewBooking
    {
        $interview = RecInterview::create([
            'team_id' => (int) $applicant->team_id,
            'title' => 'Service-Basisschulung',
            'starts_at' => $startsAt,
        ]);

        foreach ($leaderNames as $name) {
            $interview->interviewers()->attach($this->user($name));
        }

        return RecInterviewBooking::create([
            'rec_interview_id' => $interview->id,
            'rec_applicant_id' => $applicant->id,
            'team_id' => (int) $applicant->team_id,
            'status' => $status,
        ]);
    }

    /**
     * Benutzer per Query-Builder, nicht per Model: das Anlegen soll keine
     * Hooks des User-Modells (Casts, Observer) mitschleppen. Gelesen wird
     * spaeter ueber die echte Relation, das ist der Weg, der zaehlt.
     */
    private function user(string $name): int
    {
        return (int) Capsule::table('users')->insertGetId([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . uniqid() . '@example.org',
        ]);
    }

    private function certificateCount(RecApplicant $applicant): int
    {
        return RecTrainingCertificate::query()
            ->where('rec_applicant_id', $applicant->id)
            ->count();
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(User::class);
        $crm = self::packageRootOf(CrmContact::class);
        $own = dirname(__DIR__, 2);

        $files = [
            // users: die Interviewer-Namen kommen aus dieser Tabelle.
            [$core, 'database/migrations/0001_01_01_000000_create_users_table.php'],
            // CRM: Kontakt und Verknuepfung (Vor- und Nachname auf dem Dokument).
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php'],
            [$crm, 'database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php'],
            // Recruiting: Bewerber (public_token-Alter ist Pflicht, der
            // creating-Hook schreibt die Spalte), Settings, Termine/Buchungen
            // samt seat_released_at (der saving-Hook der Buchung schreibt es).
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_04_14_000001_create_rec_interview_tables.php'],
            [$own, 'database/migrations/2026_07_24_000001_add_seat_released_at_to_rec_interview_bookings.php'],
            // Die Tabelle dieses Tasks — aus der echten Migration, siehe
            // Klassen-Docblock.
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

        RecApplicantSettings::create([
            'team_id' => self::TEAM,
            'settings' => [IssueTrainingCertificateService::SETTING_ENABLED => true],
        ]);
    }

    /**
     * Wurzel des Composer-Pakets einer geladenen Klasse: von ihrer Datei
     * aufwaerts, bis ein Verzeichnis database/migrations enthaelt. Damit
     * stammen Schema und Modelle garantiert aus derselben Kopie. Uebernommen
     * aus PlaceholderResolutionPinTest, inklusive der Tiefenbegrenzung.
     */
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
