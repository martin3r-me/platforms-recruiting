<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\InterviewSchedule\Index as SchedulePage;
use Platform\Recruiting\Livewire\Statistics\Index as StatisticsPage;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * ZWEI SCHLOESSER gegen dieselbe Luecke: die Ausschreibung eines FREMDEN Teams darf
 * weder an einen eigenen Termin gehaengt (Validierung) noch angezeigt werden
 * (Eager Load).
 *
 * Der Befund war messbar und neu: `exists:rec_postings,id` prueft nur, ob die ID
 * irgendwo existiert — ein gecrafteter Livewire-Request konnte damit eine fremde
 * Ausschreibung an einen eigenen Termin haengen. Die Statistik-Seite lud die
 * Relation danach ungescopt (`posting:id,title`) und zeigte den fremden TITEL in
 * der Termin-Tabelle („GEHEIM Fremdteam Ausschreibung" in der Ansicht eines
 * anderen Teams).
 *
 * Geprueft werden beide Schloesser einzeln, denn sie schuetzen verschiedene
 * Zeitpunkte: die Validierung verhindert, dass die Zuordnung ENTSTEHT; der Scope
 * verhindert, dass eine bereits vorhandene Zuordnung SICHTBAR wird (Altbestand,
 * Direkt-Import, SQL von Hand). Ein Schloss allein waere je nach Reihenfolge der
 * Ereignisse offen.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth() als Attrappe, feste Uhr).
 */
class InterviewPostingTeamScopeTest extends TestCase
{
    private const TEAM = 8;
    private const FREMDES_TEAM = 99;

    /** Eigene Ausschreibung — muss durchkommen. */
    private const POSTING_EIGEN = 810;

    /** Ausschreibung des fremden Teams — darf nirgends erscheinen. */
    private const POSTING_FREMD = 899;

    /** Eigene Ausschreibung an einer ANDEREN Filiale (Koeln). */
    private const POSTING_ANDERE_FILIALE = 811;

    /** Eigene Ausschreibung in Essen, aber ENTWURF — also nicht online. */
    private const POSTING_ENTWURF = 812;

    private const FREMD_TITEL = 'GEHEIM Fremdteam Ausschreibung';

    /** Termin, an dem die FREMDE Ausschreibung haengt (der gecraftete Zustand). */
    private const INTERVIEW_MIT_FREMDEM_POSTING = 830;

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

            public function shouldUse($name)
            {
                // nicht benutzt: die Komponenten rufen nur auth()->user()
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
        Carbon::setTestNow();
    }

    // -----------------------------------------------------------------
    // Schloss 1: die Zuordnung entsteht nicht
    // -----------------------------------------------------------------

    public function test_eine_fremde_ausschreibung_besteht_die_validierung_nicht(): void
    {
        // Der ECHTE Weg der Regel: gegen die Datenbank geprueft, mit demselben
        // Presence-Verifier, den Laravel im Betrieb benutzt. Ein Test gegen die
        // Regel-STRINGS haette nicht gezeigt, ob die where-Klausel wirkt.
        $fremd = $this->validate(['rec_posting_id' => self::POSTING_FREMD]);

        $this->assertTrue($fremd->fails(), 'die fremde Ausschreibung muss abgewiesen werden');
        $this->assertArrayHasKey('rec_posting_id', $fremd->errors()->toArray());

        // GEGENPROBE, sonst beweist der Test nur, dass die Regel alles ablehnt
        $eigen = $this->validate(['rec_posting_id' => self::POSTING_EIGEN]);
        $this->assertFalse($eigen->fails(), $eigen->errors()->toJson());

        // ... und leer bleibt erlaubt (der Termin braucht keine Ausschreibung)
        $leer = $this->validate(['rec_posting_id' => null]);
        $this->assertFalse($leer->fails(), $leer->errors()->toJson());
    }

