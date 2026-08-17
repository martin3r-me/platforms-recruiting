<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Statistics\Index;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * ANSCHLUSS-NACHWEIS fuer die Ausschreibungs-Tabelle: kommen die Felder, die der
 * CohortAssigner nur mit ??-Bruecke liest, aus der ECHTEN Query auch wirklich an?
 *
 * Warum das keine Unit-Tests leisten koennen: der Assigner liest
 * `$applicant['phase_order_reached']`, `$pivot['posting_title']` und
 * `$pivot['posting_closed']` defensiv mit `?? null` bzw. `?? ''`. Ein vergessener
 * Anschluss in der Livewire-Komponente faellt damit NICHT als Fehler auf — die
 * Phasen-Spalten bleiben einfach leer und der Ausschreibungs-Titel leer. Genau
 * diese Sorte stiller Fehlanzeige soll die Seite abschaffen, also braucht sie
 * einen Test, der die Kette Query → Mapping → Assigner am Stueck laeuft.
 *
 * Aufbau wie PostingTargetFieldsTest (Container + Capsule von Hand, ECHTE
 * Migrationen, kein testbench) — mit zwei Ergaenzungen:
 *  - die Migrationen kommen per glob, weil die Query quer durch rec_applicants,
 *    rec_postings, rec_positions, rec_phases, rec_phase_transitions und die
 *    Pivot-Tabelle liest; eine handgepflegte Liste waere genau dann still
 *    falsch, wenn eine neue Spalte dazukommt (Muster:
 *    EmployeeCreationCertificateTest);
 *  - `auth()` ist in der Komponente die einzige Laravel-Abhaengigkeit
 *    (teamId()) und wird als Attrappe in den Container gebunden.
 *
 * Model::unsetEventDispatcher(): die 'creating'/'created'-Hooks von RecPosting
 * (UUID + PostingRefCodeService) sind hier unbeteiligtes Beiwerk. Datensaetze
 * werden deshalb per Query Builder eingefuegt, mit expliziter UUID.
 */
class StatisticsCohortWiringTest extends TestCase
{
    private const TEAM = 3;

    /** Ausschreibung online: status=published UND is_active. */
    private const POSTING_OFFEN = 10;

    /** Ausschreibung NICHT online (draft) — also geschlossen. */
    private const POSTING_ZU = 11;

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

