<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Livewire\LivewireManager;
use PHPUnit\Framework\TestCase;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeProof;
use Platform\Recruiting\Support\ProofUploadRules;

/**
 * Die Upload-Strecke: der Mensch tippt eine offene Aufgabe an, fotografiert
 * den Nachweis, traegt das Ablaufdatum ein — fertig.
 *
 * Wichtigster Fall hier: test_ohne_anmeldung_wird_nichts_gespeichert. Ein
 * $wire.call('speichereNachweis') ohne gueltige Anmeldung darf NIE etwas
 * anlegen, egal was in den oeffentlichen Eigenschaften steht.
 *
 * ContextFileService (platforms-core) wird durch eine schlanke Attrappe
 * ersetzt: der echte Dienst schreibt Dateien auf Platte, ein ContextFile-
 * Modell und stoesst einen Variantens-Job an — Infrastruktur, die nichts mit
 * der Entscheidungslogik dieser Komponente zu tun hat und in Core eigenstaendig
 * getestet ist. Gleiches Muster wie PortalAuth als Parameter in verify().
 */
final class PortalShellUploadTest extends TestCase
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

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());

        // Livewires $this->validate() braucht 'validator', 'translator' und
        // 'livewire' im Container — sonst "Target class [livewire] does not
        // exist." Ohne vollen Laravel-Boot muessen sie von Hand hinein.
        $translator = new Translator(new ArrayLoader(), 'de');
        $container->instance('translator', $translator);
        $container->instance('validator', new ValidationFactory($translator, $container));
        $container->instance('livewire', $container->make(LivewireManager::class));

        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        // Attrappe statt echtem ContextFileService: kein Storage, kein
        // ContextFile-Modell, kein Variantens-Job — reine Rueckgabe der Id,
        // die ProofWriter::store() braucht.
        $fake = new class extends ContextFileService {
            public array $aufrufe = [];

            public function __construct() {}

            public function uploadForContext($file, string $contextType, int $contextId, array $options = []): array
            {
                $this->aufrufe[] = ['contextType' => $contextType, 'contextId' => $contextId, 'options' => $options];

                return ['id' => 4242, 'token' => 'faketoken', 'path' => 'fake.webp', 'original_name' => $file->getClientOriginalName(), 'url' => null, 'variants' => []];
            }
        };
        $container->instance(ContextFileService::class, $fake);

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->integer('identity_card_front_file_id')->nullable();
            $t->integer('identity_card_back_file_id')->nullable();
            $t->date('identity_card_valid_until')->nullable();
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
        Facade::clearResolvedInstances();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function mitarbeiter(array $attr = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'uuid'            => 'u-1',
            'team_id'         => 3,
            'person_key'      => 'p-1',
            'phone'           => '+4915112345678',
            'first_name'      => 'Kevin',
            'last_name'       => 'Muster',
            'is_active'       => true,
            'portal_v2_since' => '2026-09-24 08:00:00',
        ], $attr));
    }

    /** Semantisch derselbe Datensatz wie mitarbeiter() — der Name traegt in
     *  test_ohne_anmeldung_wird_nichts_gespeichert die Aussage: diese Person
     *  hat sich real NIE angemeldet, nur der Shell-Zustand wird verfaelscht. */
    private function angemeldeterMitarbeiter(array $attr = []): RecEmployee
    {
        return $this->mitarbeiter($attr);
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';

        return $shell;
    }

    public function test_upload_legt_den_nachweis_an_und_raeumt_die_aufgabe_weg(): void
    {
        $ma = $this->angemeldeterMitarbeiter();
        $shell = $this->shell($ma);

        $shell->oeffneUpload('ausweis');
        $shell->uploadGueltigBis = '2032-01-31';
        $shell->uploadDatei = UploadedFile::fake()->image('ausweis.jpg');
        $shell->speichereNachweis();

        $this->assertSame('', $shell->uploadFehler);
        $nachweis = RecEmployeeProof::where('rec_employee_id', $ma->id)->aktuell()->first();
        $this->assertSame('ausweis', $nachweis->proof_type_code);
        $this->assertSame('2032-01-31', $nachweis->valid_until->toDateString());
    }

    public function test_falsches_datum_legt_nichts_an(): void
    {
        $ma = $this->angemeldeterMitarbeiter();
        $shell = $this->shell($ma);

        $shell->oeffneUpload('ausweis');
        $shell->uploadGueltigBis = '2020-01-01';
        $shell->uploadDatei = UploadedFile::fake()->image('alt.jpg');
        $shell->speichereNachweis();

        $this->assertNotSame('', $shell->uploadFehler);
        $this->assertSame(0, RecEmployeeProof::count());
    }

    public function test_ohne_anmeldung_wird_nichts_gespeichert(): void
    {
        // Der gefaehrlichste Fall: $wire.call('speichereNachweis') ohne Anmeldung.
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->state = 'unverified';
        $shell->uploadCode = 'ausweis';
        $shell->uploadGueltigBis = '2032-01-31';
        $shell->uploadDatei = UploadedFile::fake()->image('x.jpg');

        $shell->speichereNachweis();

        $this->assertSame(0, RecEmployeeProof::count());
    }

    public function test_upload_setzt_keinen_export_marker(): void
    {
        $ma = $this->angemeldeterMitarbeiter();
        $gefeuert = false;
        RecEmployee::updated(function () use (&$gefeuert) { $gefeuert = true; });

        $shell = $this->shell($ma);
        $shell->oeffneUpload('ausweis');
        $shell->uploadGueltigBis = '2032-01-31';
        $shell->uploadDatei = UploadedFile::fake()->image('a.jpg');
        $shell->speichereNachweis();

        $this->assertFalse($gefeuert, 'Das Hochladen darf keinen ZAS-Export ausloesen.');
        $this->assertNull(DB::table('rec_employees')->find($ma->id)->zas_changed_at);
    }

    public function test_unbekannte_art_wird_abgewiesen(): void
    {
        $ma = $this->angemeldeterMitarbeiter();
        $shell = $this->shell($ma);
        $shell->uploadCode = 'gibtesnicht';   // an oeffneUpload() vorbei gesetzt
        $shell->uploadDatei = UploadedFile::fake()->image('x.jpg');

        $shell->speichereNachweis();

        $this->assertSame(0, RecEmployeeProof::count());
    }

    /**
     * Fixrunde 1: Ohne den Fang um validate() sah der Mensch nichts — Livewire
     * legt die Meldung in eine Fehler-Ablage, die diese Ansicht nirgends
     * anzeigt (nur $uploadFehler). Das Fenster blieb stumm offen.
     */
    public function test_zu_grosse_datei_meldet_sich_beim_menschen(): void
    {
        $ma = $this->angemeldeterMitarbeiter();
        $shell = $this->shell($ma);

        $shell->oeffneUpload('ausweis');
        $shell->uploadGueltigBis = '2032-01-31';
        $shell->uploadDatei = UploadedFile::fake()->create('riesig.jpg', ProofUploadRules::MAX_KB + 1024, 'image/jpeg');
        $shell->speichereNachweis();

        $this->assertNotSame('', $shell->uploadFehler);
        $this->assertSame(0, RecEmployeeProof::count());
    }

    public function test_falscher_dateityp_meldet_sich_beim_menschen(): void
    {
        $ma = $this->angemeldeterMitarbeiter();
        $shell = $this->shell($ma);

        $shell->oeffneUpload('ausweis');
        $shell->uploadGueltigBis = '2032-01-31';
        $shell->uploadDatei = UploadedFile::fake()->create(
            'vertrag.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $shell->speichereNachweis();

        $this->assertNotSame('', $shell->uploadFehler);
        $this->assertSame(0, RecEmployeeProof::count());
    }
}
