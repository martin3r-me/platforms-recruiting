<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\InterviewBooking;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Models\RecPosition;
use Platform\Recruiting\Services\WaitlistEnrollmentPlanner;

/**
 * Die zehn Leser der Stelle rufen jetzt die Fassade primaryPosition() statt die
 * Stelle selbst aus der verknuepften Anzeige zu erraten. Drei der betroffenen
 * Stellen sitzen an der OEFFENTLICHEN Buchungsseite, an der Wartelisten-
 * Eintragung und an der Direkteinstellung — ein Fehler dort sieht ein Bewerber.
 *
 * Dieser Test belegt beides:
 *
 *  1. Die WARTELISTE bleibt von der Stelle entkoppelt. Ein Eintrag speichert
 *     seine Wunschorte als eigenen Schnappschuss (`wunschorte`) plus optional
 *     `rec_interview_id` fuers Termin-Abo; der Benachrichtigungs-Pfad
 *     (NotifyWaitlistForInterview) vergleicht den Ort des FREI GEWORDENEN
 *     Termins gegen diesen Schnappschuss — die Stelle des Bewerbers kommt darin
 *     nicht vor. Ein Stellenwechsel darf daran folglich NICHTS aendern, auch
 *     nicht an der Skip-Logik "Termin-Abo gewinnt gegen Ort-Abo".
 *     Beruehrt wird genau EIN Eingang: der Fallback-Ort beim Eintragen, wenn
 *     keine Wunschorte gepflegt sind — derselbe Wert, andere Quelle.
 *
 *  2. Die BUCHUNGSSEITE folgt nach einer Festlegung der neuen Stelle. Das ist
 *     die tragende Umstellung: switchToPosition() faesst den Pivot nicht mehr
 *     an, also wuerde ein Leser, der weiter `postings->first()` raet, nach der
 *     Festlegung wieder die Termine der ALTEN Filiale anzeigen.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr) — Kopf aus
 * InterviewPostingTeamScopeTest kopiert, nur der Bestand ist neu.
 */
class WaitlistUnchangedBySwitchTest extends TestCase
{
    private const TEAM = 8;

    /** Alte Stelle, Ort-Lookup 'essen' — Herkunft der Bewerbung. */
    private const POSITION_ESSEN = 81;

    /** Neue Stelle, Ort-Lookup 'koeln' — Ziel der Festlegung. */
    private const POSITION_KOELN = 82;

    private const POSTING_ESSEN = 810;
    private const POSTING_KOELN = 820;

    private const PHASE_EINGANG = 101;

    /** Termin in Koeln (Stelle 82). */
    private const INTERVIEW_KOELN = 830;

    /** Termin in Essen (Stelle 81) — die "alte Filiale" der Regressionsprobe. */
    private const INTERVIEW_ESSEN = 840;

    /** Phase 1, keine Buchung, KEINE gepflegten Wunschorte. */
    private const APPLICANT = 1010;

    /** Phase 1, aber AKTIVE Buchung — also festgelegt (istFestgelegt()). */
    private const APPLICANT_FESTGELEGT = 1011;

    /** Offener Ort-Eintrag fuer 1010 mit Schnappschuss ['essen']. */
    private const WAITLIST_ORT = 900;

