<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Observers\RecEmployeePhoneSyncObserver;
use Platform\Recruiting\Services\PortalProfileWriter;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;
use Platform\Recruiting\Support\PortalFieldAccess;
use Platform\Recruiting\Support\PortalProfileGuards;
use Platform\Recruiting\Support\PortalSectionHints;
use Platform\Recruiting\Support\ProofTypes;

/**
 * DIE ABNAHME: kann das neue Portal alles, was das alte kann?
 *
 * Abgenommen wird gegen
 * docs/superpowers/specs/2026-09-25-portal-bestandsaufnahme.md — 47 Felder in
 * 13 Gruppen, 31 Regeln (R1-R31), 11 Nebenwirkungen (N1-N11), 18 Eigenheiten
 * aus behobenen Fehlern (E1-E18).
 *
 * WAS DIESER TEST BEWUSST NICHT TUT: eine abgetippte Liste gegen eine andere
 * abgetippte Liste halten. Genau das ist in diesem Vorhaben schon zweimal
 * schiefgegangen (die Doppelliste der Datei-Felder, E7, und die zweite
 * BeschErforderlich-Liste im ZAS-Export). Quelle der Wahrheit ist hier
 * ausschliesslich der CODE:
 *
 *   - die Feldliste kommt aus RecEmployee::editableFieldGroups(),
 *   - die Erreichbarkeit im neuen Portal aus PortalShell::oeffneGruppe() und
 *     PortalShell::profilDaten() — also aus dem, was die Ansicht bekommt,
 *   - die Datei-Zuordnung aus ProofTypes::codeForLegacyColumn(),
 *   - die Nebenwirkungen aus den ECHTEN Beobachtern an einem ECHTEN
 *     Speichervorgang durch PortalShell::speichereGruppe(),
 *   - die Marker-Verbote aus RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS
 *     und RecApplicantSettings::DEFAULT_SETTINGS.
 *
 * Die Zahlen der Bestandsaufnahme (47 Felder, 13 Gruppen, 42 mit ZAS-Marker,
 * 5 mit Marker-Verbot, 13 lohnrelevant, 8 Datei-Felder) stehen als BEHAUPTUNG
 * in den Zusicherungen — gerechnet werden sie jedes Mal neu aus dem Code. Wer
 * ein Feld herausnimmt, macht diesen Test rot, ohne die Spec zu kennen.
 *
 * ---------------------------------------------------------------------------
 * ZUORDNUNG Bestandsaufnahme -> Test
 * ---------------------------------------------------------------------------
 *   §1.1 (47 Felder, 13 Gruppen)  test_die_feldliste_der_bestandsaufnahme_kommt_aus_dem_code
 *   §1.1 + §1.4.2, E7             test_jedes_einzelne_feld_ist_im_neuen_portal_erreichbar
 *   §1.1 Gruppen-Mechanismus      test_jede_gruppe_laesst_sich_einzeln_oeffnen
 *   §1.2                          test_die_zwei_nur_lese_felder_werden_gezeigt
 *   §1.3, R19                     test_die_ausgeschlossenen_felder_bleiben_ausgeschlossen
 *   §1.4 Punkt 1                  test_jeder_feldtyp_hat_im_blatt_einen_zweig
 *   §1.4 Punkt 2, E7              test_es_gibt_keine_zweite_datei_liste
 *   §1.4 Punkt 3, E15             test_die_zwei_erklaertexte_stehen_im_neuen_portal
 *   §2.1, R15-R18, E11            test_die_drei_waechter_greifen_im_neuen_portal
 *   §2.1 Reihenfolge              test_die_reihenfolge_der_kaskade_ist_bindend
 *   R27, R29, R30                 test_sichtbarkeit_nach_eu_status_und_beschaeftigung
 *   R20/E8                        test_datei_felder_werden_ueber_das_nachweis_blatt_gesetzt
 *   R31, N7                       test_nebenwirkung_leerraum_wird_entfernt
 *   N1, §3.2                      test_nebenwirkung_zas_marker_und_die_fuenf_verbote
 *   N2, §3.3                      test_nebenwirkung_lohn_trigger
 *   N5                            test_nebenwirkung_telefonabgleich
 *   E3                            test_kein_erzwingendes_zahlentastatur_attribut
 *   N9, N10                       test_beide_portale_teilen_sitzung_und_sperre
 *   E18/R2                        test_das_alte_portal_leitet_weiter_solange_es_steht
 *
 * Belegt in eigenen, bereits bestehenden Tests (hier nicht doppelt gemessen,
 * damit dieser Test nicht zur zweiten Liste wird):
 *   R1, R3-R14, §6   PortalAuthTest, PortalShellVerifyTest, PortalTokenRouteTest
 *   R19-R23, N1/N2/N5/N7 im Detail   PortalProfileWriterTest
 *   R24-R26          PortalShellUploadTest
 *   R28              PortalFieldRelevanceTest, PortalCompletenessEuBuergerTest
 *   R27 alt/neu      PortalFieldAccessEquivalenceTest (Wahrheitstabelle)
 *   E11              PortalProfileGuardsTest
 *   E17, N8          PortalShellDokumenteTest, PortalCertificateBadgeTest
 *   E18              EmployeePortalV2RedirectTest
 *
 * ---------------------------------------------------------------------------
 * DIE BEWUSSTEN ABWEICHUNGEN — benannt, nicht verschwiegen
 * ---------------------------------------------------------------------------
 * Jede steht unten als eigener Test mit Begruendung. Zusammengefasst:
 *
 *  A1 REICHWEITE DER WAECHTER. Beim gruppenweisen Speichern blockt ein
 *     Waechter nur noch, wenn mindestens eines SEINER Felder zur offenen
 *     Gruppe gehoert (ohne Gruppe weiterhin alle drei). Sonst fror ein
 *     Doppel-Null-Fall das ganze Profil ein: Adresse ging nicht, weil der
 *     Hauptarbeitgeber fehlte, und Arbeitgeber ging nicht, weil die
 *     Staatsangehoerigkeit fehlte — nicht einmal die Schuhgroesse liess sich
 *     noch speichern.
 *     -> test_abweichung_1_waechter_blocken_nur_ihre_reichweite
 *
 *  A2 LEERER FORMULARWERT IN DER SICHTBARKEITSREGEL. PortalFieldAccess liest
 *     einen leeren Formularwert als "keine Aussage" und faellt auf den
 *     Datensatz zurueck; EmployeePortal::fieldIsVisible() nimmt ihn woertlich.
 *     Kann kein gespeichertes Ergebnis aendern (gespeichert wird ueber den
 *     Wert, nicht ueber die Sichtbarkeit) — nur die Anzeige waehrend des
 *     Tippens.
 *     -> test_abweichung_2_leerer_formularwert_faellt_auf_den_datensatz_zurueck
 *
 *  A3 NICHT-EU-DOKUMENTE. Das neue Portal kann sie hochladen, das alte nicht
 *     (dessen Non-EU-Gruppe enthaelt nur zwei Datumsfelder). ZUGEWINN, keine
 *     Luecke.
 *     -> test_abweichung_3_nicht_eu_dokumente_sind_ein_zugewinn
 *
 *  A4 ELF PUNKTE aus dem Plan ("Was dieser Plan NICHT abdeckt"), soweit
 *     pruefbar, in test_abweichung_4_die_nicht_abgedeckten_punkte_des_plans.
 *     Vollstaendig, mit Begruendung, im Docblock jenes Tests.
 *
 * ---------------------------------------------------------------------------
 * PROZESSWEITER ZUSTAND: diese Klasse bootet Eloquent MIT Dispatcher und
 * registriert die Beobachter — ohne sie waere jede Aussage ueber N1/N2/N5
 * gruen, ohne etwas geprueft zu haben. Aufgeraeumt wird in
 * tearDownAfterClass (Muster: PortalProfileWriterTest).
 */
final class PortalGleichstandTest extends TestCase
{
    private const TEAM = 9109;

    /** Attrappe des CRM-Abgleichs — die echte braucht CRM-Tabellen. */
    private static object $telefonAbgleich;

