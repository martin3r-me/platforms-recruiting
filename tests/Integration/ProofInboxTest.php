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
 * HR-SICHT AUF NACHWEISE (Task 5, 24.09.2026; Korrektur K3 24.09.2026).
 *
 * Kundenvorgabe 22.09.2026: HR prueft AUSSCHLIESSLICH Lohnrelevantes — ein
 * Nachweis-Upload gilt sofort als erledigt. Es gibt bewusst KEINEN
 * allgemeinen Freigabe-Arbeitsvorrat — das waere genau die Arbeit, die dieses
 * Projekt abschaffen soll.
 *
 * Korrektur K3: die Bestaetigen-Aktion (bestaetige()) ist aus der Komponente
 * entfernt — sie hatte keine Wirkung (keine Einsatzsperre haengt an
 * residence_permit_valid_until/work_permit_valid_until, kein Konsument von
 * confirmed_at im ganzen Modul). wartetAufBestaetigung() bleibt als REINE
 * Anzeige stehen: HR soll sehen, wenn ein neuer Aufenthaltstitel oder eine
 * Arbeitsgenehmigung eingegangen ist — ohne Handlungsversprechen.
 *
 * Geprueft wird die Datenseite der Komponente (Computed-Methoden),
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

        // confirmedByUser() wird jetzt in der Anzeige benutzt (Fixrunde 1) —
        // ohne diese Tabelle wirft das Eager-Loading in neuEingegangen()/
        // wartetAufBestaetigung()/nachweisUebersicht() eine SQL-Exception.
        $this->capsule->schema()->create('users', function ($t) {
            $t->increments('id');
            $t->string('name')->nullable();
        });

        Capsule::table('rec_employees')->insert([
            ['id' => 900, 'uuid' => 'remp-900', 'team_id' => self::TEAM, 'first_name' => 'Lydia', 'last_name' => 'Bontioti'],
            ['id' => 901, 'uuid' => 'remp-901', 'team_id' => self::TEAM, 'first_name' => 'Lars', 'last_name' => 'Abdull'],
            ['id' => 902, 'uuid' => 'remp-902', 'team_id' => self::FREMDES_TEAM, 'first_name' => 'Fremd', 'last_name' => 'Person'],
        ]);

        Capsule::table('users')->insert([
            ['id' => self::HR_USER_ID, 'name' => 'Nina Personal'],
            ['id' => self::HR_USER_ID + 1, 'name' => 'Kevin HR'],
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

    /**
     * Fixrunde 1 (Befund 2, Important): am Umzugstag kann diese Liste
     * dreistellig werden — ohne Blaetterung stuende alles auf einer Seite.
     * total() muss die ECHTE Gesamtzahl ueber alle Seiten zeigen, nicht nur
     * die Zahl der Zeilen auf der aktuellen Seite.
     */
    public function test_wartet_auf_bestaetigung_blaettert_bei_grosser_anzahl(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->proof(['proof_type_code' => 'aufenthaltstitel', 'valid_until' => sprintf('2027-01-%02d', ($i % 28) + 1)]);
        }

        $ersteSeite = $this->component()->wartetAufBestaetigung();

        $this->assertSame(30, $ersteSeite->total(), 'Gesamtzahl ueber alle Seiten');
        $this->assertLessThan($ersteSeite->total(), $ersteSeite->count(), 'erste Seite zeigt nicht alle 30 auf einmal');
    }

    /**
     * Kleinigkeit aus Fixrunde 1: confirmedByUser() wird jetzt angezeigt
     * (Entscheidung siehe Fixbericht) — bestaetigt von <Name>.
     */
    public function test_neu_eingegangen_zeigt_wer_bestaetigt_hat(): void
    {
        $id = $this->proof(['proof_type_code' => 'aufenthaltstitel', 'confirmed_at' => now(), 'confirmed_by_user_id' => self::HR_USER_ID]);

        $zeile = collect($this->component()->neuEingegangen())->firstWhere('id', $id);

        $this->assertSame('Nina Personal', $zeile['confirmed_by']);
    }
}
