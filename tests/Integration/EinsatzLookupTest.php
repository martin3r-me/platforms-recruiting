<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Statistics\EinsatzLookup;

/**
 * Der Dispo-Abgleich je Bewerbung — bis 09/2026 inline in
 * Statistics\Index::cohort(), jetzt eine eigene Einheit, weil der
 * Sammelversand „ohne Einsatz“ dieselbe Frage kurz vor dem Senden noch einmal
 * stellen muss. Eine zweite, handgeschriebene Kopie der Regel waere genau die
 * Art Duplikat, die spaeter auseinanderlaeuft (vgl. FlynkPostingReconciler).
 *
 * Geprueft sind die drei ehrlichen Aussagen (kein MA / keine PersNr / geprueft)
 * und die beiden Feinheiten, die den Abgleich ueberhaupt erst richtig machen:
 * die Einsaetze der ZWEITEN Firma ueber den person_key (Chaieb-Befund
 * 10.09.2026) und die Zuweisungen, die nicht zaehlen duerfen.
 */
final class EinsatzLookupTest extends TestCase
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

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $s->create('rec_employees', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id')->nullable();
            $t->integer('rec_applicant_id')->nullable(); $t->string('personnel_number')->nullable();
            $t->string('person_key')->nullable(); $t->timestamps();
        });
        $s->create('rec_dispo_assignments', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_employee_id')->nullable();
            $t->date('datum')->nullable(); $t->integer('status_id')->default(1);
            $t->timestamp('zas_removed_at')->nullable(); $t->timestamp('deletion_confirmed_at')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(int $id): RecApplicant
    {
        return RecApplicant::forceCreate(['id' => $id, 'team_id' => 3]);
    }

    private function employee(int $id, ?int $applicantId, ?string $pnr, ?string $personKey = null): void
    {
        Capsule::table('rec_employees')->insert([
            'id' => $id, 'team_id' => 3, 'rec_applicant_id' => $applicantId,
            'personnel_number' => $pnr, 'person_key' => $personKey,
        ]);
    }

    private function assignment(int $employeeId, string $datum, array $extra = []): void
    {
        Capsule::table('rec_dispo_assignments')->insert(array_merge([
            'rec_employee_id' => $employeeId, 'datum' => $datum, 'status_id' => 1,
        ], $extra));
    }

    /** @param list<int> $ids */
    private function lookup(array $ids): EinsatzLookup
    {
        $applicants = RecApplicant::whereIn('id', $ids)
            ->with('employees:id,rec_applicant_id,personnel_number,person_key')
            ->get();

        return EinsatzLookup::for(3, $applicants);
    }

    public function testOhneMitarbeiterIstNichtPruefbar(): void
    {
        $this->applicant(1);

        $lookup = $this->lookup([1]);

        $this->assertSame(EinsatzLookup::FLAG_UNVERIFIABLE, $lookup->flag(1));
        $this->assertSame(['count' => 0, 'first' => null, 'grund' => 'kein_ma'], $lookup->infoFor(1));
    }

    public function testMitarbeiterOhnePersonalnummerIstNichtPruefbar(): void
    {
        $this->applicant(2);
        $this->employee(20, 2, null);

        $lookup = $this->lookup([2]);

        $this->assertSame(EinsatzLookup::FLAG_UNVERIFIABLE, $lookup->flag(2));
        $this->assertSame('keine_pnr', $lookup->infoFor(2)['grund']);
    }

    public function testPersonalnummerOhneZuweisungIstOhneEinsatz(): void
    {
        $this->applicant(3);
        $this->employee(30, 3, 'RG18231');

        $lookup = $this->lookup([3]);

        $this->assertSame(EinsatzLookup::FLAG_NONE, $lookup->flag(3));
        $this->assertSame(['count' => 0, 'first' => null, 'grund' => null], $lookup->infoFor(3));
    }

    public function testEinsaetzeBeiderFirmenZaehlenUeberDenPersonKey(): void
    {
        $this->applicant(4);
        // Verlinkte Anstellung (RG) und die unverlinkte Zweitfirma (MA) —
        // verbunden allein ueber den person_key, genau der Chaieb-Fall.
        $this->employee(40, 4, 'RG18231', 'pk-chaieb');
        $this->employee(41, null, 'MA18232', 'pk-chaieb');
        $this->assignment(40, '2026-09-20');
        $this->assignment(41, '2026-09-12');
        $this->assignment(41, '2026-09-28');

        $lookup = $this->lookup([4]);

        $this->assertSame(EinsatzLookup::FLAG_DEPLOYED, $lookup->flag(4));
        $this->assertSame(3, $lookup->infoFor(4)['count']);
        $this->assertSame('2026-09-12', $lookup->infoFor(4)['first'], 'Frühester Einsatz über beide Firmen.');
    }

    public function testStornierteUndEntfernteZuweisungenZaehlenNicht(): void
    {
        $this->applicant(5);
        $this->employee(50, 5, 'RG19000');
        $this->assignment(50, '2026-09-01', ['status_id' => 3]);
        $this->assignment(50, '2026-09-02', ['zas_removed_at' => '2026-09-03 10:00:00']);
        $this->assignment(50, '2026-09-04', ['deletion_confirmed_at' => '2026-09-05 10:00:00']);

        $lookup = $this->lookup([5]);

        $this->assertSame(EinsatzLookup::FLAG_NONE, $lookup->flag(5));
        $this->assertSame(0, $lookup->infoFor(5)['count']);
    }

    public function testInfoLiefertDieGanzeKarteNachBewerbungGeschluesselt(): void
    {
        $this->applicant(6);
        $this->applicant(7);
        $this->employee(60, 6, 'RG20000');
        $this->assignment(60, '2026-08-15');

        $info = $this->lookup([6, 7])->info();

        $this->assertSame([6, 7], array_keys($info));
        $this->assertSame(1, $info[6]['count']);
        $this->assertSame('kein_ma', $info[7]['grund']);
    }
}
