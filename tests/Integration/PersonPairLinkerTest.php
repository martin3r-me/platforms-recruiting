<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\PersonPairLinker;

/**
 * Personen-Paarung am lebenden Datensatz: der ZAS-Stammdaten-Import legt den
 * Zweit-Firmen-Datensatz an (Chaieb: MA18232 kam per Lieferung #35, waehrend
 * RG18231 laengst verlinkt war) — pairIfExact() erkennt den doppelt-exakten
 * Geschwister-Treffer und stempelt person_key + erbt den Bewerber-Link.
 * stamp() ist der gemeinsame Schreibweg fuer Audit-Kommando und Hand-Link.
 */
final class PersonPairLinkerTest extends TestCase
{
    private const TEAM = 9;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        Container::getInstance()->instance('db', $capsule->getDatabaseManager());
        Facade::setFacadeApplication(Container::getInstance());

        Capsule::schema()->create('rec_employees', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('rec_applicant_id')->nullable();
            $t->string('person_key', 36)->nullable();
            $t->string('personnel_number')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->timestamps();
        });
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    private function emp(array $attrs): RecEmployee
    {
        // Query-Builder-Insert + find: der uuid-creating-Hook des Models
        // braucht keinen Event-Dispatcher-Testaufbau.
        $id = Capsule::table('rec_employees')->insertGetId($attrs + [
            'team_id' => self::TEAM, 'first_name' => 'Wannes', 'last_name' => 'Chaieb',
            'birth_date' => '1998-08-03', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return RecEmployee::findOrFail($id);
    }

    public function test_exakter_geschwister_treffer_stempelt_und_erbt_den_bewerber(): void
    {
        $rg = $this->emp(['personnel_number' => 'RG18231', 'rec_applicant_id' => 2851]);
        $ma = $this->emp(['personnel_number' => 'MA18232']);

        $ergebnis = (new PersonPairLinker())->pairIfExact($ma);

        $this->assertSame('paired', $ergebnis['status']);
        $rg->refresh(); $ma->refresh();
        $this->assertNotNull($ma->person_key);
        $this->assertSame($rg->person_key, $ma->person_key, 'beide tragen denselben Schluessel');
        $this->assertSame(2851, (int) $ma->rec_applicant_id, 'der unverlinkte erbt die Bewerbung');
    }

    public function test_namensvariante_und_mehrdeutigkeit_stempeln_nicht(): void
    {
        $this->emp(['personnel_number' => 'RG1', 'first_name' => 'Leni Emily', 'last_name' => 'Runtenberg', 'birth_date' => '2000-01-01']);
        $variante = $this->emp(['personnel_number' => 'MA2', 'first_name' => 'Leni', 'last_name' => 'Runtenberg', 'birth_date' => '2000-01-01']);
        $this->assertSame('none', (new PersonPairLinker())->pairIfExact($variante)['status'], 'Variante gehoert dem Menschen');

        $this->emp(['personnel_number' => 'RG3', 'rec_applicant_id' => 1]);
        $this->emp(['personnel_number' => 'RG4', 'rec_applicant_id' => 2]);
        $dritter = $this->emp(['personnel_number' => 'MA5']);
        $ergebnis = (new PersonPairLinker())->pairIfExact($dritter);
        $this->assertSame('ambiguous', $ergebnis['status'], 'zwei Kandidaten mit verschiedenen Bewerbungen');
        $this->assertNull($dritter->refresh()->person_key);
    }

    public function test_verschiedene_bewerbungen_werden_nie_still_vereint(): void
    {
        $this->emp(['personnel_number' => 'RG6', 'rec_applicant_id' => 10]);
        $neuer = $this->emp(['personnel_number' => 'MA7', 'rec_applicant_id' => 20]);

        $this->assertSame('ambiguous', (new PersonPairLinker())->pairIfExact($neuer)['status']);
    }

    public function test_stamp_setzt_key_und_optional_bewerber(): void
    {
        $a = $this->emp(['personnel_number' => 'RG8', 'rec_applicant_id' => 30]);
        $b = $this->emp(['personnel_number' => 'MA9']);

        $key = PersonPairLinker::stamp([$a->id, $b->id], 30);

        $this->assertNotSame('', $key);
        $this->assertSame($key, $a->refresh()->person_key);
        $this->assertSame($key, $b->refresh()->person_key);
        $this->assertSame(30, (int) $b->refresh()->rec_applicant_id);

        // Vorhandener Key gewinnt (idempotent, keine Key-Rotation)
        $this->assertSame($key, PersonPairLinker::stamp([$a->id, $b->id], null));
    }
}
