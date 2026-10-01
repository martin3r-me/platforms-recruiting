<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\ProofReader;

/**
 * Die Checkliste fragt den MENSCHEN, nicht die eine Anstellung.
 *
 * ProofReader::current() liest die vorhandenen Nachweise laengst ueber die
 * ganze Person (PersonScopeResolver). Die PFLICHTEN kamen bisher aus der
 * einen Anstellung, mit der jemand gerade zu tun hatte — wer bei RG als
 * Student und bei MA als Aushilfe gefuehrt wird, sah je nach Anstellung eine
 * andere Liste. Hier wird die Vereinigung festgenagelt.
 *
 * Gescoped wird ueber rec_person_id (Zweig 1 des PersonScopeResolver): die
 * entschiedene Zuordnung, kein Rateversuch ueber person_key und Telefon. Der
 * Telefonzweig hat seine eigenen Tests (PersonScopeResolverTest,
 * ProofReaderTest) — hier geht es ausschliesslich um die Pflichtliste.
 */
final class ProofReaderPersonPflichtenTest extends TestCase
{
    private const TEAM = 3;

    private Capsule $capsule;

    private int $naechsteId = 1;

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
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            $t->timestamps();
        });

        // ProofReader::current() liest hier — ohne die Tabelle kaeme die
        // Checkliste gar nicht erst bis zur Pflichtliste.
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
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function personAnlegen(): int
    {
        return (int) DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-' . $this->naechsteId++,
            'team_id' => self::TEAM,
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);
    }

    /**
     * Vorgabe: aktive Anstellung eines EU-Buergers ohne Besonderheit. Wer
     * etwas anderes braucht, uebergibt es — so steht in jedem Testfall genau
     * das, worauf es ihm ankommt.
     */
    private function mitarbeiter(array $attr = []): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId(array_merge([
            'uuid' => 'e-' . $this->naechsteId++,
            'team_id' => self::TEAM,
            'phone' => '+4915112345678',
            'is_active' => true,
            'is_eu_citizen' => true,
            'employment_type' => 'aushilfe',
            'is_first_aider' => false,
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ], $attr));

        return RecEmployee::find($id);
    }

    /** @return list<string> */
    private function codes(RecEmployee $employee): array
    {
        return array_column((new ProofReader())->checklist($employee), 'code');
    }

    public function test_die_checkliste_nimmt_die_pflichten_beider_anstellungen(): void
    {
        // Zwei Anstellungen derselben Person: eine als Student, eine als Aushilfe.
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'student']);
        $ma = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);

        $codes = array_column((new ProofReader())->checklist($ma), 'code');

        // Angemeldet ueber die Aushilfen-Anstellung, trotzdem steht die
        // Immatrikulation auf der Liste — der Mensch studiert ja.
        $this->assertContains('immatrikulation', $codes);
    }

    /**
     * Gegenrichtung zum Test darueber: die Pflicht der ERSTEN Anstellung
     * verschwindet nicht, wenn man ueber sie selbst fragt. Beide Richtungen
     * werden mit eigenen Erwartungen festgenagelt und NICHT miteinander
     * verglichen — zwei Ergebnisse gleichzusetzen sichert die Gleichheit und
     * kein einziges Feld.
     */
    public function test_auch_ueber_die_studenten_anstellung_steht_beides_auf_der_liste(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'student']);
        $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);

        $codes = $this->codes($rg);

        $this->assertContains('immatrikulation', $codes);
        $this->assertContains('ausweis', $codes);
    }

    /**
     * Die Staatsangehoerigkeit gehoert zum Menschen, nicht zur Anstellung.
     * Widersprechen sich die Datensaetze, gilt die strengere Lesart — und
     * zwar ausdruecklich auch, wenn man ueber die HARMLOSERE Anstellung
     * fragt. Das deckt zugleich die K.-o.-Nachweise ab (ProofTypes::KO_CODES),
     * die ein reiner Student-Testfall nie beruehrt.
     */
    public function test_eine_nicht_eu_anstellung_zieht_die_ganze_person_mit(): void
    {
        $person = $this->personAnlegen();
        $this->mitarbeiter(['rec_person_id' => $person, 'is_eu_citizen' => false]);
        $eu = $this->mitarbeiter(['rec_person_id' => $person, 'is_eu_citizen' => true]);

        $codes = $this->codes($eu);

        $this->assertContains('aufenthaltstitel', $codes);
        $this->assertContains('arbeitsgenehmigung', $codes);
        $this->assertContains('nationalpass', $codes);
    }

    /**
     * Gegenprobe gegen "alles aus dem Katalog": die Vereinigung darf nur
     * nehmen, was auch wirklich jemand verlangt. Ohne diese Erwartung bliebe
     * eine Mutation auf ProofTypes::all() gruen.
     */
    public function test_was_keine_anstellung_verlangt_steht_auch_nicht_auf_der_liste(): void
    {
        $person = $this->personAnlegen();
        $rg = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'student']);
        $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);

        $codes = $this->codes($rg);

        $this->assertNotContains('schulbescheinigung', $codes, 'niemand ist Schueler');
        $this->assertNotContains('ersthelfer', $codes, 'niemand ist Ersthelfer');
        $this->assertNotContains('aufenthaltstitel', $codes, 'beide sind EU-Buerger');
    }

    /**
     * Die Vereinigung bleibt an der PERSON. Ein fremder Mensch mit eigener
     * rec_person_id darf nichts beisteuern — sonst waere aus der Person
     * unbemerkt die ganze Tabelle geworden.
     */
    public function test_ein_fremder_mensch_steuert_nichts_bei(): void
    {
        $ich = $this->mitarbeiter(['rec_person_id' => $this->personAnlegen()]);
        $this->mitarbeiter(['rec_person_id' => $this->personAnlegen(), 'employment_type' => 'student']);

        $codes = $this->codes($ich);

        $this->assertNotContains('immatrikulation', $codes);
        $this->assertContains('ausweis', $codes, 'die eigene Pflicht steht weiter da');
    }

    public function test_eine_beendete_anstellung_ergibt_nicht_alles_erledigt(): void
    {
        // vereinige([]) gibt [] — und mit dem is_active-Filter unten kommt bei
        // einem beendeten Mitarbeiter eine LEERE Pflichtliste heraus. Die Ansicht
        // liest daraus "alles erledigt" statt "kein Nachweis da".
        //
        // Im Portal unmoeglich (verifyPortalAccess verlangt is_active), in der
        // HR-Mitarbeiterakte sehr wohl: Livewire/Employees/Show.php:247 ruft
        // checklist() auch fuer beendete Mitarbeiter.
        $person = $this->personAnlegen();
        $beendet = $this->mitarbeiter(['rec_person_id' => $person, 'is_active' => false]);

        $checkliste = (new ProofReader())->checklist($beendet);

        // Der Ausweis ist Pflicht fuer JEDEN — er darf nicht verschwinden, nur
        // weil die Anstellung beendet ist.
        $this->assertContains('ausweis', array_column($checkliste, 'code'));
    }

    /**
     * Der Rueckfall ist kein fest verdrahtetes ['ausweis'], sondern dieselbe
     * Vereinigung ueber ALLE Anstellungen: in der HR-Akte eines beendeten
     * Studenten gehoert die Immatrikulation weiter auf die Liste, sonst sieht
     * HR eine Akte als vollstaendig an, die es nie war.
     */
    public function test_beim_rueckfall_zaehlt_der_typ_der_beendeten_anstellung_mit(): void
    {
        $person = $this->personAnlegen();
        $beendet = $this->mitarbeiter([
            'rec_person_id' => $person,
            'is_active' => false,
            'employment_type' => 'student',
        ]);

        $codes = $this->codes($beendet);

        $this->assertContains('immatrikulation', $codes);
        $this->assertContains('ausweis', $codes);
    }

    /**
     * Die andere Seite desselben Filters, und der Test zur zweiten
     * Mutationsprobe: solange EINE aktive Anstellung da ist, greift der
     * Rueckfall NICHT — die Pflicht einer beendeten Anstellung ist erledigt.
     * Wer studiert hat und jetzt nur noch als Aushilfe arbeitet, wird nicht
     * weiter nach der Immatrikulation gefragt.
     */
    public function test_eine_beendete_anstellung_bringt_ihre_pflicht_nicht_mehr_mit(): void
    {
        $person = $this->personAnlegen();
        $aktiv = $this->mitarbeiter(['rec_person_id' => $person, 'employment_type' => 'aushilfe']);
        $this->mitarbeiter([
            'rec_person_id' => $person,
            'is_active' => false,
            'employment_type' => 'student',
        ]);

        $codes = $this->codes($aktiv);

        $this->assertNotContains('immatrikulation', $codes);
        // Zweitbeleg gegen die leere Liste: die Erwartung darueber waere auch
        // dann erfuellt, wenn gar nichts mehr auf der Liste staende.
        $this->assertContains('ausweis', $codes);
    }
}
