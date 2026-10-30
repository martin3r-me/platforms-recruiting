<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\ProofTypes;
use ReflectionMethod;

/**
 * Spec 2026-10-22 §3 (RG + MA ueber die Person): das neue Portal liest
 * Personenfelder ueber alle AKTIVEN Anstellungen der Person — eigener Wert,
 * sonst juengste aktive Schwester. Harness wie PortalShellProfilDatenTest.
 */
final class PortalShellPersonDatensatzTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            // Nachweise aus der alten Akte (30.10.2026): datensatzWerte() liest sie.
            'database/migrations/2026_09_23_000001_create_rec_employee_proofs_table.php',
            'database/migrations/2026_05_21_000001_add_full_field_set_to_rec_employees.php',
            // account_holder, tax_class, religion, ... -- braucht die HR-Data-Tabelle als Voraussetzung
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php',
            // is_first_aider
            'database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php',
            // erstbescheinigung_file_id (Gesundheit-Gruppe)
            'database/migrations/2026_08_06_000001_add_erstbescheinigung_file_id_to_rec_employees.php',
            // first_aider_certificate_file_id
            'database/migrations/2026_09_01_000001_add_first_aider_certificate_file_id_to_rec_employees.php',
            // Staatsangehoerigkeit
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
            // is_main_employer / other_employer
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
            // portal_locked_at -- berechtigterMitarbeiter() liest sie
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            // portal_last_seen_at -- Reihenfolge-Abhaengigkeit (->after())
            'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php',
            // portal_v2_since -- Reihenfolge-Abhaengigkeit (->after())
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
            // Spec HR-Bereich §9.6: PortalAuth::sitzungGueltig() liest rec_person_id.
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
            // Spec 2026-10-22 §3: Schwestern ueber person_key, is_test, company
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_10_21_000001_add_is_test_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
            // oeffneGruppe() -> berechtigterMitarbeiter() liest die Person (Sitzungs-Ungueltigkeit)
            'database/migrations/2026_09_28_000001_create_rec_persons_table.php',
            'database/migrations/2026_09_29_000001_add_konto_felder_to_rec_persons.php',
            'database/migrations/2026_10_12_000003_add_sitzungen_ungueltig_ab_to_rec_persons.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        TestPortalSitzung::aufraeumen();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_persons')->delete();
        // Sitzungspruefung (PortalAuth::sitzungGueltig) liest die Person.
        Capsule::table('rec_persons')->insert(['id' => 77, 'uuid' => 'p-77', 'team_id' => 913, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';
        TestPortalSitzung::oeffne((int) $ma->id);

        return $shell;
    }

    /** @return array{gruppen:array, stand:array, felder:array, hinweis:?string, nurLesen:array, kacheln:array} */
    private function profilDaten(PortalShell $shell, ?RecEmployee $employee): array
    {
        $methode = new ReflectionMethod($shell, 'profilDaten');
        $methode->setAccessible(true);

        return $methode->invoke($shell, $employee);
    }

    private function akte(array $set): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => 913, 'first_name' => 'Tanja', 'last_name' => 'Test',
            'portal_token' => 'tok-' . uniqid(), 'is_active' => true,
            'portal_v2_since' => '2026-09-24 08:00:00',
            'is_eu_citizen' => true, 'is_main_employer' => true, 'rec_person_id' => 77,
        ], $set));
    }

    /** @return list<string> */
    private function offenePflichtfelder(RecEmployee $e): array
    {
        return array_column($this->profilDaten($this->shell($e), $e)['pflicht'], 'feld');
    }

    /** Fall Tanja (Spec §1): Staatsangehoerigkeit nur an der MA-Akte. */
    public function test_staatsangehoerigkeit_der_aktiven_schwester_zaehlt(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);

        $this->assertNotContains('nationality', $this->offenePflichtfelder($rg));
    }

    public function test_blatt_ist_mit_dem_wert_der_schwester_vorbelegt(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Adresse');

        $this->assertSame('deutsch', $shell->profilWerte['nationality'] ?? null);
    }

    public function test_inaktive_schwester_zaehlt_nicht(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_active' => false]);

        $this->assertContains('nationality', $this->offenePflichtfelder($rg));
    }

    public function test_test_schwester_zaehlt_nicht(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_test' => true]);

        $this->assertContains('nationality', $this->offenePflichtfelder($rg));
    }

    public function test_hauptarbeitgeber_bleibt_je_akte(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'is_main_employer' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);

        $this->assertContains('is_main_employer', $this->offenePflichtfelder($rg));
    }

    public function test_eigener_wert_gewinnt_bei_widerspruch(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch']);
        $this->akte(['company' => 'MA', 'nationality' => 'polnisch', 'is_main_employer' => false]);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Adresse');

        $this->assertSame('deutsch', $shell->profilWerte['nationality'] ?? null);
    }

    /** Review Focus 2: drei aktive Akten — die juengste gefuellte Schwester gewinnt. */
    public function test_juengste_schwester_gewinnt(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null]);
        $alt = $this->akte(['company' => 'MA', 'nationality' => 'polnisch', 'is_main_employer' => false]);
        $neu = $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false]);
        Capsule::table('rec_employees')->where('id', $alt->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        Capsule::table('rec_employees')->where('id', $neu->id)->update(['updated_at' => '2026-10-01 00:00:00']);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Adresse');

        $this->assertSame('deutsch', $shell->profilWerte['nationality'] ?? null);
    }

    /** Review Focus 4: Verbindung nur ueber person_key + gleiche Nummer. */
    public function test_person_key_verbindung_reicht(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => null, 'rec_person_id' => null, 'person_key' => 'k-9', 'phone' => '+4915112345678']);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'rec_person_id' => null, 'person_key' => 'k-9', 'phone' => '+4915112345678', 'is_main_employer' => false]);

        $this->assertNotContains('nationality', $this->offenePflichtfelder($rg));
    }

    /**
     * Review Focus 1: false der Schwester ist ein Wert. Ersthelfer "leer" ist
     * heute keine offene Pflicht (der Waechter blockt erst bei Ja ohne Datum),
     * deshalb pruefen wir die Vorbelegung des Blatts (Plan Task 2, Hinweis).
     */
    public function test_ersthelfer_nein_der_schwester_ist_beantwortet(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'is_first_aider' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_first_aider' => false, 'is_main_employer' => false]);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Arbeitsschutz');

        $this->assertSame('0', $shell->profilWerte['is_first_aider'] ?? null);
    }

    /**
     * Review I1 (22.10.): Schwester hat Ersthelfer Ja + Datum + Schein, die
     * RG-Akte nichts. Das Blatt zeigt Ja und das Datum — Speichern darf nicht
     * mit "Datum fehlt" scheitern (Speicher-Waechter las nur die eigene Akte).
     */
    public function test_arbeitsschutz_speichern_mit_ersthelfer_der_schwester(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'is_first_aider' => null, 'first_aider_valid_until' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false,
            'is_first_aider' => true, 'first_aider_valid_until' => '2027-05-01', 'first_aider_certificate_file_id' => 501]);
        $shell = $this->shell($rg);

        $shell->oeffneGruppe('Arbeitsschutz');
        $shell->speichereGruppe();

        $this->assertSame('', $shell->profilFehler);
        $this->assertTrue((bool) RecEmployee::find($rg->id)->is_first_aider, 'RG-Akte heilt sich beim Speichern');
    }

    /** 26.10.2026: lohnrelevante Luecken kommen aus dem echten (personenweiten) Datensatz. */
    public function test_lohn_luecken_nur_lohnrelevant_und_personenweit(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'iban' => null, 'religion' => null, 'steuer_id' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false, 'steuer_id' => '12345678901']);

        $fehlt = $this->profilDaten($this->shell($rg), $rg)['lohnFehlt'];

        $this->assertArrayHasKey('iban', $fehlt);
        $this->assertArrayNotHasKey('religion', $fehlt, 'freiwillig');
        $this->assertArrayNotHasKey('steuer_id', $fehlt, 'die Schwester hat sie (Personenfeld)');
    }

    /** 26.10.2026: der Lohn-Zweig der Themen-Auswahl rechnet wie der Portal-Start. */
    public function test_lohnangaben_rechnen_wie_der_start(): void
    {
        $rg = $this->akte(['company' => 'RG', 'nationality' => 'deutsch', 'iban' => null, 'religion' => null, 'steuer_id' => null]);
        $this->akte(['company' => 'MA', 'nationality' => 'deutsch', 'is_main_employer' => false, 'steuer_id' => '12345678901']);

        $dienst = (new \Platform\Recruiting\Services\Lohnangaben())->fehlendFuer($rg);
        $start = $this->profilDaten($this->shell($rg), $rg)['lohnFehlt'];

        $this->assertSame($start, $dienst);
        $this->assertArrayHasKey('iban', $dienst);
        $this->assertArrayNotHasKey('steuer_id', $dienst);
    }
}