    // -----------------------------------------------------------------
    // Pruefstand
    // -----------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        // \Log:: im Beobachter nutzt den globalen Alias, den es ausserhalb
        // einer gebooteten Laravel-App nicht gibt.
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }

        $container = Container::getInstance();
        Container::setInstance($container);

        $container->instance('config', new ConfigRepository([]));
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $dispatcher = new Dispatcher($container);
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $container->instance('events', $dispatcher);
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        $container->instance('cache', new CacheRepository(new ArrayStore()));
        $container->instance('session', new Store('test', new ArraySessionHandler(60)));
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Model::clearBootedModels();

        self::migrationen();

        self::$telefonAbgleich = new class extends ContactPhoneSync {
            /** @var list<int> */
            public array $gerufenFuer = [];

            public function syncEmployee(RecEmployee $employee, bool $dryRun = false): array
            {
                $this->gerufenFuer[] = (int) $employee->id;

                return ['status' => 'synced', 'contacts' => 1];
            }
        };
        $container->instance(ContactPhoneSync::class, self::$telefonAbgleich);

        RecEmployeeExportObserver::register();
        RecEmployee::observe(RecEmployeePhoneSyncObserver::class);
    }

    private static function migrationen(): void
    {
        $eigen = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000001_add_full_field_set_to_rec_employees.php',
            'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php',
            'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_05_21_000007_add_fiktion_file_ids_to_rec_employees.php',
            'database/migrations/2026_05_21_000010_add_schulbescheinigung_file_id_to_rec_employees.php',
            'database/migrations/2026_05_22_000001_add_personnel_number_to_rec_employees.php',
            'database/migrations/2026_06_05_000001_add_payroll_tracking_to_rec_employees.php',
            'database/migrations/2026_07_07_000001_add_cost_center_to_rec_employees.php',
            'database/migrations/2026_07_17_000001_add_arbeitsschutz_fields_to_rec_employees.php',
            'database/migrations/2026_08_06_000001_add_erstbescheinigung_file_id_to_rec_employees.php',
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
            'database/migrations/2026_09_01_000001_add_first_aider_certificate_file_id_to_rec_employees.php',
            'database/migrations/2026_09_23_000001_add_nationality_to_rec_employees.php',
            'database/migrations/2026_09_23_000002_add_employer_fields_to_rec_employees.php',
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
        ] as $datei) {
            (require $eigen . '/' . $datei)->up();
        }

        // Ohne diese Tabelle liefe das Lohn-Tracking in seinen stillen
        // Fehlerzweig — der Test pruefte den Trigger, ohne ihn gelaufen zu
        // sein.
        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Model::unsetEventDispatcher();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('config');
        $container->forgetInstance('log');
        $container->forgetInstance('cache');
        $container->forgetInstance('session');
        $container->forgetInstance(ContactPhoneSync::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_applicant_settings')->delete();
        self::$telefonAbgleich->gerufenFuer = [];
    }

    // -----------------------------------------------------------------
    // Hilfen
    // -----------------------------------------------------------------

    /**
     * Ein umgestellter, angemeldefaehiger Mitarbeiter. portal_v2_since ist
     * gesetzt, sonst laesst PortalShell::berechtigterMitarbeiter() niemanden
     * durch (§6).
     */
    private function mitarbeiter(array $attr = []): RecEmployee
    {
        $ma = RecEmployee::create(array_merge([
            'uuid'            => 'u-' . bin2hex(random_bytes(6)),
            'team_id'         => self::TEAM,
            'first_name'      => 'Kevin',
            'last_name'       => 'Muster',
            'portal_token'    => 'tok-' . uniqid('', true),
            'is_active'       => true,
            'portal_v2_since' => '2026-09-24 08:00:00',
        ], $attr));

        // Den Marker des ANLEGENS misst hier nie jemand — gemessen wird
        // ausschliesslich, was das Speichern im Portal ausloest.
        Capsule::table('rec_employees')->where('id', $ma->id)->update([
            'zas_changed_at'              => null,
            'payroll_data_changed_at'     => null,
            'payroll_data_changed_fields' => null,
        ]);

        return $ma->fresh();
    }

    private function shell(RecEmployee $ma, bool $angemeldet = true): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = $angemeldet ? 'verified' : 'unverified';
        $shell->duzen = true;

        return $shell;
    }

    /** Was die Ansicht bekommt — private Methode, Testzugriff per Reflection. */
    private function profil(PortalShell $shell, RecEmployee $ma): array
    {
        $ref = new \ReflectionMethod($shell, 'profilDaten');
        $ref->setAccessible(true);

        return $ref->invoke($shell, $ma);
    }

    private function privat(object $obj, string $methode, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $methode);
        $ref->setAccessible(true);

        return $ref->invoke($obj, ...$args);
    }

    private function frisch(RecEmployee $ma): object
    {
        return Capsule::table('rec_employees')->where('id', $ma->id)->first();
    }

    private function blade(): string
    {
        $pfad = dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php';
        $this->assertFileExists($pfad);

        return (string) file_get_contents($pfad);
    }

    /**
     * Die Blade ohne ihre {{-- --}}-Kommentare — fuer Zusicherungen, die
     * etwas AUSSCHLIESSEN. Diese Blade erklaert an mehreren Stellen in Prosa,
     * warum sie etwas NICHT mehr tut; ein Treffer in so einer Erklaerung waere
     * ein falscher Befund.
     */
    private function bladeOhneKommentare(): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $this->blade());
    }

    private function quelle(string $klasse): string
    {
        $datei = (new \ReflectionClass($klasse))->getFileName();
        $this->assertIsString($datei);

        return (string) file_get_contents($datei);
    }

    /**
     * Die ZWEI Auspraegungen, die zusammen JEDE Gruppe und JEDES Feld sichtbar
     * machen — nicht geraten, sondern aus den drei Weichen der Feldliste
     * abgeleitet:
     *   - is_eu_citizen === false  schaltet "Aufenthalt (Non-EU)" frei (R30),
     *   - employment_type          entscheidet zwischen Schulbescheinigung
     *                              und Immatrikulation (R29) — deshalb zwei
     *                              Datensaetze statt einem,
     *   - is_main_employer !== true haelt other_employer sichtbar (R27/E16).
     *
     * @return array<string, RecEmployee>
     */
    private function beidePersonen(): array
    {
        return [
            'Schueler (Nicht-EU)' => $this->mitarbeiter([
                'is_eu_citizen'    => false,
                'employment_type'  => 'schueler',
                'is_main_employer' => null,
            ]),
            'Student (Nicht-EU)' => $this->mitarbeiter([
                'is_eu_citizen'    => false,
                'employment_type'  => 'student',
                'is_main_employer' => null,
            ]),
        ];
    }

    /**
     * Alle editierbaren Felder des ALTEN Portals, zusammengesetzt aus beiden
     * Auspraegungen. Quelle: RecEmployee::editableFieldGroups().
     *
     * @return array<string, array<string,mixed>>
     */
    private function alleFelder(): array
    {
        $felder = [];
        foreach ($this->beidePersonen() as $ma) {
            foreach ($ma->editableFieldsFlat() as $schluessel => $meta) {
                $felder[$schluessel] = $meta;
            }
        }

        return $felder;
    }

    /** @return list<string> */
    private function alleGruppen(): array
    {
        $gruppen = [];
        foreach ($this->beidePersonen() as $ma) {
            foreach (array_keys($ma->editableFieldGroups()) as $name) {
                $gruppen[$name] = true;
            }
        }

        return array_keys($gruppen);
    }

    // =================================================================
    // §1.1 — die Liste selbst
    // =================================================================

    /**
     * §1.1: 47 editierbare Feld-Schluessel in 13 Gruppen. Gerechnet, nicht
     * abgetippt — wer ein Feld entfernt, macht diese Zeile rot.
     */
    public function test_die_feldliste_der_bestandsaufnahme_kommt_aus_dem_code(): void
    {
        $felder = $this->alleFelder();
        $gruppen = $this->alleGruppen();

        $this->assertCount(
            47,
            $felder,
            "Die Bestandsaufnahme §1.1 nennt 47 editierbare Felder, der Code liefert "
            . count($felder) . ': ' . implode(', ', array_keys($felder)),
        );
        $this->assertCount(
            13,
            $gruppen,
            'Die Bestandsaufnahme §1.1 nennt 13 Gruppen, der Code liefert '
            . count($gruppen) . ': ' . implode(' | ', $gruppen),
        );

        // §1.1 nennt genau acht Datei-Felder (E7: "Heute sind es 8").
        $dateiFelder = array_keys(array_filter(
            $felder,
            static fn (array $meta) => ($meta['type'] ?? 'text') === 'file',
        ));
        $this->assertCount(8, $dateiFelder, 'Datei-Felder: ' . implode(', ', $dateiFelder));
    }

    // =================================================================
    // §1.1 — Erreichbarkeit im NEUEN Portal, Feld fuer Feld
    // =================================================================

    /**
     * DER KERN DIESER ABNAHME.
     *
     * Fuer jedes der 47 Felder wird EINZELN belegt, dass es im neuen Portal
     * einen Weg hat — und zwar den echten: die Gruppe wird ueber
     * PortalShell::oeffneGruppe() geoeffnet, und geprueft wird, was
     * PortalShell::profilDaten() der Ansicht uebergibt.
     *
     * Zwei zulaessige Wege, mehr nicht:
     *   1. Formularfeld im Gruppen-Blatt ($profilFelder) — fuer alles ausser
     *      Dateien,
     *   2. Nachweis-Kachel ($kacheln, aufgeloest ueber
     *      ProofTypes::codeForLegacyColumn) — fuer die acht Datei-Felder.
     *      Dateien stehen bewusst NICHT im Blatt (R20/E8: ein manipulierter
     *      POST duerfte nie eine File-Id setzen) und auch nicht in der
     *      Gruppenzeile (I1: die Ausweis-Zeile lautete sonst
     *      "01.01.2032 · 5002 · 5003 · 5004").
     *
     * Eine Kachel ohne Ziel waere genau der Fehler vom 06.08. (E7): das Feld
     * wird als fehlend gemeldet, aber es gibt keinen Knopf, mit dem man die
     * Forderung erfuellen kann.
     */
    public function test_jedes_einzelne_feld_ist_im_neuen_portal_erreichbar(): void
    {
        $erwartet = array_keys($this->alleFelder());
        $erreicht = [];

        foreach ($this->beidePersonen() as $label => $ma) {
            $shell = $this->shell($ma);

            // Die Kacheln haengen nicht an der offenen Gruppe — einmal lesen
            // genuegt.
            $kachelCodes = array_column($this->profil($shell, $ma)['kacheln'], 'code');

            foreach ($ma->editableFieldGroups() as $gruppe => $felder) {
                $shell->oeffneGruppe($gruppe);
                $this->assertSame(
                    $gruppe,
                    $shell->profilGruppe,
                    "{$label}: die Gruppe „{$gruppe}“ laesst sich nicht oeffnen",
                );

                $imBlatt = array_keys($this->profil($shell, $ma)['felder']);

                foreach ($felder as $schluessel => $meta) {
                    if (($meta['type'] ?? 'text') === 'file') {
                        $code = ProofTypes::codeForLegacyColumn($schluessel);
                        $this->assertNotNull(
                            $code,
                            "{$label}: Datei-Feld {$schluessel} hat keine Nachweisart — "
                            . 'es bekaeme eine Kachel ohne Ziel (E7)',
                        );
                        $this->assertContains(
                            $code,
                            $kachelCodes,
                            "{$label}: Datei-Feld {$schluessel} ({$code}) hat im neuen Portal keine Kachel",
                        );
                        $erreicht[$schluessel] = true;
                        continue;
                    }

                    $this->assertContains(
                        $schluessel,
                        $imBlatt,
                        "{$label}: Feld {$schluessel} steht nicht im Blatt der Gruppe „{$gruppe}“",
                    );
                    $this->assertNotSame(
                        '',
                        (string) ($meta['label'] ?? ''),
                        "{$label}: Feld {$schluessel} hat keine Beschriftung",
                    );
                    $erreicht[$schluessel] = true;
                }
            }
        }

        $fehlend = array_diff($erwartet, array_keys($erreicht));
        $this->assertSame(
            [],
            array_values($fehlend),
            'Diese Felder des alten Portals sind im neuen NICHT erreichbar: ' . implode(', ', $fehlend),
        );
        $this->assertCount(47, $erreicht, 'Erreicht: ' . count($erreicht) . ' von 47');
    }

    /**
     * Der Gruppen-Mechanismus selbst, ohne Umweg ueber die Felder: JEDE der 13
     * Gruppen muss sich ueber ihren Namen oeffnen lassen — auch
     * „Steuer & Versicherung“ mit dem kaufmaennischen Und und
     * „Schul-/Immatrikulationsbescheinigung“ mit Schraegstrich.
     *
     * Ein unbekannter Gruppenname laeuft im neuen Portal still ins Leere; er
     * wuerde beim Speichern als Fehler gemeldet, nicht als "Keine
     * Aenderungen." — deshalb wird hier das Oeffnen gemessen, nicht geraten.
     */
    public function test_jede_gruppe_laesst_sich_einzeln_oeffnen(): void
    {
        foreach ($this->beidePersonen() as $label => $ma) {
            $shell = $this->shell($ma);
            foreach (array_keys($ma->editableFieldGroups()) as $gruppe) {
                $shell->schliesseGruppe();
                $shell->oeffneGruppe($gruppe);

                $this->assertSame($gruppe, $shell->profilGruppe, "{$label}: „{$gruppe}“ bleibt zu");
                $this->assertNotSame(
                    [],
                    $shell->profilWerte,
                    "{$label}: „{$gruppe}“ oeffnet sich, aber ohne ein einziges Formularfeld — "
                    . 'eine reine Datei-Gruppe gibt es in der Feldliste nicht',
                );
            }
        }
    }

    // =================================================================
    // §1.2 / §1.3
    // =================================================================

    /** §1.2: die zwei Nur-Lese-Felder erreichen die Ansicht des neuen Portals. */
    public function test_die_zwei_nur_lese_felder_werden_gezeigt(): void
    {
        $ma = $this->mitarbeiter([
            'identity_card_number'          => 'L01X00T47',
            'recruited_by_personnel_number' => 'MA123',
        ]);

        $this->assertSame(
            ['identity_card_number', 'recruited_by_personnel_number'],
            array_keys($ma->readOnlyDisplayFields()),
        );

        $nurLesen = $this->profil($this->shell($ma), $ma)['nurLesen'];
        $this->assertSame(
            array_keys($ma->readOnlyDisplayFields()),
            array_keys($nurLesen),
            'Das neue Portal reicht die Nur-Lese-Felder nicht an die Ansicht durch',
        );
        $this->assertStringContainsString('$nurLesen', $this->blade());
    }

    /**
     * §1.3: die ausgeschlossenen Felder bleiben ausgeschlossen — und das
     * nicht nur in der Liste, sondern auch im Schreibweg (R19). first_name,
     * birth_date und identity_card_number sind Login-Faktoren; eine Aenderung
     * wuerde den Menschen aus seinem eigenen Portal aussperren.
     */
    public function test_die_ausgeschlossenen_felder_bleiben_ausgeschlossen(): void
    {
        $ausgeschlossen = [
            'first_name', 'last_name', 'birth_date', 'identity_card_number',
            'is_eu_citizen', 'recruited_by_personnel_number', 'personnel_number',
        ];

        $flach = $this->alleFelder();
        foreach ($ausgeschlossen as $feld) {
            $this->assertArrayNotHasKey($feld, $flach, "{$feld} ist editierbar geworden");
        }

        // Und der Schreibweg laesst sie auch nicht durch die Hintertuer
        // hinein: ein manipulierter POST schickt sie einfach mit.
        $ma = $this->mitarbeiter([
            'first_name'           => 'Kevin',
            'birth_date'           => '1995-03-14',
            'identity_card_number' => 'L01X00T47',
            'nationality'          => 'deutsch',
            'is_main_employer'     => true,
        ]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Kontakt');
        foreach ($ausgeschlossen as $feld) {
            $shell->profilWerte[$feld] = 'manipuliert';
        }
        $shell->profilWerte['email'] = 'neu@example.test';
        $shell->speichereGruppe();

        $nachher = $this->frisch($ma);
        $this->assertSame('neu@example.test', $nachher->email, 'Der Speichervorgang lief gar nicht');
        $this->assertSame('Kevin', $nachher->first_name);
        $this->assertSame('L01X00T47', $nachher->identity_card_number);
        $this->assertStringStartsWith('1995-03-14', (string) $nachher->birth_date);
    }

    // =================================================================
    // §1.4 — wo das alte Blade eigen war
    // =================================================================

    /**
     * §1.4 Punkt 1: `inline_select` ist ein fuenfter Typ, den der Docblock der
     * Feldliste nicht kennt (tax_class 1..6, shirt_size S..XL).
     *
     * Geprueft wird nicht "kommt inline_select im Blade vor", sondern die
     * VOLLSTAENDIGKEIT: jeder Typ, den die Feldliste tatsaechlich benutzt,
     * braucht im Gruppen-Blatt einen Zweig. Kommt morgen ein sechster dazu,
     * faellt das hier auf, statt als leeres Feld im Portal zu landen.
     *
     * Zwei Sonderfaelle, beide bewusst:
     *   - `file` hat keinen Zweig, weil Datei-Felder gar nicht erst ins Blatt
     *     gelangen (R20/E8) — sie laufen ueber die Kacheln,
     *   - `text` hat keinen eigenen Zweig, es ist der @else-Zweig und
     *     zugleich der Vorgabewert in PortalShell::profilDaten().
     */
    public function test_jeder_feldtyp_hat_im_blatt_einen_zweig(): void
    {
        $typen = [];
        foreach ($this->alleFelder() as $meta) {
            $typen[$meta['type'] ?? 'text'] = true;
        }
        $typen = array_keys($typen);

        $this->assertContains('inline_select', $typen, 'Der fuenfte Typ (§1.4 Punkt 1) ist verschwunden');

        $blade = $this->blade();
        foreach ($typen as $typ) {
            if ($typ === 'file' || $typ === 'text') {
                continue;
            }
            $this->assertStringContainsString(
                "\$feld['type'] === '{$typ}'",
                $blade,
                "Feldtyp {$typ} kommt in der Feldliste vor, hat im Gruppen-Blatt aber keinen Zweig",
            );
        }

        // Der @else-Zweig (text) und sein Vorgabewert.
        $this->assertStringContainsString("wire:model.defer=\"profilWerte.", $blade);
        $this->assertStringContainsString("\$meta['type'] ?? 'text'", $this->quelle(PortalShell::class));
    }

    /**
     * §1.4 Punkt 2 / E7: die Upload-Zuordnung existierte im alten Portal
     * ZWEIMAL — als EmployeePortal::FILE_FIELDS und woertlich im Blade als
     * $fileUploadProps. Stand ein Feld nur in einer der beiden Listen, gab es
     * entweder keinen Knopf oder einen Knopf ohne Ziel. Genau das ist am
     * 06.08. passiert.
     *
     * Das neue Portal baut die Liste nicht nach, es leitet sie ab —
     * ProofTypes ist die einzige Zuordnungsstelle.
     */
    public function test_es_gibt_keine_zweite_datei_liste(): void
    {
        $quelle = $this->quelle(PortalShell::class);

        $this->assertStringNotContainsString('FILE_FIELDS', $quelle);
        $this->assertStringNotContainsString('fileUploadProps', $this->blade());
        $this->assertStringContainsString('codeForLegacyColumn', $quelle);

        // Und die Ableitung traegt: jedes Datei-Feld der Feldliste findet
        // seine Nachweisart, ohne dass irgendwo eine Spaltenliste steht.
        foreach ($this->alleFelder() as $schluessel => $meta) {
            if (($meta['type'] ?? 'text') !== 'file') {
                continue;
            }
            $this->assertNotNull(
                ProofTypes::codeForLegacyColumn($schluessel),
                "{$schluessel} hat keine Nachweisart",
            );
        }
    }

    /**
     * §1.4 Punkt 3 / §7 Punkt 1 / E15: die zwei fest im alten Blade
     * verdrahteten Erklaertexte.
     *
     * Sie stehen in keiner Feld-Definition und werden von keiner generischen
     * Feldruebernahme mitgenommen — sie sind das, was beim Umbau am ehesten
     * lautlos verschwindet. Der Arbeitgeber-Text ist der EINZIGE Ort im
     * Produkt, der dem Mitarbeiter den Minijob-Sonderfall erklaert; ohne ihn
     * waehlen Schueler und Studenten "Nein" und loesen Steuerklasse VI aus.
     *
     * Gemessen wird am Ergebnis von PortalShell::profilDaten() — also daran,
     * dass der Text beim OEFFNEN DER GRUPPE wirklich an die Ansicht geht, und
     * nicht nur daran, dass die Klasse ihn kennt (das haelt
     * PortalSectionHintsTest im Wortlaut fest).
     */
    public function test_die_zwei_erklaertexte_stehen_im_neuen_portal(): void
    {
        $ma = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => true]);
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Arbeitgeber');
        $arbeitgeber = (string) $this->profil($shell, $ma)['hinweis'];
        $this->assertStringContainsString('Minijob', $arbeitgeber);
        $this->assertSame(PortalSectionHints::fuer('Arbeitgeber', true), $arbeitgeber);

        $shell->oeffneGruppe('Arbeitsschutz');
        $arbeitsschutz = (string) $this->profil($shell, $ma)['hinweis'];
        $this->assertStringContainsString('Schein', $arbeitsschutz);
        $this->assertSame(PortalSectionHints::fuer('Arbeitsschutz', true), $arbeitsschutz);

        // Eine Gruppe ohne Text bekommt auch keinen.
        $shell->oeffneGruppe('Bankdaten');
        $this->assertNull($this->profil($shell, $ma)['hinweis']);

        $this->assertStringContainsString('$profilHinweis', $this->blade());
    }

    // =================================================================
    // §2.1 / R15-R18 — die drei Waechter
    // =================================================================

    /**
     * §2.1, R15-R17: alle drei Waechter greifen im NEUEN Portal, gemessen am
     * echten Weg (PortalShell::speichereGruppe) und am Ergebnis in der
     * Datenbank — ein Waechter, der meldet, aber trotzdem schreibt, waere
     * kein Waechter.
     *
     * Im alten Portal bricht saveAll() ab und schreibt GAR NICHTS. Dasselbe
     * gilt hier: das Blatt bleibt offen, die Eingaben bleiben stehen, die
     * Spalte bleibt unveraendert.
     */
    public function test_die_drei_waechter_greifen_im_neuen_portal(): void
    {
        // R15 — Ersthelfer MIT Dokumentpflicht (nur im Portal, nicht in der
        // HR-Akte).
        $ma = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => true]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitsschutz');
        $shell->profilWerte['is_first_aider'] = '1';
        $shell->profilWerte['first_aider_valid_until'] = '';
        $shell->speichereGruppe();

        $this->assertNotSame('', $shell->profilFehler, 'R15: der Ersthelfer-Waechter meldet nichts');
        $this->assertSame('Arbeitsschutz', $shell->profilGruppe, 'R15: das Blatt schliesst trotz Fehler');
        $this->assertNull($this->frisch($ma)->is_first_aider, 'R15: es wurde trotz Fehler geschrieben');

        // R16 — Staatsangehoerigkeit (Kundenentscheidung 23.09.2026).
        $ma2 = $this->mitarbeiter(['nationality' => null, 'is_main_employer' => true, 'city' => 'Koeln']);
        $shell2 = $this->shell($ma2);
        $shell2->oeffneGruppe('Adresse');
        $shell2->profilWerte['nationality'] = '';
        $shell2->profilWerte['city'] = 'Duesseldorf';
        $shell2->speichereGruppe();

        $this->assertNotSame('', $shell2->profilFehler, 'R16: der Staatsangehoerigkeits-Waechter meldet nichts');
        $this->assertSame('Koeln', $this->frisch($ma2)->city, 'R16: die Adresse wurde trotz Fehler geschrieben');

        // R17 — Haupt-/Nebenarbeitgeber, unbeantwortet.
        $ma3 = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => null]);
        $shell3 = $this->shell($ma3);
        $shell3->oeffneGruppe('Arbeitgeber');
        $shell3->profilWerte['is_main_employer'] = '';
        $shell3->speichereGruppe();

        $this->assertNotSame('', $shell3->profilFehler, 'R17: der Arbeitgeber-Waechter meldet nichts');
        $this->assertNull($this->frisch($ma3)->is_main_employer);

        // R17 — "nein" ohne Namen.
        $shell3->profilWerte['is_main_employer'] = '0';
        $shell3->profilWerte['other_employer'] = '';
        $shell3->speichereGruppe();
        $this->assertNotSame('', $shell3->profilFehler, 'R17: "nein" ohne Namen kommt durch');

        // R17/E14 — Name laenger als die Spalte (128). Ohne diese Grenze
        // schluege es als SQLSTATE 22001 durch und risse den ganzen
        // Speichervorgang mit — derselbe Abbruch, der am 25.08.2026 die
        // MA-Anlage gekillt hat.
        $shell3->profilWerte['other_employer'] = str_repeat('x', 129);
        $shell3->speichereGruppe();
        $this->assertNotSame('', $shell3->profilFehler, 'R17/E14: die Laengengrenze fehlt');
        $this->assertNull($this->frisch($ma3)->other_employer);

        // Und der gueltige Weg geht durch, sonst pruefte das alles nichts.
        $shell3->profilWerte['other_employer'] = 'Mueller GmbH';
        $shell3->speichereGruppe();
        $this->assertSame('', $shell3->profilFehler);
        $this->assertSame('Mueller GmbH', $this->frisch($ma3)->other_employer);

        // R18/E11 — wer ordentlich "nein" geantwortet hat, kann WEITER
        // speichern. Ein (string)-Rueckfall ergaebe '' und damit genau die
        // Form, die der Waechter als "unbeantwortet" liest: der Mensch waere
        // fuer immer ausgesperrt.
        $shell3->oeffneGruppe('Arbeitgeber');
        $this->assertSame('0', $shell3->profilWerte['is_main_employer']);
        $shell3->speichereGruppe();
        $this->assertSame('', $shell3->profilFehler, 'R18/E11: ein korrektes "nein" blockt sich selbst');
    }

    /**
     * §2.1: die Reihenfolge Ersthelfer -> Staatsangehoerigkeit ->
     * Hauptarbeitgeber ist bindend, nicht Formalie. Die Waechter laufen
     * nacheinander mit Early-Return; wer den ersten nicht passiert, sieht die
     * spaeteren Fehler nie. Eine parallele Sammelvalidierung wuerde andere
     * Fehlertexte zeigen als heute.
     *
     * Gemessen an der Kaskade selbst, mit einer Reichweite, die alle drei
     * Waechter betrifft (Vollspeicherungs-Pfad) und bei der alle drei Regeln
     * gleichzeitig verletzt sind: es muss der ERSTE Fehler gewinnen.
     */
    public function test_die_reihenfolge_der_kaskade_ist_bindend(): void
    {
        $alleFelder = $this->alleFelder();
        $kaputt = [
            'is_first_aider'                  => true,
            'first_aider_valid_until'         => null,
            'first_aider_certificate_file_id' => null,
            'nationality'                     => null,
            'is_main_employer'                => null,
            'other_employer'                  => null,
        ];

        $erster = PortalProfileGuards::fehler([], $kaputt, $alleFelder);
        $this->assertNotNull($erster);

        // 2. Stufe: Ersthelfer in Ordnung, Rest weiter kaputt.
        $zweiter = PortalProfileGuards::fehler(
            [],
            array_merge($kaputt, ['is_first_aider' => false]),
            $alleFelder,
        );
        $this->assertNotNull($zweiter);
        $this->assertNotSame($erster, $zweiter, 'Stufe 1 und 2 melden denselben Text');

        // 3. Stufe: nur noch der Arbeitgeber fehlt.
        $dritter = PortalProfileGuards::fehler(
            [],
            array_merge($kaputt, ['is_first_aider' => false, 'nationality' => 'deutsch']),
            $alleFelder,
        );
        $this->assertNotNull($dritter);
        $this->assertNotSame($zweiter, $dritter, 'Stufe 2 und 3 melden denselben Text');

        // 4. Stufe: alles beantwortet, nichts blockt mehr.
        $this->assertNull(PortalProfileGuards::fehler(
            [],
            array_merge($kaputt, [
                'is_first_aider'   => false,
                'nationality'      => 'deutsch',
                'is_main_employer' => true,
            ]),
            $alleFelder,
        ));
    }

    // =================================================================
    // R27 / R29 / R30 — Sichtbarkeit
    // =================================================================

    /**
     * R29/R30: welche Gruppe wer sieht. Strikt dreiwertig — bei unbekanntem
     * EU-Status erscheint die Non-EU-Gruppe NICHT (sonst forderte das Portal
     * Papiere von Leuten, die keine brauchen).
     */
    public function test_sichtbarkeit_nach_eu_status_und_beschaeftigung(): void
    {
        $eu = $this->mitarbeiter(['is_eu_citizen' => true, 'employment_type' => 'aushilfe']);
        $this->assertArrayNotHasKey('Aufenthalt (Non-EU)', $eu->editableFieldGroups());
        $this->assertArrayNotHasKey('Schul-/Immatrikulationsbescheinigung', $eu->editableFieldGroups());

        $unbekannt = $this->mitarbeiter(['is_eu_citizen' => null]);
        $this->assertArrayNotHasKey('Aufenthalt (Non-EU)', $unbekannt->editableFieldGroups());

        $schueler = $this->mitarbeiter(['is_eu_citizen' => false, 'employment_type' => 'schueler']);
        $gruppen = $schueler->editableFieldGroups();
        $this->assertArrayHasKey('Aufenthalt (Non-EU)', $gruppen);
        $this->assertArrayHasKey('schulbescheinigung_file_id', $gruppen['Schul-/Immatrikulationsbescheinigung']);
        $this->assertArrayNotHasKey('immatrikulation_file_id', $gruppen['Schul-/Immatrikulationsbescheinigung']);

        $student = $this->mitarbeiter(['is_eu_citizen' => false, 'employment_type' => 'student_erwerbstaetig']);
        $this->assertArrayHasKey(
            'immatrikulation_file_id',
            $student->editableFieldGroups()['Schul-/Immatrikulationsbescheinigung'],
            'student_erwerbstaetig verliert seine Nachweispflicht',
        );

        // Und das neue Portal laesst eine Gruppe, die dieser Mensch nicht hat,
        // auch nicht ueber einen veralteten Schnappschuss oeffnen.
        $shell = $this->shell($eu);
        $shell->oeffneGruppe('Aufenthalt (Non-EU)');
        $this->assertNull($shell->profilGruppe);
    }

    /**
     * R20/E8: Datei-Felder werden vom Schreibweg IMMER uebersprungen und
     * stehen gar nicht erst im Formular. Sonst koennte ein manipulierter POST
     * fremde File-Ids setzen oder einen gerade geprueften Nachweis im selben
     * Zug wieder leeren.
     */
    public function test_datei_felder_werden_ueber_das_nachweis_blatt_gesetzt(): void
    {
        $ma = $this->mitarbeiter([
            'nationality'                 => 'deutsch',
            'is_main_employer'            => true,
            'identity_card_front_file_id' => 4711,
        ]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Ausweis');

        $this->assertArrayNotHasKey('identity_card_front_file_id', $shell->profilWerte);

        $shell->profilWerte['identity_card_front_file_id'] = 999;
        $shell->profilWerte['identity_card_valid_until'] = '2032-01-01';
        $shell->speichereGruppe();

        $nachher = $this->frisch($ma);
        $this->assertSame(4711, (int) $nachher->identity_card_front_file_id, 'R20/E8: die File-Id wurde ueberschrieben');
        $this->assertStringStartsWith('2032-01-01', (string) $nachher->identity_card_valid_until);
    }

    // =================================================================
    // §3 — die Nebenwirkungen auf dem NEUEN Weg
    // =================================================================

    /**
     * N1 und §3.2, am echten Speichervorgang des neuen Portals.
     *
     * Das ist die dritte der "drei Dinge, die beim Umbau am ehesten vergessen
     * werden": wer fuer die Profilfelder auf den Query Builder ausweicht, weil
     * es bequemer ist, verliert den Marker fuer 42 Spalten still — und wer
     * umgekehrt alles ueber Eloquent schreibt, setzt ihn fuer die fuenf
     * Spalten mit ausdruecklichem Verbot. Hinter jeder dieser fuenf steht ein
     * Vorfall.
     *
     * Die Zahlen 42/5 werden GERECHNET (Schnittmenge der 47 editierbaren
     * Felder mit RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS), nicht
     * abgetippt.
     */
    public function test_nebenwirkung_zas_marker_und_die_fuenf_verbote(): void
    {
        $editierbar = array_keys($this->alleFelder());
        $mitMarker = array_values(array_intersect($editierbar, RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS));
        $ohneMarker = array_values(array_diff($editierbar, RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS));

        $this->assertCount(42, $mitMarker, '§3.2 nennt 42 Spalten mit Marker, gerechnet: ' . count($mitMarker));
        $this->assertSame(
            ['phone', 'is_main_employer', 'other_employer', 'erstbescheinigung_file_id', 'first_aider_certificate_file_id'],
            $ohneMarker,
            '§3.2: die fuenf Spalten mit Marker-VERBOT haben sich geaendert',
        );

        // N1 — eine relevante Spalte setzt den Marker.
        $ma = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => true, 'city' => 'Koeln']);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Adresse');
        $shell->profilWerte['city'] = 'Duesseldorf';
        $shell->speichereGruppe();

        $this->assertSame('', $shell->profilFehler);
        $this->assertNotNull($this->frisch($ma)->zas_changed_at, 'N1: der ZAS-Marker bleibt aus');

        // §3.2 — phone setzt ihn NICHT (Vorfall Katona RG999999: ein
        // Rueck-Export wuerde per PNr-Match dortige Akten ueberschreiben).
        $ma2 = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => true, 'phone' => '+4915100000001']);
        $shell2 = $this->shell($ma2);
        $shell2->oeffneGruppe('Kontakt');
        $shell2->profilWerte['phone'] = '+4915100000002';
        $shell2->speichereGruppe();

        $this->assertSame('+4915100000002', $this->frisch($ma2)->phone, 'Der Speichervorgang lief gar nicht');
        $this->assertNull($this->frisch($ma2)->zas_changed_at, '§3.2: phone hat den ZAS-Marker gesetzt');

        // §3.2 — und die Arbeitgeber-Antwort ebenfalls nicht (Vorfall
        // 02.09.2026: die Aktualisierungsdatei liefert VOLLE ZEILEN).
        $ma3 = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => null]);
        $shell3 = $this->shell($ma3);
        $shell3->oeffneGruppe('Arbeitgeber');
        $shell3->profilWerte['is_main_employer'] = '0';
        $shell3->profilWerte['other_employer'] = 'Mueller GmbH';
        $shell3->speichereGruppe();

        $this->assertSame('Mueller GmbH', $this->frisch($ma3)->other_employer);
        $this->assertNull($this->frisch($ma3)->zas_changed_at, '§3.2: die Arbeitgeber-Antwort hat den Marker gesetzt');
    }

    /**
     * N2 und §3.3: der Lohn-Trigger. An is_main_employer haengt die
     * Steuerklasse — das alte Portal meldet den Wechsel ans Lohnbuero, ein
     * Query-Builder-Schreibweg wuerde das verschweigen.
     *
     * Falle (a) aus §3.3: Erstbefuellungen zaehlen NICHT — deshalb wird hier
     * zuerst ein Ausgangswert gesetzt und dann gewechselt.
     */
    public function test_nebenwirkung_lohn_trigger(): void
    {
        $lohnrelevant = array_values(array_intersect(
            array_keys($this->alleFelder()),
            RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields'],
        ));
        $this->assertCount(13, $lohnrelevant, '§3.3 nennt 13 lohnrelevante Portal-Felder, gerechnet: ' . count($lohnrelevant));
        $this->assertContains('is_main_employer', $lohnrelevant);

        $ma = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => true]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');
        $shell->profilWerte['is_main_employer'] = '0';
        $shell->profilWerte['other_employer'] = 'Mueller GmbH';
        $shell->speichereGruppe();

        $nachher = $this->frisch($ma);
        $this->assertSame('', $shell->profilFehler);
        $this->assertNotNull($nachher->payroll_data_changed_at, 'N2: der Lohn-Trigger bleibt aus');
        $this->assertStringContainsString('is_main_employer', (string) $nachher->payroll_data_changed_fields);
    }

    /**
     * N5: der CRM-Kontakt bekommt die neue Telefonnummer (Vorfall RG19734,
     * 04.09.). Ohne den Abgleich ordnet der WhatsApp-Eingang Antworten von
     * der neuen Nummer keinem Kontakt mehr zu — der Chat der Person bleibt
     * leer, und niemand merkt es.
     */
    public function test_nebenwirkung_telefonabgleich(): void
    {
        $ma = $this->mitarbeiter([
            'nationality'      => 'deutsch',
            'is_main_employer' => true,
            'phone'            => '+4915100000001',
            'email'            => 'alt@example.test',
        ]);
        $shell = $this->shell($ma);

        // Ohne Telefonaenderung laeuft kein Abgleich.
        $shell->oeffneGruppe('Kontakt');
        $shell->profilWerte['email'] = 'neu@example.test';
        $shell->speichereGruppe();
        $this->assertSame([], self::$telefonAbgleich->gerufenFuer);

        $shell->oeffneGruppe('Kontakt');
        $shell->profilWerte['phone'] = '+4915100000002';
        $shell->speichereGruppe();
        $this->assertSame([(int) $ma->id], self::$telefonAbgleich->gerufenFuer, 'N5: der Telefonabgleich bleibt aus');
    }

    /**
     * N7/R31: Steuer-ID und SV-Nummer verlieren beim Schreiben jeden
     * Leerraum (Clara 28.08.2026 — "sonst landet spaeter nicht die
     * vollstaendige Nummer in Agenda"). Das kommt aus den Mutatoren am
     * Modell und faellt weg, sobald jemand am Query Builder vorbei schreibt.
     */
    public function test_nebenwirkung_leerraum_wird_entfernt(): void
    {
        $ma = $this->mitarbeiter(['nationality' => 'deutsch', 'is_main_employer' => true]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Steuer & Versicherung');
        $shell->profilWerte['steuer_id'] = '12 345 678 901';
        $shell->profilWerte['sozialversicherungsnummer'] = "65 1704\u{00A0}95 K 123";
        $shell->speichereGruppe();

        $nachher = $this->frisch($ma);
        $this->assertSame('', $shell->profilFehler);
        $this->assertSame('12345678901', $nachher->steuer_id);
        $this->assertSame('65170495K123', $nachher->sozialversicherungsnummer);
    }

    // =================================================================
    // Eigenheiten aus behobenen Fehlern
    // =================================================================

    /**
     * E3: `inputmode="numeric"` am Ausweisfeld war ein LOGIN-BLOCKER —
     * Ausweisnummern enthalten Buchstaben, auf der iOS-Zahlentastatur waren
     * die nicht eingebbar. Das Attribut darf im ganzen Blade nicht einmal in
     * Prosa vorkommen, sonst prueft diese Zeile nichts.
     */
    public function test_kein_erzwingendes_zahlentastatur_attribut(): void
    {
        $this->assertStringNotContainsString('inputmode', $this->blade());
    }

    /**
     * N9/N10: Sitzungsschluessel und Sperr-Schluessel sind von BEIDEN
     * Portalen geteilt. „Ein eigener Schluessel hiesse: im alten gesperrt, im
     * neuen offen" — und weil beide Portale nebeneinander laufen und alle
     * verschickten WhatsApp-Links auf das alte zeigen, waere das eine offene
     * Tuer neben einer verschlossenen.
     *
     * WARUM DIESER TEST HIER STEHT: die drei Schluessel werden an ZWEI
     * Stellen gebaut — in den privaten Helfern von EmployeePortal und in
     * PortalAuth. Bisher nagelte PortalAuthTest nur die NEUE Seite auf ihren
     * Wortlaut fest; damit haette eine Aenderung am ALTEN Portal die Kopplung
     * still zerschnitten, ohne dass irgendwo etwas rot wird. Das ist dieselbe
     * Bauart wie die Doppelliste, die am 06.08. zwei Felder ohne Knopf
     * zurueckliess (E7). Hier werden beide Seiten GEGENEINANDER gehalten,
     * nicht gegen einen abgetippten String.
     */
    public function test_beide_portale_teilen_sitzung_und_sperre(): void
    {
        $alt = new EmployeePortal();
        foreach (['employeeId' => 4242, 'token' => 'tok-geteilt'] as $eigenschaft => $wert) {
            $ref = new \ReflectionProperty($alt, $eigenschaft);
            $ref->setAccessible(true);
            $ref->setValue($alt, $wert);
        }

        $this->assertSame(
            $this->privat($alt, 'sessionKey'),
            \Platform\Recruiting\Services\PortalAuth::sessionKey(4242),
            'N9: die Portale teilen den Sitzungsschluessel nicht mehr — wer sich im einen abmeldet, bleibt im anderen drin',
        );

        $auth = new \Platform\Recruiting\Services\PortalAuth();
        $this->assertSame(
            $this->privat($alt, 'attemptCacheKey'),
            $this->privat($auth, 'attemptsKey', ['tok-geteilt']),
            'N10: die Fehlversuche werden getrennt gezaehlt — zusammen gaebe es zehn statt fuenf',
        );
        $this->assertSame(
            $this->privat($alt, 'lockoutCacheKey'),
            $this->privat($auth, 'lockoutKey', ['tok-geteilt']),
            'N10: eine Sperre gilt nicht mehr in beiden Portalen — im alten gesperrt, im neuen offen',
        );
    }

    /**
     * E18/R2: solange das alte Portal noch steht, muss seine Weiche stehen —
     * alle bereits verschickten WhatsApp-Links zeigen auf die alte Adresse,
     * und weil beide Portale den Sitzungsschluessel teilen, ist ein
     * umgestellter Mensch dort sogar schon angemeldet. Er koennte etwas
     * eintragen, das im neuen nie ankommt.
     *
     * Diese Zusicherung faellt erst mit der Abschaltung (Task 10), nicht
     * vorher.
     */
    public function test_das_alte_portal_leitet_weiter_solange_es_steht(): void
    {
        $this->assertStringContainsString(
            'recruiting.public.portal-shell',
            $this->quelle(EmployeePortal::class),
            'Die Weiche ins neue Portal ist aus EmployeePortal verschwunden',
        );
    }

    // =================================================================
    // DIE BEWUSSTEN ABWEICHUNGEN
    // =================================================================

    /**
     * ABWEICHUNG A1 — die Waechter blocken beim gruppenweisen Speichern nur
     * noch fuer Felder in der Reichweite der offenen Gruppe.
     *
     * WARUM: im alten Portal laedt mount() ALLE Felder auf einmal ins
     * Formular; der Mensch kann Staatsangehoerigkeit UND Hauptarbeitgeber auf
     * derselben Seite beantworten, eine Sammelpruefung ist dort also fair.
     * Im gruppenweisen Portal fror genau diese Sammelpruefung das GANZE
     * Profil ein, sobald zwei Pflichtangaben gleichzeitig fehlten: die
     * Adresse liess sich nicht speichern, weil der Hauptarbeitgeber fehlte,
     * und der Arbeitgeber nicht, weil die Staatsangehoerigkeit fehlte — ein
     * Ping-Pong-Deadlock, aus dem niemand mehr herauskam, nicht einmal ueber
     * die Schuhgroesse.
     *
     * WAS BLEIBT: ohne Gruppe (Vollspeicherung) blocken weiterhin alle drei.
     * Und jeder Waechter blockt in seiner EIGENEN Gruppe unveraendert — die
     * Pflicht verschwindet nicht, sie wandert nur dorthin, wo man sie
     * erfuellen kann.
     */
    public function test_abweichung_1_waechter_blocken_nur_ihre_reichweite(): void
    {
        // Der Doppel-Null-Fall: beide Pflichtangaben fehlen.
        $ma = $this->mitarbeiter([
            'nationality'      => null,
            'is_main_employer' => null,
            'shoe_size'        => 42,
        ]);
        $shell = $this->shell($ma);

        // Eine dritte Gruppe geht trotzdem durch — das ist die Abweichung.
        $shell->oeffneGruppe('Arbeitskleidung');
        $shell->profilWerte['shoe_size'] = '43';
        $shell->speichereGruppe();

        $this->assertSame('', $shell->profilFehler, 'A1: die Schuhgroesse ist wieder eingefroren');
        $this->assertSame(43, (int) $this->frisch($ma)->shoe_size);

        // Die Pflicht selbst bleibt: in IHRER Gruppe blockt sie weiter.
        $shell->oeffneGruppe('Adresse');
        $shell->profilWerte['nationality'] = '';
        $shell->speichereGruppe();
        $this->assertNotSame('', $shell->profilFehler, 'A1: der Waechter blockt in seiner eigenen Gruppe nicht mehr');

        $shell->oeffneGruppe('Arbeitgeber');
        $shell->profilWerte['is_main_employer'] = '';
        $shell->speichereGruppe();
        $this->assertNotSame('', $shell->profilFehler, 'A1: der Arbeitgeber-Waechter blockt sich selbst nicht mehr');

        // Und ohne Gruppe (Vollspeicherung) blocken weiterhin alle drei.
        $ergebnis = (new PortalProfileWriter())->speichere($ma->fresh(), ['shoe_size' => '44'], null);
        $this->assertFalse($ergebnis['ok'], 'A1: der gruppenlose Pfad blockt nicht mehr');
        $this->assertSame(43, (int) $this->frisch($ma)->shoe_size);
    }

    /**
     * ABWEICHUNG A2 — die Sichtbarkeitsregel behandelt einen LEEREN
     * Formularwert anders als das alte Portal.
     *
     * ALT (EmployeePortal::fieldIsVisible): array_key_exists ohne
     * Leer-Pruefung — ein ausdrueckliches '' gilt als Aussage
     * "unbeantwortet", das abhaengige Feld bleibt sichtbar.
     * NEU (PortalFieldAccess::istSichtbar): ein leerer Formularwert ist keine
     * Aussage, es gilt der Datensatz.
     *
     * WARUM DAS UNGEFAEHRLICH IST: Sichtbarkeit steuert nur die ANZEIGE
     * waehrend des Tippens. Gespeichert wird ueber den Wert, und der laeuft
     * durch dieselbe Quelle (PortalBoolValue) und dieselben Waechter. Ein
     * gespeichertes Ergebnis kann sich dadurch nicht aendern.
     *
     * Die vollstaendige Wahrheitstabelle alt gegen neu — ueber ALLE
     * visible_if-Bedingungen der Feldliste — steht in
     * PortalFieldAccessEquivalenceTest. Hier wird nur die eine abweichende
     * Zelle festgenagelt, damit sie beim Lesen dieser Abnahme nicht fehlt.
     */
    public function test_abweichung_2_leerer_formularwert_faellt_auf_den_datensatz_zurueck(): void
    {
        $meta = ['visible_if' => ['is_main_employer' => false]];
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        // NEU: '' ist keine Aussage -> Datensatz (true) widerlegt die
        // Bedingung -> other_employer ist unsichtbar.
        $this->assertFalse(
            PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => true], ['is_main_employer' => '']),
            'A2: das neue Portal verhaelt sich nicht mehr wie beschrieben',
        );

        // ALT: '' zaehlt als "unbeantwortet" -> nichts ist widerlegt ->
        // other_employer bleibt sichtbar.
        $alt = new EmployeePortal();
        $ref = new \ReflectionProperty($alt, 'fieldValues');
        $ref->setAccessible(true);
        $ref->setValue($alt, ['is_main_employer' => '']);

        $this->assertTrue(
            (bool) $this->privat($alt, 'fieldIsVisible', [$ma, $meta]),
            'A2: das ALTE Portal verhaelt sich nicht mehr wie in der Bestandsaufnahme beschrieben',
        );

        // Beide sind sich einig, sobald ein echter Wert im Formular steht —
        // und genau darueber wird gespeichert.
        foreach (['0' => true, '1' => false] as $formwert => $erwartet) {
            $ref->setValue($alt, ['is_main_employer' => $formwert]);
            $this->assertSame(
                $erwartet,
                PortalFieldAccess::istSichtbar($meta, ['is_main_employer' => true], ['is_main_employer' => $formwert]),
            );
            $this->assertSame($erwartet, (bool) $this->privat($alt, 'fieldIsVisible', [$ma, $meta]));
        }
    }

    /**
     * ABWEICHUNG A3 — Nicht-EU-Dokumente: ZUGEWINN, keine Luecke.
     *
     * Das alte Portal kann sie NICHT hochladen; seine Non-EU-Gruppe enthaelt
     * ausschliesslich zwei DATUMSFELDER, obwohl die Spalten existieren und im
     * ZAS-Export stehen. Das neue Portal kann es ueber den Nachweis-Katalog.
     *
     * Beides wird abgeleitet, nicht behauptet: die Datumsfelder aus
     * editableFieldGroups(), die Upload-Faehigkeit aus ProofTypes.
     */
    public function test_abweichung_3_nicht_eu_dokumente_sind_ein_zugewinn(): void
    {
        $nonEu = $this->mitarbeiter(['is_eu_citizen' => false])
            ->editableFieldGroups()['Aufenthalt (Non-EU)'];

        $typen = array_values(array_unique(array_column($nonEu, 'type')));
        $this->assertSame(['date'], $typen, '§5 Punkt 1: die Non-EU-Gruppe des alten Portals hat Datei-Felder bekommen');
        $this->assertSame(
            ['residence_permit_valid_until', 'work_permit_valid_until'],
            array_keys($nonEu),
        );

        // Das neue Portal kennt die Papiere selbst — die Altspalten, die im
        // alten Portal unerreichbar waren, haben eine Nachweisart.
        foreach ([
            'nationalpass_file_id',
            'aufenthaltstitel_front_file_id',
            'visumsblatt_file_id',
            'zusatzblatt_file_id',
            'fiktionsbescheinigung_front_file_id',
        ] as $spalte) {
            $this->assertNotNull(
                ProofTypes::codeForLegacyColumn($spalte),
                "Zugewinn A3: {$spalte} hat keine Nachweisart",
            );
        }
    }

    /**
     * ABWEICHUNG A4 — die elf Punkte, die der Plan ausdruecklich NICHT
     * abdeckt. Hier stehen sie vollstaendig, damit niemand sie fuer
     * vergessen haelt; pruefbar ist ein Teil davon, und der wird gemessen.
     *
     *  1. §1.4 Punkt 4, die LEERE GRUPPENKARTE. Das alte Blade rendert eine
     *     Gruppe ohne Felder als leere Karte mit Ueberschrift; die
     *     Bestandsaufnahme nennt selbst "unklar", ob das je auftritt. Das neue
     *     Portal laesst leere Gruppen weg — in der Zeilendarstellung waere
     *     eine leere Karte eine Zeile, die beim Antippen ein leeres Blatt
     *     oeffnet. GEMESSEN unten.
     *  2. N4, `portal_verified_at` ueber Eloquent. Das neue Portal schreibt
     *     den Stempel ueber den Query Builder. Es geht kein Marker verloren,
     *     weil die Spalte nicht in der Relevanzliste steht. GEMESSEN unten.
     *  3. R26, die ROH-AUSNAHMEMELDUNG beim Upload. Das alte Portal zeigt dem
     *     Mitarbeiter `$e->getMessage()`, das neue einen festen Satz und
     *     `report()`-et die Ausnahme. Die Bestandsaufnahme nennt das selbst
     *     einen Zugewinn. GEMESSEN unten.
     *  4. E1 und E10 (Tailwind-Variable `--ui-primary-dark`, geerbtes
     *     `dark:text-white` aus dem Guest-Layout von platforms-core). NICHT
     *     ANWENDBAR: das neue Portal bringt sein eigenes Layout mit.
     *     GEMESSEN unten.
     *  5. E4, `style="color-scheme: light"` an jedem Datumsfeld — ersetzt
     *     durch `:root { color-scheme: light }` im Portal-Layout. Gleiche
     *     Zusage, eine Stelle statt sieben. GEMESSEN unten.
     *  6. §5 Punkt 3: Datei ansehen, herunterladen, ersetzen-und-loeschen.
     *     Kann das alte nicht, kann das neue nicht. Kein Rueckschritt, aber
     *     auch kein Fortschritt — bleibt offen.
     *  7. §5 Punkt 12: Abmelden vom Sperr- oder Ratenbegrenzungs-Bildschirm.
     *     Bleibt wie im alten Portal: den Knopf gibt es nur im angemeldeten
     *     Zustand.
     *  8. §5 Punkte 4, 7, 8, 13, 14 (Einsaetze, Konto/Canvas 68,
     *     `portal_last_seen_at`, Feldvalidierung, Mehrsprachigkeit). Weder
     *     alt noch neu — ausserhalb dieses Auftrags.
     *  9. EIN EINZIGER "Alles speichern"-Knopf. Das alte Portal speichert 47
     *     Felder auf einmal, das neue gruppenweise. Der Preis: der Fehlertext
     *     eines Waechters kann eine andere Gruppe nennen als die offene —
     *     deshalb traegt er weiterhin den vollen Satz mit dem Feldnamen,
     *     woertlich wie im alten Portal. Siehe A1.
     * 10. Die FREIGABE des Arbeitgeber-Erklaertexts durch jemanden, der die
     *     Lohnabrechnung verantwortet (offener Punkt aus Commit 0f9cffa). Der
     *     Text wandert woertlich mit; die Freigabe ist eine Frage an den
     *     Kunden, kein Bauteil.
     * 11. DOPPELTUER bei fuenf Datumsfeldern: identity_card_valid_until,
     *     school_certificate_valid_until, first_aider_valid_until,
     *     residence_permit_valid_until und work_permit_valid_until sind
     *     zugleich editierbare Profilfelder UND Ablaufspalte einer
     *     Nachweisart. Ueber das Profil geschrieben setzen sie den ZAS-Marker
     *     (wie im alten Portal), ueber das Nachweis-Blatt nicht. Beide Wege
     *     bleiben — bewusst. GEMESSEN unten.
     */
    public function test_abweichung_4_die_nicht_abgedeckten_punkte_des_plans(): void
    {
        // Punkt 1 — leere Gruppen fallen weg statt als leere Karte zu stehen.
        $gruppen = PortalFieldAccess::sichtbareGruppen(
            ['Leer' => [], 'Voll' => ['x' => ['type' => 'text', 'label' => 'X']]],
            [],
            [],
        );
        $this->assertSame(
            ['Voll'],
            array_keys($gruppen),
            'Punkt 1: eine leere Gruppe waere eine Zeile, die beim Antippen ein leeres Blatt oeffnet',
        );

        // Punkt 2 — portal_verified_at steht nicht in der Relevanzliste, es
        // geht also kein Marker verloren.
        $this->assertNotContains(
            'portal_verified_at',
            RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS,
            'Punkt 2: der Anmeldestempel loest jetzt doch einen ZAS-Export aus',
        );
        $this->assertStringContainsString(
            'portal_verified_at',
            $this->quelle(PortalShell::class),
            'Punkt 2: das neue Portal setzt den Anmeldestempel gar nicht mehr',
        );

        // Punkt 3 — fester Satz statt Roh-Ausnahme.
        $shellQuelle = $this->quelle(PortalShell::class);
        $this->assertStringNotContainsString('Upload-Fehler: ', $shellQuelle, 'R26: die Wortwahl des alten Portals ist zurueck');
        $this->assertStringNotContainsString(
            '$e->getMessage()',
            $shellQuelle,
            'Punkt 3: die Roh-Ausnahmemeldung steht wieder vor dem Mitarbeiter',
        );
        $this->assertStringContainsString('report($e)', $shellQuelle, 'Punkt 3: die Ausnahme wird nirgends mehr gemeldet');

        // Punkt 4 — eigenes Layout, keine geerbten Fallen.
        $blade = $this->blade();
        $this->assertStringNotContainsString('--ui-primary-dark', $blade, 'E1: die Variable ohne Definition ist zurueck');
        $this->assertStringNotContainsString('dark:text-white', $blade, 'E10: weisse Schrift auf weisser Karte ist zurueck');

        // Punkt 5 — eine Stelle statt sieben.
        $layout = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/layouts/portal.blade.php',
        );
        $this->assertStringContainsString('color-scheme: light', $layout);
        // Gegen den GERENDERTEN Teil gemessen, nicht gegen den Quelltext: die
        // Blade erklaert in einem Kommentar ausdruecklich, warum sie das
        // Attribut NICHT mehr setzt — ein Treffer dort waere kein Befund.
        $this->assertStringNotContainsString(
            'style="color-scheme',
            $this->bladeOhneKommentare(),
            'E4: das Datumsfeld setzt color-scheme wieder selbst statt ueber das Layout',
        );

        // Punkt 11 — die fuenf Doppeltueren. Beide Wege existieren, und das
        // ist Absicht: das Profil, weil die Bestandsaufnahme 47 editierbare
        // Felder verlangt; das Nachweis-Blatt, weil dort die Datei dazugehoert.
        $ablaufSpalten = [];
        foreach (ProofTypes::all() as $code) {
            $spalte = ProofTypes::legacyExpiryColumn($code);
            if ($spalte !== null) {
                $ablaufSpalten[$spalte] = true;
            }
        }
        $doppelt = array_values(array_intersect(array_keys($this->alleFelder()), array_keys($ablaufSpalten)));
        sort($doppelt);
        $this->assertSame([
            'first_aider_valid_until',
            'identity_card_valid_until',
            'residence_permit_valid_until',
            'school_certificate_valid_until',
            'work_permit_valid_until',
        ], $doppelt, 'Punkt 11: die Doppeltueren haben sich veraendert');
    }
}
