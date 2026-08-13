<?php

namespace Platform\Recruiting\Tests\Integration;

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
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Models\RecInterview;
use Platform\Recruiting\Models\RecInterviewBooking;
use Platform\Recruiting\Models\RecTrainingCertificate;
use Platform\Recruiting\Services\HrDeskRoutingService;
use Platform\Recruiting\Services\IssueTrainingCertificateService;

/**
 * Die Zertifikat-Ausstellung im Ablehnen-Zweig — und vor allem ihr Gegenteil.
 *
 * DER WICHTIGSTE TEST DIESER KLASSE ist testAblehnungOhneHakenBleibtUnveraendert.
 * Er ist ein Charakterisierungs-Test: er war schon VOR dem Eingriff gruen
 * (gemessen gegen cfa178b, siehe task-11-report.md) und muss es danach bleiben.
 * Genau deshalb steht er hier: ein Eingriff in rejectCase(), der im Normalfall
 * etwas verschiebt, faellt niemandem auf, weil die Ablehnung ja weiter
 * funktioniert. Er prueft nicht nur den Zustand danach, sondern das
 * QUERY-PROTOKOLL — sonst waere "kein zusaetzlicher Query" eine Zusicherung
 * statt einer Messung.
 *
 * Die Fixture des Falsifikators ist bewusst so gebaut, dass ein Zertifikat
 * ausgestellt WERDEN KOENNTE: Team-Schalter an, attended-Buchung, Kontakt da.
 * Sonst wuerde der Test nur belegen, dass eine unmoegliche Ausstellung nicht
 * passiert.
 *
 * PROZESSWEITER ZUSTAND, und was davon wirklich traegt — gemessen, nicht
 * behauptet:
 *
 *  - Model::clearBootedModels() im SETUP ist LOAD-BEARING. Ohne die Zeile
 *    brechen alle sieben Tests dieser Klasse im GESAMTLAUF mit "NOT NULL
 *    constraint failed: rec_applicants.uuid" — und bleiben im GEFILTERTEN Lauf
 *    gruen. Eloquents $booted-Cache ist statisch: wer eine Modellklasse zuerst
 *    ohne Event-Dispatcher bootet, laesst deren creating-Hooks (uuid,
 *    public_token) fuer alle spaeteren Klassen still ausfallen.
 *  - Facade::clearResolvedInstances() im Setup aus demselben Grund: die Facades
 *    zeigen sonst auf die in-memory-DB einer frueheren Klasse.
 *  - Im TEARDOWN sind beide Zeilen HEUTE nicht load-bearing (gemessen: entfernt,
 *    Gesamtlauf unveraendert). Alphabetisch folgt IssueTrainingCertificateServiceTest,
 *    und die raeumt in ihrem eigenen Setup dasselbe auf. Sie stehen hier fuer den
 *    Tag, an dem eine Klasse dazwischenrutscht, die das nicht tut.
 *
 * Fixtures loeschen nichts zwischen den Tests, sondern legen pro Test neue
 * Bewerber an (HasExtraFields cacht statisch unter "Klasse:id"). Gezaehlt wird
 * deshalb IMMER gegen einen Bewerber, nie gegen die ganze Tabelle.
 */
class HrDeskRejectionCertificateTest extends TestCase
{
    /** Team MIT eingeschaltetem Schalter. */
    private const TEAM = 3;

    /** Team OHNE Settings-Zeile — Schalter aus, der Normalfall. */
    private const TEAM_AUS = 4;

    /** Der ablehnende HR-Benutzer. */
    private const USER = 42;

