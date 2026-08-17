<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPosting;
use Symfony\Component\Uid\UuidV7;

/**
 * Regressionsschutz fuer die Mutatoren RecPosting::setBedarfAttribute() und
 * ::setBewerbungsFaktorAttribute() — Integrationstest gegen das ECHTE Modell
 * auf SQLite in-memory via Capsule (Muster: DuplicateMatchQueryTest), laeuft
 * im regulaeren Runner (meingedeck/vendor/bin/phpunit -c phpunit.xml).
 *
 * Warum das kein reiner Unit-Test sein kann: das Zusammenspiel von
 * Eloquent-Mutator (schreibt) und Eloquent-Cast (liest) laesst sich nur ueber
 * eine echte Model-Instanz mit einem echten Connection-Resolver pruefen —
 * Eloquent-Objekte brauchen dafuer den Container.
 *
 * Der eigentliche Fehler, den dieser Test verhindert: `wire:model` schreibt
 * bei leerem Formularfeld den Leerstring '' direkt auf das Attribut. Der
 * `integer`/`float`-Cast in RecPosting::$casts wirkt aber nur beim LESEN,
 * nicht beim Schreiben — Livewire liest den Wert vor dem Speichern zurueck
 * und bekommt int(0) bzw. float(0.0) statt NULL. Fachlich fatal: 0 heisst
 * "Ziel erreicht mit null Personen" statt "nicht gepflegt", und beim Faktor
 * scheitert 0.0 an der Regel min:0.1 und blockiert das GESAMTE Formular.
 *
 * Bewusst OHNE Event-Dispatcher auf der Capsule: RecPosting::booted()
 * erzeugt bei 'creating' eine UUID und ruft bei 'created' den
 * PostingRefCodeService auf (legt eine RecPostingExternalRef + einen
 * synthetischen RecSourcePlatform-Datensatz an). Beides ist fuer diesen Test
 * unbeteiligtes Beiwerk und braucht zusaetzliche Tabellen (rec_source_platforms,
 * rec_posting_external_refs). Ohne registrierten Dispatcher feuern Eloquents
 * 'creating'/'created'-Hooks gar nicht (siehe HasEvents::registerModelEvent —
 * no-op ohne static::$dispatcher), die Tests setzen die UUID deshalb manuell.
 *
 * WICHTIG: Model::$dispatcher ist STATISCH auf der Basisklasse — ein frueher
 * im selben Prozess gelaufener Test (z. B. DuplicateMatchQueryTest), der
 * Capsule::setEventDispatcher() aufruft, haengt sonst noch dran. Deshalb hier
 * explizit Model::unsetEventDispatcher(), statt sich auf einen "sauberen"
 * Ausgangszustand zu verlassen — sonst gruen im gefilterten Lauf, aber rot
 * ("no such table: rec_posting_external_refs") im Gesamtlauf.
 */
class PostingTargetFieldsTest extends TestCase
{
    private const TEAM = 3;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        // Schema-/DB-Facades auf Capsule verdrahten, damit die ECHTEN
        // Migrations-Dateien unveraendert laufen koennen (Schema::create(...)).
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        self::runRealMigrations();
    }

    /**
     * Siehe DuplicateMatchQueryTest::tearDownAfterClass() fuer die
     * ausfuehrliche Begruendung: Facade::setFacadeApplication() setzt nur
     * static::$app, nicht den prozessweiten $resolvedInstance-Cache von
     * Schema::. Ohne diesen Aufruf bleibt 'db.schema' fuer spaeter laufende
     * Testklassen auf DIESER Capsule gecacht.
     */
    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_postings')->delete();
    }

    /**
     * Schema aus den ECHTEN Migrationen (kein handgebautes Schema::create) —
     * ein Prod-Spaltenrename oder Cast-Wechsel schlaegt damit hier auf.
     * rec_position_id/team_id sind foreignId()->constrained(...), aber SQLite
     * erzwingt Fremdschluessel nicht ohne PRAGMA foreign_keys=ON (hier nicht
     * gesetzt) — die referenzierten Tabellen (rec_positions, teams) muessen
     * fuer diesen schmalen Test deshalb nicht existieren.
     */
    private static function runRealMigrations(): void
    {
        // Gleiche Ableitung wie in DuplicateMatchQueryTest::findModulesRoot():
        // von dieser Datei aus sind es immer zwei Ebenen zum eigenen Modul,
        // egal ob Haupt-Checkout oder Worktree.
        $ownModule = dirname(__DIR__, 2);

        $files = [
            'database/migrations/2026_02_09_000002_create_rec_postings_table.php',
            'database/migrations/2026_04_27_000001_add_activity_to_rec_postings_table.php',
            'database/migrations/2026_08_17_000001_add_bedarf_and_faktor_to_rec_postings.php',
        ];

        foreach ($files as $relative) {
            $path = $ownModule . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    private function makePosting(array $attrs = []): RecPosting
    {
        return RecPosting::create(array_merge([
            'uuid' => (string) UuidV7::generate(),
            'rec_position_id' => 1,
            'team_id' => self::TEAM,
            'title' => 'Testausschreibung',
            'status' => 'draft',
            'is_active' => true,
        ], $attrs));
    }

    public function test_leerstring_wird_zu_null_fuer_bedarf_und_faktor(): void
    {
        $posting = $this->makePosting(['bedarf' => '', 'bewerbungs_faktor' => '']);

        $this->assertNull($posting->bedarf, 'bedarf direkt nach dem Setzen');
        $this->assertNull($posting->bewerbungs_faktor, 'bewerbungs_faktor direkt nach dem Setzen');

        $fresh = RecPosting::find($posting->id);
        $this->assertNull($fresh->bedarf, 'bedarf nach frischem Laden aus der DB');
        $this->assertNull($fresh->bewerbungs_faktor, 'bewerbungs_faktor nach frischem Laden aus der DB');
    }

    public function test_reine_leerzeichen_werden_zu_null(): void
    {
        $posting = $this->makePosting(['bedarf' => '   ', 'bewerbungs_faktor' => '  ']);

        $this->assertNull($posting->bedarf);
        $this->assertNull($posting->bewerbungs_faktor);

        $fresh = RecPosting::find($posting->id);
        $this->assertNull($fresh->bedarf);
        $this->assertNull($fresh->bewerbungs_faktor);
    }

    /**
     * Eine bewusste "0" ist KEINE leere Eingabe und darf nicht zu NULL
     * werden — das waere das Gegenteil des Fixes.
     */
    public function test_bewusste_null_bleibt_null_als_zahl(): void
    {
        $posting = $this->makePosting(['bedarf' => '0', 'bewerbungs_faktor' => '0']);

        $this->assertSame(0, $posting->bedarf);
        $this->assertSame(0.0, $posting->bewerbungs_faktor);

        $fresh = RecPosting::find($posting->id);
        $this->assertSame(0, $fresh->bedarf);
        $this->assertSame(0.0, $fresh->bewerbungs_faktor);
    }

    public function test_normaler_wert_kommt_unveraendert_an(): void
    {
        $posting = $this->makePosting(['bedarf' => 12, 'bewerbungs_faktor' => 7.5]);

        $this->assertSame(12, $posting->bedarf);
        $this->assertSame(7.5, $posting->bewerbungs_faktor);

        $fresh = RecPosting::find($posting->id);
        $this->assertSame(12, $fresh->bedarf);
        $this->assertSame(7.5, $fresh->bewerbungs_faktor);
    }
}