    public function test_dieselbe_haertung_gilt_fuer_terminart_und_stelle(): void
    {
        // Dieselbe Regel-Art auf denselben Tabellen: ein halb gescopter Satz Regeln
        // laedt dazu ein, die Luecke fuer „so gemeint" zu halten.
        $this->assertTrue($this->validate(['interview_type_id' => 991])->fails(), 'fremde Terminart');
        $this->assertTrue($this->validate(['rec_position_id' => 991])->fails(), 'fremde Stelle');

        $this->assertFalse($this->validate(['interview_type_id' => 91])->fails());
        $this->assertFalse($this->validate(['rec_position_id' => 81])->fails());
    }

    // -----------------------------------------------------------------
    // Schloss 2: eine bestehende Zuordnung wird nicht angezeigt
    // -----------------------------------------------------------------

    public function test_der_titel_einer_fremden_ausschreibung_erreicht_die_termin_tabelle_nicht(): void
    {
        // Der Termin gehoert dem eigenen Team, seine Ausschreibung nicht. Die
        // Relation folgt dem Fremdschluessel ohne Rueckfrage — deshalb ist der
        // Eager Load gescopt.
        $component = new StatisticsPage();
        $component->ortFilter = 'Essen';

        $interview = $component->interviews()->firstWhere('id', self::INTERVIEW_MIT_FREMDEM_POSTING);
        $this->assertNotNull($interview, 'der Termin selbst gehoert dem Team und bleibt sichtbar');
        $this->assertSame(self::POSTING_FREMD, (int) $interview->rec_posting_id, 'der Fremdschluessel steht noch da');
        $this->assertNull($interview->posting, 'die fremde Ausschreibung wird NICHT geladen');

        // Die Terminart gehoert dem Team und kommt weiter an — der Scope darf nicht
        // pauschal alles wegnehmen.
        $this->assertSame('Schulung', $interview->interviewType?->name);

        // Und in der Tabellen-Zeile steht der TERMIN-Titel statt des fremden
        // (derselbe Rueckfall wie bei einem Termin ohne Ausschreibung).
        $zeile = (new ScheduleScopeProbe())->probeRow($component, self::INTERVIEW_MIT_FREMDEM_POSTING);
        $this->assertSame('Schulung August', $zeile['posting_title']);
        $this->assertFalse($zeile['has_posting']);

        // Kein Weg, auf dem der Titel doch auftaucht
        $this->assertStringNotContainsString(self::FREMD_TITEL, json_encode($zeile, JSON_UNESCAPED_UNICODE));
    }

    public function test_der_pivot_traegt_die_fremde_ausschreibung_nicht_in_die_tabelle(): void
    {
        // DIE DRITTE TUER: nicht der Termin, sondern der BEWERBER. Sein Pivot zeigt
        // auf die fremde Ausschreibung (RecApplicant::postings() ist ungescopt,
        // eigenes Ticket) — bis Task 10 fiel das kaum auf, weil die Seite nur Ort und
        // Taetigkeit daraus las. Mit der Ausschreibungs-Tabelle kam der TITEL dazu,
        // und damit stand er im DOM. Der Eager Load in cohort() ist deshalb gescopt.
        $component = new StatisticsPage();
        $cohort = $component->cohort();
        $rows = $cohort['rows'];

        // Der Titel steckt in KEINER Zeile — auch nicht in einem Nebenfeld
        $this->assertStringNotContainsString(
            self::FREMD_TITEL,
            json_encode($rows, JSON_UNESCAPED_UNICODE),
        );

        // ... und in keiner ANZEIGE-Zeile der Tabelle (postingGroups ist genau die
        // Quelle, aus der Tabelle 1 ihre Zeilen rendert)
        $gruppen = (new CohortViewModel())->postingGroups($rows);
        $this->assertStringNotContainsString(
            self::FREMD_TITEL,
            json_encode(array_map(fn ($g) => $g['posting_title'], $gruppen), JSON_UNESCAPED_UNICODE),
        );
        $this->assertNotContains(
            self::POSTING_FREMD,
            array_map(fn ($g) => $g['posting_id'], $gruppen),
        );

        // Die Bewerbung VERSCHWINDET nicht: Fall 3 („ohne Ausschreibung"), in der
        // Gesamtmenge und im Block „Ohne Filial-Zuordnung" benannt.
        $ohneAusschreibung = array_values(array_filter($rows, fn ($row) => $row['posting_id'] === null));
        $this->assertCount(1, $ohneAusschreibung);
        $this->assertSame([1010], $ohneAusschreibung[0]['ids']);
        $this->assertContains(1010, $cohort['total_ids']);
        $this->assertContains(
            1010,
            (new CohortViewModel())->resolveIds($cohort['unreachable_rows'], ['scope' => 'all'], 'ids'),
        );

        // GEGENPROBE: die eigene Ausschreibung bleibt eine ganz normale Zeile
        $eigen = array_values(array_filter($rows, fn ($row) => $row['posting_id'] === self::POSTING_EIGEN));
        $this->assertCount(1, $eigen);
        $this->assertSame([1011], $eigen[0]['ids']);
        $this->assertSame('Kellner (m/w/d)', $eigen[0]['posting_title']);
    }

