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
 * ja/nein).
 *
 * Fixrunde 1 (Coordinator-Befund, Important): ein einzelner Anekdoten-Test
 * fuer die eine bekannte Abweichung war keine Aussage — Task 4-7 bauen auf
 * PortalFieldAccess auf und koennten einen neuen Bedingungstyp einfuehren,
 * ohne dass ein abweichendes Verhalten auffaellt. Deshalb jetzt eine
 * Wahrheitstabelle (`test_wahrheitstabelle_visible_if_alt_gegen_neu`): sie
 * liest ALLE `visible_if`-Bedingungen aus `editableFieldGroups()` dynamisch
 * aus (nicht abgetippt) und vergleicht alt gegen neu ueber eine Matrix aus
 * Formularwert-Zustaenden x Datensatzwerten. Nur die eine dokumentierte
 * Zelle (leerer Formularwert bei widersprechendem Datensatz) wird
 * ausdruecklich als UNGLEICH behauptet, jede andere Zelle als GLEICH — faellt
 * die Abweichung weg oder kommt eine neue hinzu, wird der Test rot.
 *
 * Ausserdem (Befund 2, Minor): `sichtbareGruppen()`/`sichtbareFelderFlach()`
 * waren nur an einer synthetischen Zwei-Gruppen-Vorlage geprueft. Die
 * Reihenfolge-Tests unten vergleichen gegen die ECHTE Struktur aus
 * `RecEmployee::editableFieldGroups()` (Listenvergleich per assertSame auf
 * die Schluessel, nicht Mengenvergleich) — dazu der Fall, in dem eine ganze
 * Gruppe fehlt (Nicht-EU-Gruppe bei einem EU-Buerger).
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
     * Formularwert-Zustaende fuer die Wahrheitstabelle unten.
     *
     * @return list<array{0: string, 1: bool, 2: mixed}> [Beschriftung, Schluessel im Formular vorhanden, Formularwert]
     */
    private function formwertZustaende(): array
    {
        return [
            ['fehlt im Formular', false, null],
            ["Formularwert ''", true, ''],
            ["Formularwert '1'", true, '1'],
            ["Formularwert '0'", true, '0'],
            ["Formularwert 'irgendwas'", true, 'irgendwas'],
        ];
    }

    /**
     * WAHRHEITSTABELLE statt Anekdote (Fixrunde 1, Coordinator-Befund 1).
     *
     * Liest ALLE `visible_if`-Bedingungen dynamisch aus
     * `editableFieldGroups()` (heute genau eine: other_employer/
     * is_main_employer — morgen koennen es mehr sein, ohne dass dieser Test
     * angefasst werden muss) und haelt fuer JEDE Bedingung das alte,
     * unveraenderte `EmployeePortal::fieldIsVisible()` gegen
     * `PortalFieldAccess::istSichtbar()`, ueber die volle Matrix aus
     * Formularwert-Zustaenden (fehlt / '' / '1' / '0' / 'irgendwas') mal
     * Datensatzwerten (null / true / false).
     *
     * Behauptung: alt und neu liefern IMMER dasselbe — AUSSER in genau der
     * einen Zelle, in der der Formularwert '' ist UND der Datensatzwert die
     * Bedingung widerlegt (also von 'erwartet' abweicht und nicht null ist).
     * Genau dort behauptet der Test ausdruecklich UNGLEICH. Verschwindet die
     * Abweichung (jemand delegiert doch), wird der Test rot. Kommt ein neuer
     * Bedingungstyp mit abweichendem Verhalten hinzu, wird er ebenfalls rot.
     */
    public function test_wahrheitstabelle_visible_if_alt_gegen_neu(): void
    {
        $vorlage = $this->makeEmployee();
        $bedingungen = [];
        foreach ($vorlage->editableFieldGroups() as $gruppe => $felder) {
            foreach ($felder as $schluessel => $meta) {
                foreach (($meta['visible_if'] ?? []) as $andereFeld => $erwartet) {
                    $bedingungen[] = [$gruppe, $schluessel, $meta, $andereFeld, $erwartet];
                }
            }
        }
        // Schutz gegen ein still leerlaufendes Discovery: ohne Bedingungen
        // wuerde die Matrix unten klaglos nichts pruefen.
        $this->assertNotEmpty($bedingungen, 'Keine visible_if-Bedingung in editableFieldGroups() gefunden');

        $abweichendeZellenGefunden = 0;

        foreach ($bedingungen as [$gruppe, $schluessel, $meta, $andereFeld, $erwartet]) {
            foreach ([null, true, false] as $datensatzWert) {
                $employee = $this->makeEmployee([$andereFeld => $datensatzWert]);

                $portal = new EmployeePortal();
                $this->callPrivate($portal, 'loadFieldValues', [$employee]);
                $basisFieldValues = $portal->fieldValues;

                foreach ($this->formwertZustaende() as [$zustandLabel, $hatSchluessel, $formwert]) {
                    $fieldValues = $basisFieldValues;
                    if ($hatSchluessel) {
                        $fieldValues[$andereFeld] = $formwert;
                    } else {
                        unset($fieldValues[$andereFeld]);
                    }
                    $portal->fieldValues = $fieldValues;

                    $altesPortal = $this->callPrivate($portal, 'fieldIsVisible', [$employee, $meta]);
                    $neueKlasse = PortalFieldAccess::istSichtbar(
                        $meta,
                        [$andereFeld => $employee->getAttribute($andereFeld)],
                        $fieldValues,
                    );

                    // Die eine dokumentierte Abweichungszelle: Formularwert
                    // explizit '' UND Datensatz widerlegt die Bedingung
                    // (weicht von $erwartet ab, ist aber nicht unbeantwortet).
                    $istDokumentierteAbweichung = $hatSchluessel
                        && $formwert === ''
                        && $datensatzWert !== null
                        && $datensatzWert !== $erwartet;

                    $bezeichner = sprintf(
                        '%s.%s (visible_if %s=%s), Datensatz=%s, %s',
                        $gruppe,
                        $schluessel,
                        $andereFeld,
                        var_export($erwartet, true),
                        var_export($datensatzWert, true),
                        $zustandLabel,
                    );

                    if ($istDokumentierteAbweichung) {
                        $abweichendeZellenGefunden++;
                        $this->assertTrue($altesPortal, "{$bezeichner}: altes Portal soll sichtbar bleiben (unbeantwortet)");
                        $this->assertFalse($neueKlasse, "{$bezeichner}: neue Klasse soll auf den Datensatz zurueckfallen und verstecken");
                        $this->assertNotSame($altesPortal, $neueKlasse, "{$bezeichner}: dokumentierte Abweichungszelle — erwartungsgemaess UNGLEICH");
                    } else {
                        $this->assertSame($altesPortal, $neueKlasse, "{$bezeichner}: alt und neu weichen ab, obwohl das nicht die dokumentierte Zelle ist");
                    }
                }
            }
        }

        // Mindestens eine Abweichungszelle muss aufgetreten sein — sonst
        // waere die Formel fuer $istDokumentierteAbweichung selbst falsch
        // (sie wuerde nie greifen) und der Test bewiese nichts ueber die
        // bekannte Luecke.
        $this->assertGreaterThan(0, $abweichendeZellenGefunden, 'Die dokumentierte Abweichungszelle ist in der Matrix nicht aufgetaucht');
    }

    /**
     * Befund 2 (Fixrunde 1, Minor): Reihenfolge gegen die ECHTE Struktur aus
     * editableFieldGroups(), nicht gegen eine synthetische Vorlage.
     * Listenvergleich (assertSame auf array_keys), nicht Mengenvergleich —
     * Task 6/7 rendern in genau dieser Reihenfolge.
     *
     * "Ohne jede Einschraenkung": is_eu_citizen=true (keine Non-EU-Gruppe),
     * employment_type=null (keine Schul-/Immatrikulationsgruppe),
     * is_main_employer unbeantwortet (other_employer bleibt sichtbar) — kein
     * Feld und keine Gruppe wird durch PortalFieldAccess selbst gefiltert,
     * die Ausgabe muss 1:1 der Modellstruktur entsprechen.
     */
    public function test_sichtbare_gruppen_und_flache_liste_folgen_der_reihenfolge_des_modells(): void
    {
        $employee = $this->makeEmployee(['is_eu_citizen' => true, 'employment_type' => null]);
        $groups = $employee->editableFieldGroups();

        $portal = new EmployeePortal();
        $this->callPrivate($portal, 'loadFieldValues', [$employee]);

        $datensatz = [];
        foreach ($groups as $felder) {
            foreach ($felder as $meta) {
                foreach (array_keys($meta['visible_if'] ?? []) as $feld) {
                    $datensatz[$feld] = $employee->getAttribute($feld);
                }
            }
        }

        $sichtbar = PortalFieldAccess::sichtbareGruppen($groups, $datensatz, $portal->fieldValues);

        $this->assertSame(array_keys($groups), array_keys($sichtbar), 'Gruppenreihenfolge weicht vom Modell ab');
        foreach ($groups as $gruppe => $felder) {
            $this->assertArrayHasKey($gruppe, $sichtbar, "Gruppe {$gruppe} fehlt in sichtbareGruppen()");
            $this->assertSame(
                array_keys($felder),
                array_keys($sichtbar[$gruppe]),
                "Feldreihenfolge in {$gruppe} weicht vom Modell ab",
            );
        }

        $erwarteteFlacheReihenfolge = [];
        foreach ($groups as $felder) {
            foreach (array_keys($felder) as $schluessel) {
                $erwarteteFlacheReihenfolge[] = $schluessel;
            }
        }
        $flach = PortalFieldAccess::sichtbareFelderFlach($groups, $datensatz, $portal->fieldValues);
        $this->assertSame($erwarteteFlacheReihenfolge, array_keys($flach), 'Reihenfolge der flachen Liste weicht vom Modell ab');
    }

    /**
     * Befund 2: eine ganze Gruppe fehlt — die Nicht-EU-Gruppe bei einem
     * EU-Buerger. Sie fehlt bereits in editableFieldGroups() selbst (die
     * Gruppe wird dort nur bei is_eu_citizen===false hinzugefuegt); dieser
     * Test belegt, dass sichtbareGruppen() nichts daran aendert — keine
     * Gruppe faellt zusaetzlich weg, keine erscheint neu.
     */
    public function test_nicht_eu_gruppe_fehlt_bei_eu_buerger_in_modell_und_in_sichtbaren_gruppen(): void
    {
        $employee = $this->makeEmployee(['is_eu_citizen' => true]);
        $groups = $employee->editableFieldGroups();
        $this->assertArrayNotHasKey(
            'Aufenthalt (Non-EU)',
            $groups,
            'Testvoraussetzung: editableFieldGroups() zeigt die Non-EU-Gruppe nur bei is_eu_citizen=false',
        );

        $portal = new EmployeePortal();
        $this->callPrivate($portal, 'loadFieldValues', [$employee]);

        $sichtbar = PortalFieldAccess::sichtbareGruppen($groups, [], $portal->fieldValues);

        $this->assertArrayNotHasKey('Aufenthalt (Non-EU)', $sichtbar);
        $this->assertSame(array_keys($groups), array_keys($sichtbar), 'Keine Gruppe darf zusaetzlich verschwinden oder neu erscheinen');
    }

    /**
     * Gegenprobe zu oben: bei einem Non-EU-Buerger ist die Gruppe da — in
     * beiden, mit gleicher Reihenfolge.
     */
    public function test_nicht_eu_gruppe_bleibt_bei_non_eu_buerger_in_beiden_erhalten(): void
    {
        $employee = $this->makeEmployee(['is_eu_citizen' => false]);
        $groups = $employee->editableFieldGroups();
        $this->assertArrayHasKey('Aufenthalt (Non-EU)', $groups);

        $portal = new EmployeePortal();
        $this->callPrivate($portal, 'loadFieldValues', [$employee]);

        $sichtbar = PortalFieldAccess::sichtbareGruppen($groups, [], $portal->fieldValues);

        $this->assertArrayHasKey('Aufenthalt (Non-EU)', $sichtbar);
        $this->assertSame(array_keys($groups), array_keys($sichtbar));
    }
}
