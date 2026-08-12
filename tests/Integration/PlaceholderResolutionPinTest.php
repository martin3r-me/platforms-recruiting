<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;

/**
 * Nagelt die Platzhalter-Aufloesung der BESTANDSVORLAGEN fest
 * (RecContractTemplate::personalizeContent() / resolveSource()).
 *
 * WOGEGEN: ContractPdfRegressionTest friert das AUSSEHEN des Vertrags-PDFs
 * ein (Seitenzahl, Fontliste, Stylesheet-Hash, Stempel) und sagt selbst, dass
 * es keine Assertion auf den TEXT hat. Dieser Test friert die WERTE ein. Er
 * muss gruen sein, BEVOR ein neuer Zweig (z.B. `schulung.`) in
 * resolveSource() haengt: der bisherige Schutz war das Argument "keine
 * Bestandsvorlage benutzt das neue Praefix, also ist der neue Zweig fuer sie
 * unerreichbar". Das ist heute wahr — und bleibt wahr, bis jemand im
 * Vorlagen-Editor ein Mapping tippt. Ein Argument ueber Daten, die HR selbst
 * aendern kann, ist kein Schutz.
 *
 * ---------------------------------------------------------------------------
 * DIE FESTGENAGELTEN MAPPINGS — mechanisch aus den LIVE-Vorlagen gezogen
 * (Team 3 / RHEINGEDECK-HR, MCP `recruiting.contract_templates.GET`,
 * Stand 2026-08-12), nicht aus dem Gedaechtnis:
 *
 *   Vorlagen: 11 (AV-default, AT-140, AV, IFSG, AV-010, AV-060, AV-110,
 *                 AV-160, AV-210, AV-260, AV-TEST)
 *
 *   Alle verwendeten Quellen, sortiert, mit Anzahl der Vorlagen:
 *      1x  applicant.extra_field.ausweisnummer
 *      9x  applicant.extra_field.geburtsort
 *      1x  applicant.extra_field.nationalitaet
 *      1x  applicant.zuschlag
 *     11x  contact.address.city
 *     10x  contact.address.house_number
 *     11x  contact.address.postal_code
 *     11x  contact.address.street
 *     11x  contact.birth_date
 *     11x  contact.first_name
 *     11x  contact.last_name
 *      1x  contract.extra_field.stundenlohn
 *      9x  contract.extra_field.vertragsbeginn
 *      9x  contract.extra_field.vertragsende
 *      1x  contract.extra_field.zuschlag
 *     11x  meta.datum_heute
 *      8x  settings.minimum_wage_hourly
 *
 *   Distinkte Quellen: 17
 *   Distinkte Praefixe: applicant. contact. contract. meta. settings.
 *   schulung.* in Benutzung: NEIN
 *
 * ---------------------------------------------------------------------------
 * ZWEIG-REIHENFOLGE in resolveSource() — sie ist Teil des Verhaltens, der
 * erste passende Zweig gewinnt (Zeilennummern gegen den Stand dieses
 * Commits):
 *
 *   contact.               (:110, darin address. :126, Carbon → d.m.Y :131)
 *   applicant.             (:139, darin extra_field. :142 mit
 *                           Lookup-Label-Zweig :145-160, ISO-Datum → d.m.Y
 *                           :168-175, zuschlag → number_format :178-180)
 *   contract.extra_field.  (:243, NUR wenn ein $contract uebergeben ist)
 *   settings.              (:248, float → number_format, bool → ja/nein)
 *   text:                  (:264)
 *   meta.                  (:268, datum_heute → d.m.Y, ort → '')
 *   danach                 return '' (:277)
 *
 * ---------------------------------------------------------------------------
 * NICHT FESTGENAGELT (bewusst, kein Versehen):
 *
 *  - `contact.email` / `contact.phone` (:117/:122) und `text:` (:264): in
 *    KEINER der 11 Live-Vorlagen benutzt. Dieser Test sichert den Bestand,
 *    nicht die Vollstaendigkeit der Methode.
 *  - Der Fallback `settings.<key>` auf RecApplicantSettings::DEFAULT_SETTINGS
 *    (:251). Er ist erreichbar, aber ihn festzunageln hiesse einen
 *    KONFIGURATIONSWERT (z.B. minimum_wage_hourly = 13.90) in einen Test zu
 *    schreiben; eine legitime HR-Aenderung waere dann ein roter Test.
 *    Festgenagelt wird stattdessen die FORMATIERUNG gegen einen explizit
 *    gesetzten Settings-Wert — das ist der Teil, der beim Umbau bricht.
 *
 * BEFUND, der beim Schreiben dieses Tests herauskam (Bestand, kein Bug-Fix
 * hier): `contract.extra_field.*` formatiert NICHT um. Die Live-Werte von
 * vertragsbeginn/vertragsende sind ISO-Strings ("2026-08-01", gemessen an
 * rec_contract 451), und der Zweig :243-246 gibt sie roh aus — waehrend der
 * applicant.extra_field.-Zweig genau solche Strings zu d.m.Y umformt. Im
 * Vertragstext steht damit ein ISO-Datum. Test 9 nagelt dieses Verhalten
 * fest, wie es ist; wer es aendern will, aendert es bewusst.
 *
 * ---------------------------------------------------------------------------
 * ABWEICHUNG VOM TASK-BRIEF (Fixture-Aufbau), begruendet:
 *
 *  1. Das Schema kommt komplett aus den ECHTEN Migrationen, auch fuer
 *     rec_contract_templates — TestSchema::contractTemplates() wird NICHT
 *     benutzt. Grund: die Basis-Migration 2026_04_15_100000 erzeugt
 *     rec_contract_templates UND rec_contracts in einem Aufruf. Dieser Test
 *     braucht beide Tabellen. TestSchema zuerst aufzurufen laesst die echte
 *     Migration mit "table already exists" scheitern, danach ist sie wegen
 *     ihres hasTable-Guards ein No-Op. Die echten Migrationen sind hier also
 *     die einzige widerspruchsfreie Quelle — und die strengere: die
 *     type-Spalte kommt aus 2026_08_12_000001, nicht aus einer Testkopie.
 *
 *  2. Fremdpakete werden per Reflection auf eine ihrer geladenen Klassen
 *     aufgeloest, nicht per Aufwaertssuche wie in DuplicateMatchQueryTest.
 *     Grund: platform-core liegt NICHT als Geschwister von platform-crm und
 *     platforms-recruiting unter platform/modules, sondern eine Ebene
 *     darueber — die inhaltsbasierte Geschwistersuche findet es nicht. Der
 *     Reflection-Weg liefert ausserdem garantiert dieselbe Kopie, aus der
 *     der Autoloader die Modelle laedt (am 2026-08-12 per `diff -rq`
 *     geprueft: die Migrationen in meingedeck/vendor und in den
 *     Arbeitsbaeumen von platform-core und platform-crm sind identisch).
 *     Das EIGENE Modul kommt weiter aus dirname(__DIR__, 2), damit ein
 *     Worktree seine eigenen Migrationen testet.
 *
 * Fixtures loeschen nichts zwischen den Tests, sondern legen pro Test neue
 * Zeilen an. Das ist Absicht: HasExtraFields cacht Definitionen statisch
 * unter "Klasse:id" — mit wiederverwendeten IDs nach einem delete() wuerde
 * ein Test den Definitionssatz eines anderen sehen.
 */
