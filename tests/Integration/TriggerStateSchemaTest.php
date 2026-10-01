<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecPerson;

/**
 * Der Zustand, den die Nachrichtenregeln des Einsatz-Triggers brauchen.
 *
 * WARUM DIESER TEST MEHR PRUEFT ALS DIE SPALTENNAMEN: ein Test, der nur
 * hasColumn() fragt, besteht auch dann, wenn die Spalte den falschen Typ
 * traegt, NOT NULL ist oder der Cast fehlt — in diesem Zweig hat ein
 * Migrationstest schon einmal den Index-NAMEN geprueft und einen Index ueber
 * die falschen Spalten durchgewinkt. Deshalb steht hier neben jedem Namen
 * die Sache selbst: ein Wert geht rein und kommt zurueck, eine Zeile ohne
 * die drei Spalten laesst sich anlegen (also sind sie nullable), und der
 * Zeitstempel kommt als Datum zurueck (also greift der Cast).
 *
 * DIE WELT KOMMT AUS DEN MIGRATIONEN, nicht aus einem handgebauten Schema
 * (Muster: MassenzuweisungGeschlosseneWeltTest). Ein handgebautes Schema
 * koennte die neue Spalte vergessen, und SQLite macht aus einem fehlenden
 * Spaltennamen kein Fehler, sondern ein String-Literal — der Test waere
 * gruen und wertlos.
 */