    private const HEUTE = '2026-08-18 10:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $container->instance(AuthFactory::class, new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
                // nicht benutzt: die Komponente ruft nur auth()->user() indirekt
            }
        });

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Model::clearBootedModels();
        Carbon::setTestNow();
    }

    /**
     * Jeder Test faengt beim Bestand aus seed() an. Der Bestand wird in
     * setUpBeforeClass EINMAL angelegt und von allen Tests GETEILT — und fast
     * jeder Test hier ruft switchToPosition(), schreibt also rec_position_id
     * (und moeglicherweise rec_phase_id) um und legt einen Auto-Pilot-Log an.
     * Ohne diesen Reset waere das Ergebnis von der Ausfuehrungsreihenfolge
     * abhaengig. Muster aus PositionSwitchPostingChoiceTest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::setzeBestandAufAusgangszustand();
    }

    // -----------------------------------------------------------------
    // 1) Die Warteliste bleibt von der Stelle entkoppelt
    // -----------------------------------------------------------------

    public function test_ein_stellenwechsel_veraendert_keinen_wartelisten_eintrag(): void
    {
        $vorher = Capsule::table('rec_interview_waitlist')->where('id', 900)->first();

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $nachher = Capsule::table('rec_interview_waitlist')->where('id', 900)->first();

        $this->assertEquals($vorher, $nachher,
            'wunschorte, armed und notified_at duerfen sich nicht bewegen');
    }

    public function test_der_fallback_ort_folgt_der_stelle(): void
    {
        // Ohne gepflegte Wunschorte faellt das Eintragen auf den Ort der Stelle
        // zurueck. Nach der Festlegung ist das die NEUE Stelle — vorher kam
        // derselbe Wert aus dem umgeschriebenen Pivot.
        $applicant = RecApplicant::find(1010);
        $applicant->switchToPosition(RecPosition::find(82));

        $orte = WaitlistEnrollmentPlanner::resolveWunschorte(
            $applicant->fresh()->getExtraField('beschaftigungsort'),
            $applicant->fresh()->primaryPosition()?->beschaftigungsort_lookup_value,
        );

        $this->assertSame(['koeln'], $orte);
    }

    public function test_die_benachrichtigung_trifft_dieselbe_menge(): void
    {
        // Der Trigger-Pfad liest den Ort des frei gewordenen Termins und vergleicht
        // ihn gegen den Schnappschuss im Eintrag — die Stelle des Bewerbers kommt
        // darin nicht vor. Ein Wechsel darf daran nichts aendern.
        $treffer = fn () => RecInterviewWaitlist::query()
            ->where('team_id', self::TEAM)->whereNull('notified_at')
            ->whereNull('rec_interview_id')
            ->whereJsonContains('wunschorte', 'essen')
            ->pluck('rec_applicant_id')->all();

        $vorher = $treffer();
        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $this->assertSame($vorher, $treffer());
        $this->assertSame([1010], $vorher, 'der Eintrag bleibt fuer essen zustaendig');
    }

    public function test_ein_termin_abo_gewinnt_weiter_gegen_das_ort_abo(): void
    {
        // Skip-Logik aus NotifyWaitlistForInterview: wer ein offenes Termin-Abo fuer
        // genau diesen Termin hat, wird vom Ort-Zweig uebersprungen.
        Capsule::table('rec_interview_waitlist')->insert([
            'id' => 901, 'uuid' => 'wl-901', 'team_id' => self::TEAM,
            'rec_applicant_id' => 1010, 'rec_interview_id' => 830,
            'wunschorte' => json_encode([]), 'armed' => 1,
            'enrolled_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $terminAbo = RecInterviewWaitlist::query()
            ->where('rec_interview_id', 830)->whereNull('notified_at')
            ->pluck('rec_applicant_id')->all();

        $this->assertSame([1010], $terminAbo, 'das Termin-Abo bleibt unberuehrt bestehen');
    }

    // -----------------------------------------------------------------
    // 2) Die Buchungsseite folgt der Stelle, nicht der Anzeige
    // -----------------------------------------------------------------

    public function test_nach_der_festlegung_zeigt_die_seite_die_neue_filiale(): void
    {
        // DIE tragende Umstellung (InterviewBooking::resolvePositionIdsForApplicant):
        // 1011 hat eine aktive Buchung, ist also festgelegt und sieht nur noch die
        // Termine SEINER Stelle. Der Pivot zeigt weiter auf die Essener Anzeige —
        // wer daraus die Stelle raet, zeigt ihm nach dem Wechsel weiter Essen.
        $seite = new InterviewBooking();
        $seite->applicantId = self::APPLICANT_FESTGELEGT;
        $seite->teamId = self::TEAM;

        $this->assertTrue(RecApplicant::find(1011)->istFestgelegt(), 'Vorbedingung: festgelegt');
        $this->assertSame([self::INTERVIEW_ESSEN], self::terminIds($seite->visibleInterviews()),
            'vor dem Wechsel: die Termine der Herkunfts-Filiale');

        RecApplicant::find(1011)->switchToPosition(RecPosition::find(82));

        $this->assertSame([self::INTERVIEW_KOELN], self::terminIds($seite->visibleInterviews()),
            'nach dem Wechsel: die Termine der NEUEN Filiale — nicht mehr Essen');
    }

    public function test_auch_ohne_festlegung_folgt_die_terminliste_der_stelle(): void
    {
        // Gegenprobe im ungefestgelegten Zweig (kein Wunschort gepflegt, also
        // greift dort nur der primary-Fallback): dieselbe Erwartung, anderer Pfad
        // durch resolvePositionIdsForApplicant().
        $seite = new InterviewBooking();
        $seite->applicantId = self::APPLICANT;
        $seite->teamId = self::TEAM;

        $this->assertFalse(RecApplicant::find(1010)->istFestgelegt(), 'Vorbedingung: nicht festgelegt');
        $this->assertSame([self::INTERVIEW_ESSEN], self::terminIds($seite->visibleInterviews()));

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $this->assertSame([self::INTERVIEW_KOELN], self::terminIds($seite->visibleInterviews()));
    }

    public function test_der_ort_button_bleibt_anklickbar(): void
    {
        // ortResolvable() entscheidet, ob der "Benachrichtige mich"-Button
        // ueberhaupt angeboten wird (leere Aufloesung = Geister-Eintrag-Schutz).
        // Ohne gepflegte Wunschorte haengt das allein am Ort der Stelle — vor UND
        // nach dem Wechsel muss er anklickbar bleiben, sonst verschwindet die
        // Warteliste fuer den Bewerber still.
        $seite = new InterviewBooking();
        $seite->applicantId = self::APPLICANT;
        $seite->teamId = self::TEAM;

        $this->assertTrue($seite->ortResolvable(), 'vor dem Wechsel');

        RecApplicant::find(1010)->switchToPosition(RecPosition::find(82));

        $this->assertTrue($seite->ortResolvable(), 'nach dem Wechsel');

        // Gegenprobe: ohne Ort an der Stelle faellt der Button weg — sonst wuerde
        // der Test oben nur behaupten, die Methode gebe immer true zurueck.
        Capsule::table('rec_positions')->where('id', 82)
            ->update(['beschaftigungsort_lookup_value' => null]);

        $this->assertFalse($seite->ortResolvable(), 'ohne Ort an der Stelle kein Button');
    }

    // -----------------------------------------------------------------
    // 3) Query-Budget und Landkarte
    // -----------------------------------------------------------------

    public function test_die_fassade_kostet_keine_zusaetzliche_query(): void
    {
        // primaryPosition() liest ueber die position-Relation. Mit dem Eager Load
        // der Aufrufstellen ('position' neben 'postings.position') darf sie KEINE
        // Query mehr kosten als der alte Weg — sonst entstuende in Schleifen und
        // Sammlungen (DirectHire gruppiert eine ganze Liste) ein N+1.
        $verbindung = Capsule::connection();

        $applicant = RecApplicant::with(['postings.position', 'position', 'phase'])->find(1010);

        $verbindung->flushQueryLog();
        $verbindung->enableQueryLog();
        $alterWeg = $applicant->postings->first()?->rec_position_id;
        $alt = count($verbindung->getQueryLog());

        $verbindung->flushQueryLog();
        $neuerWeg = $applicant->primaryPosition()?->id;
        $neu = count($verbindung->getQueryLog());
        $verbindung->disableQueryLog();

        $this->assertSame(0, $alt, 'Vorflug: der alte Weg war eager geladen');
        $this->assertSame(0, $neu, 'die Fassade darf nicht nachladen');
        $this->assertSame((int) $alterWeg, (int) $neuerWeg, 'ohne gesetztes Feld dieselbe Antwort');

        // Der Eager Load, der genau das in der Direkteinstellung sicherstellt.
        // Gemessen mit 20 Bewerbern: 5 Queries mit 'position' im with(), 24 ohne
        // (4 + eine pro Bewerber) — das ist der N+1, den dieser Guard verhindert.
        $direkteinstellung = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/DirectHire/Index.php');
        $this->assertStringContainsString("'position',\n                'postings.position',", $direkteinstellung,
            "der Sammlungs-Loader der Direkteinstellung muss 'position' eager laden");
    }

    public function test_kein_leser_raet_die_stelle_mehr_aus_der_anzeige(): void
    {
        // Die Landkarte der Umstellung: genau diese vier Dateien lasen die Stelle
        // aus `postings->first()`. Der Test zaehlt die Fassaden-Aufrufe je Datei,
        // damit eine vergessene oder spaeter neu eingebaute Rate-Stelle auffaellt
        // — die Verhaltenstests oben deckeln nur die Buchungsseite ab, nicht die
        // Direkteinstellung und die beiden Heil-Kommandos (die brauchen eine
        // gebootete App).
        // Erwartete Aufrufe von ->primaryPosition() je Datei. Zwei Zahlen liegen
        // ueber den umgestellten Lesern, beide mit Grund:
        //  - Buchungsseite 6 statt 5: maybeSwitchPosition() rief die Fassade schon
        //    vor diesem Umbau (Stufe 0 des Pakets).
        //  - Direkteinstellung 4 statt 3: eigeneStelleIstDirekteinstellung() ist
        //    dazugekommen (das Blade fragt damit, ob es den Knopf "Datenerfassung
        //    starten" anbieten darf) — dieselbe Regel, aber ein eigener Leser.
        $erwartet = [
            'src/Livewire/Public/InterviewBooking.php'   => 6,
            'src/Livewire/DirectHire/Index.php'          => 4,
            'src/Console/Commands/FixApplicantPhase.php' => 1,
            'src/Console/Commands/SyncPhases.php'        => 1,
        ];

        $wurzel = dirname(__DIR__, 2);

        foreach ($erwartet as $datei => $anzahl) {
            $quelle = file_get_contents($wurzel . '/' . $datei);
            $this->assertNotFalse($quelle, "Datei fehlt: {$datei}");

            $this->assertSame(0, substr_count($quelle, 'postings->first()'),
                "{$datei} raet die Stelle noch aus der Anzeige");
            $this->assertSame($anzahl, substr_count($quelle, '->primaryPosition()'),
                "{$datei} ruft die Fassade nicht so oft wie erwartet");
        }

        // Die Festlegungs-Regel steht nur noch EINMAL im Modell: die Buchungsseite
        // leitet sie nicht mehr selbst aus Phase-order und Buchungsstatus her.
        $buchungsseite = file_get_contents($wurzel . '/src/Livewire/Public/InterviewBooking.php');
        $this->assertStringContainsString('istFestgelegt()', $buchungsseite);
        $this->assertStringNotContainsString("phase?->order ?? 0) >= 3", $buchungsseite);
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /** @param list<\Platform\Recruiting\Models\RecInterview> $termine */
    private static function terminIds(array $termine): array
    {
        return array_map(fn ($termin) => (int) $termin->id, $termine);
    }

    /**
     * Setzt genau die Spalten und Zeilen zurueck, die die Tests dieser Klasse
     * anfassen — der Bestand wird geteilt (setUpBeforeClass).
     *
     *  - rec_applicants.rec_position_id UND rec_phase_id von 1010/1011
     *    (switchToPosition schreibt beide, wenn es eine gleich-order-Phase an
     *    der neuen Stelle findet)
     *  - rec_positions.beschaftigungsort_lookup_value von 82
     *    (test_der_ort_button_bleibt_anklickbar nullt es fuer die Gegenprobe)
     *  - der Pivot beider Bewerber (kein Test haengt ihn um, aber
     *    switchToPosition hat ihn frueher geloescht — der Reset macht die
     *    Herkunfts-Zusicherung unabhaengig davon)
     *  - die Wartelisten-Zeilen (Test 4 legt 901 an)
     *  - rec_auto_pilot_logs (switchToPosition protokolliert jeden Wechsel)
     *  - core_extra_field_values (remapExtraFieldValuesToPosition schreibt dort)
     */
    private static function setzeBestandAufAusgangszustand(): void
    {
        Capsule::table('rec_applicants')->whereIn('id', [self::APPLICANT, self::APPLICANT_FESTGELEGT])
            ->update(['rec_position_id' => null, 'rec_phase_id' => self::PHASE_EINGANG]);

        Capsule::table('rec_positions')->where('id', self::POSITION_KOELN)
            ->update(['beschaftigungsort_lookup_value' => 'koeln']);

        Capsule::table('rec_applicant_posting')
            ->whereIn('rec_applicant_id', [self::APPLICANT, self::APPLICANT_FESTGELEGT])->delete();
        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::APPLICANT, 'rec_posting_id' => self::POSTING_ESSEN,
             'applied_at' => '2026-07-01', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
            ['rec_applicant_id' => self::APPLICANT_FESTGELEGT, 'rec_posting_id' => self::POSTING_ESSEN,
             'applied_at' => '2026-07-01', 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE],
        ]);

        Capsule::table('rec_interview_waitlist')->delete();
        Capsule::table('rec_interview_waitlist')->insert([
            'id' => self::WAITLIST_ORT, 'uuid' => 'wl-900', 'team_id' => self::TEAM,
            'rec_applicant_id' => self::APPLICANT, 'rec_interview_id' => null,
            'wunschorte' => json_encode(['essen']), 'armed' => 1, 'notified_at' => null,
            'enrolled_at' => self::HEUTE, 'created_at' => self::HEUTE, 'updated_at' => self::HEUTE,
        ]);

        Capsule::table('rec_auto_pilot_logs')->delete();
        Capsule::table('core_extra_field_values')->delete();
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(\Platform\Core\Models\CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
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

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }

    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => self::POSITION_ESSEN, 'uuid' => 'wpos-81', 'team_id' => self::TEAM,
             'title' => 'Essen', 'location' => 'Essen', 'is_active' => 1,
             'beschaftigungsort_lookup_value' => 'essen',
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSITION_KOELN, 'uuid' => 'wpos-82', 'team_id' => self::TEAM,
             'title' => 'Koeln', 'location' => 'Koeln', 'is_active' => 1,
             'beschaftigungsort_lookup_value' => 'koeln',
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_EINGANG, 'uuid' => 'wph-101', 'team_id' => self::TEAM,
             'rec_position_id' => self::POSITION_ESSEN, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_ESSEN, 'uuid' => 'wpstg-810', 'rec_position_id' => self::POSITION_ESSEN,
             'team_id' => self::TEAM, 'title' => 'Essen Anzeige', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_KOELN, 'uuid' => 'wpstg-820', 'rec_position_id' => self::POSITION_KOELN,
             'team_id' => self::TEAM, 'title' => 'Koeln Anzeige', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interviews')->insert([
            ['id' => self::INTERVIEW_KOELN, 'uuid' => 'wiv-830', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_KOELN,
             'title' => 'Schulung Koeln', 'location' => 'Koeln, Zentrale',
             'starts_at' => '2026-08-20 10:00:00', 'max_participants' => 5, 'status' => 'planned',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::INTERVIEW_ESSEN, 'uuid' => 'wiv-840', 'team_id' => self::TEAM,
             'interview_type_id' => null, 'rec_position_id' => self::POSITION_ESSEN,
             'title' => 'Schulung Essen', 'location' => 'Essen, Filiale',
             'starts_at' => '2026-08-21 10:00:00', 'max_participants' => 5, 'status' => 'planned',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::APPLICANT, 'uuid' => 'wapp-1010', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'is_test' => 0,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::APPLICANT_FESTGELEGT, 'uuid' => 'wapp-1011', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG, 'is_test' => 0,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Die aktive Buchung macht 1011 festgelegt — sie haengt am Koelner Termin,
        // weil genau dieser Weg (Buchung in einer anderen Filiale) den
        // Stellenwechsel ausloest.
        Capsule::table('rec_interview_bookings')->insert([
            ['uuid' => 'wbk-1011', 'rec_interview_id' => self::INTERVIEW_KOELN,
             'rec_applicant_id' => self::APPLICANT_FESTGELEGT, 'status' => 'booked',
             'is_active' => 1, 'team_id' => self::TEAM,
             'cancelled_by' => null, 'cancelled_at' => null,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        self::setzeBestandAufAusgangszustand();
    }
}
