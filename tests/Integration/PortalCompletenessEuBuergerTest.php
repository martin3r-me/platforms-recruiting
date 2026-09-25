<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PortalCompleteness;
use Platform\Recruiting\Support\PortalFieldAccess;

/**
 * Punkt 2 (Koordinator-Auftrag zu Aufgabe 6, Portal-Gleichstand):
 *
 * "Der Vollstaendigkeitsring darf nicht haengen. Ein EU-Buerger muss 100
 * Prozent erreichen koennen. Wenn Nicht-EU-Felder mitgezaehlt werden, haengt
 * er dauerhaft darunter." -- Aufgabe 5 hat PortalCompleteness gebaut und
 * ausdruecklich NICHT mit echten Feldschluesseln geprueft (siehe
 * task-5-report.md, PRUEFPUNKT FUER AUFGABE 6/7). Dieser Test schliesst die
 * Luecke: ECHTE Verdrahtung, ECHTE Feldschluessel aus
 * RecEmployee::editableFieldGroups(), kein synthetisches Beispiel.
 *
 * PortalShell::render() selbst laesst sich in dieser Suite nicht aufrufen
 * (kein 'view'-Binding, siehe PortalShellEmployerWiringTest-Kommentar) --
 * render() fuehrt aber exakt dieselben drei Schritte aus, die hier direkt
 * geprueft werden: editableFieldGroups() -> PortalFieldAccess::
 * sichtbareFelderFlach() -> PortalCompleteness::stand().
 *
 * Migrationen wie PortalFieldAccessEquivalenceTest: echtes RecEmployee-
 * Modell auf SQLite via Capsule, kuratierte Teilmenge der Migrationen (nur
 * die, die fuer die Felder eines vollstaendigen EU-Buergers gebraucht
 * werden -- residence_permit_valid_until/work_permit_valid_until (Non-EU)
 * und die Schul-/Immatrikulationsspalten bleiben bewusst aussen vor, sie
 * betreffen diesen Mitarbeiter gar nicht).
 */
