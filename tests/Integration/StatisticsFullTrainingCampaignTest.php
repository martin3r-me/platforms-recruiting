<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * Kampagne „Schulung voll" (05.10.2026) — der Anschluss an Tabelle 2.
 *
 * Ein ausgebuchter, kuenftiger Termin MIT Ausschreibung bekommt in Tabelle 2
 * das Badge „Ausgebucht" und die Pille „N ohne Termin": die Bewerber SEINER
 * Ausschreibung, die keinen Termin haben. Der Klick oeffnet das bestehende
 * Drill-Modal mit dem Kampagnen-Fuss (Scope 'posting_type'), der Kopf nennt
 * den Anlass und ob es Alternativen gibt.
 *
 * Eigene Fixture statt der von StatisticsInterviewsTableTest: dort haengt an
 * jedem Bewerber eine Buchung und jede Aussen-Zahl ist festgenagelt — eine
 * neue Zeile dort haette ein Dutzend Erwartungen verschoben, ohne dass eine
 * davon etwas mit dieser Kampagne zu tun hat.
 *
 * Aufbau wie dort: Container + Capsule von Hand, ECHTE Migrationen per glob,
 * auth() als Attrappe. drill() selbst wird nicht aufgerufen (liest die
 * Computed `cohort`, die es nur im Livewire-Lebenszyklus gibt) — getestet
 * wird, was drill() zusammensetzt: die Token-Aufloesung gegen termin_rows
 * (resolveIds) und die Freischaltung (CampaignModalStateTest, Unit).
 */
class StatisticsFullTrainingCampaignTest extends TestCase
{
    private const TEAM = 5;

    private const POSTING_SERVICE = 20;
    private const POSTING_BANKETT = 22;

    /** Essen, Ausschreibung Service, 2/2 belegt, in der Zukunft — DER Anlass. */
    private const IV_VOLL_ZUKUNFT = 300;
    /** Essen, Service, 0/5, Zukunft — Alternative Nr. 1. */
    private const IV_FREI_ZUKUNFT = 301;
    /** Essen, Service, 1/1, aber VERGANGEN — kein Anlass mehr. */
    private const IV_VOLL_VERGANGEN = 302;
    /** Essen, OHNE Ausschreibung, 1/1, Zukunft — Badge ja, Pille nein. */
    private const IV_OHNE_AUSSCHREIBUNG = 303;
    /** Essen, Service, unbegrenzt, Zukunft — Alternative Nr. 2 (∞ zaehlt mit). */
    private const IV_UNBEGRENZT_ZUKUNFT = 304;
    /** Wuppertal (andere Stelle), frei, Zukunft — KEINE Alternative fuer Essen. */
    private const IV_ANDERE_STELLE = 305;

    private const HEUTE = '2026-08-17 10:00:00';

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

            public function id(): int
            {
                return 77;
            }