        // auth()->user()->currentTeam->id — die einzige Framework-Abhaengigkeit
        // der Komponente. Als Attrappe im Container, nicht als Umbau der
        // Komponente: getestet werden soll der Produktionspfad.
        // Der auth()-Helper hat einen Rueckgabetyp — die Attrappe muss die
        // Factory-Schnittstelle wirklich implementieren, ein beliebiges Objekt
        // reicht nicht.
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
                // nicht benutzt: die Komponente ruft nur auth()->user()
            }
        });

        self::runRealMigrations();
        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        // Siehe DuplicateMatchQueryTest: setFacadeApplication() setzt nur
        // static::$app, nicht den prozessweiten $resolvedInstance-Cache.
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
    }

    private function cohortRows(?string $ort = null): array
    {
        $component = new Index();
        $component->ortFilter = $ort;

        return $component->cohort()['rows'];
    }

    /** Genau eine Zeile suchen — der Test soll nicht an der Zeilenreihenfolge haengen. */
    private function rowOf(array $rows, ?int $postingId, string $type): array
    {
        foreach ($rows as $row) {
            if ($row['posting_id'] === $postingId && $row['type'] === $type) {
                return $row;
            }
        }

        $this->fail("Keine Zeile mit posting_id=" . var_export($postingId, true) . " und type={$type}");
    }

    public function test_ausschreibungs_titel_und_status_kommen_an_der_zeile_an(): void
    {
        $rows = $this->cohortRows();

        $offen = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung');
        $this->assertSame('Kellner (m/w/d)', $offen['posting_title'], 'Titel der Ausschreibung');
        $this->assertFalse($offen['posting_closed'], 'published + is_active ist online, nicht geschlossen');

        // draft: nicht veroeffentlicht, also geschlossen — auch wenn is_active gesetzt ist
        $zu = $this->rowOf($rows, self::POSTING_ZU, 'ohne_schulung');
        $this->assertSame('Aushilfe Bankett', $zu['posting_title']);
        $this->assertTrue($zu['posting_closed'], 'draft ist nicht online, also geschlossen');
    }

    public function test_ziel_stammdaten_haengen_an_der_zeile_und_bleiben_null_wenn_ungepflegt(): void
    {
        $rows = $this->cohortRows();

        $offen = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung');
        $this->assertSame(10, $offen['bedarf']);
        $this->assertSame(8.0, $offen['bewerbungs_faktor']);
        $this->assertSame('2026-07-01', $offen['published_ymd'], 'Y-m-d-String, kein Carbon');
        $this->assertSame('2026-09-30', $offen['closes_ymd']);

        // Nicht gepflegt bleibt null — kein Default, kein Raten (graue Ampel)
        $zu = $this->rowOf($rows, self::POSTING_ZU, 'ohne_schulung');
        $this->assertNull($zu['bedarf']);
        $this->assertNull($zu['bewerbungs_faktor']);
        $this->assertNull($zu['published_ymd']);
        $this->assertNull($zu['closes_ymd']);
    }

    public function test_phase_erreicht_wird_aus_phase_und_transition_log_gefuellt(): void
    {
        $rows = $this->cohortRows();
        $vm = new CohortViewModel();

        // Bewerber 101 steht in Phase 2, das Log kennt Phase 3 (spaeter
        // zurueckgesetzt) -> Maximum ist 3, kumulativ also 1, 2 und 3.
        // Bewerber 102 steht in Phase 1 ohne Log-Eintrag -> nur Phase 1.
        $offen = $this->rowOf($rows, self::POSTING_OFFEN, 'ohne_schulung');
        $phasen = $offen['columns']['phase_reached'];

        $this->assertSame([101], $phasen[3] ?? [], 'Log gewinnt gegen die niedrigere aktuelle Phase');
        $this->assertSame([101], $phasen[2] ?? [], 'kumulativ: Phase 2 ist mit erreicht');
        $this->assertSame([101], $phasen[1] ?? [], 'kumulativ: Phase 1 ist mit erreicht');

        // ... und derselbe Zugriff, den die Tabelle nimmt (order-qualifiziert)
        $this->assertSame(1, $vm->countIn([$offen], CohortViewModel::phaseColumnKey(3)));
        $this->assertSame(1, $vm->countIn([$offen], CohortViewModel::phaseColumnKey(1)));

        $zu = $this->rowOf($rows, self::POSTING_ZU, 'ohne_schulung');
        $this->assertSame([102], $zu['columns']['phase_reached'][1] ?? []);
        $this->assertArrayNotHasKey(2, $zu['columns']['phase_reached'], 'ohne Log-Eintrag endet der Trichter bei der aktuellen Phase');
    }

    public function test_ausschreibungs_zeilen_tragen_bedarf_und_ampel_bis_in_die_tabelle(): void
    {
        // Der Weg, den die View geht: cohort()-Zeilen -> postingGroups() ->
        // TargetLight. Zeigt in einem Zug, dass die angehaengten Stammdaten in
        // der Gruppe UND in der Ampel ankommen.
        $component = new Index();
        $groups = (new CohortViewModel())->postingGroups($component->cohort()['rows']);

        $bedarfe = [];
        foreach ($groups as $group) {
            $bedarfe[(string) ($group['posting_id'] ?? 'ohne')] = $group['bedarf'];
        }
        $this->assertSame(10, $bedarfe[(string) self::POSTING_OFFEN]);
        $this->assertNull($bedarfe[(string) self::POSTING_ZU]);

        $offen = null;
        foreach ($groups as $group) {
            if ($group['posting_id'] === self::POSTING_OFFEN) {
                $offen = $group;
            }
        }
        $this->assertNotNull($offen);

        // Erfuellung: eine Unterschrift von zehn benoetigten = 10 %, rot
        $this->assertSame(10, $component->fulfilmentLight($offen)['pct']);
        $this->assertSame('red', $component->fulfilmentLight($offen)['status']);

        // Pipeline: Bedarf und Faktor sind gepflegt, also KEINE graue Ampel
        $this->assertNotSame('grey', $component->pipelineLight($offen)['status']);
        $this->assertSame(80, $component->pipelineLight($offen)['target'], '10 x 8,0 benoetigte Bewerbungen');

        // Ungepflegte Ausschreibung bleibt grau — nichts wird geraten
        $zu = null;
        foreach ($groups as $group) {
            if ($group['posting_id'] === self::POSTING_ZU) {
                $zu = $group;
            }
        }
        $this->assertSame('grey', $component->pipelineLight($zu)['status']);
        $this->assertNull($component->fulfilmentLight($zu)['pct']);
    }

    public function test_phasen_ueberschriften_kommen_aus_dem_phasensatz_der_filiale(): void
    {
        $component = new Index();
        $component->ortFilter = 'Essen';

        $this->assertSame(
            [1 => 'Eingang', 2 => 'Telefonat', 3 => 'Schulung'],
            $component->phaseLabels(),
        );

        // Inaktive Phasen gehoeren nicht in die Kopfzeile
        $this->assertArrayNotHasKey(4, $component->phaseLabels());
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    /**
     * Schema aus den ECHTEN Migrationen, die eigenen per glob (Begruendung siehe
     * Klassen-Kommentar).
     *
     * Aus platforms-core nur die beiden Extra-Feld-Tabellen: eine
     * DATEN-Migration des Moduls (2026_04_12_000003_migrate_extra_fields_to_phases)
     * liest sie, sonst bricht der Durchlauf ab. Alles andere aus Fremdpaketen ist
     * nicht noetig — SQLite erzwingt Fremdschluessel ohne PRAGMA foreign_keys=ON
     * nicht, und gelesen werden nur Recruiting-Tabellen.
     */
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

    /** Wurzel-Aufloesung per Reflection: platforms-core liegt nicht als Geschwister der Module. */
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
        $now = '2026-08-17 10:00:00';

        Capsule::table('rec_positions')->insert([
            'id' => 1, 'uuid' => 'pos-1', 'team_id' => self::TEAM, 'title' => 'Kellner',
            'location' => 'Essen', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'uuid' => 'ph-1', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Eingang', 'order' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'uuid' => 'ph-2', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Telefonat', 'order' => 2, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'uuid' => 'ph-3', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Schulung', 'order' => 3, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // inaktiv: darf in phaseLabels() nicht auftauchen
            ['id' => 4, 'uuid' => 'ph-4', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'name' => 'Archiv', 'order' => 4, 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            // online: published + aktiv, Ziel-Felder gepflegt
            ['id' => self::POSTING_OFFEN, 'uuid' => 'post-10', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Kellner (m/w/d)', 'activity' => 'Service', 'status' => 'published', 'is_active' => 1,
             'published_at' => '2026-07-01 08:00:00', 'closes_at' => '2026-09-30 23:59:59',
             'bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'created_at' => $now, 'updated_at' => $now],
            // draft: nicht online, Ziel-Felder NICHT gepflegt
            ['id' => self::POSTING_ZU, 'uuid' => 'post-11', 'team_id' => self::TEAM, 'rec_position_id' => 1,
             'title' => 'Aushilfe Bankett', 'activity' => 'Bankett', 'status' => 'draft', 'is_active' => 1,
             'published_at' => null, 'closes_at' => null,
             'bedarf' => null, 'bewerbungs_faktor' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => 101, 'uuid' => 'app-101', 'team_id' => self::TEAM, 'applied_at' => '2026-07-10',
             'rec_phase_id' => 2, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 102, 'uuid' => 'app-102', 'team_id' => self::TEAM, 'applied_at' => '2026-07-11',
             'rec_phase_id' => 1, 'is_test' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => 101, 'rec_posting_id' => self::POSTING_OFFEN, 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => 102, 'rec_posting_id' => self::POSTING_ZU, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // 101 war schon in Phase 3 und wurde auf 2 zurueckgesetzt: das Log kennt
        // die tiefere Phase, die aktuelle Phase nicht. Genau der Fall, fuer den
        // das Maximum aus beiden Quellen gebildet wird.
        Capsule::table('rec_phase_transitions')->insert([
            ['team_id' => self::TEAM, 'rec_applicant_id' => 101, 'rec_position_id' => 1,
             'from_phase_id' => 1, 'to_phase_id' => 2, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-12 09:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'rec_applicant_id' => 101, 'rec_position_id' => 1,
             'from_phase_id' => 2, 'to_phase_id' => 3, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-13 09:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['team_id' => self::TEAM, 'rec_applicant_id' => 101, 'rec_position_id' => 1,
             'from_phase_id' => 3, 'to_phase_id' => 2, 'trigger' => 'manual', 'source' => 'live',
             'occurred_at' => '2026-07-14 09:00:00', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Eine Unterschrift, damit die Erfuellungs-Quote einen Zaehler hat
        Capsule::table('rec_contracts')->insert([
            // rec_contract_template_id ist NOT NULL; welche Vorlage es ist, spielt
            // fuer die Zaehlung keine Rolle (SQLite erzwingt den FK hier nicht).
            'uuid' => 'con-1', 'team_id' => self::TEAM, 'rec_applicant_id' => 101,
            'rec_contract_template_id' => 1,
            'status' => 'signed', 'sent_at' => '2026-07-20 10:00:00', 'signed_at' => '2026-07-21 10:00:00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
