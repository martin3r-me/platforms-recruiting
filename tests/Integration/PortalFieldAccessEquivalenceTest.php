<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PortalFieldAccess;
use Platform\Recruiting\Support\PortalFieldRelevance;

/**
 * Task 2 (Portal-Gleichstand) — Gleichstandsbeleg fuer die geloesten Regeln.
 *
 * `RecEmployee::fieldIsRelevant()` DELEGIERT inzwischen an
 * `PortalFieldRelevance::istRelevant()` — hier ist der Vergleich also ein
 * Regressionsnetz (wenn jemand die Delegation kaputt macht, faellt es hier
 * auf).
 *
 * `EmployeePortal::fieldIsVisible()` delegiert BEWUSST NICHT (Kommentar an
 * der Methode, `EmployeePortal.php:624ff`): PortalFieldAccess behandelt
 * einen expliziten Leerstring im Formularwert wie "kein Formularwert" und
 * faellt auf den Datensatz zurueck, das alte Portal nicht. Fuer den
 * NORMALFALL (Formularwerte frisch aus loadFieldValues(), also synchron zum
 * Datensatz, oder ein echt getippter Wert) verhalten sich beide Wege
 * trotzdem GLEICH — das ist hier belegt, ueber mehrere Mitarbeiter-
 * Auspraegungen (EU / Nicht-EU / unbekannt, Schueler, Student, Ersthelfer
 * ja/nein). Der eine bekannte Abweichungsfall (Formular explizit auf ''
 * zurueckgesetzt, Datensatz noch mit altem Wert) ist als eigener Test
 * dokumentiert, nicht Teil der Gleichstandsbehauptung.
 */
final class PortalFieldAccessEquivalenceTest extends TestCase
{
    private const TEAM = 913;

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
            // employment_type (Schueler/Student-Weiche)
            'database/migrations/2026_05_21_000001_add_full_field_set_to_rec_employees.php',
            // is_first_aider
            'database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php',
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