final class TriggerStateSchemaTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_01_000001_add_trigger_state.php';

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository([]));

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Zweite Verbindung NUR als Grammatik-Attrappe: sie wird nie
        // verbunden, sondern ausschliesslich in pretend() benutzt, wo Laravel
        // die DDL erzeugt und nicht absetzt. Kein MySQL-Server noetig.
        $this->capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => '127.0.0.1',
            'database'  => 'attrappe',
            'username'  => 'attrappe',
            'password'  => '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ], 'mysql-attrappe');
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->migrationenFahren();
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
     * Alle Migrationen des Moduls gegen die frische SQLite-Datenbank. Was
     * hier wirft, wird verschluckt — welche Migration in dieser Umgebung
     * nicht laufen kann, haelt MassenzuweisungGeschlosseneWeltTest fest;
     * diese Zusicherung hier ein zweites Mal zu bauen hiesse, sie zu
     * verdoppeln.
     */
    private function migrationenFahren(): void
    {
        $dateien = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($dateien);

        foreach ($dateien as $datei) {
            try {
                (require $datei)->up();
            } catch (\Throwable) {
                // siehe Docblock
            }
        }
    }

    private function migration(): object
    {
        return require dirname(__DIR__, 2).'/'.self::MIGRATION;
    }

    public function test_die_drei_spalten_stehen_im_schema(): void
    {
        $this->assertTrue(Schema::hasColumn('rec_persons', 'aufgaben_signatur'));
        $this->assertTrue(Schema::hasColumn('rec_persons', 'aufgaben_gemeldet_at'));
        $this->assertTrue(Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at'));

        // Nicht nur der Name, sondern der Typ: eine Signatur ist ein kurzer
        // Text, die beiden Stempel sind Zeitpunkte. Stuende hier ein
        // Zeitstempel als Text, liefe jeder Vergleich "aelter als" auf einen
        // Zeichenkettenvergleich hinaus.
        //
        // DIE LAENGE STEHT HIER NICHT, weil SQLite sie nicht kennt: Laravels
        // SQLite-Grammatik schreibt fuer string(64) UND fuer string(255)
        // dasselbe blosse "varchar" in die Tabelle, auch mit
        // getColumnType(..., true) — gemessen, nicht vermutet. Die 64 haelt
        // deshalb test_auf_mysql_entsteht_varchar_64_hinter_den_genannten_ankern
        // fest, auf der Grammatik, auf der sie etwas bedeutet.
        $this->assertSame('varchar', Schema::getColumnType('rec_persons', 'aufgaben_signatur'));

        // GRENZE DIESER ZUSICHERUNG: "datetime" ist die SQLite-Lesart einer
        // timestamp-Spalte; MySQL meldet dort "timestamp". Geprueft ist hier
        // also "ein Zeitpunkt-Typ in der Testumgebung", nicht der Typname auf
        // der Produktion — den haelt
        // test_auf_mysql_entsteht_varchar_64_hinter_den_genannten_ankern
        // fest. Wer die Suite einmal gegen MySQL faehrt, muss hier nachziehen.
        $this->assertSame('datetime', Schema::getColumnType('rec_persons', 'aufgaben_gemeldet_at'));
        $this->assertSame('datetime', Schema::getColumnType('rec_dispo_assignments', 'aufgaben_erinnert_at'));
    }

    /**
     * DIE LAENGE UND DIE ANKER — auf der Grammatik, auf der sie etwas
     * bedeuten.
     *
     * Beides ist auf SQLite unsichtbar: die SQLite-Grammatik schreibt
     * "varchar" ohne Laenge, und after() ignoriert SQLite ganz. Eine
     * Migration mit string(..., 255) statt 64 oder mit einem erfundenen Anker
     * kaeme hier also durch, ohne dass ein Test etwas merkt.
     *
     * Deshalb wird DIESELBE Migration ein zweites Mal gefahren — gegen die
     * MySQL-Grammatik, in pretend(). Laravel erzeugt die DDL und setzt sie
     * nicht ab; ein MySQL-Server ist dafuer nicht noetig und wird nicht
     * kontaktiert.
     */
    public function test_auf_mysql_entsteht_varchar_64_hinter_den_genannten_ankern(): void
    {
        $ddl = $this->ddlAufMysql(self::MIGRATION);

        $this->assertStringContainsString(
            'add `aufgaben_signatur` varchar(64) null after `letzte_anmeldung_at`',
            $ddl,
            "DDL war:\n".$ddl,
        );
        $this->assertStringContainsString(
            'add `aufgaben_gemeldet_at` timestamp null after `aufgaben_signatur`',
            $ddl,
            "DDL war:\n".$ddl,
        );
        $this->assertStringContainsString(
            'add `aufgaben_erinnert_at` timestamp null after `reminder_sent_at`',
            $ddl,
            "DDL war:\n".$ddl,
        );

        // Und die beiden Anker aus FREMDEN Migrationen sind keine toten
        // Namen: sie stehen wirklich im Schema, das die uebrigen Migrationen
        // bauen. Ein erfundener Anker braecht die Migration auf MySQL.
        $this->assertTrue(Schema::hasColumn('rec_persons', 'letzte_anmeldung_at'));
        $this->assertTrue(Schema::hasColumn('rec_dispo_assignments', 'reminder_sent_at'));
    }

    /**
     * Faehrt eine Migration gegen die MySQL-Grammatik und gibt die DDL
     * zurueck, die dabei entstanden waere. Die Vorgabe-Verbindung wird
     * danach wiederhergestellt — auch wenn die Migration wirft.
     */
    private function ddlAufMysql(string $relativerPfad): string
    {
        $verwaltung = $this->capsule->getDatabaseManager();
        $vorher = $verwaltung->getDefaultConnection();
        $container = Container::getInstance();

        $verwaltung->setDefaultConnection('mysql-attrappe');
        $container->instance('db.schema', $verwaltung->connection('mysql-attrappe')->getSchemaBuilder());
        Facade::clearResolvedInstances();

        try {
            $abfragen = $verwaltung->connection('mysql-attrappe')->pretend(
                fn () => (require dirname(__DIR__, 2).'/'.$relativerPfad)->up(),
            );
        } finally {
            $verwaltung->setDefaultConnection($vorher);
            $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
            Facade::clearResolvedInstances();
        }

        return implode("\n", array_column($abfragen, 'query'));
    }

    /**
     * Die Sache statt des Namens, Teil 1: ein Wert geht rein und kommt
     * zurueck — geschrieben ueber den Query Builder, also genau auf dem Weg,
     * den das Kommando spaeter nimmt.
     */
    public function test_die_drei_spalten_halten_ihre_werte(): void
    {
        $personId = $this->personAnlegen();
        $einbuchungId = $this->einbuchungAnlegen();

        Capsule::table('rec_persons')->where('id', $personId)->update([
            'aufgaben_signatur'    => str_repeat('a', 64),
            'aufgaben_gemeldet_at' => '2026-10-01 08:00:00',
        ]);
        Capsule::table('rec_dispo_assignments')->where('id', $einbuchungId)->update([
            'aufgaben_erinnert_at' => '2026-10-01 09:00:00',
        ]);

        $person = Capsule::table('rec_persons')->where('id', $personId)->first();
        $einbuchung = Capsule::table('rec_dispo_assignments')->where('id', $einbuchungId)->first();

        $this->assertSame(str_repeat('a', 64), $person->aufgaben_signatur);
        $this->assertSame('2026-10-01 08:00:00', $person->aufgaben_gemeldet_at);
        $this->assertSame('2026-10-01 09:00:00', $einbuchung->aufgaben_erinnert_at);
    }

    /**
     * Die Sache statt des Namens, Teil 2: nullable. Der Bestand hat die drei
     * Spalten nicht gefuellt — waere eine davon NOT NULL, liefe die
     * Migration auf der Produktion gar nicht erst durch, und hier liesse
     * sich keine Zeile ohne sie anlegen.
     */
    public function test_die_drei_spalten_duerfen_leer_bleiben(): void
    {
        $person = Capsule::table('rec_persons')->where('id', $this->personAnlegen())->first();
        $einbuchung = Capsule::table('rec_dispo_assignments')->where('id', $this->einbuchungAnlegen())->first();

        // Erst DASS die Spalte da ist, dann dass sie leer ist: auf einem
        // stdClass liefert ein fehlender Name null und nur eine Warnung —
        // ohne die erste Zusicherung waere dieser Test gruen, solange es die
        // Spalten gar nicht gibt (die elfte Falle dieses Zweigs).
        $this->assertObjectHasProperty('aufgaben_signatur', $person);
        $this->assertObjectHasProperty('aufgaben_gemeldet_at', $person);
        $this->assertObjectHasProperty('aufgaben_erinnert_at', $einbuchung);

        $this->assertNull($person->aufgaben_signatur);
        $this->assertNull($person->aufgaben_gemeldet_at);
        $this->assertNull($einbuchung->aufgaben_erinnert_at);
    }

    /**
     * Die Sache statt des Namens, Teil 3: der Cast. Ohne ihn kaeme der
     * Stempel als Zeichenkette zurueck, und ein ->diffInHours() darauf waere
     * ein Fehler statt einer Frist.
     */
    public function test_die_beiden_stempel_kommen_als_datum_zurueck(): void
    {
        $personId = $this->personAnlegen();
        $einbuchungId = $this->einbuchungAnlegen();

        Capsule::table('rec_persons')->where('id', $personId)
            ->update(['aufgaben_gemeldet_at' => '2026-10-01 08:00:00']);
        Capsule::table('rec_dispo_assignments')->where('id', $einbuchungId)
            ->update(['aufgaben_erinnert_at' => '2026-10-01 09:00:00']);

        $this->assertInstanceOf(Carbon::class, RecPerson::find($personId)->aufgaben_gemeldet_at);
        $this->assertInstanceOf(Carbon::class, RecDispoAssignment::find($einbuchungId)->aufgaben_erinnert_at);
    }

    public function test_die_signatur_steht_nicht_in_fillable(): void
    {
        // Geschrieben wird ausschliesslich ueber den Query Builder im Kommando —
        // observer-frei, damit eine Pruefung keinen ZAS-Marker setzt.
        $this->assertNotContains('aufgaben_signatur', (new RecPerson())->getFillable());
        $this->assertNotContains('aufgaben_gemeldet_at', (new RecPerson())->getFillable());
        $this->assertNotContains('aufgaben_erinnert_at', (new RecDispoAssignment())->getFillable());
    }

    /**
     * Und dasselbe als Sache statt als Liste: eine Massenzuweisung schreibt
     * die Spalte NICHT.
     *
     * WARUM HIER DER WAECHTER KURZ WIEDER AN MUSS: im Baum rufen 94
     * Testdateien Model::unguard() und keine einzige reguard(). $unguarded
     * ist statisch — ohne das Zuruecksetzen hier waere diese Probe im
     * Gesamtlauf gruen, egal was in $fillable steht. Der vorherige Zustand
     * wird danach exakt wiederhergestellt, damit die uebrigen Testklassen
     * die Umgebung vorfinden, die sie erwarten.
     */
    public function test_eine_massenzuweisung_schreibt_die_signatur_nicht(): void
    {
        $vorher = Model::isUnguarded();
        Model::reguard();

        try {
            $person = RecPerson::create([
                'uuid'                 => 'trigger-massenzuweisung',
                'team_id'              => 6,
                'aufgaben_signatur'    => 'untergeschoben',
                'aufgaben_gemeldet_at' => '2026-10-01 08:00:00',
            ]);

            $zeile = Capsule::table('rec_persons')->where('id', $person->id)->first();
            $this->assertObjectHasProperty('aufgaben_signatur', $zeile);
            $this->assertNull($zeile->aufgaben_signatur);
            $this->assertNull($zeile->aufgaben_gemeldet_at);
        } finally {
            $vorher ? Model::unguard() : Model::reguard();
        }
    }

    public function test_die_migration_ist_idempotent_und_umkehrbar(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->up(); // zweiter Lauf darf nicht werfen (hasColumn-Wache)
        $this->assertTrue(Schema::hasColumn('rec_persons', 'aufgaben_signatur'));

        $migration->down();
        $migration->down(); // auch die Umkehrung traegt die Wache
        $this->assertFalse(Schema::hasColumn('rec_persons', 'aufgaben_signatur'));
        $this->assertFalse(Schema::hasColumn('rec_persons', 'aufgaben_gemeldet_at'));
        $this->assertFalse(Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at'));

        $migration->up(); // und wieder hoch
        $this->assertTrue(Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at'));
    }

    private function personAnlegen(): int
    {
        return Capsule::table('rec_persons')->insertGetId([
            'uuid'    => 'trigger-person-'.uniqid(),
            'team_id' => 6,
        ]);
    }

    private function einbuchungAnlegen(): int
    {
        $eventId = Capsule::table('rec_dispo_events')->insertGetId([
            'uuid'        => 'trigger-event-'.uniqid(),
            'einsatz_ref' => 'RG-'.uniqid(),
        ]);

        return Capsule::table('rec_dispo_assignments')->insertGetId([
            'uuid'               => 'trigger-einbuchung-'.uniqid(),
            'ds_ref'             => 'DS-'.uniqid(),
            'rec_dispo_event_id' => $eventId,
            'pnr_raw'            => 'RG14',
            'datum'              => '2026-10-05',
        ]);
    }
}