    public function test_die_auswahllisten_der_statistik_zeigen_keine_fremde_ausschreibung(): void
    {
        // Gegenprobe an der zweiten Stelle, an der Ausschreibungs-Titel gerendert
        // werden: die Filterleiste. Sie war schon forTeam-gescopt — der Test haelt
        // es fest, damit die Liste beim naechsten Umbau nicht aufgeht.
        $component = new StatisticsPage();
        $component->ortFilter = 'Essen';

        $options = $component->postingOptions();

        $this->assertArrayHasKey(self::POSTING_EIGEN, $options);
        $this->assertArrayNotHasKey(self::POSTING_FREMD, $options);
        $this->assertStringNotContainsString(self::FREMD_TITEL, implode(' | ', $options));
    }

    // -----------------------------------------------------------------
    // Die Auswahlliste folgt den Filtern links von ihr
    // -----------------------------------------------------------------

    public function test_die_auswahlliste_zeigt_nur_ausschreibungen_der_gewaehlten_filiale(): void
    {
        // Der Befund aus dem Live-Blick: bei Filiale „Essen" standen auch
        // Ausschreibungen anderer Filialen zur Wahl. Wer eine davon waehlt, sieht
        // leere Tabellen und erfaehrt nicht, warum.
        $component = new StatisticsPage();
        $component->ortFilter = 'Essen';

        $essen = $component->postingOptions();
        $this->assertArrayHasKey(self::POSTING_EIGEN, $essen);
        $this->assertArrayNotHasKey(self::POSTING_ANDERE_FILIALE, $essen, 'die andere Filiale gehoert nicht in diese Auswahl');

        // GEGENPROBE, sonst zeigte der Test nur, dass die Liste leer ist
        $component->ortFilter = 'Koeln';
        $andere = $component->postingOptions();
        $this->assertArrayHasKey(self::POSTING_ANDERE_FILIALE, $andere);
        $this->assertArrayNotHasKey(self::POSTING_EIGEN, $andere);

        // Ohne Filiale gibt es nichts zu waehlen — die Seite zeigt dort die
        // Aufforderung statt einer Tabelle.
        $component->ortFilter = null;
        $this->assertSame([], $component->postingOptions());
    }

