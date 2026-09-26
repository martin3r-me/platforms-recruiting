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
use ReflectionMethod;

/**
 * `PortalShell::profilDaten()` -- der groesste neue Block aus Aufgabe 6, bis
 * zu dieser Fixrunde vollstaendig ungetestet (I3). Deckt gleichzeitig zwei
 * gemeldete Fehler ab, die beide in profilDaten() sitzen:
 *
 *  - C2 (Critical): die Felder der OFFENEN Gruppe wurden GEGEN DEN DATENSATZ
 *    berechnet, nie gegen $this->profilWerte. Ein visible_if-Feld wie
 *    other_employer (sichtbar nur bei is_main_employer=false) blieb deshalb
 *    unsichtbar, waehrend der Mensch GERADE per Live-Auswahl von "ja" auf
 *    "nein" umstellt -- "Nein" war damit praktisch unerreichbar.
 *  - I1: anzeigewert() fehlte der 'file'-Zweig aus
 *    EmployeePortal::formatDisplayValue() -- die Gruppenzeile "Ausweis"
 *    zeigte rohe Datei-Ids ("01.01.2032 · 5002 · 5003 · 5004").
 *
 * Migrationen wie PortalCompletenessEuBuergerTest: echtes RecEmployee-Modell
 * auf SQLite via Capsule, kuratierte Teilmenge der Migrationen -- ergaenzt um
 * die Portal-Sperr-/Umstellungs-Spalten, die berechtigterMitarbeiter() (und
 * damit oeffneGruppe()) braucht.
 *
 * profilDaten() ist private -- Zugriff ueber ReflectionMethod wie in
 * InboundApplicantLookupTest/AutoPilotSkipsParkedTest, nicht ueber render()
 * (kein 'view'-Binding in dieser Suite, siehe
 * PortalShellEmployerWiringTest-Kommentar).
 */