            public function shouldUse($name)
            {
            }
        });
        $container->instance('config', new \Illuminate\Config\Repository([]));

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Carbon::setTestNow();
    }

    private function component(?string $ort = 'Essen'): FullTrainingProbe
    {
        $component = new FullTrainingProbe();
        $component->ortFilter = $ort;

        return $component;
    }

    private function rowOf(array $table, int $interviewId): array
    {
        foreach ($table['rows'] as $row) {
            if ($row['interview_id'] === $interviewId) {
                return $row;
            }
        }
        $this->fail('Termin ' . $interviewId . ' fehlt in Tabelle 2.');
    }

    public function test_voller_kuenftiger_termin_mit_ausschreibung_traegt_die_pille(): void
    {
        $table = $this->component()->probeInterviewTable();

        $voll = $this->rowOf($table, self::IV_VOLL_ZUKUNFT);
        $this->assertSame(self::POSTING_SERVICE, $voll['posting_id']);
        $this->assertTrue($voll['voll']);
        $this->assertTrue($voll['kuenftig']);
        // 205 (Buchungsphase), 206 (noch Phase 1) und 208 (Buchung storniert)
        // haben keinen Termin; 209 ist Testbewerber, 210 geparkt, 207 haengt an
        // Bankett. Die Pille zaehlt ALLE drei — dass 206 im Modal nicht waehlbar
        // ist, ist Sache der Segmentregel (CampaignSegment::nurBuchungsphase).
        $this->assertSame(3, $voll['ohne_termin']);
        $this->assertSame(2, $voll['seat_taking'], 'stornierte Buchung belegt keinen Platz');
    }

    public function test_frei_vergangen_und_ohne_ausschreibung_bekommen_keine_pille(): void
    {
        $table = $this->component()->probeInterviewTable();

        $frei = $this->rowOf($table, self::IV_FREI_ZUKUNFT);
        $this->assertFalse($frei['voll']);
        $this->assertTrue($frei['kuenftig']);
        $this->assertSame(3, $frei['ohne_termin'], 'Zahl haengt an der Ausschreibung, nicht am Termin — die View zeigt sie nur bei voll UND kuenftig');

        $vergangen = $this->rowOf($table, self::IV_VOLL_VERGANGEN);
        $this->assertTrue($vergangen['voll']);
        $this->assertFalse($vergangen['kuenftig']);

        $ohne = $this->rowOf($table, self::IV_OHNE_AUSSCHREIBUNG);
        $this->assertTrue($ohne['voll']);
        $this->assertTrue($ohne['kuenftig']);
        $this->assertNull($ohne['posting_id']);
        $this->assertSame(0, $ohne['ohne_termin'], 'ohne Ausschreibung gibt es keine Zielgruppe');

        $unbegrenzt = $this->rowOf($table, self::IV_UNBEGRENZT_ZUKUNFT);
        $this->assertFalse($unbegrenzt['voll'], 'unbegrenzt ist nie voll');
    }

    /**
     * Das Pillen-Token loest gegen termin_rows auf — also unabhaengig vom
     * Ort-Filter der Seite (Entscheidung 05.10.: alle Bewerber der
     * Ausschreibung, die aelteren sind die eigentliche Zielgruppe).
     */
    public function test_token_der_pille_trifft_genau_die_bewerber_ohne_termin_der_ausschreibung(): void
    {
        $component = $this->component('Wuppertal');
        $cohort = $component->cohort();
        $vm = new CohortViewModel();

        $spec = ['scope' => 'posting_type', 'posting' => self::POSTING_SERVICE, 'type' => 'ohne_schulung'];
        $ids = $vm->resolveIds($cohort['termin_rows'], $spec, 'ids');
        sort($ids);

        $this->assertSame([205, 206, 208], $ids);
        $this->assertSame([], $vm->resolveIds($cohort['rows'], $spec, 'ids'), 'Die Auswahl der Seite (Wuppertal) enthaelt sie nicht — deshalb liest drill() fuer diesen Scope termin_rows');
    }

    public function test_anlass_karte_nennt_termin_belegung_und_alternativen(): void
    {
        $component = $this->component();
        $component->drillScopeName = 'posting_type';
        $component->drillScopeType = 'ohne_schulung';
        $component->campaignAnlassInterviewId = self::IV_VOLL_ZUKUNFT;
        $component->campaignAnlassPostingId = self::POSTING_SERVICE;

        $anlass = $component->campaignAnlass();

        $this->assertNotNull($anlass);
        $this->assertSame('01.09.2026 10:00', $anlass['datum']);
        $this->assertSame('Schulung', $anlass['typ']);
        $this->assertSame(2, $anlass['taken']);
        $this->assertSame(2, $anlass['max']);
        $this->assertTrue($anlass['voll']);
        $this->assertSame('Kellner (m/w/d)', $anlass['posting_title']);
        $this->assertSame('Essen', $anlass['stelle']);
        // 301 (frei) und 304 (unbegrenzt) — nicht 302 (vergangen), 303 (voll),
        // 305 (andere Stelle) und nicht der Anlass selbst.
        $this->assertSame(2, $anlass['alternativen']);
    }

    public function test_anlass_karte_ist_fail_closed(): void
    {
        $component = $this->component();
        $component->drillScopeName = 'posting_type';
        $component->drillScopeType = 'ohne_schulung';

        $component->campaignAnlassPostingId = self::POSTING_SERVICE;

        $component->campaignAnlassInterviewId = null;
        $this->assertNull($component->campaignAnlass(), 'ohne Anlass kein Kopf');

        $component->campaignAnlassInterviewId = 999;
        $this->assertNull($component->campaignAnlass(), 'unbekannter Termin: kein Kopf, keine Exception');

        // Review 05.10.: gecraftetes Token mit eigenem, aber anderem Termin
        // ueber der Liste einer anderen Ausschreibung → kein Kopf.
        $component->campaignAnlassInterviewId = self::IV_VOLL_ZUKUNFT;
        $component->campaignAnlassPostingId = self::POSTING_BANKETT;
        $this->assertNull($component->campaignAnlass(), 'Termin gehoert zu Service, Liste zu Bankett');

        $component->campaignAnlassInterviewId = self::IV_OHNE_AUSSCHREIBUNG;
        $component->campaignAnlassPostingId = self::POSTING_SERVICE;
        $this->assertNull($component->campaignAnlass(), 'Termin ohne Ausschreibung passt zu keiner Liste');

        $component->campaignAnlassInterviewId = self::IV_VOLL_ZUKUNFT;
        $component->drillScopeName = 'type_all';
        $this->assertNull($component->campaignAnlass(), 'Kachel-Modus zeigt nie einen Termin-Kopf');
    }

    /**
     * Review 05.10.: die Vorfilter „Einzelne Ausschreibung" und „Quelle"
     * greifen VOR dem Assigner — die Pille zeigt dann eine Teilmenge und muss
     * das sagen. Hier: Filter auf Bankett → der Service-Termin hat „0 ohne
     * Termin in dieser Auswahl", nicht „alle haben einen Termin".
     */
    public function test_vorfilter_schneiden_die_pille_und_werden_benannt(): void
    {
        $component = $this->component();
        $this->assertFalse($component->pillenVorgefiltert());

        $component->postingFilter = self::POSTING_BANKETT;
        $this->assertTrue($component->pillenVorgefiltert());

        $voll = $this->rowOf($component->probeInterviewTable(), self::IV_VOLL_ZUKUNFT);
        $this->assertSame(0, $voll['ohne_termin'], 'Teilmenge: Service-Bewerber sind weggefiltert');
        $this->assertTrue($voll['voll']);

        $component = $this->component();
        $component->sourcePlatformFilter = 99;
        $this->assertTrue($component->pillenVorgefiltert());
    }

    public function test_anlass_ohne_alternativen_wird_als_null_gezaehlt(): void
    {
        // IV_FREI_ZUKUNFT als Anlass gedacht: Service, Essen. Alternativen sind
        // dann 300 (voll → nein), 304 (unbegrenzt → ja), 302 (vergangen → nein).
        $component = $this->component();
        $component->drillScopeName = 'posting_type';
        $component->drillScopeType = 'ohne_schulung';
        $component->campaignAnlassInterviewId = self::IV_FREI_ZUKUNFT;
        $component->campaignAnlassPostingId = self::POSTING_SERVICE;

        $anlass = $component->campaignAnlass();

        $this->assertNotNull($anlass);
        $this->assertFalse($anlass['voll'], 'inzwischen nicht mehr voll → Karte sagt es, statt zu schweigen');
        $this->assertSame(1, $anlass['alternativen'], 'nur der unbegrenzte Termin; der volle zaehlt nicht');
    }

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
        $ts = ['created_at' => $now, 'updated_at' => $now];

        Capsule::table('rec_positions')->insert([
            ['id' => 1, 'uuid' => 'ftpos-1', 'team_id' => self::TEAM, 'title' => 'Kellner', 'location' => 'Essen', 'is_active' => 1] + $ts,
            ['id' => 2, 'uuid' => 'ftpos-2', 'team_id' => self::TEAM, 'title' => 'Küche', 'location' => 'Wuppertal', 'is_active' => 1] + $ts,
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'uuid' => 'ftph-1', 'team_id' => self::TEAM, 'rec_position_id' => 1, 'name' => 'Eingang', 'order' => 1, 'completion_type' => 'fields', 'is_active' => 1] + $ts,
            ['id' => 2, 'uuid' => 'ftph-2', 'team_id' => self::TEAM, 'rec_position_id' => 1, 'name' => 'Schulung buchen', 'order' => 2, 'completion_type' => 'booking', 'is_active' => 1] + $ts,
            ['id' => 3, 'uuid' => 'ftph-3', 'team_id' => self::TEAM, 'rec_position_id' => 2, 'name' => 'Eingang', 'order' => 1, 'completion_type' => 'fields', 'is_active' => 1] + $ts,
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_SERVICE, 'uuid' => 'ftpost-20', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1] + $ts,
            ['id' => self::POSTING_BANKETT, 'uuid' => 'ftpost-22', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'published', 'is_active' => 1] + $ts,
        ]);

        Capsule::table('rec_interview_types')->insert([
            ['id' => 1, 'uuid' => 'fttype-1', 'team_id' => self::TEAM, 'name' => 'Schulung', 'is_active' => 1] + $ts,
        ]);

        $iv = fn (int $id, int $pos, ?int $posting, string $start, ?int $max) => [
            'id' => $id, 'uuid' => 'ftiv-' . $id, 'team_id' => self::TEAM, 'interview_type_id' => 1,
            'rec_position_id' => $pos, 'rec_posting_id' => $posting, 'title' => 'Termin ' . $id,
            'starts_at' => $start, 'max_participants' => $max, 'status' => 'planned', 'is_active' => 1,
        ] + $ts;
        Capsule::table('rec_interviews')->insert([
            $iv(self::IV_VOLL_ZUKUNFT, 1, self::POSTING_SERVICE, '2026-09-01 10:00:00', 2),
            $iv(self::IV_FREI_ZUKUNFT, 1, self::POSTING_SERVICE, '2026-09-15 10:00:00', 5),
            $iv(self::IV_VOLL_VERGANGEN, 1, self::POSTING_SERVICE, '2026-08-01 10:00:00', 1),
            $iv(self::IV_OHNE_AUSSCHREIBUNG, 1, null, '2026-09-20 10:00:00', 1),
            $iv(self::IV_UNBEGRENZT_ZUKUNFT, 1, self::POSTING_SERVICE, '2026-10-01 10:00:00', null),
            $iv(self::IV_ANDERE_STELLE, 2, null, '2026-09-10 10:00:00', 5),
        ]);

        $app = fn (int $id, int $phase, array $extra = []) => [
            'id' => $id, 'uuid' => 'ftapp-' . $id, 'team_id' => self::TEAM, 'applied_at' => '2026-07-' . (10 + $id % 20),
            'rec_phase_id' => $phase,
        ] + $extra + ['is_test' => 0, 'is_parked' => 0] + $ts;
        Capsule::table('rec_applicants')->insert([
            $app(201, 2), $app(202, 2), $app(203, 2), $app(204, 2),
            $app(205, 2),                    // ohne Termin, Buchungsphase
            $app(206, 1),                    // ohne Termin, noch Phase 1
            $app(207, 2),                    // ohne Termin, aber Bankett
            $app(208, 2),                    // Buchung storniert → ohne Termin
            $app(209, 2, ['is_test' => 1]),  // Testbewerber: nie
            $app(210, 2, ['is_parked' => 1]),// geparkt: eigener Zeilentyp, keine Zielgruppe
        ]);

        $piv = fn (int $a, int $p) => ['rec_applicant_id' => $a, 'rec_posting_id' => $p] + $ts;
        Capsule::table('rec_applicant_posting')->insert([
            $piv(201, self::POSTING_SERVICE), $piv(202, self::POSTING_SERVICE), $piv(203, self::POSTING_SERVICE),
            $piv(204, self::POSTING_BANKETT), $piv(205, self::POSTING_SERVICE), $piv(206, self::POSTING_SERVICE),
            $piv(207, self::POSTING_BANKETT), $piv(208, self::POSTING_SERVICE), $piv(209, self::POSTING_SERVICE),
            $piv(210, self::POSTING_SERVICE),
        ]);

        $bk = fn (int $id, int $iv, int $a, string $status, array $extra = []) => [
            'id' => $id, 'uuid' => 'ftbk-' . $id, 'team_id' => self::TEAM, 'rec_interview_id' => $iv,
            'rec_applicant_id' => $a, 'status' => $status, 'seat_released_at' => null, 'is_active' => 1,
        ] + $extra + ['cancelled_at' => null, 'cancelled_by' => null] + $ts;
        Capsule::table('rec_interview_bookings')->insert([
            $bk(401, self::IV_VOLL_ZUKUNFT, 201, 'confirmed'),
            $bk(402, self::IV_VOLL_ZUKUNFT, 202, 'booked'),
            $bk(403, self::IV_VOLL_VERGANGEN, 203, 'attended'),
            $bk(404, self::IV_OHNE_AUSSCHREIBUNG, 204, 'confirmed'),
            $bk(405, self::IV_VOLL_ZUKUNFT, 208, 'cancelled', ['cancelled_at' => '2026-08-10 09:00:00', 'cancelled_by' => 'applicant']),
        ]);
    }
}

/** Reicht buildInterviewTable() heraus (Muster InterviewTableProbe). */
final class FullTrainingProbe extends Index
{
    public function probeInterviewTable(): array
    {
        $cohort = $this->cohort();

        return $this->buildInterviewTable($this->interviews(), $cohort['termin_rows'], $cohort['rows']);
    }
}
