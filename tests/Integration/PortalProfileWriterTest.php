<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Observers\RecEmployeePhoneSyncObserver;
use Platform\Recruiting\Services\PortalProfileWriter;
use Platform\Recruiting\Services\Zas\ContactPhoneSync;

/**
 * Der Schreibweg des Mitarbeiterportals — gemessen, nicht behauptet.
 *
 * Am Speichern haengen fuenf Nebenwirkungen, und jede kann still ausfallen
 * oder still zu viel tun. Deshalb laeuft dieser Test gegen die ECHTEN
 * Migrationen und mit REGISTRIERTEN Beobachtern (Muster:
 * EmployerFieldsNoExportMarkerTest). Ein Test ohne Beobachter waere gruen,
 * ohne irgendetwas davon geprueft zu haben:
 *
 *   N1  ZAS-Export-Marker fuer die relevanten Spalten (§3.2)
 *   N2  Lohn-Trigger fuer die 13 lohnrelevanten Spalten (§3.3)
 *   N5  CRM-Telefonabgleich bei phone (Vorfall RG19734)
 *   N7  Leerraum raus aus steuer_id und sozialversicherungsnummer
 *   R15-R18 die Waechter-Kaskade VOR jedem Schreiben
 *
 * Die fuenf Spalten mit Marker-VERBOT werden einzeln gemessen, nicht
 * gesammelt: phone, is_main_employer, other_employer,
 * erstbescheinigung_file_id, first_aider_certificate_file_id. Hinter jeder
 * steht ein Vorfall; am 02.09.2026 hat ein Massenlauf 505 volle Akten in die
 * Aktualisierungsdatei gespuelt und dort gepflegte Daten ueberschrieben.
 */
class PortalProfileWriterTest extends TestCase
{
    private const TEAM = 771;

    /** Attrappe des CRM-Abgleichs — die echte braucht CRM-Tabellen. */
    private static object $telefonAbgleich;

    public static function setUpBeforeClass(): void
    {
        // \Log:: im Observer nutzt den globalen Alias, den es ausserhalb einer
        // gebooteten Laravel-App nicht gibt. Ohne ihn verdeckt ein
        // "Class Log not found" im catch-Zweig den eigentlichen Fehler.
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
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Model::unguard();
        Model::clearBootedModels();

        self::migrationen();

        // Der Telefonabgleich schreibt im Betrieb CRM-Kontakte. Hier zaehlt
        // nur, DASS er gerufen wird — die Regel selbst haengt in
        // ContactPhoneSyncTest.
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
            // Ohne diese Spalten scheitert das Lohn-Tracking still (safelyRun)
            // — der Test pruefte den Trigger, ohne ihn gelaufen zu sein.
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

        // Das Lohn-Tracking liest die Team-Einstellungen; ohne die Tabelle
        // liefe es in den stillen Fehlerzweig.
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
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Container::getInstance()->forgetInstance(ContactPhoneSync::class);
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_applicant_settings')->delete();
        self::$telefonAbgleich->gerufenFuer = [];
    }

    /**
     * Ein Mitarbeiter, der die Waechter-Kaskade passiert: Staatsangehoerigkeit
     * gesetzt, Arbeitgeber-Frage beantwortet, kein Ersthelfer. Alles andere
     * wuerde JEDEN Speichervorgang blocken — und der Test pruefte nichts.
     */
    private function mitarbeiter(array $attr = []): RecEmployee
    {
        $ma = RecEmployee::create(array_merge([
            'team_id'          => self::TEAM,
            'first_name'       => 'Kevin',
            'last_name'        => 'Muster',
            'portal_token'     => 'tok-' . uniqid('', true),
            'is_active'        => true,
            'nationality'      => 'deutsch',
            'is_main_employer' => true,
        ], $attr));

        // Der Marker des Anlegens interessiert hier nie — gemessen wird
        // ausschliesslich, was das Speichern ausloest.
        Capsule::table('rec_employees')->where('id', $ma->id)->update([
            'zas_changed_at'              => null,
            'payroll_data_changed_at'     => null,
            'payroll_data_changed_fields' => null,
        ]);

        return $ma->fresh();
    }

    private function frisch(RecEmployee $ma): object
    {
        return Capsule::table('rec_employees')->where('id', $ma->id)->first();
    }

