<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Posting\Show as PostingForm;
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
     * UMGEKEHRTE ZUSICHERUNG (Abschluss-Review Task 10): eine 0 ist „NICHT
     * GEPFLEGT" und wird zu NULL.
     *
     * Hier stand vorher das Gegenteil („eine bewusste 0 bleibt 0"). Diese
     * Entscheidung aus Task 5 ist aufgehoben, und zwar aus zwei Gruenden:
     *  - FACHLICH hat sie nie gegolten: alle Leser der Statistik behandeln den
     *    Bedarf 0 als nicht gepflegt (graue Ampel, „–", nicht im Nenner der Quote).
     *    Eine 0 war damit ein Wert, der sich wie „leer" verhaelt, aber wie eine
     *    Angabe aussieht.
     *  - PRAKTISCH blockierte sie das Formular: mit `min:1` in den Regeln wirft
     *    save() auf `posting.bedarf` — und Livewire validiert das GANZE Formular,
     *    verwirft also die Aenderung an einem voellig anderen Feld (gemessen: Titel
     *    geaendert, Titel weg, Fehler am Bedarf).
     *
     * Die Regel sitzt deshalb am FELD (Setter UND Getter in RecPosting), damit sie
     * fuer jeden Schreibweg gilt und den Bestand mitliest.
     */
    public function test_null_heisst_nicht_gepflegt_und_wird_zu_null(): void
    {
        // ueber den Setter, als String wie als Zahl
        $ausString = $this->makePosting(['bedarf' => '0', 'bewerbungs_faktor' => '0']);
        $this->assertNull($ausString->bedarf);
        $this->assertNull($ausString->bewerbungs_faktor);

        $ausZahl = $this->makePosting(['bedarf' => 0, 'bewerbungs_faktor' => 0.0]);
        $this->assertNull($ausZahl->bedarf);
        $this->assertNull($ausZahl->bewerbungs_faktor);

        // ... und nach frischem Laden aus der DB (der Setter hat NULL geschrieben)
        $fresh = RecPosting::find($ausString->id);
        $this->assertNull($fresh->bedarf);
        $this->assertNull($fresh->bewerbungs_faktor);
    }

    /**
     * Der BESTAND: eine 0, die schon in der Datenbank liegt (per Massen-Update, SQL
     * von Hand oder aus der Zeit vor dieser Regel), kommt beim Lesen als „nicht
     * gepflegt" an — sonst blockiert sie das Formular an einem Feld, das gerade
     * niemand angefasst hat.
     */
    public function test_bestandsdatensatz_mit_null_liest_wie_nicht_gepflegt(): void
    {
        $posting = $this->makePosting(['bedarf' => 7, 'bewerbungs_faktor' => 8.0]);

        // Am Model VORBEI geschrieben — genau der Weg, den der Setter nicht sieht
        Capsule::table('rec_postings')->where('id', $posting->id)->update([
            'bedarf' => 0,
            'bewerbungs_faktor' => 0,
        ]);

        $fresh = RecPosting::find($posting->id);
        $this->assertNull($fresh->bedarf, 'die 0 aus dem Bestand liest wie nicht gepflegt');
        $this->assertNull($fresh->bewerbungs_faktor);

        // Und damit besteht der Datensatz die Formular-Validierung: `min:1` bzw.
        // `min:0.1` greifen nur noch auf echte Angaben, nicht auf den Bestand.
        $regeln = (new PostingFormProbe())->probeRules();
        $validator = new Validator(
            new Translator(new ArrayLoader(), 'de'),
            [
                'posting' => [
                    'title' => 'Ein neuer Titel',
                    'status' => 'published',
                    'is_active' => true,
                    'bedarf' => $fresh->bedarf,
                    'bewerbungs_faktor' => $fresh->bewerbungs_faktor,
                ],
            ],
            $regeln,
        );

        $this->assertFalse(
            $validator->fails(),
            'ein Bestandsdatensatz mit 0 darf das Formular nicht blockieren: ' . $validator->errors()->toJson(),
        );

        // GEGENPROBE, sonst beweist der Test nur, dass die Regeln nichts pruefen:
        // eine 0, die trotzdem ankommt (Weg ohne Setter), faellt am Guertel auf.
        $mitNull = new Validator(
            new Translator(new ArrayLoader(), 'de'),
            [
                'posting' => [
                    'title' => 'Ein neuer Titel',
                    'status' => 'published',
                    'is_active' => true,
                    'bedarf' => 0,
                    'bewerbungs_faktor' => 0,
                ],
            ],
            $regeln,
        );
        $this->assertTrue($mitNull->fails());
        $this->assertArrayHasKey('posting.bedarf', $mitNull->errors()->toArray());
    }

    /**
     * Ein Wert UNTER der Schwelle des Faktors verhaelt sich wie die 0: die Regel
     * (min:0.1) wuerde ihn abweisen, also darf er nicht als Angabe gelesen werden.
     * Regel und Feld teilen dafuer eine Konstante.
     */
    public function test_wert_unter_der_schwelle_gilt_als_nicht_gepflegt(): void
    {
        $posting = $this->makePosting(['bedarf' => 1, 'bewerbungs_faktor' => 0.05]);

        $this->assertSame(1, $posting->bedarf, 'die kleinste echte Angabe bleibt');
        $this->assertNull($posting->bewerbungs_faktor, '0,05 liegt unter min:0.1');
        $this->assertSame(RecPosting::BEDARF_MIN, 1);
        $this->assertSame(RecPosting::FAKTOR_MIN, 0.1);
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

    // -----------------------------------------------------------------
    // Taetigkeit: im Detail-Formular pflegbar, „leer" heisst NULL
    // -----------------------------------------------------------------

    public function test_taetigkeit_wird_getrimmt_und_leer_heisst_null(): void
    {
        // Das Detail-Formular bindet direkt an das Attribut und schreibt beim
        // Leeren '' — der Anlege-Dialog normalisierte von Hand auf null. Ohne
        // Setter gaebe es damit zwei Schreibweisen fuer „nicht gepflegt" in einer
        // Spalte, und jeder Leser muesste beide kennen.
        $leer = $this->makePosting(['activity' => '']);
        $this->assertNull($leer->activity, 'Leerstring direkt nach dem Setzen');
        $this->assertNull(RecPosting::find($leer->id)->activity, 'und nach frischem Laden');

        $blanks = $this->makePosting(['activity' => '   ']);
        $this->assertNull($blanks->activity, 'reine Leerzeichen sind keine Angabe');

        $getrimmt = $this->makePosting(['activity' => '  Abraeumer  ']);
        $this->assertSame('Abraeumer', $getrimmt->activity, 'Rand-Leerzeichen wuerden eine eigene Taetigkeit bilden');
        $this->assertSame('Abraeumer', RecPosting::find($getrimmt->id)->activity);
    }

    public function test_die_regel_der_taetigkeit_passt_zur_spaltenbreite(): void
    {
        // Waere die Regel weiter als string(60), schluege erst die Datenbank fehl —
        // und Livewire verwirft dann die Aenderung am GANZEN Formular, auch an
        // Feldern, die gerade niemand angefasst hat (gemessen beim Bedarf).
        $regeln = (new PostingFormProbe())->probeRules();

        $this->assertSame('nullable|string|max:60', $regeln['posting.activity']);
    }

    public function test_ein_bestandswert_mit_leerstring_blockiert_das_formular_nicht(): void
    {
        // '' kann aus der Zeit VOR dem Setter in der Spalte liegen (Import, MCP,
        // SQL von Hand). Er muss die Regel bestehen, sonst blockiert er beim
        // naechsten Speichern ein Formular, an dem gerade jemand anderes arbeitet.
        $posting = $this->makePosting(['activity' => 'Service']);
        Capsule::table('rec_postings')->where('id', $posting->id)->update(['activity' => '']);

        $fresh = RecPosting::find($posting->id);

        $validator = new Validator(
            new Translator(new ArrayLoader(), 'de'),
            ['posting' => ['title' => $fresh->title, 'activity' => $fresh->activity]],
            ['posting.title' => 'required|string|max:255', 'posting.activity' => (new PostingFormProbe())->probeRules()['posting.activity']],
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }
}

/**
 * Reicht die Validierungsregeln des Ausschreibungs-Formulars heraus, ohne den
 * Livewire-Lebenszyklus: rules() ist public, aber die Komponente selbst braucht
 * fuer validate() eine gebootete App. Geprueft wird hier die REGEL, nicht Livewire.
 */
final class PostingFormProbe extends PostingForm
{
    public function probeRules(): array
    {
        return $this->rules();
    }
}