final class PortalShellProfilDatenTest extends TestCase
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
            // portal_locked_at -- berechtigterMitarbeiter() liest sie
            'database/migrations/2026_08_24_000004_add_portal_lock_to_rec_employees.php',
            // portal_last_seen_at -- Reihenfolge-Abhaengigkeit (->after())
            'database/migrations/2026_09_03_000003_add_portal_last_seen_at_to_rec_employees.php',
            // portal_v2_since -- Reihenfolge-Abhaengigkeit (->after())
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
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

    /** @return array<string,mixed> ein vollstaendiger EU-Buerger, Basis fuer alle Tests hier. */
    private function mitarbeiter(array $ueberschreiben = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'         => 913,
            'first_name'      => 'Erika',
            'last_name'       => 'Musterfrau',
            'portal_token'    => 'tok-' . uniqid(),
            'is_active'       => true,
            'portal_v2_since' => '2026-09-24 08:00:00',
            'nationality'     => 'deutsch',
            'is_eu_citizen'   => true,
            'is_main_employer' => true,
        ], $ueberschreiben));
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';

        return $shell;
    }

    /** @return array{gruppen:array, stand:array, felder:array, hinweis:?string, nurLesen:array, kacheln:array} */
    private function profilDaten(PortalShell $shell, ?RecEmployee $employee): array
    {
        $methode = new ReflectionMethod($shell, 'profilDaten');
        $methode->setAccessible(true);

        return $methode->invoke($shell, $employee);
    }

    // -----------------------------------------------------------------
    // C2 (Critical): "Nein" beim Hauptarbeitgeber ist erreichbar
    // -----------------------------------------------------------------

    public function test_offene_gruppe_zeigt_das_namensfeld_waehrend_auf_nein_umgestellt_wird(): void
    {
        // Datensatz sagt "ja" (is_main_employer=true) -- other_employer ist
        // per visible_if nur bei "nein" sichtbar. Der Mensch stellt gerade
        // per Live-Auswahl (is_main_employer hat 'live' => true) auf "nein"
        // um: der FORMULARWERT ist schon '0', der DATENSATZ noch true.
        $ma = $this->mitarbeiter(['is_main_employer' => true]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');

        // Testvoraussetzung: beim Oeffnen (Datensatz sagt "ja") ist das
        // Namensfeld noch nicht da -- sonst waere der Test von Anfang an
        // gruen, ohne dass die Umstellung ueberhaupt geprueft wuerde.
        $this->assertArrayNotHasKey('other_employer', $shell->profilWerte);

        $shell->profilWerte['is_main_employer'] = '0';

        $profil = $this->profilDaten($shell, $ma);

        $this->assertArrayHasKey(
            'other_employer',
            $profil['felder'],
            'Das Namensfeld muss sichtbar werden, sobald der FORMULARWERT auf "nein" steht -- auch wenn der Datensatz noch "ja" sagt.',
        );
    }

    public function test_offene_gruppe_versteckt_das_namensfeld_wieder_bei_formularwert_ja(): void
    {
        // Gegenprobe: Datensatz "nein" (Feld also im Datensatz sichtbar),
        // Formularwert gerade auf "ja" umgestellt -- muss wieder verschwinden.
        $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Musterfirma GmbH']);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');
        $this->assertArrayHasKey('other_employer', $shell->profilWerte);

        $shell->profilWerte['is_main_employer'] = '1';

        $profil = $this->profilDaten($shell, $ma);

        $this->assertArrayNotHasKey('other_employer', $profil['felder']);
    }

    public function test_andere_gruppen_bleiben_gegen_den_datensatz_auch_waehrend_die_arbeitgeber_gruppe_offen_ist(): void
    {
        // Die Beschraenkung gilt NUR fuer die offene Gruppe -- andere
        // Gruppenzeilen (hier: die Ausweis-Zeile ueber $profil['gruppen'])
        // duerfen sich nicht ploetzlich nach profilWerte richten, die zu
        // einer ganz anderen Gruppe gehoeren.
        $ma = $this->mitarbeiter(['is_main_employer' => true, 'identity_card_valid_until' => '2032-01-01']);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');
        // Ein Formularwert, der zu einer FREMDEN Gruppe gehoeren wuerde,
        // faende sich nur im (nicht existierenden) Feld -- hier reicht der
        // Nachweis, dass die Ausweis-Zeile unveraendert bleibt.
        $shell->profilWerte['is_main_employer'] = '0';

        $profil = $this->profilDaten($shell, $ma);

        $this->assertSame('01.01.2032', $profil['gruppen']['Ausweis']['zeile']);
    }

    // -----------------------------------------------------------------
    // I1: anzeigewert() ohne 'file'-Zweig zeigte rohe Datei-Ids in der
    // Gruppenzeile. Entscheidung (Fixrunde 2, siehe Bericht): Datei-Felder
    // erscheinen NICHT in der Zusammenfassung -- sie laufen exklusiv ueber
    // die Kacheln (Vollstaendigkeits-Icons), eine zweite Erscheinung waere
    // Rauschen und ein Dateiname-Lookup pro Render zusaetzliche Abfragen.
    // KEIN 'file'-Zweig in anzeigewert() -- ein Zweig ohne erreichbaren
    // Aufrufer waere Ballast mit einem Test, der nur sich selbst pinnt.
    // -----------------------------------------------------------------

    public function test_gruppenzeile_ausweis_enthaelt_keine_rohen_datei_ids(): void
    {
        $ma = $this->mitarbeiter([
            'identity_card_valid_until'   => '2032-01-01',
            'identity_card_front_file_id' => 5002,
            'identity_card_back_file_id'  => 5003,
            'selfie_file_id'              => 5004,
        ]);
        $shell = $this->shell($ma);

        $profil = $this->profilDaten($shell, $ma);

        $zeile = $profil['gruppen']['Ausweis']['zeile'];
        $this->assertStringNotContainsString('5002', $zeile);
        $this->assertStringNotContainsString('5003', $zeile);
        $this->assertStringNotContainsString('5004', $zeile);
        $this->assertSame('01.01.2032', $zeile, 'Datei-Felder gehoeren nicht in die Zusammenfassung -- nur das Datum bleibt.');
    }

    public function test_fehlende_ausweis_dateien_zaehlen_trotzdem_als_offen(): void
    {
        // Der Ausschluss aus der Zeile darf die "offen"-Zaehlung nicht
        // veraendern -- ein fehlendes Selfie ist weiterhin eine offene
        // Aufgabe, nur eben keine, die in der Zeile auftaucht.
        $ma = $this->mitarbeiter(['identity_card_valid_until' => '2032-01-01']);
        $shell = $this->shell($ma);

        $profil = $this->profilDaten($shell, $ma);

        $this->assertGreaterThan(0, $profil['gruppen']['Ausweis']['offen']);
    }

    // -----------------------------------------------------------------
    // Auflage 3 (Fixrunde 3, Aufgabe 7, 26.09.2026): "Noch nichts
    // hinterlegt" widersprach den gruenen Kacheln direkt darueber, wenn
    // eine Gruppe NUR Datei-Werte hat und das einzige Nicht-Datei-Feld
    // leer ist -- der Offen-Zaehler zaehlt die Dateien mit, die Zeile
    // verneinte aber jede Angabe. Der Mensch soll sehen: Dateien SIND da,
    // etwas fehlt trotzdem (der rote Punkt uebernimmt das "fehlt noch").
    // -----------------------------------------------------------------

    public function test_ausweis_mit_allen_fotos_aber_ohne_datum_sagt_nicht_mehr_noch_nichts_hinterlegt(): void
    {
        $ma = $this->mitarbeiter([
            'identity_card_valid_until'   => null,
            'identity_card_front_file_id' => 5002,
            'identity_card_back_file_id'  => 5003,
            'selfie_file_id'              => 5004,
        ]);
        $shell = $this->shell($ma);

        $profil = $this->profilDaten($shell, $ma);

        $this->assertNotSame('Noch nichts hinterlegt', $profil['gruppen']['Ausweis']['zeile']);
        // Das fehlende Datum bleibt trotzdem eine offene Pflicht -- Zeile
        // und Offen-Zaehler duerfen sich nicht mehr widersprechen.
        $this->assertGreaterThan(0, $profil['gruppen']['Ausweis']['offen']);
    }

    public function test_ausweis_ganz_ohne_irgendeinen_wert_bleibt_bei_noch_nichts_hinterlegt(): void
    {
        // Gegenprobe: fehlt WIRKLICH alles (auch die Dateien), bleibt die
        // ehrliche Meldung stehen.
        $ma = $this->mitarbeiter(['identity_card_valid_until' => null]);
        $shell = $this->shell($ma);

        $profil = $this->profilDaten($shell, $ma);

        $this->assertSame('Noch nichts hinterlegt', $profil['gruppen']['Ausweis']['zeile']);
    }

    // -----------------------------------------------------------------
    // Auflage 4 (Fixrunde 3, Aufgabe 7, 26.09.2026): das live erscheinende
    // Pflichtfeld (other_employer, sobald auf "nein" umgestellt wird)
    // bekam nie den roten Rand ($feld['fehlt']), weil dessen required_if
    // noch gegen den ALTEN Datensatz (is_main_employer=true) geprueft
    // wurde -- die Pflicht fiel erst beim geblockten Speichern auf.
    // -----------------------------------------------------------------

    public function test_das_live_erscheinende_namensfeld_bekommt_sofort_den_roten_rand(): void
    {
        $ma = $this->mitarbeiter(['is_main_employer' => true]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');

        $shell->profilWerte['is_main_employer'] = '0';

        $profil = $this->profilDaten($shell, $ma);

        $this->assertArrayHasKey('other_employer', $profil['felder'], 'Testvoraussetzung: das Namensfeld muss sichtbar sein (C2).');
        $this->assertTrue(
            $profil['felder']['other_employer']['fehlt'],
            'Ein gerade erst live sichtbar gewordenes Pflichtfeld muss sofort den roten Rand bekommen, nicht erst nach einem geblockten Speicherversuch.',
        );
    }

    public function test_zurueck_auf_ja_nimmt_den_roten_rand_vom_verschwundenen_namensfeld_wieder_weg(): void
    {
        // Gegenprobe zu C2/Auflage 4: Datensatz "nein" (Feld also
        // gespeichert-sichtbar), Formularwert gerade auf "ja" umgestellt --
        // das Namensfeld verschwindet (C2) und darf dann natuerlich auch
        // keinen roten Rand mehr tragen.
        $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Musterfirma GmbH']);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');

        $shell->profilWerte['is_main_employer'] = '1';

        $profil = $this->profilDaten($shell, $ma);

        $this->assertArrayNotHasKey('other_employer', $profil['felder']);
    }

    // -----------------------------------------------------------------
    // I3: profilDaten() im Uebrigen -- Sichtbarkeit, offen-Zaehlung,
    // Hinweistext, keine rohen Ids irgendwo im Rueckgabewert.
    // -----------------------------------------------------------------

    public function test_hinweistext_gehoert_zur_offenen_gruppe(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');

        $profil = $this->profilDaten($shell, $ma);

        $this->assertNotNull($profil['hinweis']);
        $this->assertStringContainsString('Hauptarbeitgeber', $profil['hinweis']);
    }

    public function test_kein_hinweistext_ohne_offene_gruppe(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);

        $profil = $this->profilDaten($shell, $ma);

        $this->assertNull($profil['hinweis']);
        $this->assertSame([], $profil['felder']);
    }

    public function test_offen_zaehlung_sinkt_wenn_ein_pflichtfeld_nachgetragen_wird(): void
    {
        $unvollstaendig = $this->mitarbeiter(['iban' => null]);
        $vollstaendig = $this->mitarbeiter(['iban' => 'DE89370400440532013000']);
        $shell = $this->shell($unvollstaendig);

        $offenVorher = $this->profilDaten($shell, $unvollstaendig)['gruppen']['Bankdaten']['offen'];
        $offenNachher = $this->profilDaten($shell, $vollstaendig)['gruppen']['Bankdaten']['offen'];

        $this->assertGreaterThan($offenNachher, $offenVorher);
    }

    public function test_kein_mitarbeiter_liefert_leere_daten_ohne_fehler(): void
    {
        $shell = new PortalShell();

        $profil = $this->profilDaten($shell, null);

        $this->assertSame([], $profil['gruppen']);
        $this->assertNull($profil['hinweis']);
        $this->assertSame([], $profil['felder']);
        $this->assertSame([], $profil['kacheln']);
    }
}