    // -----------------------------------------------------------------
    // Der Pruefstand selbst
    // -----------------------------------------------------------------

    public function test_der_pruefstand_kennt_alle_bearbeitbaren_spalten(): void
    {
        // Ohne diese Gegenprobe waere jeder Test unten gruen, der eine
        // fehlende Spalte nur nicht anfasst.
        $spalten = Capsule::schema()->getColumnListing('rec_employees');

        foreach ([[true, 'student'], [false, 'schueler']] as [$eu, $art]) {
            $ma = new RecEmployee(['is_eu_citizen' => $eu, 'employment_type' => $art]);
            foreach (array_keys($ma->editableFieldsFlat()) as $feld) {
                $this->assertContains($feld, $spalten, "Spalte {$feld} fehlt im Pruefstand");
            }
        }
    }

    // -----------------------------------------------------------------
    // Werte-Umwandlung (R22)
    // -----------------------------------------------------------------

    public function test_text_und_lookup_werden_getrimmt_und_leer_wird_null(): void
    {
        $ma = $this->mitarbeiter(['city' => 'Koeln', 'zip' => '50667']);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => '  Duesseldorf  ', 'zip' => '']);

        $this->assertTrue($ergebnis['ok']);
        $this->assertSame('Gespeichert.', $ergebnis['meldung']);
        $this->assertSame('Duesseldorf', $ma->fresh()->city);
        $this->assertNull($ma->fresh()->zip);
    }

    public function test_ja_nein_laeuft_ueber_PortalBoolValue(): void
    {
        $ma = $this->mitarbeiter();

        (new PortalProfileWriter())->speichere($ma, ['has_car' => 'ja']);

        $this->assertTrue($ma->fresh()->has_car);
    }

    public function test_datei_felder_werden_beim_speichern_uebersprungen(): void
    {
        // R20/E8: ein manipulierter POST darf keine fremde File-Id setzen und
        // keinen gerade geprueften Nachweis im selben Zug leeren.
        $ma = $this->mitarbeiter(['selfie_file_id' => 7]);

        (new PortalProfileWriter())->speichere($ma, ['selfie_file_id' => '0', 'city' => 'Bonn']);

        $this->assertSame(7, (int) $ma->fresh()->selfie_file_id);
        $this->assertSame('Bonn', $ma->fresh()->city);
    }

    public function test_unbekannte_schluessel_werden_uebersprungen(): void
    {
        // R19: Schutz gegen manipulierten POST.
        $ma = $this->mitarbeiter();

        (new PortalProfileWriter())->speichere($ma, ['personnel_number' => 'MA99999', 'city' => 'Bonn']);

        $this->assertNull($ma->fresh()->personnel_number);
        $this->assertSame('Bonn', $ma->fresh()->city);
    }

    // -----------------------------------------------------------------
    // Gruppenweises Speichern (Verschaerfung von R19)
    // -----------------------------------------------------------------

    public function test_nur_die_offene_gruppe_wird_geschrieben(): void
    {
        // Aus dem Blatt "Arbeitskleidung" darf niemand die
        // Hauptarbeitgeber-Angabe umstellen.
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        (new PortalProfileWriter())->speichere($ma, ['shirt_size' => 'L', 'is_main_employer' => '0'], 'Arbeitskleidung');

        $this->assertSame('L', $ma->fresh()->shirt_size);
        $this->assertTrue($ma->fresh()->is_main_employer);
    }

    public function test_eine_fremde_gruppe_raeumt_den_anderen_arbeitgeber_nicht_ab(): void
    {
        // R21 gilt nur, soweit die offene Gruppe reicht. Sonst wuerde ein
        // Speichern der Schuhgroesse eine Angabe aus einem anderen Blatt
        // veraendern — genau das, was "nur die offene Gruppe" ausschliesst.
        // (Der Zustand ja + Name ist in sich widerspruechlich, aber genau
        // deshalb taugt er als Messpunkt.)
        $ma = $this->mitarbeiter(['is_main_employer' => true, 'other_employer' => 'Mueller GmbH']);

        (new PortalProfileWriter())->speichere($ma, ['shoe_size' => '43'], 'Arbeitskleidung');

        $this->assertSame('Mueller GmbH', $ma->fresh()->other_employer);
    }

    public function test_ja_leert_den_anderen_arbeitgeber(): void
    {
        // R21: die Spalte ist ausschliesslich die Antwort auf "wenn nicht wir,
        // wer dann" — sonst stuende Mueller als Hauptarbeitgeber.
        $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']);

        (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '1'], 'Arbeitgeber');

        $this->assertTrue($ma->fresh()->is_main_employer);
        $this->assertNull($ma->fresh()->other_employer);
    }

    // -----------------------------------------------------------------
    // Leerer Diff (R23)
    // -----------------------------------------------------------------

    public function test_leerer_diff_meldet_keine_aenderungen_und_schreibt_nicht(): void
    {
        $ma = $this->mitarbeiter(['city' => 'Koeln']);
        Capsule::table('rec_employees')->where('id', $ma->id)
            ->update(['updated_at' => '2020-01-01 00:00:00']);
        $ma = $ma->fresh();

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => 'Koeln']);

        $this->assertTrue($ergebnis['ok']);
        $this->assertSame('Keine Änderungen.', $ergebnis['meldung']);
        $this->assertSame('2020-01-01 00:00:00', (string) $this->frisch($ma)->updated_at);
    }

    public function test_eine_gruppe_ohne_bekannte_felder_schreibt_nichts(): void
    {
        $ma = $this->mitarbeiter(['city' => 'Koeln']);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => 'Bonn'], 'Arbeitskleidung');

        $this->assertSame('Keine Änderungen.', $ergebnis['meldung']);
        $this->assertSame('Koeln', $ma->fresh()->city);
    }

    // -----------------------------------------------------------------
    // Die Waechter-Kaskade blockt VOR dem Schreiben (R15-R18)
    // -----------------------------------------------------------------

    public function test_waechter_blockt_und_es_wird_gar_nichts_geschrieben(): void
    {
        // Endzustandspruefung: auch ein Speichern, das nur den Ort aendert,
        // wird abgewiesen.
        $ma = $this->mitarbeiter(['nationality' => null, 'city' => 'Koeln']);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['city' => 'Bonn']);

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('Staatsangeh', $ergebnis['fehler']);
        $this->assertNull($ergebnis['meldung']);
        $this->assertSame('Koeln', $ma->fresh()->city);
        $this->assertNull($this->frisch($ma)->zas_changed_at, 'Ein geblockter Save darf keinen Marker hinterlassen.');
    }

    public function test_ersthelfer_waechter_blockt_die_arbeitskleidung(): void
    {
        // Der Waechter laeuft auch bei einer Gruppe, die mit ihm nichts zu tun
        // hat — der Rueckfall auf den Datensatz ist im neuen Portal der
        // Normalfall, weil immer nur EINE Gruppe im Formular steht.
        $ma = $this->mitarbeiter(['is_first_aider' => true, 'first_aider_valid_until' => null]);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['shirt_size' => 'L'], 'Arbeitskleidung');

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('Ersthelfer', $ergebnis['fehler']);
        $this->assertNull($ma->fresh()->shirt_size);
    }

    public function test_der_waechter_liest_die_form_vor_dem_datensatz(): void
    {
        // Wer "nein" antwortet, kommt durch — obwohl der Datensatz die Frage
        // noch als unbeantwortet fuehrt.
        $ma = $this->mitarbeiter(['is_main_employer' => null]);

        $ergebnis = (new PortalProfileWriter())->speichere(
            $ma,
            ['is_main_employer' => '0', 'other_employer' => 'Mueller GmbH'],
            'Arbeitgeber',
        );

        $this->assertTrue($ergebnis['ok']);
        $this->assertFalse($ma->fresh()->is_main_employer);
        $this->assertSame('Mueller GmbH', $ma->fresh()->other_employer);
    }

    // -----------------------------------------------------------------
    // C1 — ein ausdrueckliches null ist ein geleertes Feld, keine Auslassung
    // -----------------------------------------------------------------
    //
    // Die beiden Haelften des Schreibwegs lasen denselben Wert verschieden:
    // die Waechter mit ?? (Schluessel mit Wert null gilt als NICHT uebergeben
    // und faellt auf den Datensatz zurueck), die Schreibschleife mit
    // array_key_exists (derselbe Wert gilt als uebergeben und schreibt NULL).
    // Damit kam ein null an jedem Waechter vorbei und leerte die Spalte.
    // Mit '' war alles richtig -- deshalb war das Loch unsichtbar.

    public function test_ein_ausdrueckliches_null_umgeht_den_nationalitaets_waechter_nicht(): void
    {
        // Der teuerste der drei: nationality steht in
        // RELEVANT_EMPLOYEE_FIELDS. Eine geleerte Nation haette den Marker
        // gesetzt, und die naechste Aktualisierungsdatei haette den in ZAS
        // gepflegten Wert mit einer leeren Zelle ueberschrieben.
        $ma = $this->mitarbeiter(['nationality' => 'deutsch']);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['nationality' => null], 'Adresse');

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('Staatsangeh', (string) $ergebnis['fehler']);
        $this->assertSame('deutsch', $ma->fresh()->nationality);
        $this->assertNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_ein_ausdrueckliches_null_erzeugt_keinen_ersthelfer_ohne_datum(): void
    {
        // R15 verhindert den Zustand "Ersthelfer=Ja ohne Datum". Ueber ein
        // null waere er DURCH das Speichern entstanden.
        $ma = $this->mitarbeiter([
            'is_first_aider'                  => true,
            'first_aider_valid_until'         => '2027-01-01',
            'first_aider_certificate_file_id' => 99,
        ]);

        $ergebnis = (new PortalProfileWriter())->speichere(
            $ma,
            ['first_aider_valid_until' => null],
            'Arbeitsschutz',
        );

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('Ersthelfer', (string) $ergebnis['fehler']);
        $this->assertSame('2027-01-01', $ma->fresh()->first_aider_valid_until?->format('Y-m-d'));
    }

    public function test_ein_ausdrueckliches_null_setzt_die_pflichtantwort_nicht_zurueck(): void
    {
        // Sonst stuende die Arbeitgeber-Frage wieder auf "unbeantwortet",
        // waehrend die Oberflaeche "Gespeichert." meldet.
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => null], 'Arbeitgeber');

        $this->assertFalse($ergebnis['ok']);
        $this->assertStringContainsString('Hauptarbeitgeber', (string) $ergebnis['fehler']);
        $this->assertTrue($ma->fresh()->is_main_employer);
    }

    public function test_ein_null_in_einem_freien_feld_leert_es_wie_ein_leerstring(): void
    {
        // Gegenprobe: die Normalisierung darf nicht das Leeren an sich
        // verhindern -- nur das Leeren an den Waechtern vorbei.
        $ma = $this->mitarbeiter(['bank_institute' => 'Sparkasse']);

        $ergebnis = (new PortalProfileWriter())->speichere($ma, ['bank_institute' => null], 'Bankdaten');

        $this->assertTrue($ergebnis['ok']);
        $this->assertNull($ma->fresh()->bank_institute);
    }

    // -----------------------------------------------------------------
    // I3 — ein unbekannter Gruppenname ist ein Fehler, kein Nichts
    // -----------------------------------------------------------------

    public function test_ein_unbekannter_gruppenname_meldet_einen_fehler(): void
    {
        // Ein Tippfehler im Aufruf ("Steuer und Versicherung" statt
        // "Steuer & Versicherung") haette sonst eine leere Reichweite ergeben:
        // nichts geschrieben, Rueckgabe ok=true, "Keine Änderungen." -- der
        // Mensch sieht eine unauffaellige Meldung, seine Steuer-ID ist weg.
        $ma = $this->mitarbeiter(['steuer_id' => '12345678901']);

        $ergebnis = (new PortalProfileWriter())->speichere(
            $ma,
            ['steuer_id' => '99999999999'],
            'Steuer und Versicherung',
        );

        $this->assertFalse($ergebnis['ok']);
        $this->assertNotNull($ergebnis['fehler']);
        $this->assertSame('12345678901', $ma->fresh()->steuer_id);
    }

    public function test_eine_gruppe_die_dieser_mensch_nicht_hat_meldet_ebenfalls(): void
    {
        // Nicht nur Tippfehler: die Gruppen sind vom Datensatz abhaengig.
        // "Aufenthalt (Non-EU)" gibt es fuer einen EU-Buerger nicht -- wird
        // sie trotzdem gespeichert, ist etwas auseinandergelaufen.
        $ma = $this->mitarbeiter(['is_eu_citizen' => true]);

        $ergebnis = (new PortalProfileWriter())->speichere(
            $ma,
            ['work_permit_valid_until' => '2027-01-01'],
            'Aufenthalt (Non-EU)',
        );

        $this->assertFalse($ergebnis['ok']);
        $this->assertNull($ma->fresh()->work_permit_valid_until);
    }

    // -----------------------------------------------------------------
    // N7 — Leerraum raus (Clara 28.08.2026)
    // -----------------------------------------------------------------

    public function test_leerraum_wird_aus_steuer_id_und_sv_nummer_entfernt(): void
    {
        // Faellt ueber die Eloquent-Mutatoren von selbst an. Genau deshalb
        // schreibt dieser Weg nicht ueber den Query Builder.
        $ma = $this->mitarbeiter();

        (new PortalProfileWriter())->speichere($ma, [
            'steuer_id'                 => '12 345 678 901',
            'sozialversicherungsnummer' => '65 170 839 K 003',
        ]);

        $this->assertSame('12345678901', $ma->fresh()->steuer_id);
        $this->assertSame('65170839K003', $ma->fresh()->sozialversicherungsnummer);
    }

    // -----------------------------------------------------------------
    // N1 — ZAS-Export-Marker
    // -----------------------------------------------------------------

    public function test_relevantes_feld_setzt_den_zas_marker(): void
    {
        $ma = $this->mitarbeiter();

        (new PortalProfileWriter())->speichere($ma, ['city' => 'Bonn']);

        $this->assertNotNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_auch_ein_ja_nein_feld_aus_der_liste_setzt_den_marker(): void
    {
        $ma = $this->mitarbeiter();

        (new PortalProfileWriter())->speichere($ma, ['has_car' => '1'], 'Sonstiges');

        $this->assertNotNull($this->frisch($ma)->zas_changed_at);
    }

    // -----------------------------------------------------------------
    // §3.2 — die fuenf Spalten mit Marker-VERBOT, einzeln gemessen
    // -----------------------------------------------------------------

    public function test_telefon_setzt_keinen_marker(): void
    {
        // Vorfall Katona RG999999: fuehrend ist ZAS, ein Rueck-Export wuerde
        // per PNr-Match dortige Akten ueberschreiben.
        $ma = $this->mitarbeiter(['phone' => '+4917612345678']);

        (new PortalProfileWriter())->speichere($ma, ['phone' => '+4917699999999'], 'Kontakt');

        $this->assertSame('+4917699999999', $ma->fresh()->phone);
        $this->assertNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_hauptarbeitgeber_setzt_keinen_marker(): void
    {
        // Vorfall 02.09.2026: unsere Aktualisierungsdatei liefert VOLLE
        // ZEILEN — ein Marker wuerde die in ZAS gepflegte Akte ueberschreiben.
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '0', 'other_employer' => 'Mueller GmbH'], 'Arbeitgeber');

        $this->assertFalse($ma->fresh()->is_main_employer);
        $this->assertNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_anderer_arbeitgeber_setzt_keinen_marker(): void
    {
        $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Alt GmbH']);

        (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '0', 'other_employer' => 'Neu GmbH'], 'Arbeitgeber');

        $this->assertSame('Neu GmbH', $ma->fresh()->other_employer);
        $this->assertNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_erstbescheinigung_datei_setzt_keinen_marker(): void
    {
        // Diese beiden Spalten erreicht der Schreibweg gar nicht (R20: 'file'
        // wird uebersprungen). Gemessen wird trotzdem der Eloquent-Weg, denn
        // das ist die Stelle, an der ProofWriter und die HR-Akte schreiben —
        // der Schutz sitzt in der Feldliste, nicht in der Schreibart.
        $ma = $this->mitarbeiter();

        $ma->update(['erstbescheinigung_file_id' => 4711]);

        $this->assertNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_ersthelfer_schein_datei_setzt_keinen_marker(): void
    {
        $ma = $this->mitarbeiter();

        $ma->update(['first_aider_certificate_file_id' => 4712]);

        $this->assertNull($this->frisch($ma)->zas_changed_at);
    }

    public function test_die_verbotenen_spalten_stehen_nicht_in_der_feldliste(): void
    {
        // Die Absicht neben der Messung — wer eine der fuenf spaeter
        // aufnimmt, faellt hier auf und muss die Frage an ZAS geklaert haben.
        foreach ([
            'phone',
            'is_main_employer',
            'other_employer',
            'erstbescheinigung_file_id',
            'first_aider_certificate_file_id',
        ] as $spalte) {
            $this->assertNotContains($spalte, RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS, $spalte);
        }
    }

    // -----------------------------------------------------------------
    // N2 — Lohn-Trigger (§3.3)
    // -----------------------------------------------------------------

    public function test_wechsel_beim_hauptarbeitgeber_setzt_den_lohn_trigger(): void
    {
        // is_main_employer steht in
        // RecApplicantSettings::DEFAULT_SETTINGS['employee_payroll_tracked_fields'].
        // Das alte Portal loest ihn aus, das neue tat es nicht, weil es ueber
        // den Query Builder schrieb. Genau diese Abweichung schliesst der
        // gemeinsame Schreibweg.
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '0', 'other_employer' => 'Mueller GmbH'], 'Arbeitgeber');

        $frisch = $this->frisch($ma);
        $this->assertNotNull($frisch->payroll_data_changed_at);
        $this->assertStringContainsString('is_main_employer', (string) $frisch->payroll_data_changed_fields);
        // ... und trotzdem kein ZAS-Marker.
        $this->assertNull($frisch->zas_changed_at);
    }

    public function test_die_erste_antwort_ist_eine_erstbefuellung_und_loest_nichts_aus(): void
    {
        // RecEmployeeExportObserver::trackPayrollChanges: old === null zaehlt
        // nicht. Gilt im alten wie im neuen Portal — hier festgehalten, damit
        // niemand den fehlenden Trigger spaeter fuer einen Fehler haelt.
        $ma = $this->mitarbeiter(['is_main_employer' => null]);

        (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '1'], 'Arbeitgeber');

        $this->assertTrue($ma->fresh()->is_main_employer);
        $this->assertNull($this->frisch($ma)->payroll_data_changed_at);
    }

    public function test_auch_der_wechsel_zurueck_meldet_sich(): void
    {
        // Gegenprobe zur Erstbefuellung: nein -> ja ist ein Wechsel.
        $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Mueller GmbH']);

        (new PortalProfileWriter())->speichere($ma, ['is_main_employer' => '1'], 'Arbeitgeber');

        $this->assertNotNull($this->frisch($ma)->payroll_data_changed_at);
    }

    public function test_erstbefuellung_der_bankdaten_loest_ebenfalls_nichts_aus(): void
    {
        // Dieselbe Regel jenseits des Ja/Nein-Feldes: leer -> Wert ist keine
        // Aenderung, sonst flutete jedes Onboarding die Lohnliste.
        $ma = $this->mitarbeiter(['iban' => null]);

        (new PortalProfileWriter())->speichere($ma, ['iban' => 'DE02120300000000202051'], 'Bankdaten');

        $this->assertNull($this->frisch($ma)->payroll_data_changed_at);
    }

    public function test_wechsel_der_bankdaten_meldet_sich(): void
    {
        $ma = $this->mitarbeiter(['iban' => 'DE02120300000000202051']);

        (new PortalProfileWriter())->speichere($ma, ['iban' => 'DE02500105170137075030'], 'Bankdaten');

        $frisch = $this->frisch($ma);
        $this->assertNotNull($frisch->payroll_data_changed_at);
        $this->assertStringContainsString('iban', (string) $frisch->payroll_data_changed_fields);
    }

    // -----------------------------------------------------------------
    // N5 — CRM-Telefonabgleich (Vorfall RG19734)
    // -----------------------------------------------------------------

    public function test_telefonabgleich_laeuft_bei_einer_neuen_nummer(): void
    {
        // Ohne ihn ordnet der WhatsApp-Eingang Antworten von der neuen Nummer
        // keinem Kontakt mehr zu, und der Chat der Person bleibt leer.
        $ma = $this->mitarbeiter(['phone' => '+4917612345678']);

        (new PortalProfileWriter())->speichere($ma, ['phone' => '+4917699999999'], 'Kontakt');

        $this->assertSame([$ma->id], self::$telefonAbgleich->gerufenFuer);
    }

    public function test_ohne_telefonaenderung_laeuft_kein_abgleich(): void
    {
        $ma = $this->mitarbeiter(['phone' => '+4917612345678']);

        (new PortalProfileWriter())->speichere($ma, ['city' => 'Bonn'], 'Adresse');

        $this->assertSame([], self::$telefonAbgleich->gerufenFuer);
    }
}