final class PortalCompletenessEuBuergerTest extends TestCase
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

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
    }

    /** @return array<string,mixed> alle Felder, mit denen ein EU-Buerger 100 % erreichen kann. */
    private function vollstaendigerEuBuerger(): array
    {
        return [
            'team_id'         => 913,
            'first_name'      => 'Erika',
            'last_name'       => 'Musterfrau',
            'portal_token'    => 'tok-eu-' . uniqid(),
            'is_active'       => true,

            // Kontakt
            'email' => 'erika@example.com',
            'phone' => '01761234567',

            // Adresse -- is_eu_citizen=true schliesst die Non-EU-Gruppe
            // schon in editableFieldGroups() selbst aus (kein Feld zaehlt
            // dort mit).
            'street'        => 'Musterstrasse',
            'house_number'  => '1',
            'zip'           => '50667',
            'city'          => 'Koeln',
            'country_code'  => 'DE',
            'birth_country' => 'DE',
            'nationality'   => 'DE',
            'is_eu_citizen' => true,

            // Persoenliches -- employment_type bewusst NICHT schueler/student,
            // sonst kaeme die Schul-/Immatrikulationsgruppe mit dazu.
            'birth_name'         => 'Musterfrau',
            'birth_place'        => 'Koeln',
            'gender'             => 'w',
            'marital_status'     => 'ledig',
            'employment_type'    => 'vollzeit',
            'religion'           => 'keine',
            'number_of_children' => 0,

            // Bankdaten
            'iban'           => 'DE89370400440532013000',
            'bic'            => 'COBADEFFXXX',
            'bank_institute' => 'Musterbank',
            'account_holder' => 'Erika Musterfrau',

            // Steuer & Versicherung (kaufmaennisches Und im Gruppennamen)
            'tax_class'                     => '1',
            'steuer_id'                     => '12345678901',
            'sozialversicherungsnummer'     => '65220594M001',
            'health_insurance'              => 'aok',
            'health_insurance_card_file_id' => 5001,

            // Arbeitgeber -- "ja" versteckt other_employer per visible_if,
            // es zaehlt also gar nicht erst mit.
            'is_main_employer' => true,

            // Ausweis
            'identity_card_valid_until'   => '2032-01-01',
            'identity_card_front_file_id' => 5002,
            'identity_card_back_file_id'  => 5003,
            'selfie_file_id'              => 5004,

            // Gesundheit -- kein required_if, immer relevant
            'has_infection_protection_certificate' => true,
            'infection_protection_first_issued_at' => '2024-01-01',
            'erstbescheinigung_file_id'            => 5005,

            // Arbeitsschutz -- "nein" versteckt/entrelevantisiert die
            // beiden required_if-Felder darunter komplett.
            'is_first_aider' => false,

            // Arbeitskleidung
            'shirt_size' => 'M',
            'pants_size' => 32,
            'shoe_size'  => 42,

            // Sonstiges
            'drivers_license_class' => 'B',
            'has_car'               => true,
        ];
    }

    /** Fuehrt render()s Vollstaendigkeits-Kette aus, ohne PortalShell selbst (kein 'view'-Binding). */
    private function stand(RecEmployee $employee): array
    {
        $gruppen = $employee->editableFieldGroups();
        $datensatz = [];
        foreach ($gruppen as $felder) {
            foreach (array_keys($felder) as $schluessel) {
                $datensatz[$schluessel] = $employee->getAttribute($schluessel);
            }
        }
        $flach = PortalFieldAccess::sichtbareFelderFlach($gruppen, $datensatz, []);

        return PortalCompleteness::stand($flach, $datensatz);
    }

    public function test_eu_buerger_mit_allen_relevanten_feldern_erreicht_hundert_prozent(): void
    {
        $employee = RecEmployee::create($this->vollstaendigerEuBuerger());

        // Testvoraussetzung: die Non-EU-Gruppe darf fuer diesen Menschen gar
        // nicht erst existieren -- sonst waere der Test nur deshalb gruen,
        // weil PortalCompleteness grosszuegig ist, nicht weil die Gruppe
        // korrekt herausgefiltert wurde.
        $this->assertArrayNotHasKey('Aufenthalt (Non-EU)', $employee->editableFieldGroups());

        $stand = $this->stand($employee);

        $this->assertSame(100, $stand['prozent'], 'fehlend: ' . implode(', ', $stand['fehlend']));
        $this->assertSame([], $stand['fehlend']);
        $this->assertGreaterThan(0, $stand['gesamt'], 'Der Ring darf nicht nur deshalb bei 100 % stehen, weil gar keine Felder gezaehlt wurden.');
    }

    public function test_gegenprobe_ein_fehlendes_feld_haengt_den_ring_unter_hundert(): void
    {
        // Beweist, dass der obige Test nicht zufaellig gruen ist: ein
        // einzelnes fehlendes Pflichtfeld (Schuhgroesse) muss den Ring
        // wirklich senken.
        $attrs = $this->vollstaendigerEuBuerger();
        unset($attrs['shoe_size']);
        $employee = RecEmployee::create($attrs);

        $stand = $this->stand($employee);

        $this->assertLessThan(100, $stand['prozent']);
        $this->assertContains('Schuhgroesse (Zahl)', $stand['fehlend']);
    }

    public function test_non_eu_felder_zaehlen_bei_einem_eu_buerger_gar_nicht_erst_mit(): void
    {
        // Der eigentliche Kern von Punkt 2: waeren residence_permit_valid_until
        // und work_permit_valid_until (Non-EU) Teil der Zaehlung, wuerde ein
        // EU-Buerger NIE 100 % erreichen koennen, weil er sie nie ausfuellen
        // kann (die Gruppe zeigt sich ihm gar nicht). Der obige Haupttest
        // beweist das indirekt (100 % ohne diese Felder); dieser Test macht
        // es direkt sichtbar.
        $employee = RecEmployee::create($this->vollstaendigerEuBuerger());

        $flach = PortalFieldAccess::sichtbareFelderFlach(
            $employee->editableFieldGroups(),
            [],
            [],
        );

        $this->assertArrayNotHasKey('residence_permit_valid_until', $flach);
        $this->assertArrayNotHasKey('work_permit_valid_until', $flach);
    }
}