class PlaceholderResolutionPinTest extends TestCase
{
    private const TEAM = 3;

    /** Eigene Teams fuer die Settings-Faelle: rec_applicant_settings.team_id ist unique. */
    private const TEAM_SETTINGS_FLOAT = 91;
    private const TEAM_SETTINGS_BOOL = 92;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        // LogsActivity-Trait (CrmContact) verlangt config(); Events leer = keine Hooks.
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher feuern die creating-Hooks der Modelle nicht (uuid,
        // public_token) — das echte Schema verlangt sie als NOT NULL.
        $capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

        // Defensiv, gleiche Begruendung wie in ContractTemplateTypeInvariantsTest:
        // Model::$booted ist prozessweit statisch. Hat eine frueher laufende
        // Testklasse eines dieser Modelle ohne Dispatcher gebootet, sind dessen
        // creating-Hooks toter Code — und das Insert bricht an der uuid-Spalte.
        Model::clearBootedModels();

        // CrmContactLink::creating ruft auth()->check() — Stub ohne User.
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });

        // Schema-/DB-Facades auf Capsule verdrahten, damit die ECHTEN
        // Migrations-Dateien unveraendert laufen koennen (und damit
        // ZasLookupResolver seine DB-Facade findet).
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        // PFLICHT, nicht Deko: Facade::$resolvedInstance ist prozessweit
        // statisch und wird von setFacadeApplication() NICHT geleert.
        // DuplicateMatchQueryTest bindet dieselben Keys ('db', 'db.schema')
        // an SEINE Capsule; wer danach laeuft, bekommt beim Zugriff auf
        // DB::/Schema:: dessen zwischengespeicherte Instanzen — also eine
        // FREMDE in-memory-Datenbank. Gemessen im Gesamtlauf (nie im
        // gefilterten): "no such table: core_extra_field_definitions",
        // weil Schema:: und DB:: auf verschiedenen Verbindungen landeten.
        Facade::clearResolvedInstances();

        self::runRealMigrations();
    }

    /**
     * Hinter sich aufraeumen — gleiche Begruendung wie
     * ContractPdfRegressionTest::tearDownAfterClass(): diese Klasse bootet
     * Modelle gegen ihren eigenen Dispatcher. Ohne clearBootedModels() erben
     * spaeter laufende Testklassen sie als "gebootet" und binden ihre Hooks
     * an einen Dispatcher, der auf eine geschlossene in-memory-DB zeigt.
     */
    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        // Symmetrisch zum Aufraeumen oben: keine Facade-Instanz auf die
        // hier geschlossene in-memory-DB fuer nachfolgende Testklassen
        // hinterlassen.
        Facade::clearResolvedInstances();
    }

    // -----------------------------------------------------------------
    // Die 13 Faelle
    // -----------------------------------------------------------------

    /**
     * FALL 1 — der wertvollste Fall dieses Tests, deshalb zuerst.
     *
     * personalizeContent() ersetzt NUR die gemappten Platzhalter und laesst
     * alles andere unangetastet stehen. Die AT-140-Logik
     * (Support/ResttagePlaceholder) baut genau darauf: {{resttage}} ist in
     * der Live-Vorlage AT-140 bewusst NICHT gemappt (die Vorlagen-
     * Beschreibung sagt das ausdruecklich) und wird erst beim Unterschreiben
     * durch die Eingabe des Bewerbers gefuellt.
     *
     * Wer personalizeContent() spaeter "aufraeumt" und unbekannte
     * Platzhalter leert, bricht den Zusatzvertrag STILL: ein Vertrag, dem
     * die Zahl fehlt, ohne Fehlermeldung, ohne Log. Genau die Sorte
     * Annahme, die nirgends steht, weil sie immer galt.
     */
    public function test_nicht_gemappter_platzhalter_bleibt_unveraendert_stehen(): void
    {
        $applicant = $this->applicantWithContact(['first_name' => 'Marie']);

        $rendered = $this->render(
            '<p>Ich habe noch {{resttage}} Tage. Gruss {{kontakt_vorname}}</p>',
            ['kontakt_vorname' => 'contact.first_name'],
            $applicant
        );

        $this->assertSame(
            '<p>Ich habe noch {{resttage}} Tage. Gruss Marie</p>',
            $rendered,
            '{{resttage}} ist in AT-140 bewusst nicht gemappt und MUSS stehen bleiben; '
            . 'nur gemappte Platzhalter werden ersetzt.'
        );
    }

    /** FALL 2 — contact.first_name / contact.last_name aus dem verknuepften CRM-Kontakt. */
    public function test_contact_vorname_und_nachname_kommen_aus_dem_crm_kontakt(): void
    {
        $applicant = $this->applicantWithContact([
            'first_name' => 'Marie',
            'last_name' => 'van Ackeren',
        ]);

        $rendered = $this->render(
            '{{kontakt_vorname}}|{{kontakt_nachname}}',
            ['kontakt_vorname' => 'contact.first_name', 'kontakt_nachname' => 'contact.last_name'],
            $applicant
        );

        $this->assertSame('Marie|van Ackeren', $rendered, 'Vor- und Nachname kommen unveraendert aus dem CRM-Kontakt.');
    }

    /**
     * FALL 3 — contact.birth_date ist ein Carbon (cast 'date' auf CrmContact)
     * und muss als d.m.Y herauskommen, nicht als ISO-String.
     */
    public function test_contact_geburtsdatum_wird_als_d_m_Y_formatiert(): void
    {
        $applicant = $this->applicantWithContact(['birth_date' => '1998-03-07']);

        $rendered = $this->render(
            '{{kontakt_geburtsdatum}}',
            ['kontakt_geburtsdatum' => 'contact.birth_date'],
            $applicant
        );

        $this->assertSame('07.03.1998', $rendered, 'Carbon muss als d.m.Y formatiert werden, nicht als 1998-03-07.');
    }

    /**
     * FALL 4 — die vier Adressfelder aus der primaeren Postadresse,
     * und der Fall ohne Adresse: leerer String, keine Exception.
     */
    public function test_contact_adressfelder_aus_primaeradresse_und_leer_ohne_adresse(): void
    {
        $mappings = [
            'kontakt_strasse' => 'contact.address.street',
            'kontakt_hausnr' => 'contact.address.house_number',
            'kontakt_plz' => 'contact.address.postal_code',
            'kontakt_ort' => 'contact.address.city',
        ];
        $content = '{{kontakt_strasse}}|{{kontakt_hausnr}}|{{kontakt_plz}}|{{kontakt_ort}}';

        $mitAdresse = $this->applicantWithContact([], [
            'street' => 'Bahnstrasse',
            'house_number' => '12a',
            'postal_code' => '41061',
            'city' => 'Moenchengladbach',
        ]);

        $this->assertSame(
            'Bahnstrasse|12a|41061|Moenchengladbach',
            $this->render($content, $mappings, $mitAdresse),
            'Die vier Adressfelder kommen aus der primaeren Postadresse.'
        );

        $ohneAdresse = $this->applicantWithContact();

        $this->assertSame(
            '|||',
            $this->render($content, $mappings, $ohneAdresse),
            'Ohne Postadresse liefert jedes Adressfeld einen leeren String.'
        );
    }

    /** FALL 5 — applicant.extra_field.geburtsort als Text (Live-Definition: type=text, options=[]). */
    public function test_applicant_extra_field_geburtsort_kommt_als_text(): void
    {
        $applicant = $this->applicantWithContact();
        $this->definition('geburtsort_pin', 'text', [], RecApplicant::class);
        $applicant->setExtraField('geburtsort_pin', 'Duisburg');

        $rendered = $this->render(
            '{{kontakt_geburtsort}}',
            ['kontakt_geburtsort' => 'applicant.extra_field.geburtsort_pin'],
            $applicant
        );

        $this->assertSame('Duisburg', $rendered, 'Text-Extra-Field kommt unveraendert durch.');
    }

    /**
     * FALL 6 — Lookup-Feld: im Dokument muss das LABEL stehen, nicht der
     * Maschinenwert. Fixture 1:1 nach der Live-Definition (nationalitaet,
     * type=lookup, options.lookup_id=10, Lookup "geburtsland" mit
     * tr → Tuerkei).
     *
     * Der Labeltext IST der Punkt dieses Falls: ein Test auf "nicht leer"
     * bliebe gruen, wenn "tr" im Arbeitsvertrag landet.
     */
    public function test_applicant_extra_field_mit_lookup_liefert_das_label(): void
    {
        $applicant = $this->applicantWithContact();
        $lookupId = $this->lookup('geburtsland_pin', ['de' => 'Deutschland', 'tr' => 'Türkei']);
        $this->definition('nationalitaet_pin', 'lookup', ['lookup_id' => $lookupId], RecApplicant::class);
        $applicant->setExtraField('nationalitaet_pin', 'tr');

        $rendered = $this->render(
            '{{nationalitaet}}',
            ['nationalitaet' => 'applicant.extra_field.nationalitaet_pin'],
            $applicant
        );

        $this->assertSame('Türkei', $rendered, 'Lookup-Felder muessen als Label im Dokument stehen, nicht als Maschinenwert "tr".');
    }

    /**
     * FALL 7 — dasselbe Roh-Datum ("tr") in einem Feld OHNE Lookup-Definition
     * bleibt unveraendert. Belegt, dass der Label-Zweig ausschliesslich bei
     * echten Lookup-Feldern greift und nicht global uebersetzt.
     */
    public function test_applicant_extra_field_ohne_lookup_definition_bleibt_rohwert(): void
    {
        $applicant = $this->applicantWithContact();
        $this->definition('nationalitaet_freitext_pin', 'text', [], RecApplicant::class);
        $applicant->setExtraField('nationalitaet_freitext_pin', 'tr');

        $rendered = $this->render(
            '{{nationalitaet}}',
            ['nationalitaet' => 'applicant.extra_field.nationalitaet_freitext_pin'],
            $applicant
        );

        $this->assertSame('tr', $rendered, 'Ohne options.lookup_id darf nichts uebersetzt werden.');
    }

    /**
     * FALL 8 — applicant.zuschlag: nicht die Aufloesung, die FORMATIERUNG ist
     * der Punkt (number_format($v, 2, ',', '.')). Aus 0.6 muss 0,60 werden.
     * Ein Test auf "enthaelt 0" bliebe gruen, wenn daraus 0.6 wird — und 0.6
     * in einem Arbeitsvertrag ist ein Zahlendreher mit Rechtsfolge.
     */
    public function test_applicant_zuschlag_wird_deutsch_mit_zwei_stellen_formatiert(): void
    {
        $applicant = $this->applicantWithContact([], null, ['zuschlag' => 0.6]);

        $rendered = $this->render(
            '{{zuschlag}}',
            ['zuschlag' => 'applicant.zuschlag'],
            $applicant
        );

        $this->assertSame('0,60', $rendered, 'Zuschlag ist ein Geldbetrag: deutsches Dezimalkomma, zwei Stellen.');
    }

    /**
     * FALL 9 — contract.extra_field.*: Wert aus dem UEBERGEBENEN Contract,
     * und ohne Contract greift der Zweig nicht (:243 verlangt "&& $contract").
     *
     * Der Wert wird ROH ausgegeben — der ISO-String bleibt ISO (siehe BEFUND
     * im Klassen-Docblock). Live gespeichert wird genau so (rec_contract 451:
     * vertragsbeginn = "2026-08-01").
     */
    public function test_contract_extra_field_nur_mit_contract_und_ohne_umformatierung(): void
    {
        $applicant = $this->applicantWithContact();
        $this->definition('vertragsbeginn_pin', 'date', null, RecContract::class);

        $template = $this->template(
            '{{vertragsbeginn}}',
            ['vertragsbeginn' => 'contract.extra_field.vertragsbeginn_pin']
        );
        $contract = RecContract::create([
            'rec_applicant_id' => $applicant->id,
            'rec_contract_template_id' => $template->id,
            'team_id' => self::TEAM,
            'status' => 'pending',
        ]);
        $contract->setExtraField('vertragsbeginn_pin', '2026-08-01');

        $this->assertSame(
            '2026-08-01',
            $template->personalizeContent($applicant, $contract),
            'Mit Contract kommt der gespeicherte Wert — unveraendert, ohne Umformatierung auf d.m.Y.'
        );

        $this->assertSame(
            '',
            $template->personalizeContent($applicant),
            'Ohne $contract greift der contract.extra_field.-Zweig nicht: leerer String.'
        );
    }

    /**
     * FALL 10 — settings.*: Float-Formatierung (deutsch, zwei Stellen) und
     * der Bool-Fall (true → "ja"). Beides gegen explizit gesetzte
     * Settings-Werte, damit der Test die FORMATIERUNG festnagelt und nicht
     * einen Konfigurationswert (siehe "NICHT FESTGENAGELT" im Docblock).
     */
    public function test_settings_float_deutsch_formatiert_und_bool_als_ja(): void
    {
        RecApplicantSettings::create([
            'team_id' => self::TEAM_SETTINGS_FLOAT,
            'settings' => ['minimum_wage_hourly' => 13.9],
        ]);
        $floatApplicant = $this->applicantWithContact([], null, ['team_id' => self::TEAM_SETTINGS_FLOAT]);

        $this->assertSame(
            '13,90',
            $this->render('{{stundenlohn}}', ['stundenlohn' => 'settings.minimum_wage_hourly'], $floatApplicant),
            'Float-Settings kommen im deutschen Format mit zwei Stellen: 13,90.'
        );

        RecApplicantSettings::create([
            'team_id' => self::TEAM_SETTINGS_BOOL,
            'settings' => ['use_informal_address' => true],
        ]);
        $boolApplicant = $this->applicantWithContact([], null, ['team_id' => self::TEAM_SETTINGS_BOOL]);

        $this->assertSame(
            'ja',
            $this->render('{{duzen}}', ['duzen' => 'settings.use_informal_address'], $boolApplicant),
            'true wird zu "ja" (nicht zu "1").'
        );

        $this->assertSame(
            '',
            $this->render('{{unbekannt}}', ['unbekannt' => 'settings.gibt_es_nicht_pin'], $boolApplicant),
            'Ein Settings-Key, den es weder in der Zeile noch in DEFAULT_SETTINGS gibt, liefert leeren String.'
        );
    }

    /** FALL 11 — meta.datum_heute ist das heutige Datum als d.m.Y. */
    public function test_meta_datum_heute_ist_heute_als_d_m_Y(): void
    {
        $applicant = $this->applicantWithContact();

        $rendered = $this->render('{{datum_heute}}', ['datum_heute' => 'meta.datum_heute'], $applicant);

        $this->assertSame(
            Carbon::now()->format('d.m.Y'),
            $rendered,
            'meta.datum_heute liefert das heutige Datum als d.m.Y.'
        );
    }

    /**
     * FALL 12 — meta.ort ist ein dokumentiertes Dead End und liefert leeren
     * String. Wird es je versehentlich verdrahtet, muss dieser Test rot
     * werden: Vorlagen setzen den Ort heute als festen Text.
     */
    public function test_meta_ort_liefert_leeren_string(): void
    {
        $applicant = $this->applicantWithContact();

        $rendered = $this->render('[{{ort}}]', ['ort' => 'meta.ort'], $applicant);

        $this->assertSame('[]', $rendered, 'meta.ort ist bewusst nicht verdrahtet und liefert leeren String.');
    }

    /**
     * FALL 13 — NEGATIVFALL: ein unbekanntes Praefix liefert leeren String
     * und wirft NICHT. Ohne diesen Fall fiele nicht auf, wenn ein neu
     * hinzugefuegter Zweig die Fallback-Semantik aendert — auf eine
     * Exception oder auf den rohen Quellstring ("voelligUnbekannt.feld" im
     * Vertragstext).
     */
    public function test_unbekanntes_praefix_liefert_leeren_string_und_wirft_nicht(): void
    {
        $applicant = $this->applicantWithContact();

        $rendered = $this->render('[{{x}}]', ['x' => 'voelligUnbekannt.feld'], $applicant);

        $this->assertSame('[]', $rendered, 'Unbekanntes Praefix: leerer String, keine Exception, kein roher Quellstring.');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** @param array<string, string> $mappings */
    private function render(string $content, array $mappings, RecApplicant $applicant, ?RecContract $contract = null): string
    {
        return $this->template($content, $mappings)->personalizeContent($applicant, $contract);
    }

    /** @param array<string, string> $mappings */
    private function template(string $content, array $mappings): RecContractTemplate
    {
        return RecContractTemplate::create([
            'name' => 'Pin-Vorlage',
            'code' => 'PIN',
            'team_id' => self::TEAM,
            'content' => $content,
            'field_mappings' => $mappings,
            'is_active' => true,
        ]);
    }

    /**
     * Bewerber mit verknuepftem CRM-Kontakt (so wie personalizeContent() ihn
     * erwartet: ueber crmContactLinks), optional mit primaerer Postadresse.
     *
     * @param  array<string, mixed>       $contactAttrs
     * @param  array<string, mixed>|null  $address
     * @param  array<string, mixed>       $applicantAttrs
     */
    private function applicantWithContact(array $contactAttrs = [], ?array $address = null, array $applicantAttrs = []): RecApplicant
    {
        $applicant = RecApplicant::create(array_merge([
            'team_id' => self::TEAM,
            'is_active' => true,
        ], $applicantAttrs));

        $contact = CrmContact::create(array_merge([
            'team_id' => self::TEAM,
            'is_active' => true,
            'first_name' => 'Marie',
            'last_name' => 'van Ackeren',
        ], $contactAttrs));

        $applicant->crmContactLinks()->create([
            'contact_id' => $contact->id,
            'team_id' => self::TEAM,
        ]);

        if ($address !== null) {
            $contact->postalAddresses()->create(array_merge([
                // NOT NULL im echten Schema (FK auf crm_address_types).
                'address_type_id' => 1,
                'is_primary' => true,
            ], $address));
        }

        return $applicant;
    }

    /** @param array<string, mixed>|null $options */
    private function definition(string $name, string $type, ?array $options, string $contextType): CoreExtraFieldDefinition
    {
        return CoreExtraFieldDefinition::create([
            'team_id' => self::TEAM,
            // context_id = null → gilt fuer alle Objekte dieses Typs, so wie
            // recruiting:seed-rec-contract-extra-fields es fuer rec_contract
            // anlegt (context_type = FQCN).
            'context_type' => $contextType,
            'context_id' => null,
            'name' => $name,
            'label' => $name,
            'type' => $type,
            'options' => $options,
            'order' => 0,
        ]);
    }

    /**
     * Lookup samt Werten, wie ZasLookupResolver sie erwartet
     * (core_lookups + core_lookup_values). Bewusst per Query-Builder: die
     * CoreLookup-Modelle sind hier nicht gebraucht.
     *
     * @param  array<string, string>  $valueLabels  value => label
     */
    private function lookup(string $name, array $valueLabels): int
    {
        $lookupId = (int) Capsule::table('core_lookups')->insertGetId([
            'team_id' => self::TEAM,
            'name' => $name,
            'label' => $name,
            'is_system' => false,
        ]);

        $order = 0;
        foreach ($valueLabels as $value => $label) {
            Capsule::table('core_lookup_values')->insert([
                'lookup_id' => $lookupId,
                'value' => $value,
                'label' => $label,
                'order' => $order++,
                'is_active' => true,
            ]);
        }

        return $lookupId;
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    /**
     * Schema ausschliesslich aus den ECHTEN Migrationen — ein
     * Prod-Spaltenrename oder Relation-Key-Change schlaegt damit hier auf,
     * statt still gruen durchzulaufen. Begruendung der Wurzel-Aufloesung
     * steht im Klassen-Docblock ("ABWEICHUNG VOM TASK-BRIEF").
     */
    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);
        $crm = self::packageRootOf(CrmContact::class);
        $own = dirname(__DIR__, 2);

        $files = [
            // Core: Extra-Field-Definitionen/-Werte + Lookups (fuer den
            // Lookup-Label-Zweig) inkl. aller Alters darauf.
            [$core, 'database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php'],
            [$core, 'database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php'],
            [$core, 'database/migrations/2026_02_08_120000_add_is_mandatory_to_core_extra_field_definitions_table.php'],
            [$core, 'database/migrations/2026_02_12_000001_add_llm_verification_to_extra_fields.php'],
            [$core, 'database/migrations/2026_02_12_000002_add_auto_fill_to_extra_fields.php'],
            [$core, 'database/migrations/2026_02_12_000003_create_core_lookups_tables.php'],
            [$core, 'database/migrations/2026_02_16_000001_add_visibility_config_to_extra_field_definitions.php'],
            [$core, 'database/migrations/2026_03_19_000001_add_description_to_core_extra_field_definitions_table.php'],
            // CRM: Kontakt, Verknuepfung und die drei von personalizeContent()
            // eager-geladenen Kontaktdaten-Tabellen.
            [$crm, 'database/migrations/2024_01_01_000013_create_crm_postal_addresses_table.php'],
            [$crm, 'database/migrations/2024_01_01_000014_create_crm_phone_numbers_table.php'],
            [$crm, 'database/migrations/2024_01_01_000015_create_crm_email_addresses_table.php'],
            [$crm, 'database/migrations/2024_01_01_000016_create_crm_contacts_table.php'],
            [$crm, 'database/migrations/2024_01_01_000020_create_crm_contact_links_table.php'],
            [$crm, 'database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php'],
            [$crm, 'database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php'],
            // Recruiting (eigenes Modul, eigener Arbeitsbaum): Bewerber,
            // Settings, Vertraege/Vorlagen inkl. type-Spalte.
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php'],
            [$own, 'database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_04_12_000002_add_rec_phase_id_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_04_15_100000_create_rec_contract_tables.php'],
            [$own, 'database/migrations/2026_04_30_000001_add_import_source_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php'],
            [$own, 'database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
        }
    }

    /**
     * Wurzel des Composer-Pakets, aus dem eine geladene Klasse kommt: von
     * ihrer Datei aufwaerts, bis ein Verzeichnis database/migrations
     * enthaelt. Damit stammen Schema und Modelle garantiert aus derselben
     * Kopie, und es wird keine Verzeichnistiefe geraten.
     *
     * Begrenzt auf 10 Ebenen: ohne Obergrenze liefe die Suche bei einem
     * kaputten Layout bis zum Dateisystem-Root.
     */
    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break; // Dateisystem-Root erreicht
            }
            $dir = $parent;
        }

        throw new \RuntimeException(
            'Paketwurzel nicht gefunden: von ' . $class . ' aufwaerts liegt kein '
            . 'Verzeichnis mit database/migrations.'
        );
    }
}
