<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\ProofInbox;
use Platform\Recruiting\Support\ProofTypes;

/**
 * HR-SICHT AUF NACHWEISE (Task 5, 24.09.2026).
 *
 * Kundenvorgabe 22.09.2026: HR prueft AUSSCHLIESSLICH Lohnrelevantes — ein
 * Nachweis-Upload gilt sofort als erledigt. Die EINZIGE Ausnahme sind
 * Aufenthaltstitel und Arbeitsgenehmigung, an denen die harte Einsatzsperre
 * haengt; nur dort bestaetigt ein Mensch das Datum. Es gibt bewusst KEINEN
 * allgemeinen Freigabe-Arbeitsvorrat — das waere genau die Arbeit, die dieses
 * Projekt abschaffen soll.
 *
 * Geprueft wird die Datenseite der Komponente (Computed-Methoden + Aktion),
 * nicht das Markup — wie im Rest der Suite (siehe EmployeeActivityLogTest).
 */
final class ProofInboxTest extends TestCase
{
    private const TEAM = 7;
    private const FREMDES_TEAM = 9;
    private const HR_USER_ID = 42;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $container->instance('db', $this->capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->setAuth(self::HR_USER_ID);

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('person_key', 36)->nullable();
            // Steht fuer den ZAS-Update-Marker (RecEmployeeExportObserver).
            // Muss nach bestaetige() unangetastet bleiben.
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->uuid('uuid');
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

        Capsule::table('rec_employees')->insert([
            ['id' => 900, 'uuid' => 'remp-900', 'team_id' => self::TEAM, 'first_name' => 'Lydia', 'last_name' => 'Bontioti'],
            ['id' => 901, 'uuid' => 'remp-901', 'team_id' => self::TEAM, 'first_name' => 'Lars', 'last_name' => 'Abdull'],
            ['id' => 902, 'uuid' => 'remp-902', 'team_id' => self::FREMDES_TEAM, 'first_name' => 'Fremd', 'last_name' => 'Person'],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        $this->capsule->schema()->dropAllTables();
        parent::tearDown();
    }

    /** Setzt den angemeldeten HR-User (Team fest auf self::TEAM) — austauschbar, um zwei verschiedene HR-Personen zu simulieren. */
    private function setAuth(int $userId): void
    {
        Container::getInstance()->instance(AuthFactory::class, new class(self::TEAM, $userId) implements AuthFactory
        {
            public function __construct(private int $teamId, private int $userId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function id(): int
            {
                return $this->userId;
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }
        });
    }

    /** @param array<string,mixed> $attr */
    private function proof(array $attr): int
    {
        $id = Capsule::table('rec_employee_proofs')->insertGetId(array_merge([
            'uuid'            => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id'         => self::TEAM,
            'rec_employee_id' => 900,
            'proof_type_code' => 'ausweis',
            'uploaded_via'    => 'employee',
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $attr));

        return (int) $id;
    }

    private function component(): ProofInbox
    {
        return new ProofInbox();
    }

    // ---- Skizze aus dem Brief -------------------------------------------------

    public function test_liste_zeigt_neu_eingegangene_zuerst(): void
    {
        $this->proof(['proof_type_code' => 'selfie', 'created_at' => '2026-09-20 09:00:00']);
        $neuestId = $this->proof(['proof_type_code' => 'krankenkasse', 'created_at' => '2026-09-23 10:00:00']);
        $this->proof(['proof_type_code' => 'ausweis', 'created_at' => '2026-09-21 09:00:00']);

        $liste = $this->component()->neuEingegangen();

        $this->assertSame($neuestId, $liste[0]['id'], 'der zuletzt hochgeladene Nachweis steht oben');
        $this->assertGreaterThanOrEqual(
            $liste[1]['created_at'], $liste[0]['created_at'],
            'absteigend nach Eingang sortiert'
        );
    }

    public function test_neu_eingegangen_ignoriert_massenimport(): void
    {
        // recruiting:nachweise-umziehen setzt created_at=now() beim einmaligen
        // Ruecksicherungslauf der Altspalten — das ist kein frischer Eingang.
        $this->proof(['proof_type_code' => 'selfie', 'uploaded_via' => 'import', 'created_at' => now()]);
        $echterUpload = $this->proof(['proof_type_code' => 'ausweis', 'uploaded_via' => 'employee', 'created_at' => '2026-09-01 08:00:00']);

        $liste = $this->component()->neuEingegangen();

        $this->assertCount(1, $liste);
        $this->assertSame($echterUpload, $liste[0]['id']);
    }

    public function test_neu_eingegangen_zeigt_nur_eigenes_team(): void
    {
        $this->proof(['rec_employee_id' => 902, 'team_id' => self::FREMDES_TEAM, 'proof_type_code' => 'ausweis']);
        $eigener = $this->proof(['proof_type_code' => 'selfie']);

        $liste = $this->component()->neuEingegangen();

        $this->assertCount(1, $liste);
        $this->assertSame($eigener, $liste[0]['id']);
    }

    /**
     * Auch hier abgedeckt (der Katalog selbst wird zusaetzlich rein per
     * Unit-Test in ProofTypesTest geprueft) — laut Brief gehoert diese
     * Zusicherung mit in die Integrationssuite dieser Aufgabe.
     */
    public function test_nur_aufenthaltstitel_und_arbeitsgenehmigung_verlangen_bestaetigung(): void
    {
        $this->assertTrue(ProofTypes::needsHrConfirmation('aufenthaltstitel'));
        $this->assertTrue(ProofTypes::needsHrConfirmation('arbeitsgenehmigung'));
        $this->assertFalse(ProofTypes::needsHrConfirmation('ausweis'));
        $this->assertFalse(ProofTypes::needsHrConfirmation('selfie'));
    }

    public function test_bestaetigen_setzt_keinen_export_marker(): void
    {
        $id = $this->proof(['proof_type_code' => 'aufenthaltstitel', 'valid_until' => '2027-01-01']);

        $this->component()->bestaetige($id);

        $proof = Capsule::table('rec_employee_proofs')->find($id);
        $this->assertNotNull($proof->confirmed_at, 'Bestaetigung wurde gesetzt');
        $this->assertSame(self::HR_USER_ID, (int) $proof->confirmed_by_user_id);

        $employee = Capsule::table('rec_employees')->find(900);
        $this->assertNull($employee->zas_changed_at, 'Bestaetigen darf keinen ZAS-Export-Marker setzen');
    }

    // ---- Ergaenzungen: wer darf bestaetigen, doppelte Bestaetigung, Mandat ----

    public function test_nur_pflicht_arten_koennen_bestaetigt_werden(): void
    {
        // ausweis braucht laut Kundenvorgabe KEINE Bestaetigung — ein Aufruf
        // darauf darf trotzdem nichts kaputt machen, aber auch nichts setzen.
        $id = $this->proof(['proof_type_code' => 'ausweis']);

        $this->component()->bestaetige($id);

        $proof = Capsule::table('rec_employee_proofs')->find($id);
        $this->assertNull($proof->confirmed_at, 'ausweis ist nicht bestaetigungspflichtig');
    }

    public function test_doppelte_bestaetigung_aendert_nichts_mehr(): void
    {
        $id = $this->proof(['proof_type_code' => 'arbeitsgenehmigung', 'valid_until' => '2027-06-01']);

        $this->setAuth(self::HR_USER_ID);
        $this->component()->bestaetige($id);
        $nachErstemKlick = Capsule::table('rec_employee_proofs')->find($id);

        // Zweiter Klick von einer ANDEREN HR-Person — muss ins Leere laufen,
        // sonst verliert die Akte, wer wirklich zuerst bestaetigt hat.
        $this->setAuth(self::HR_USER_ID + 1);
        $this->component()->bestaetige($id);
        $nachZweitemKlick = Capsule::table('rec_employee_proofs')->find($id);

        $this->assertSame($nachErstemKlick->confirmed_at, $nachZweitemKlick->confirmed_at);
        $this->assertSame((int) $nachErstemKlick->confirmed_by_user_id, (int) $nachZweitemKlick->confirmed_by_user_id,
            'der zweite Klick darf die erste Bestaetigung nicht ueberschreiben, auch nicht durch eine andere Person');
        $this->assertSame(self::HR_USER_ID, (int) $nachZweitemKlick->confirmed_by_user_id);
    }

    public function test_bestaetigen_scheitert_ueber_mandatsgrenze(): void
    {
        $fremderProof = $this->proof([
            'rec_employee_id' => 902, 'team_id' => self::FREMDES_TEAM,
            'proof_type_code' => 'aufenthaltstitel',
        ]);

        // auth()->user()->currentTeam ist TEAM (7), der Nachweis gehoert FREMDES_TEAM (9).
        $this->component()->bestaetige($fremderProof);

        $proof = Capsule::table('rec_employee_proofs')->find($fremderProof);
        $this->assertNull($proof->confirmed_at, 'ein fremdes Mandat darf HR nicht bestaetigen');
    }

    public function test_wartet_auf_bestaetigung_zeigt_nur_offene_pflicht_nachweise_sortiert_nach_ablauf(): void
    {
        // nicht relevant: falsche Art
        $this->proof(['proof_type_code' => 'nationalpass', 'valid_until' => '2026-10-01']);
        // nicht relevant: schon bestaetigt
        $this->proof(['proof_type_code' => 'aufenthaltstitel', 'valid_until' => '2026-10-05', 'confirmed_at' => now(), 'confirmed_by_user_id' => self::HR_USER_ID]);

        $laeuftBaldAb = $this->proof(['proof_type_code' => 'arbeitsgenehmigung', 'valid_until' => '2026-10-01']);
        $laeuftSpaeterAb = $this->proof(['proof_type_code' => 'aufenthaltstitel', 'valid_until' => '2027-05-01']);

        $liste = $this->component()->wartetAufBestaetigung();

        $this->assertCount(2, $liste);
        $this->assertSame($laeuftBaldAb, $liste[0]['id'], 'das fruehere Ablaufdatum ist dringender');
        $this->assertSame($laeuftSpaeterAb, $liste[1]['id']);
    }
}
