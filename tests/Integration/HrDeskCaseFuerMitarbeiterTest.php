<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrDeskCase;

/**
 * Ein HR-Fall auch fuer den, der nie Bewerber war.
 *
 * rec_hr_desk_cases hing bisher ausschliesslich an rec_applicant_id — und
 * zwar NOT NULL. Wer ueber ZAS kam und nie durch den Bewerberprozess lief
 * (das ist der Grossteil des Bestands), konnte deshalb gar keinen Fall
 * bekommen. Die harte Sperre des Einsatz-Triggers braucht aber genau fuer
 * diese Menschen einen.
 *
 * WARUM DIE WELT AUS DEN MIGRATIONEN KOMMT und nicht aus einem handgebauten
 * Schema: ein handgebautes Schema kann die neue Spalte weglassen, und SQLite
 * macht aus einem fehlenden Spaltennamen kein Fehler, sondern ein
 * String-Literal — der Test waere gruen und wertlos.
 */
final class HrDeskCaseFuerMitarbeiterTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository([]));

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

        $dateien = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($dateien);
        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // Welche Migration in dieser Umgebung nicht laufen kann, haelt
                // MassenzuweisungGeschlosseneWeltTest fest.
            }
        }
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('config');
        $container->forgetInstance('db');
        $container->forgetInstance('db.schema');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /**
     * WARUM DER WAECHTER HIER KURZ WIEDER AN MUSS: im Baum rufen 94
     * Testdateien Model::unguard() und keine einzige reguard(). $unguarded
     * ist statisch — ohne das Zuruecksetzen schriebe create() die Spalte im
     * Gesamtlauf auch dann, wenn sie gar nicht in $fillable steht, und
     * dieser Test koennte den Unterschied nicht herstellen. Der vorherige
     * Zustand wird danach exakt wiederhergestellt.
     */
    private function mitWaechter(callable $tat): mixed
    {
        $vorher = Model::isUnguarded();
        Model::reguard();

        try {
            return $tat();
        } finally {
            $vorher ? Model::unguard() : Model::reguard();
        }
    }

    public function test_ein_fall_kann_an_einem_mitarbeiter_haengen(): void
    {
        $fall = $this->mitWaechter(fn () => RecHrDeskCase::create([
            'uuid'            => (string) Str::uuid(),
            'rec_employee_id' => 42,
            'team_id'         => 6,
            'reason'          => RecHrDeskCase::REASON_WORK_PERMIT,
            'status'          => RecHrDeskCase::STATUS_OPEN,
            'opened_at'       => now(),
        ]));

        $this->assertSame(42, $fall->fresh()->rec_employee_id);
        $this->assertNull($fall->fresh()->rec_applicant_id);
    }

    /**
     * Der alte Weg bleibt: ein Fall aus dem Bewerberprozess haengt
     * weiterhin am Bewerber und braucht keinen Mitarbeiter. Ohne diese
     * Zusicherung koennte die Migration die eine Haelfte oeffnen und die
     * andere zuziehen, ohne dass es auffaellt.
     */
    public function test_der_fall_aus_dem_bewerberprozess_haengt_weiter_am_bewerber(): void
    {
        $fall = $this->mitWaechter(fn () => RecHrDeskCase::create([
            'uuid'             => (string) Str::uuid(),
            'rec_applicant_id' => 7,
            'team_id'          => 6,
            'reason'           => RecHrDeskCase::REASON_NON_EU_CITIZEN,
            'status'           => RecHrDeskCase::STATUS_OPEN,
            'opened_at'        => now(),
        ]));

        $this->assertSame(7, $fall->fresh()->rec_applicant_id);
        $this->assertNull($fall->fresh()->rec_employee_id);
    }

    /**
     * Der Index ueber die SPALTE, nicht ueber den NAMEN. In diesem Zweig
     * prueste einmal ein Test den Index-Namen und haette einen Index ueber
     * die falschen Spalten bestanden; die Abfrage des Triggers sucht die
     * offenen Faelle EINES Mitarbeiters, und ohne Index liest sie die ganze
     * Tabelle.
     */
    public function test_auf_der_mitarbeiter_spalte_liegt_ein_index(): void
    {
        $spaltenJeIndex = array_map(
            fn (array $index) => $index['columns'],
            Schema::getIndexes('rec_hr_desk_cases'),
        );

        $this->assertContains(
            ['rec_employee_id'],
            $spaltenJeIndex,
            'kein Index ueber rec_employee_id — gefunden: '.json_encode($spaltenJeIndex),
        );
    }

    public function test_die_migration_ist_idempotent_und_umkehrbar(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->up(); // zweiter Lauf darf nicht werfen (hasColumn-Wache)
        $this->assertTrue(Schema::hasColumn('rec_hr_desk_cases', 'rec_employee_id'));

        $migration->down();
        $migration->down(); // auch die Umkehrung traegt die Wache
        $this->assertFalse(Schema::hasColumn('rec_hr_desk_cases', 'rec_employee_id'));
        $this->assertFalse(
            $this->istNullable('rec_applicant_id'),
            'die Umkehrung nimmt die Pflicht zurueck, solange keine Zeile ohne Bewerber dasteht',
        );

        $migration->up(); // und wieder hoch, damit der Zustand steht
        $this->assertTrue($this->istNullable('rec_applicant_id'));
    }

    /**
     * Gemessen, nicht behauptet: steht eine Zeile OHNE Bewerber da — und
     * genau die legt der Einsatz-Trigger an —, laesst die Umkehrung die
     * Spalte nullable. Ohne diese Bremse scheiterte das ALTER mitten im
     * Rueckbau und liesse die Tabelle halb umgebaut zurueck.
     */
    public function test_die_umkehrung_laesst_die_pflicht_weg_wenn_faelle_ohne_bewerber_dastehen(): void
    {
        $this->mitWaechter(fn () => RecHrDeskCase::create([
            'uuid'            => (string) Str::uuid(),
            'rec_employee_id' => 42,
            'team_id'         => 6,
            'reason'          => RecHrDeskCase::REASON_WORK_PERMIT,
            'status'          => RecHrDeskCase::STATUS_OPEN,
            'opened_at'       => now(),
        ]));

        $this->migration()->down();

        $this->assertTrue($this->istNullable('rec_applicant_id'));
        $this->assertSame(1, Capsule::table('rec_hr_desk_cases')->count(), 'die Zeile ueberlebt den Rueckbau');
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/database/migrations/2026_10_01_000002_add_employee_to_hr_desk_cases.php';
    }

    private function istNullable(string $spalte): bool
    {
        foreach (Schema::getColumns('rec_hr_desk_cases') as $beschreibung) {
            if ($beschreibung['name'] === $spalte) {
                return $beschreibung['nullable'];
            }
        }

        $this->fail("Spalte {$spalte} gibt es nicht");
    }

    /**
     * Der Grund braucht ein deutsches Label: der HR-Schreibtisch baut seine
     * Filterleiste aus REASON_LABELS (HrDesk\Index::reasonCounts). Ein Grund
     * ohne Label ist dort nicht filterbar.
     */
    public function test_der_grund_hat_ein_label_fuer_die_oberflaeche(): void
    {
        $this->assertSame('work_permit', RecHrDeskCase::REASON_WORK_PERMIT);
        $this->assertArrayHasKey(RecHrDeskCase::REASON_WORK_PERMIT, RecHrDeskCase::REASON_LABELS);
        $this->assertNotSame(
            '',
            trim(RecHrDeskCase::REASON_LABELS[RecHrDeskCase::REASON_WORK_PERMIT]),
        );

        // Und die Methode, die die Oberflaeche wirklich ruft, liefert es auch
        // — sie faellt sonst still auf den rohen Code zurueck.
        $fall = new RecHrDeskCase();
        $fall->reason = RecHrDeskCase::REASON_WORK_PERMIT;
        $this->assertNotSame(RecHrDeskCase::REASON_WORK_PERMIT, $fall->reasonLabel());
    }
}