    private function makeEmployee(array $attributes = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'      => self::TEAM,
            'first_name'   => 'Erika',
            'last_name'    => 'Muster',
            'portal_token' => 'tok-equiv-' . uniqid(),
            'is_active'    => true,
        ], $attributes));
    }

    /** Ruft eine private Methode via Reflection auf — Testzugriff, kein API-Aenderungswunsch. */
    private function callPrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($obj, $args);
    }

    /** @return array<int, array{0: string, 1: array<string,mixed>}> */
    public static function profileProvider(): array
    {
        return [
            'EU, sonstige Beschaeftigung, Ersthelfer ja, Hauptarbeitgeber ja' => ['eu_sonstige_ersthelfer_ja', [
                'is_eu_citizen' => true, 'employment_type' => 'minijob',
                'is_first_aider' => true, 'is_main_employer' => true,
            ]],
            'Non-EU, Minijob, Ersthelfer nein, Hauptarbeitgeber nein+Angabe' => ['noneu_ersthelfer_nein', [
                'is_eu_citizen' => false, 'employment_type' => 'minijob',
                'is_first_aider' => false, 'is_main_employer' => false, 'other_employer' => 'Musterkantine GmbH',
            ]],
            'Unbekannte Staatsangehoerigkeit, Schueler, Ersthelfer unbeantwortet' => ['unbekannt_schueler', [
                'is_eu_citizen' => null, 'employment_type' => 'schueler',
                'is_first_aider' => null, 'is_main_employer' => null,
            ]],
            'EU, Student, Ersthelfer ja, Hauptarbeitgeber unbeantwortet' => ['eu_student_ersthelfer_ja', [
                'is_eu_citizen' => true, 'employment_type' => 'student',
                'is_first_aider' => true, 'is_main_employer' => null,
            ]],
            'Non-EU, Student erwerbstaetig, Ersthelfer nein, Hauptarbeitgeber ja' => ['noneu_student_erwerbstaetig', [
                'is_eu_citizen' => false, 'employment_type' => 'student_erwerbstaetig',
                'is_first_aider' => false, 'is_main_employer' => true,
            ]],
            'Unbekannt, kein Beschaeftigungstyp, Ersthelfer ja, Hauptarbeitgeber nein+Angabe' => ['unbekannt_leer', [
                'is_eu_citizen' => null, 'employment_type' => null,
                'is_first_aider' => true, 'is_main_employer' => false, 'other_employer' => 'Nachbarbetrieb OHG',
            ]],
        ];
    }

    /**
     * required_if (R28): das Modell (delegiert an PortalFieldRelevance)
     * gegen die reine Klasse — direkt mit denselben gecasteten Attributen.
     */
    #[DataProvider('profileProvider')]
    public function test_required_if_stimmt_ueberein(string $label, array $attrs): void
    {
        $employee = $this->makeEmployee($attrs);
        $groups = $employee->editableFieldGroups();

        foreach ($groups as $gruppe => $felder) {
            foreach ($felder as $schluessel => $meta) {
                if (!isset($meta['required_if'])) {
                    continue;
                }
                $datensatz = [];
                foreach (array_keys($meta['required_if']) as $feld) {
                    $datensatz[$feld] = $employee->getAttribute($feld);
                }

                $altesModell = $employee->fieldIsRelevant($meta);
                $neueKlasse = PortalFieldRelevance::istRelevant($meta, $datensatz);

                $this->assertSame(
                    $altesModell,
                    $neueKlasse,
                    "{$label} / {$gruppe}.{$schluessel}: Modell und PortalFieldRelevance weichen ab",
                );
            }
        }
    }

    /**
     * visible_if (R27): das alte, NICHT delegierte EmployeePortal::fieldIsVisible()
     * gegen PortalFieldAccess::istSichtbar() — mit Formularwerten frisch aus
     * loadFieldValues() (also synchron zum Datensatz, der Normalfall bei
     * jedem Seitenaufruf und nach jedem Speichern).
     */
    #[DataProvider('profileProvider')]
    public function test_visible_if_stimmt_ueberein_bei_synchronen_formularwerten(string $label, array $attrs): void
    {
        $employee = $this->makeEmployee($attrs);

        $portal = new EmployeePortal();
        $this->callPrivate($portal, 'loadFieldValues', [$employee]);

        $groups = $employee->editableFieldGroups();
        foreach ($groups as $gruppe => $felder) {
            foreach ($felder as $schluessel => $meta) {
                if (!isset($meta['visible_if'])) {
                    continue;
                }
                $datensatz = [];
                foreach (array_keys($meta['visible_if']) as $feld) {
                    $datensatz[$feld] = $employee->getAttribute($feld);
                }

                $altesPortal = $this->callPrivate($portal, 'fieldIsVisible', [$employee, $meta]);
                $neueKlasse = PortalFieldAccess::istSichtbar($meta, $datensatz, $portal->fieldValues);

                $this->assertSame(
                    $altesPortal,
                    $neueKlasse,
                    "{$label} / {$gruppe}.{$schluessel}: EmployeePortal::fieldIsVisible() und PortalFieldAccess weichen ab",
                );
            }
        }
    }

    /**
     * Gleicher Vergleich wie oben, aber mit einem echt GETIPPTEN Formularwert
     * (nicht leer), der dem Datensatz widerspricht — der Fall, den E16 loesen
     * sollte: die Auswahl wirkt sofort, bevor gespeichert wurde.
     */
    public function test_visible_if_stimmt_ueberein_wenn_formular_dem_datensatz_widerspricht(): void
    {
        // Datensatz sagt "ja, wir sind Hauptarbeitgeber" (other_employer also
        // im Datensatz unsichtbar) — das Formular sagt gerade live "nein".
        $employee = $this->makeEmployee(['is_main_employer' => true]);

        $portal = new EmployeePortal();
        $this->callPrivate($portal, 'loadFieldValues', [$employee]);
        $portal->fieldValues['is_main_employer'] = '0';

        $meta = $employee->editableFieldGroups()['Arbeitgeber']['other_employer'];
        $datensatz = ['is_main_employer' => $employee->getAttribute('is_main_employer')];

        $altesPortal = $this->callPrivate($portal, 'fieldIsVisible', [$employee, $meta]);
        $neueKlasse = PortalFieldAccess::istSichtbar($meta, $datensatz, $portal->fieldValues);

        $this->assertTrue($altesPortal, 'Altes Portal: Formularwert sticht, Feld muss sichtbar werden');
        $this->assertSame($altesPortal, $neueKlasse);
    }

    /**
     * DOKUMENTIERTE ABWEICHUNG — bewusst KEINE Gleichstandsbehauptung.
     *
     * Setzt der User das 'live'-Feld is_main_employer im Formular explizit
     * auf die leere Option zurueck (moeglich, siehe Blade: eine Option mit
     * value="" existiert), waehrend der Datensatz noch den alten,
     * ungespeicherten Wert traegt, unterscheiden sich beide Wege:
     *  - altes Portal: array_key_exists() OHNE Leer-Pruefung → Formularwert
     *    '' gilt als "gesetzt", PortalBoolValue::parse('') ist null →
     *    Bedingung nicht widerlegt → sichtbar.
     *  - PortalFieldAccess: leerer Formularwert zaehlt als "keine Aussage" →
     *    faellt auf den (noch alten) Datensatz zurueck → kann verstecken.
     *
     * Deshalb bleibt EmployeePortal::fieldIsVisible() unveraendert (siehe
     * Kommentar dort) statt an PortalFieldAccess zu delegieren. Dieser Test
     * haelt die Abweichung fest, damit sie nicht versehentlich verschwindet
     * (waere ein Zeichen, dass doch delegiert wurde) oder sich unbemerkt
     * vergroessert.
     */
    public function test_dokumentierte_abweichung_leerer_formularwert_nach_live_reset(): void
    {
        $employee = $this->makeEmployee(['is_main_employer' => true]);

        $portal = new EmployeePortal();
        $this->callPrivate($portal, 'loadFieldValues', [$employee]);
        // User waehlt im Live-Dropdown die leere Option erneut.
        $portal->fieldValues['is_main_employer'] = '';

        $meta = $employee->editableFieldGroups()['Arbeitgeber']['other_employer'];
        $datensatz = ['is_main_employer' => $employee->getAttribute('is_main_employer')];

        $altesPortal = $this->callPrivate($portal, 'fieldIsVisible', [$employee, $meta]);
        $neueKlasse = PortalFieldAccess::istSichtbar($meta, $datensatz, $portal->fieldValues);

        $this->assertTrue($altesPortal, 'Altes, unveraendertes Portal: bleibt sichtbar (unbeantwortet)');
        $this->assertFalse($neueKlasse, 'Neue Klasse faellt auf den Datensatz zurueck und versteckt das Feld');
        $this->assertNotSame($altesPortal, $neueKlasse, 'Die Abweichung ist der Punkt dieses Tests');
    }
}