    public function test_die_auswahlliste_folgt_dem_status_filter(): void
    {
        $component = new StatisticsPage();
        $component->ortFilter = 'Essen';

        // Standard „nur online": der Entwurf ist nicht waehlbar
        $component->postingStatusFilter = 'online';
        $this->assertArrayNotHasKey(self::POSTING_ENTWURF, $component->postingOptions());

        // Bei „alle" ist er waehlbar UND als nicht online gekennzeichnet — sonst
        // waeren zwei Lesarten desselben Zustands in derselben Filterleiste.
        $component->postingStatusFilter = 'alle';
        $alle = $component->postingOptions();
        $this->assertArrayHasKey(self::POSTING_ENTWURF, $alle);
        $this->assertStringContainsString('(nicht online)', $alle[self::POSTING_ENTWURF]);
        $this->assertStringNotContainsString('(nicht online)', $alle[self::POSTING_EIGEN]);
    }

    public function test_ein_filterwechsel_verwirft_eine_ungueltig_gewordene_ausschreibung(): void
    {
        // Sonst bliebe eine Ausschreibung stehen, die es in der neuen Auswahl nicht
        // gibt: leere Tabellen, und im Dropdown ein Titel, der dort nicht mehr zur
        // Wahl steht. Dieselbe Falle wie beim Stellenwechsel im Termin-Formular.
        $component = new StatisticsPage();
        $component->ortFilter = 'Essen';
        $component->postingFilter = self::POSTING_EIGEN;

        $component->updatedOrtFilter('Koeln');
        $this->assertNull($component->postingFilter, 'die Essen-Ausschreibung passt nicht zur anderen Filiale');

        // Statuswechsel genauso: der Entwurf faellt weg, sobald nur online gilt
        $component->ortFilter = 'Essen';
        $component->postingStatusFilter = 'alle';
        $component->postingFilter = self::POSTING_ENTWURF;
        $component->updatedPostingStatusFilter('online');
        $this->assertNull($component->postingFilter);

        // GEGENPROBE: eine Auswahl, die weiter gueltig ist, bleibt stehen
        $component->postingFilter = self::POSTING_EIGEN;
        $component->updatedPostingStatusFilter('alle');
        $this->assertSame(self::POSTING_EIGEN, $component->postingFilter);
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /**
     * Validiert einen Termin-Datensatz mit den ECHTEN Regeln der Termin-Seite.
     * $ueberschreibung ersetzt einzelne Felder eines gueltigen Grundsatzes.
     */
    private function validate(array $ueberschreibung): Validator
    {
        $daten = array_merge([
            'title' => 'Schulung',
            'starts_at' => '2026-08-10 10:00:00',
            'status' => 'planned',
            'language' => 'de',
            'is_active' => true,
        ], $ueberschreibung);

        $validator = new Validator(
            new Translator(new ArrayLoader(), 'de'),
            $daten,
            (new ScheduleScopeProbe())->probeRules(),
        );
        $validator->setPresenceVerifier(
            new DatabasePresenceVerifier(Container::getInstance()->make('db')),
        );

        return $validator;
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
            ['id' => 81, 'uuid' => 'spos-81', 'team_id' => self::TEAM, 'title' => 'Kellner',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 82, 'uuid' => 'spos-82', 'team_id' => self::TEAM, 'title' => 'Kellner Koeln',
             'location' => 'Koeln', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 991, 'uuid' => 'spos-991', 'team_id' => self::FREMDES_TEAM, 'title' => 'Fremde Stelle',
             'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_interview_types')->insert([
            ['id' => 91, 'uuid' => 'styp-91', 'team_id' => self::TEAM, 'name' => 'Schulung',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 991, 'uuid' => 'styp-991', 'team_id' => self::FREMDES_TEAM, 'name' => 'Fremde Terminart',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::POSTING_EIGEN, 'uuid' => 'spost-810', 'team_id' => self::TEAM,
             'rec_position_id' => 81, 'title' => 'Kellner (m/w/d)', 'activity' => 'Service',
             'status' => 'published', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // Andere Filiale: darf bei Essen nicht in der Auswahlliste stehen
            ['id' => self::POSTING_ANDERE_FILIALE, 'uuid' => 'spost-811', 'team_id' => self::TEAM,
             'rec_position_id' => 82, 'title' => 'Kellner Koeln (m/w/d)', 'activity' => 'Service',
             'status' => 'published', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            // Entwurf in Essen: nur bei Status „alle" waehlbar, dann markiert
            ['id' => self::POSTING_ENTWURF, 'uuid' => 'spost-812', 'team_id' => self::TEAM,
             'rec_position_id' => 81, 'title' => 'Spueler (m/w/d)', 'activity' => 'Kueche',
             'status' => 'draft', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::POSTING_FREMD, 'uuid' => 'spost-899', 'team_id' => self::FREMDES_TEAM,
             'rec_position_id' => 991, 'title' => self::FREMD_TITEL, 'activity' => 'Service',
             'status' => 'published', 'is_active' => 1, 'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Der gecraftete Zustand: eigener Termin, FREMDE Ausschreibung. Genau so lag
        // er nach einem Request an der alten Validierung in der Datenbank.
        Capsule::table('rec_interviews')->insert([
            ['id' => self::INTERVIEW_MIT_FREMDEM_POSTING, 'uuid' => 'siv-830', 'team_id' => self::TEAM,
             'interview_type_id' => 91, 'rec_position_id' => 81, 'rec_posting_id' => self::POSTING_FREMD,
             'title' => 'Schulung August', 'location' => 'Essen, Zentrale',
             'starts_at' => '2026-08-10 10:00:00', 'max_participants' => 5,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 101, 'uuid' => 'sph-101', 'team_id' => self::TEAM, 'rec_position_id' => 81,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Zwei Bewerber, die den DRITTEN Weg abdecken (Pivot statt Termin):
        //  - 1010 haengt NUR an der fremden Ausschreibung,
        //  - 1011 an der eigenen (Gegenprobe, damit der Test nicht einfach alles
        //    wegnimmt).
        Capsule::table('rec_applicants')->insert([
            ['id' => 1010, 'uuid' => 'sapp-1010', 'team_id' => self::TEAM, 'applied_at' => '2026-07-01',
             'rec_phase_id' => 101, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 1011, 'uuid' => 'sapp-1011', 'team_id' => self::TEAM, 'applied_at' => '2026-07-02',
             'rec_phase_id' => 101, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 1010, 'rec_posting_id' => self::POSTING_FREMD,
             'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 1011, 'rec_posting_id' => self::POSTING_EIGEN,
             'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}

/**
 * Probe fuer diesen Test:
 *  - `probeRules()` reicht die protected rules() der Termin-Seite heraus. Protected
 *    ist sie, weil Livewire sie selbst aufruft; sie public zu machen, waere eine
 *    zusaetzliche Client-Action ohne Nutzen.
 *  - `probeRow()` reicht die protected buildInterviewTable() der Statistik-Seite
 *    heraus (dieselbe Begruendung wie in StatisticsInterviewsTableTest: die Methode
 *    erwartet Eloquent-Objekte, ein gecrafteter Aufruf mit Arrays waere ein 500er).
 */
final class ScheduleScopeProbe extends SchedulePage
{
    public function probeRules(): array
    {
        return $this->rules();
    }

    /** @return array<string,mixed> */
    public function probeRow(StatisticsPage $statistics, int $interviewId): array
    {
        $tabelle = (new StatisticsRowProbe())->probeInterviewRows($statistics);

        foreach ($tabelle as $zeile) {
            if ($zeile['interview_id'] === $interviewId) {
                return $zeile;
            }
        }

        throw new \RuntimeException("keine Tabellenzeile fuer Termin {$interviewId}");
    }
}

/** Reicht buildInterviewTable() der Statistik-Seite heraus (siehe ScheduleScopeProbe). */
final class StatisticsRowProbe extends StatisticsPage
{
    /** @return list<array> */
    public function probeInterviewRows(StatisticsPage $statistics): array
    {
        $cohort = $statistics->cohort();

        return $this->buildInterviewTable($statistics->interviews(), $cohort['termin_rows'], $cohort['rows'])['rows'];
    }
}
