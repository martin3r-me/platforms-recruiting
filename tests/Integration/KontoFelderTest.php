<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecPerson;

/**
 * Die Kontofelder an der Personen-Zeile: Einladung und Einmalcode.
 *
 * Zwei Dinge, die stillschweigend falsch sein koennten, ohne dass ein
 * Funktionstest es je bemerkt: die Hash-Spalten landen in einer
 * Serialisierung (Log, JSON-Antwort, Fehlerseite), oder die Zeitstempel
 * kommen als roher String statt als Date-Objekt zurueck, weil $casts fehlt.
 * Beides beweist dieser Test direkt an der Datenbank, nicht am
 * Anwendungscode — die Migrationen laufen hier NICHT, das Schema wird von
 * Hand nachgebaut (wie in PersonLinkerTest/RecPersonTest), deckungsgleich
 * mit der echten Migration.
 */
final class KontoFelderTest extends TestCase
{
    private const TEAM = 3;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

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

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();

            // Die Kontofelder aus dieser Aufgabe — deckungsgleich mit
            // 2026_09_29_000001_add_konto_felder_to_rec_persons.php.
            $t->string('invite_token_hash', 64)->nullable();
            $t->timestamp('invite_expires_at')->nullable();
            $t->timestamp('invite_used_at')->nullable();
            $t->string('code_hash', 64)->nullable();
            $t->timestamp('code_expires_at')->nullable();
            $t->unsignedTinyInteger('code_versuche')->default(0);
            $t->string('code_zweck', 20)->nullable();
            $t->string('code_neue_nummer', 32)->nullable();
            $t->timestamp('letzte_anmeldung_at')->nullable();

            $t->timestamps();

            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /**
     * Legt eine Personen-Zeile per Query Builder an — genau der Weg, den
     * auch der spaetere KontoWriter nimmt, nicht Eloquent::create(), weil
     * die Kontofelder bewusst nicht in $fillable stehen.
     */
    private function personAnlegen(array $attribute = []): int
    {
        return DB::table('rec_persons')->insertGetId(array_merge([
            'uuid'       => 'p-' . bin2hex(random_bytes(6)),
            'team_id'    => self::TEAM,
            'created_at' => '2026-09-29 10:00:00',
            'updated_at' => '2026-09-29 10:00:00',
        ], $attribute));
    }

    public function test_die_geheimnisse_stehen_nicht_in_der_serialisierung(): void
    {
        $person = RecPerson::query()->find($this->personAnlegen([
            'password_hash'    => 'geheim-hash',
            'invite_token_hash'=> 'token-hash',
            'code_hash'        => 'code-hash',
        ]));

        $serialisiert = $person->toArray();

        foreach (['password_hash', 'invite_token_hash', 'code_hash'] as $feld) {
            $this->assertArrayNotHasKey(
                $feld,
                $serialisiert,
                "{$feld} darf nie in einer Serialisierung landen — von dort geht es in Logs, Antworten und Fehlerseiten",
            );
        }
    }

    public function test_die_zeitstempel_kommen_als_datum_zurueck(): void
    {
        $person = RecPerson::query()->find($this->personAnlegen([
            'invite_expires_at' => '2026-10-06 12:00:00',
        ]));

        $this->assertNotNull($person->invite_expires_at);
        $this->assertSame('2026-10-06', $person->invite_expires_at->format('Y-m-d'));
    }

    /** code_versuche muss ohne Angabe 0 sein, nicht NULL — sonst scheitert jeder Vergleich "< max". */
    public function test_code_versuche_startet_bei_null(): void
    {
        $person = RecPerson::query()->find($this->personAnlegen());

        $this->assertSame(0, $person->code_versuche);
    }

    /**
     * code_zweck und code_neue_nummer stehen nebeneinander und beide leer,
     * solange kein Vorgang laeuft — sie duerfen sich nicht gegenseitig
     * erzwingen (das waere ein Konstruktionsfehler in der Migration).
     */
    public function test_code_zweck_und_neue_nummer_sind_unabhaengig_voneinander_leer(): void
    {
        $person = RecPerson::query()->find($this->personAnlegen());

        $this->assertNull($person->code_zweck);
        $this->assertNull($person->code_neue_nummer);
    }

    /**
     * $fillable darf die Kontofelder NICHT tragen (Ruling T3-D) — sonst
     * koennte ein Eloquent-Update mit Beobachter-Lauf daran vorbeischreiben.
     *
     * Bewusst gegen das rohe $fillable-Array geprueft, nicht gegen
     * isFillable(): mehrere Testklassen dieses Moduls rufen Model::unguard()
     * in setUp() ohne ein passendes reguard() in tearDown() — $unguarded ist
     * eine statische Eigenschaft und bleibt dann fuer den Rest des
     * Prozesses gesetzt. isFillable() liefert danach fuer jedes Feld true,
     * egal was in $fillable steht, und der Test waere von der Laufreihenfolge
     * der GESAMTEN Suite abhaengig (genau die Falle aus phpunit.xml).
     */
    public function test_kontofelder_stehen_nicht_in_fillable(): void
    {
        $fillable = (new RecPerson())->getFillable();

        foreach ([
            'invite_token_hash', 'invite_expires_at', 'invite_used_at',
            'code_hash', 'code_expires_at', 'code_versuche', 'code_zweck',
            'code_neue_nummer', 'letzte_anmeldung_at',
        ] as $feld) {
            $this->assertNotContains(
                $feld,
                $fillable,
                "{$feld} darf nicht ueber Eloquent-Massenzuweisung schreibbar sein — nur KontoWriter per Query Builder",
            );
        }
    }
}
