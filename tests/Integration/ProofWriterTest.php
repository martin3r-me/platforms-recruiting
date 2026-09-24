<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Services\ProofWriter;

/**
 * Das Doppelschreiben ist die riskanteste Stelle von Runde 1.
 *
 * Geht die Spiegelung in die Altspalten ueber Eloquent, setzt der
 * RecEmployeeExportObserver `zas_changed_at` — und die erste Upload-Welle
 * spuelt bei ueber 500 abgelaufenen Nachweisen den halben Bestand in die
 * updates.csv. Genau das ist am 02.09.2026 schon einmal passiert.
 *
 * Der Test haengt sich deshalb an das Eloquent-Ereignis `updated` von
 * RecEmployee: Feuert es waehrend eines Uploads auch nur einmal, faellt er um.
 */
final class ProofWriterTest extends TestCase
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

        // Ohne diese Bindung findet die DB-Fassade den Manager nicht; ohne
        // clearResolvedInstances zeigt sie auf die Verbindung der vorigen
        // Testklasse. Beides load-bearing, Muster aus
        // EmployeeCreationCertificateTest.
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
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->integer('identity_card_front_file_id')->nullable();
            $t->integer('identity_card_back_file_id')->nullable();
            $t->date('identity_card_valid_until')->nullable();
            $t->integer('immatrikulation_file_id')->nullable();
            $t->date('school_certificate_valid_until')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->boolean('is_active')->default(true);
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

    private function anstellung(int $id, ?string $key, ?string $phone): RecEmployee
    {
        DB::table('rec_employees')->insert([
            'id' => $id, 'uuid' => "u-{$id}", 'team_id' => 3,
            'person_key' => $key, 'phone' => $phone,
            'first_name' => 'Test', 'last_name' => "Person{$id}",
        ]);

        return RecEmployee::find($id);
    }

    public function test_legt_nachweis_mit_personen_marker_an(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');

        $proof = (new ProofWriter())->store($ma, 'ausweis', [
            'file_id' => 111, 'file_back_id' => 222, 'valid_until' => '2030-01-31',
        ]);

        $this->assertSame('p-1', $proof->person_key);
        $this->assertSame(1, $proof->version);
        $this->assertSame(111, $proof->file_id);
    }

    public function test_spiegelt_in_die_altspalten(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');

        (new ProofWriter())->store($ma, 'ausweis', [
            'file_id' => 111, 'file_back_id' => 222, 'valid_until' => '2030-01-31',
        ]);

        $zeile = DB::table('rec_employees')->find(1);
        $this->assertSame(111, (int) $zeile->identity_card_front_file_id);
        $this->assertSame(222, (int) $zeile->identity_card_back_file_id);
        $this->assertSame('2030-01-31', substr((string) $zeile->identity_card_valid_until, 0, 10));
    }

    /** Die Kernzusage: kein Eloquent-Update auf rec_employees, also kein Export-Marker. */
    public function test_setzt_keinen_export_marker(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');

        $gefeuert = false;
        RecEmployee::updated(function () use (&$gefeuert) { $gefeuert = true; });

        (new ProofWriter())->store($ma, 'ausweis', ['file_id' => 111, 'valid_until' => '2030-01-31']);

        $this->assertFalse($gefeuert, 'Spiegelung darf NICHT ueber Eloquent laufen — sonst setzt der Observer zas_changed_at');
        $this->assertNull(DB::table('rec_employees')->find(1)->zas_changed_at);

        // Gegenprobe: Das Ereignis feuert in dieser Umgebung grundsaetzlich.
        // Ohne sie waere die Zusicherung oben wertlos — ein Lauscher, der nie
        // anspringt, bestaetigt jede Behauptung.
        $ma->update(['first_name' => 'Geaendert']);
        $this->assertTrue($gefeuert, 'Gegenprobe: ein echtes Eloquent-Update MUSS das Ereignis ausloesen');
    }

    public function test_spiegelt_auf_beide_anstellungen_der_person(): void
    {
        $rg = $this->anstellung(1, 'p-1', '+4915112345678');
        $this->anstellung(2, 'p-1', '015112345678'); // dieselbe Nummer, andere Schreibweise

        (new ProofWriter())->store($rg, 'ausweis', ['file_id' => 111, 'valid_until' => '2030-01-31']);

        $this->assertSame(111, (int) DB::table('rec_employees')->find(2)->identity_card_front_file_id,
            'beide Gesellschaften exportieren getrennt — der Ausweis gehoert dem Menschen');
    }

    public function test_spiegelt_nicht_auf_geschwister_mit_abweichender_nummer(): void
    {
        $rg = $this->anstellung(1, 'p-1', '+4915112345678');
        $this->anstellung(2, 'p-1', '+4917699998888');

        (new ProofWriter())->store($rg, 'ausweis', ['file_id' => 111, 'valid_until' => '2030-01-31']);

        $this->assertNull(DB::table('rec_employees')->find(2)->identity_card_front_file_id,
            'im Zweifel weniger — der Fall gehoert auf die HR-Liste');
    }

    public function test_neue_fassung_loest_die_alte_ab_ohne_sie_zu_loeschen(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');
        $writer = new ProofWriter();

        $alt = $writer->store($ma, 'ausweis', ['file_id' => 111, 'valid_until' => '2027-01-01']);
        $neu = $writer->store($ma, 'ausweis', ['file_id' => 999, 'valid_until' => '2032-01-01']);

        $this->assertSame(2, $neu->version);
        $this->assertNotNull(RecEmployeeProof::find($alt->id)->superseded_at, 'alte Fassung abgeloest');
        $this->assertNotNull(RecEmployeeProof::find($alt->id), 'aber nicht geloescht');
        $this->assertCount(1, RecEmployeeProof::query()->aktuell()->get());
        $this->assertSame(999, (int) DB::table('rec_employees')->find(1)->identity_card_front_file_id);
    }

    /**
     * M1 (Schlusspruefung): Die Rueckseiten-Spalte wurde BEDINGUNGSLOS mit
     * $proof->file_back_id geschrieben — und das Portal reicht kein
     * file_back_id durch. Wer seinen Ausweis erneuerte und die Vorderseite
     * hochlud, verlor damit stumm die Rueckseite aus ZAS-Export und HR-Akte.
     *
     * Ein fehlendes Feld ist keine Aussage ueber die Rueckseite.
     */
    public function test_upload_ohne_rueckseite_laesst_die_alte_rueckseite_stehen(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');
        DB::table('rec_employees')->where('id', 1)->update([
            'identity_card_front_file_id' => 100,
            'identity_card_back_file_id'  => 200,
        ]);

        (new ProofWriter())->store($ma, 'ausweis', [
            'file_id' => 111, 'valid_until' => '2030-01-31',
        ]);

        $zeile = DB::table('rec_employees')->find(1);
        $this->assertSame(111, (int) $zeile->identity_card_front_file_id, 'die neue Vorderseite ersetzt die alte');
        $this->assertSame(200, (int) $zeile->identity_card_back_file_id, 'die Rueckseite bleibt unangetastet');
    }

    public function test_mitgelieferte_rueckseite_ersetzt_die_alte(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');
        DB::table('rec_employees')->where('id', 1)->update([
            'identity_card_back_file_id' => 200,
        ]);

        (new ProofWriter())->store($ma, 'ausweis', [
            'file_id' => 111, 'file_back_id' => 222, 'valid_until' => '2030-01-31',
        ]);

        $this->assertSame(222, (int) DB::table('rec_employees')->find(1)->identity_card_back_file_id);
    }

    public function test_weist_unbekannte_art_ab(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');
        $this->expectException(InvalidArgumentException::class);
        (new ProofWriter())->store($ma, 'gibtsnicht');
    }

    public function test_weist_gueltig_bis_bei_art_ohne_ablauf_ab(): void
    {
        $ma = $this->anstellung(1, 'p-1', '+4915112345678');
        $this->expectException(InvalidArgumentException::class);
        (new ProofWriter())->store($ma, 'selfie', ['valid_until' => '2030-01-01']);
    }
}
