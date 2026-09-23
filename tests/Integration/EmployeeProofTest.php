<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployeeProof;

/**
 * Nachweise als eigene Objekte: Fassungen, Gueltigkeit, und die Auflösung
 * ueber den Personen-Marker statt ueber die Anstellung.
 */
final class EmployeeProofTest extends TestCase
{
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

        $this->capsule->schema()->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64);
            $t->integer('team_id')->nullable();
            $t->integer('rec_employee_id');
            $t->string('person_key', 64)->nullable();
            $t->string('proof_type_code', 40);
            $t->integer('file_id')->nullable();
            $t->integer('file_back_id')->nullable();
            $t->date('valid_until')->nullable();
            $t->integer('version')->default(1);
            $t->timestamp('superseded_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->integer('confirmed_by_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->string('uploaded_via', 20)->default('employee');
            $t->integer('uploaded_by_user_id')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function nachweis(array $attr = []): RecEmployeeProof
    {
        return RecEmployeeProof::create(array_merge([
            'rec_employee_id' => 1,
            'person_key'      => 'p-1',
            'proof_type_code' => 'ausweis',
        ], $attr));
    }

    public function test_bekommt_beim_anlegen_eine_uuid(): void
    {
        $this->assertNotEmpty($this->nachweis()->uuid);
    }

    public function test_aktuell_blendet_abgeloeste_fassungen_aus(): void
    {
        $alt = $this->nachweis(['version' => 1, 'superseded_at' => Carbon::parse('2026-09-01 10:00')]);
        $neu = $this->nachweis(['version' => 2]);

        $aktuell = RecEmployeeProof::query()->aktuell()->get();

        $this->assertCount(1, $aktuell);
        $this->assertSame($neu->id, $aktuell->first()->id);
        // Die alte Fassung ist NICHT geloescht — Nachweis bleibt nachvollziehbar.
        $this->assertNotNull(RecEmployeeProof::find($alt->id));
    }

    public function test_lesen_ueber_den_personen_marker_findet_beide_anstellungen(): void
    {
        $this->nachweis(['rec_employee_id' => 1, 'person_key' => 'p-1', 'proof_type_code' => 'ausweis']);
        $this->nachweis(['rec_employee_id' => 2, 'person_key' => 'p-1', 'proof_type_code' => 'immatrikulation']);
        $this->nachweis(['rec_employee_id' => 3, 'person_key' => 'p-2', 'proof_type_code' => 'ausweis']);

        $derPerson = RecEmployeeProof::query()->aktuell()->where('person_key', 'p-1')->get();

        $this->assertCount(2, $derPerson, 'Ein Upload an Anstellung 1 muss auch fuer Anstellung 2 zaehlen');
        $this->assertEqualsCanonicalizing(['ausweis', 'immatrikulation'], $derPerson->pluck('proof_type_code')->all());
    }

    public function test_abgelaufen(): void
    {
        $stichtag = Carbon::parse('2026-09-23');

        $this->assertTrue($this->nachweis(['valid_until' => '2026-09-22'])->isExpired($stichtag));
        $this->assertFalse($this->nachweis(['valid_until' => '2026-09-23'])->isExpired($stichtag), 'am Tag selbst noch gueltig');
        $this->assertFalse($this->nachweis(['valid_until' => null])->isExpired($stichtag), 'ohne Datum laeuft nichts ab');
    }

    /** Aufenthaltstitel 60 Tage Vorlauf, Ausweis 30 — aus dem Katalog, nicht hartkodiert. */
    public function test_faellig_richtet_sich_nach_der_vorlaufzeit_der_art(): void
    {
        $stichtag = Carbon::parse('2026-09-23');
        $in45Tagen = $stichtag->copy()->addDays(45)->toDateString();

        $titel = $this->nachweis(['proof_type_code' => 'aufenthaltstitel', 'valid_until' => $in45Tagen]);
        $ausweis = $this->nachweis(['proof_type_code' => 'ausweis', 'valid_until' => $in45Tagen]);

        $this->assertTrue($titel->isDueSoon($stichtag), 'Aufenthaltstitel: 45 Tage liegen in den 60 Tagen Vorlauf');
        $this->assertFalse($ausweis->isDueSoon($stichtag), 'Ausweis: 45 Tage liegen ausserhalb der 30 Tage Vorlauf');
    }

    public function test_bereits_abgelaufenes_ist_nicht_faellig_sondern_abgelaufen(): void
    {
        $stichtag = Carbon::parse('2026-09-23');
        $alt = $this->nachweis(['proof_type_code' => 'ausweis', 'valid_until' => '2026-08-01']);

        $this->assertTrue($alt->isExpired($stichtag));
        $this->assertFalse($alt->isDueSoon($stichtag), 'Abgelaufenes faellt nicht mehr in den Vorlauf');
    }

    public function test_arten_ohne_ablauf_sind_nie_faellig(): void
    {
        $stichtag = Carbon::parse('2026-09-23');
        $selfie = $this->nachweis(['proof_type_code' => 'selfie', 'valid_until' => null]);

        $this->assertFalse($selfie->isExpired($stichtag));
        $this->assertFalse($selfie->isDueSoon($stichtag));
    }
}
