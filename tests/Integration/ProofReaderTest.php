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
use Platform\Recruiting\Services\ProofWriter;
use Platform\Recruiting\Support\ProofChecklist;

/**
 * Der Leseweg entscheidet, was der Mitarbeiter beim Anmelden als erstes sieht.
 * Vor allem: dass er seinen Ausweis EINMAL hochlaedt, auch wenn er bei beiden
 * Gesellschaften angestellt ist.
 */
final class ProofReaderTest extends TestCase
{
    private const HEUTE = '2026-09-24';

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

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            $t->integer('identity_card_front_file_id')->nullable();
            $t->integer('identity_card_back_file_id')->nullable();
            $t->date('identity_card_valid_until')->nullable();
            $t->integer('immatrikulation_file_id')->nullable();
            $t->date('school_certificate_valid_until')->nullable();
            $t->integer('aufenthaltstitel_front_file_id')->nullable();
            $t->integer('aufenthaltstitel_back_file_id')->nullable();
            $t->date('residence_permit_valid_until')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

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

    private function anstellung(int $id, array $attr = []): RecEmployee
    {
        DB::table('rec_employees')->insert(array_merge([
            'id' => $id, 'uuid' => "u-{$id}", 'team_id' => 3,
            'person_key' => 'p-1', 'phone' => '+4915112345678',
        ], $attr));

        return RecEmployee::find($id);
    }

    /** @return array<string,string> code => status */
    private function statusJeArt(array $liste): array
    {
        return array_combine(array_column($liste, 'code'), array_column($liste, 'status'));
    }

    public function test_ohne_nachweise_fehlt_der_ausweis(): void
    {
        $ma = $this->anstellung(1, ['is_eu_citizen' => true]);

        $status = $this->statusJeArt((new ProofReader())->checklist($ma, self::HEUTE));

        $this->assertSame(ProofChecklist::FEHLT, $status['ausweis']);
        $this->assertArrayNotHasKey('aufenthaltstitel', $status, 'EU-Buerger wird nicht nach Aufenthaltstitel gefragt');
    }

    public function test_upload_an_einer_anstellung_zaehlt_fuer_die_andere(): void
    {
        $rg = $this->anstellung(1, ['is_eu_citizen' => true]);
        $ma = $this->anstellung(2, ['is_eu_citizen' => true, 'phone' => '015112345678']);

        (new ProofWriter())->store($rg, 'ausweis', ['file_id' => 111, 'valid_until' => '2030-01-01']);

        $status = $this->statusJeArt((new ProofReader())->checklist($ma, self::HEUTE));

        $this->assertSame(ProofChecklist::OK, $status['ausweis'],
            'derselbe Mensch — er soll den Ausweis nicht zweimal hochladen muessen');
    }

    public function test_geschwister_mit_abweichender_nummer_zaehlt_nicht(): void
    {
        $rg = $this->anstellung(1, ['is_eu_citizen' => true]);
        $fremd = $this->anstellung(2, ['is_eu_citizen' => true, 'phone' => '+4917699998888']);

        (new ProofWriter())->store($rg, 'ausweis', ['file_id' => 111, 'valid_until' => '2030-01-01']);

        $status = $this->statusJeArt((new ProofReader())->checklist($fremd, self::HEUTE));

        $this->assertSame(ProofChecklist::FEHLT, $status['ausweis'], 'im Zweifel weniger zeigen');
    }

    public function test_nicht_eu_bekommt_die_zusaetzlichen_pflichten(): void
    {
        $ma = $this->anstellung(1, ['is_eu_citizen' => false]);

        $status = $this->statusJeArt((new ProofReader())->checklist($ma, self::HEUTE));

        $this->assertSame(ProofChecklist::FEHLT, $status['aufenthaltstitel']);
        $this->assertSame(ProofChecklist::FEHLT, $status['arbeitsgenehmigung']);
        $this->assertSame(ProofChecklist::FEHLT, $status['nationalpass']);
    }

    /** 83 % des Bestands haben kein EU-Kennzeichen — die duerfen keine falsche Aufgabe bekommen. */
    public function test_unbekannter_eu_status_erzeugt_keine_aufgabe(): void
    {
        $ma = $this->anstellung(1, ['is_eu_citizen' => null]);

        $status = $this->statusJeArt((new ProofReader())->checklist($ma, self::HEUTE));

        $this->assertArrayNotHasKey('aufenthaltstitel', $status);
        $this->assertSame(ProofChecklist::FEHLT, $status['ausweis']);
    }

    public function test_student_braucht_die_immatrikulation(): void
    {
        $ma = $this->anstellung(1, ['is_eu_citizen' => true, 'employment_type' => 'student']);

        $status = $this->statusJeArt((new ProofReader())->checklist($ma, self::HEUTE));

        $this->assertSame(ProofChecklist::FEHLT, $status['immatrikulation']);
        $this->assertArrayNotHasKey('schulbescheinigung', $status);
    }

    public function test_abgelaufener_nachweis_zaehlt_als_offen(): void
    {
        $ma = $this->anstellung(1, ['is_eu_citizen' => true]);
        (new ProofWriter())->store($ma, 'ausweis', ['file_id' => 111, 'valid_until' => '2026-08-01']);

        $reader = new ProofReader();

        $this->assertSame(ProofChecklist::ABGELAUFEN, $this->statusJeArt($reader->checklist($ma, self::HEUTE))['ausweis']);
        $this->assertSame(1, $reader->openCount($ma, self::HEUTE));
    }

    public function test_nur_die_aktuelle_fassung_zaehlt(): void
    {
        $ma = $this->anstellung(1, ['is_eu_citizen' => true]);
        $writer = new ProofWriter();

        $writer->store($ma, 'ausweis', ['file_id' => 111, 'valid_until' => '2026-08-01']); // abgelaufen
        $writer->store($ma, 'ausweis', ['file_id' => 999, 'valid_until' => '2031-01-01']); // erneuert

        $this->assertSame(ProofChecklist::OK, $this->statusJeArt((new ProofReader())->checklist($ma, self::HEUTE))['ausweis']);
        $this->assertSame(0, (new ProofReader())->openCount($ma, self::HEUTE));
    }
}