    /**
     * Die Anzahl Queries einer Ablehnung OHNE Haken, GEMESSEN gegen den Stand
     * VOR diesem Task (cfa178b) mit derselben Fixture:
     *
     *   update rec_hr_desk_cases  (Fall geschlossen)
     *   select  rec_applicants    (case->applicant, lazy)
     *   update  rec_applicants    (rejected_at, is_on_hr_desk, is_active)
     *   insert  rec_auto_pilot_logs
     *
     * Die Zahl ist kein Selbstzweck: sie faengt jeden Query, den der
     * Zertifikat-Zweig in den Normalfall einschleppt — auch einen, der nicht auf
     * einer der beiden unten geprueften Tabellen landet. Aendert sich der Ablauf
     * absichtlich, gehoert die neue Zahl hierher und die Begruendung in den
     * Commit.
     */
    private const QUERIES_OHNE_HAKEN = 4;

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

        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        // Die Stub-Bindung des Rollback-Tests darf nicht in die naechste Klasse
        // lecken. Sie wird dort schon im finally aufgeraeumt; hier steht sie als
        // zweite Sicherung, weil eine leckende Stub-Bindung von aussen wie ein
        // kaputter Service aussieht.
        Container::getInstance()->forgetInstance(IssueTrainingCertificateService::class);
    }

    // -----------------------------------------------------------------
    // Der Falsifikator
    // -----------------------------------------------------------------

    /**
     * OHNE HAKEN LAEUFT DIE ABLEHNUNG EXAKT WIE HEUTE.
     *
     * Gemessen wird beides: der Zustand danach (Fall, Bewerber, Log) und der
     * Weg dorthin (Query-Protokoll). Die drei Query-Assertions haengen an
     * verschiedenen Fehlern:
     *  - rec_training_certificates: der Zertifikat-Zweig laeuft ueberhaupt an.
     *  - rec_applicant_settings: der Team-Schalter wird bei JEDER Ablehnung
     *    gefragt. Das ist der teure Fehler, weil er nichts kaputt macht — nur
     *    einen firstOrCreate pro Ablehnung, unsichtbar bis zum Profiler.
     *    (Dieselbe Falle stand schon einmal hier: der Settings-Lookup des
     *    Jugendschutz-Zweigs liegt aus genau diesem Grund INNERHALB des
     *    Minderjaehrigen-Ifs.)
     *  - die Gesamtzahl: alles andere, was jemand hier einbaut.
     */
    public function testAblehnungOhneHakenBleibtUnveraendert(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM, RecHrDeskCase::REASON_NON_EU_CITIZEN);

        $queries = $this->mitQueryProtokoll(function () use ($case) {
            (new HrDeskRoutingService())->rejectCase($case, self::USER, 'Papiere reichen nicht.');
        });

        // Der Zustand danach — unveraendert gegenueber heute.
        $frisch = RecHrDeskCase::find($case->id);
        $this->assertSame(RecHrDeskCase::STATUS_REJECTED, $frisch->status);
        $this->assertNotNull($frisch->resolved_at);
        $this->assertSame(self::USER, (int) $frisch->resolved_by_user_id);
        $this->assertSame('Papiere reichen nicht.', $frisch->resolution_notes);

        $applicant->refresh();
        $this->assertNotNull($applicant->rejected_at);
        $this->assertFalse((bool) $applicant->is_on_hr_desk);
        $this->assertFalse((bool) $applicant->auto_pilot);
        $this->assertFalse((bool) $applicant->is_active);
        $this->assertNull($applicant->rec_applicant_status_id, 'Nur der Jugendschutz-Zweig stempelt einen Status.');

        $logs = RecAutoPilotLog::where('rec_applicant_id', $applicant->id)->get();
        $this->assertCount(1, $logs, 'Genau ein Log-Eintrag — kein zusaetzlicher aus dem Zertifikat-Zweig.');
        $this->assertSame('hr_desk_rejected', $logs->first()->type);
        $this->assertStringContainsString('Papiere reichen nicht.', $logs->first()->summary);

        // Kein Zertifikat — obwohl eines moeglich WAERE (Schalter an, attended,
        // Kontakt da). Das ist der Punkt der Fixture.
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));

        // Und der Weg dorthin.
        $this->assertSame([], $this->queriesAuf($queries, 'rec_training_certificates'));
        $this->assertSame([], $this->queriesAuf($queries, 'rec_applicant_settings'));
        $this->assertSame(
            self::QUERIES_OHNE_HAKEN,
            count($queries),
            "Der Normalfall hat einen Query dazubekommen:\n" . implode("\n", array_column($queries, 'query'))
        );
    }

    /**
     * Der ausdrueckliche false-Aufruf ist derselbe Weg wie der Aufruf ohne
     * vierten Parameter. Steht getrennt, weil der Default-Wert und der
     * uebergebene Wert zwei verschiedene Stellen sind — ein Default true (der
     * naheliegende Copy-Paste-Fehler von sendRejectionMessage, das bei
     * Jugendschutz-Faellen bewusst vorbelegt wird) faellt nur im Test darueber
     * auf, ein verrutschtes Negat nur hier.
     */
    public function testExplizitesFalseIstDerselbeWeg(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM, RecHrDeskCase::REASON_NON_EU_CITIZEN);

        $queries = $this->mitQueryProtokoll(function () use ($case) {
            (new HrDeskRoutingService())->rejectCase($case, self::USER, null, false);
        });

        $this->assertSame(RecHrDeskCase::STATUS_REJECTED, RecHrDeskCase::find($case->id)->status);
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));
        $this->assertSame([], $this->queriesAuf($queries, 'rec_training_certificates'));
        $this->assertSame([], $this->queriesAuf($queries, 'rec_applicant_settings'));
        $this->assertSame(self::QUERIES_OHNE_HAKEN, count($queries));
    }

    // -----------------------------------------------------------------
    // Mit Haken
    // -----------------------------------------------------------------

    /** Der Auftrag: Haken gesetzt, Zertifikat da, Ablehnung vollstaendig. */
    public function testMitHakenWirdAusgestellt(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM, RecHrDeskCase::REASON_NON_EU_CITIZEN);

        (new HrDeskRoutingService())->rejectCase($case, self::USER, 'Trotzdem Nachweis.', true);

        $this->assertSame(RecHrDeskCase::STATUS_REJECTED, RecHrDeskCase::find($case->id)->status);
        $this->assertFalse((bool) $applicant->refresh()->is_active);

        $this->assertSame(1, $this->zertifikatAnzahl($applicant));
        $cert = RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->first();
        $this->assertSame(RecTrainingCertificate::KIND_SERVICE_BASIS, $cert->kind);
        $this->assertSame(self::TEAM, (int) $cert->team_id);
        $this->assertSame(self::USER, (int) $cert->issued_by_user_id, 'Der ablehnende HR-Benutzer ist der Aussteller.');
        $this->assertNotEmpty($cert->uuid);
        $this->assertNull($cert->wa_sent_at, 'Der Versand ist ein eigener Schritt nach dem Commit.');
        $this->assertStringContainsString('Erika Mustermann', (string) $cert->personalized_content);
        $this->assertStringContainsString('24.07.2026', (string) $cert->personalized_content);

        // Die Spur fuer HR: ohne sie ist die Ausstellung im Bewerber-Verlauf
        // unsichtbar, bis der Versand dazukommt.
        $typen = RecAutoPilotLog::where('rec_applicant_id', $applicant->id)->pluck('type')->all();
        $this->assertSame(['hr_desk_rejected', 'certificate_issued'], $typen);
    }

    /**
     * KEINE GRUNDLISTE. Der heutige Anlass ist die Nicht-EU-Ablehnung, aber die
     * Bedingung ist die attended-Buchung, nicht der Grund. Eine
     * in_array($reason, ['non_eu_citizen'])-Verengung — auch als "vorerst"
     * gemeint — macht genau diesen Test rot.
     */
    public function testHakenGiltFuerJedenAblehnungsgrund(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM, RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE);

        (new HrDeskRoutingService())->rejectCase($case, self::USER, null, true);

        $this->assertSame(1, $this->zertifikatAnzahl($applicant));
    }

    /**
     * Der Team-Schalter ist aus, der Haken kommt trotzdem an (Race: HR hat das
     * Modal offen, waehrend jemand den Schalter ausschaltet — oder ein
     * manipulierter Livewire-Payload).
     *
     * Zwei Dinge muessen gleichzeitig gelten, und sie ziehen in
     * verschiedene Richtungen: die Ablehnung laeuft durch (eine fehlende
     * Ausstellung darf sie nicht mitreissen), UND es bleibt eine Spur (sie darf
     * nicht stumm verschwinden). Deshalb steht in applyRejection() der
     * isEnabledForTeam()-Vorabcheck und KEIN try/catch um issue(): ein catch
     * wuerde jede andere Ursache mitschlucken.
     */
    public function testAusgeschalteterSchalterStelltNichtAusUndReisstDieAblehnungNichtMit(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM_AUS, RecHrDeskCase::REASON_NON_EU_CITIZEN);

        (new HrDeskRoutingService())->rejectCase($case, self::USER, null, true);

        $this->assertSame(RecHrDeskCase::STATUS_REJECTED, RecHrDeskCase::find($case->id)->status);
        $this->assertFalse((bool) $applicant->refresh()->is_active);
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));

        $typen = RecAutoPilotLog::where('rec_applicant_id', $applicant->id)->pluck('type')->all();
        $this->assertContains('certificate_skipped', $typen, 'Nicht stumm: der uebergangene Haken steht im Log.');

        $summary = (string) RecAutoPilotLog::where('rec_applicant_id', $applicant->id)
            ->where('type', 'certificate_skipped')
            ->value('summary');
        $this->assertStringContainsString(
            IssueTrainingCertificateService::SETTING_ENABLED,
            $summary,
            'Die Meldung nennt den Schalter — sonst sucht HR im Code statt in den Einstellungen.'
        );
    }

    /**
     * ALLES ODER NICHTS. Scheitert die Ausstellung aus einem ECHTEN Grund
     * (nicht: Schalter aus), rollt die Ablehnung mit zurueck — der Fall bleibt
     * offen, der Bewerber aktiv. Sonst stuende ein abgelehnter Bewerber ohne
     * das Dokument da, das HR ihm zugesagt hat, und niemand wuesste davon.
     *
     * Der Fehler wird ueber eine Container-Bindung erzeugt, nicht ueber kaputte
     * Daten: so trifft er genau die Stelle, um die es geht, und kein Nachbar.
     */
    public function testFehlgeschlageneAusstellungRolltDieAblehnungZurueck(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM, RecHrDeskCase::REASON_NON_EU_CITIZEN);

        Container::getInstance()->instance(
            IssueTrainingCertificateService::class,
            new class extends IssueTrainingCertificateService {
                public function issue(RecApplicant $applicant, ?int $issuedByUserId): RecTrainingCertificate
                {
                    throw new \RuntimeException('Ausstellung kaputt (Test-Stub).');
                }
            }
        );

        // Die Exception in eine Variable, NICHT try/fail/catch(\RuntimeException):
        // PHPUnits AssertionFailedError IST eine \RuntimeException, ein fail()
        // im try landete also im eigenen catch und der Test waere gruen, wenn
        // der Wurf ausbleibt. expectException() geht hier nicht, weil nach dem
        // Wurf noch der Rollback geprueft wird.
        $gefangen = null;
        try {
            (new HrDeskRoutingService())->rejectCase($case, self::USER, null, true);
        } catch (\Throwable $e) {
            $gefangen = $e;
        } finally {
            Container::getInstance()->forgetInstance(IssueTrainingCertificateService::class);
        }

        $this->assertNotNull($gefangen, 'Erwartet: der Fehler der Ausstellung schlaegt durch.');
        $this->assertStringContainsString('Test-Stub', $gefangen->getMessage());

        $frisch = RecHrDeskCase::find($case->id);
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $frisch->status, 'Fall zurueckgerollt.');
        $this->assertNull($frisch->resolved_at);

        $applicant->refresh();
        $this->assertNull($applicant->rejected_at, 'Bewerber zurueckgerollt.');
        $this->assertTrue((bool) $applicant->is_active);
        $this->assertTrue((bool) $applicant->is_on_hr_desk);

        $this->assertSame(0, RecAutoPilotLog::where('rec_applicant_id', $applicant->id)->count());
        $this->assertSame(0, $this->zertifikatAnzahl($applicant));
    }

    /**
     * Zweimal ausstellen ist kein Fehlerfall (wird ein abgelehnter Bewerber
     * spaeter doch eingestellt, laeuft Weg b an) — es bleibt bei einem
     * Zertifikat, und der Snapshot der ersten Ausstellung bleibt stehen. Steht
     * hier, weil die Ablehnung der Pfad ist, ueber den das erste Zertifikat
     * entsteht: ein updateOrCreate in issue() waere in dieser Klasse gruen und
     * beim Bewerber falsch.
     */
    public function testZweiteAblehnungLegtKeinZweitesZertifikatAn(): void
    {
        [$applicant, $case] = $this->faerbigerFall(self::TEAM, RecHrDeskCase::REASON_NON_EU_CITIZEN);
        $zweiterFall = $this->fall($applicant, RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE);

        $service = new HrDeskRoutingService();
        $service->rejectCase($case, self::USER, null, true);
        $erstesId = (int) RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->value('id');

        $service->rejectCase($zweiterFall, self::USER, null, true);

        $this->assertSame(1, $this->zertifikatAnzahl($applicant));
        $this->assertSame(
            $erstesId,
            (int) RecTrainingCertificate::where('rec_applicant_id', $applicant->id)->value('id')
        );

        // Und GENAU EIN "ausgestellt"-Log. issue() gibt beim zweiten Mal das
        // bestehende Zertifikat zurueck; ein zweites Log waere eine falsche
        // Aussage ueber ein Dokument, das der Bewerber laengst hat.
        $this->assertSame(
            1,
            RecAutoPilotLog::where('rec_applicant_id', $applicant->id)
                ->where('type', 'certificate_issued')
                ->count()
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * Ein Fall, bei dem eine Ausstellung MOEGLICH waere: Bewerber mit Kontakt
     * und attended-Buchung, offener HR-Fall.
     *
     * @return array{0: RecApplicant, 1: RecHrDeskCase}
     */
    private function faerbigerFall(int $teamId, string $reason): array
    {
        $applicant = RecApplicant::create([
            'team_id' => $teamId,
            'is_active' => true,
            'is_on_hr_desk' => true,
            // auto_pilot bewusst false: so steht ein Bewerber auf dem
            // HR-Schreibtisch (routeToHrDesk setzt es). Mit true wuerde der
            // saving-Guard des Modells calculateProgress() ziehen und das
            // Query-Protokoll um Fremd-Queries erweitern.
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
            'status' => 'attended',
        ]);

        return [$applicant, $this->fall($applicant, $reason)];
    }

    private function fall(RecApplicant $applicant, string $reason): RecHrDeskCase
    {
        return RecHrDeskCase::create([
            'rec_applicant_id' => $applicant->id,
            'team_id' => (int) $applicant->team_id,
            'reason' => $reason,
            'status' => RecHrDeskCase::STATUS_OPEN,
            'opened_at' => '2026-08-01 10:00:00',
        ]);
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
     * flushQueryLog() vor dem Aufruf ist Pflicht, nicht Hygiene: die Fixture
     * oben laeuft ueber dieselbe Verbindung, ihre Inserts stuenden sonst im
     * Protokoll und die Zaehlung waere sinnlos.
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
     * Schema aus den ECHTEN Migrationen, nicht aus tests/Support/TestSchema:
     * fuer die meisten dieser Tabellen gibt es dort keine Methode, und zwei
     * Schema-Quellen in einer Klasse zu mischen waere die Drift, gegen die
     * TestSchema gebaut wurde. Wurzel-Aufloesung per Reflection, weil
     * platforms-core NICHT als Geschwister der Module liegt (Begruendung im
     * Original: PlaceholderResolutionPinTest).
     */
    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(User::class);
        $crm = self::packageRootOf(CrmContact::class);
        $own = dirname(__DIR__, 2);

        $files = [
            [$core, 'database/migrations/0001_01_01_000000_create_users_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php'],
            [$crm, 'database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php'],
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
