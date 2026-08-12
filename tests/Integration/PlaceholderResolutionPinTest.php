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
 * DIE ZWEIGE in resolveSource(), in Code-Reihenfolge. Als Anker stehen hier
 * ABSICHTLICH nur die Praefix-STRINGS, keine Zeilennummern: die Strings
 * altern nicht, Zeilennummern bei jedem Task. Hier stand vorher eine
 * Nummernliste, die nach einem einzigen Commit zur Haelfte falsch war,
 * obwohl sie "gegen den Stand dieses Commits" behauptete — und teilweise
 * nachgemessene Doku ist schlechter als durchgehend veraltete, weil sie
 * Verlaesslichkeit vortaeuscht.
 *
 *   'contact.'              darin 'email', 'phone', 'address.';
 *                           sonst Feld am Kontakt (Carbon → d.m.Y)
 *   'applicant.'            darin 'extra_field.': Early-Return bei leer,
 *                           Lookup-Label-Zweig, Carbon → d.m.Y,
 *                           ISO-String → d.m.Y, trim();
 *                           sonst Feld am Bewerber ('zuschlag' →
 *                           number_format)
 *   'contract.extra_field.' NUR wenn ein $contract uebergeben ist; Wert ROH
 *   'settings.'             float → number_format, bool → ja/nein
 *   'text:'                 Literal nach dem Praefix
 *   'meta.'                 'datum_heute' → d.m.Y, alles andere → ''
 *   danach                  return ''
 *
 * WAS DIE REIHENFOLGE BEDEUTET — und was nicht. Der erste passende Zweig
 * gewinnt, aber DIE PRAEFIXE SIND DISJUNKT. Fuer einen neuen Zweig mit einem
 * neuen Praefix (z.B. `schulung.`) ist die Einfuegeposition damit
 * VERHALTENSNEUTRAL. Gemessen: derselbe Zweig ganz oben vor `contact.` und
 * ganz unten vor dem finalen `return ''` laesst diesen Test beide Male gruen.
 * Die frueher hier und im Task-Report behauptete Regel ("nur unerreichbar,
 * wenn NACH settings. und VOR return '' einsortiert") ist damit widerlegt —
 * sie war nie gemessen.
 *
 * Was dieser Test beim Einhaengen des sechsten Zweigs wirklich schuetzt, sind
 * zwei ANDERE Dinge. Beide gemessen rot:
 *
 *  (a) Die BEDINGUNG darf nicht breiter werden als das neue Praefix. Eine zu
 *      greifige Bedingung (`contract.` statt `schulung.`) vor dem
 *      contract.extra_field.-Zweig macht Fall 9 rot.
 *  (b) Die FALLBACK-SEMANTIK. Ein Zweig, der am Ende `return $source` statt
 *      `return ''` hinterlaesst, macht Fall 13 UND Fall 9 rot.
 *
 * Die echte Gefahr ist also nicht die Einfuegeposition, sondern ein UMBAU DES
 * IF-CHAINS: die Kette in ein match() ueberfuehren, Praefixe zu einem
 * gemeinsamen Praefix zusammenziehen, einen frueheren Zweig auf einen
 * Early-Return umstellen. Wer das tut, verschiebt Bedingungen und Fallback
 * mit — und genau dagegen sind (a) und (b) die Sicherung. Fall 7b sichert
 * zusaetzlich, dass ein Early-Return INNERHALB des applicant.-Zweigs die
 * nachgelagerte Formatierung nicht ueberspringt.
 *
 * ---------------------------------------------------------------------------
 * NICHT FESTGENAGELT (bewusst, kein Versehen):
 *
 *  - Der Fallback `settings.<key>` auf RecApplicantSettings::DEFAULT_SETTINGS.
 *    Er ist erreichbar, aber ihn festzunageln hiesse einen
 *    KONFIGURATIONSWERT (z.B. minimum_wage_hourly = 13.90) in einen Test zu
 *    schreiben; eine legitime HR-Aenderung waere dann ein roter Test.
 *    Festgenagelt wird stattdessen die FORMATIERUNG gegen einen explizit
 *    gesetzten Settings-Wert — das ist der Teil, der beim Umbau bricht.
 *  - Das abschliessende `trim()` im applicant.extra_field.-Zweig. Gemessen:
 *    es zu entfernen laesst die Suite gruen. Bekannte Luecke, nicht
 *    geschlossen — ein Extra-Field-Wert mit fuehrendem/nachlaufendem
 *    Leerzeichen ist kein Live-Fall, den ich belegen kann.
 *
 * FRUEHER HIER AUSGELASSEN, jetzt festgenagelt (Faelle 14 und 15):
 * `contact.email`, `contact.phone` und `text:`. Die Begruendung war "in
 * KEINER der 11 Live-Vorlagen benutzt" — also genau die Argumentationsform
 * ueber Daten, die HR selbst aendern kann, die dieser Test oben ausdruecklich
 * als keinen Schutz bezeichnet. Verschaerfend: `contact.email` und
 * `contact.phone` werden HR bzw. dem MCP-Agenten AKTIV als verfuegbare
 * Quellen angeboten (Tools/CreateContractTemplateTool,
 * Tools/UpdateContractTemplateTool). `text:` wird nirgends angeboten, ist
 * aber trivial festzunageln. Gemessen war ohne diese Faelle jede der drei
 * Quellen mutierbar: "email liefert immer ''" und "text:-Zweig entfernt"
 * liessen die Suite gruen.
 *
 * BEFUND, der beim Schreiben dieses Tests herauskam (Bestand, kein Bug-Fix
 * hier): `contract.extra_field.*` formatiert NICHT um. Die Live-Werte von
 * vertragsbeginn/vertragsende sind ISO-Strings ("2026-08-01", gemessen an
 * rec_contract 451), und der Zweig gibt sie roh aus — waehrend der
 * applicant.extra_field.-Zweig genau solche Strings zu d.m.Y umformt. Im
 * Vertragstext steht damit ein ISO-Datum. Beide Seiten der Asymmetrie sind
 * jetzt festgenagelt: Fall 9 die Vertrags-Seite (roh bleibt roh), Fall 7b die
 * Bewerber-Seite (ISO wird d.m.Y). Wer die Asymmetrie spaeter
 * "harmonisiert", muss sich entscheiden, welche Seite er aendert, und wird in
 * JEDER Richtung rot. Vorher war nur die Vertrags-Seite gesichert — die
 * Umformung auf der Bewerber-Seite zu ENTFERNEN war gemessen gruen, und das
 * ist genau die Richtung, in die ein "Aufraeumen" am ehesten laeuft.
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
    // Die Faelle (1-13 plus 7b/7c/14/15; die Nummern sind Lesehilfe, keine
    // Reihenfolge-Garantie — PHPUnit laeuft in Deklarationsreihenfolge)
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
     * FALL 7 — ROHWERT BLEIBT ROHWERT: "tr" in einem Feld OHNE
     * Lookup-Definition kommt unveraendert heraus.
     *
     * Was dieser Fall NICHT belegt, obwohl er das frueher behauptete: "dass
     * der Label-Zweig nicht global uebersetzt". Diese Eigenschaft ist ueber
     * den Codepfad PRINZIPIELL NICHT VERLETZBAR und deshalb auch nicht
     * testbar — ZasLookupResolver::loadLabelMap() liest `options` selbst
     * erneut aus der DB und setzt fuer Nicht-Lookup-Definitionen eine LEERE
     * Map, worauf LookupLabelFormatter::format() auf `$labelMap[$v] ?? $v`
     * zurueckfaellt, also auf den Rohwert. Ein Test kann nur zeigen, was
     * brechen KANN; ein Fall, der eine unverletzbare Eigenschaft "beweist",
     * taeuscht Schutz vor.
     *
     * Der Guard `if ($lookupId)` ist trotzdem load-bearing — aber fuer die
     * REIHENFOLGE, nicht fuer das Label. Genau das nagelt Fall 7b fest.
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

        $this->assertSame('tr', $rendered, 'Ein Wert ohne Lookup-Definition kommt unveraendert durch.');
    }

    /**
     * FALL 7b — der scharfe Zwilling von Fall 7. Ein Textfeld (KEINE
     * Lookup-Definition) mit einem ISO-Datum als Wert muss als d.m.Y
     * herauskommen. Diese eine Assertion sichert zwei Dinge, die je einzeln
     * ungesichert waren:
     *
     *  1. Den Guard `if ($lookupId)` im Lookup-Zweig. Faellt er, kehren
     *     Nicht-Lookup-Werte ueber `return $label;` VORZEITIG zurueck (der
     *     Formatter liefert ja den Rohwert, siehe Fall 7) und erreichen die
     *     nachgelagerte Formatierung nie mehr. Gemessen: Guard auf
     *     `if (true)` mutiert → dieser Fall rot, '2026-08-01' statt
     *     '01.08.2026'. Das ist der echte Zweck, den Fall 7 nur behauptete.
     *  2. Die ISO→d.m.Y-Umformung im applicant.extra_field.-Zweig selbst. Sie
     *     war ueberhaupt nicht festgenagelt: sie KOMPLETT zu entfernen war
     *     gemessen gruen. Sie ist die Bewerber-Seite der Asymmetrie zu
     *     contract.extra_field. (siehe BEFUND im Klassen-Docblock) — wer die
     *     Asymmetrie "harmonisiert", indem er die Umformung ENTFERNT statt
     *     sie drueben zu ergaenzen, wird jetzt rot.
     *
     * Der Fixture-Wert ist bewusst derselbe ISO-String wie in Fall 9
     * ('2026-08-01'): die beiden Faelle stehen damit als Paar da und die
     * gegenlaeufige Erwartung ist beim Lesen sofort sichtbar.
     */
    public function test_applicant_extra_field_iso_datum_wird_auch_ohne_lookup_zu_d_m_Y(): void
    {
        $applicant = $this->applicantWithContact();
        $this->definition('eintritt_freitext_pin', 'text', [], RecApplicant::class);
        $applicant->setExtraField('eintritt_freitext_pin', '2026-08-01');

        $rendered = $this->render(
            '{{eintritt}}',
            ['eintritt' => 'applicant.extra_field.eintritt_freitext_pin'],
            $applicant
        );

        $this->assertSame(
            '01.08.2026',
            $rendered,
            'Ein ISO-String aus einem applicant.extra_field muss zu d.m.Y werden — auch ohne '
            . 'Lookup-Definition, d.h. der Lookup-Zweig darf nicht vorzeitig zurueckkehren.'
        );
    }

    /**
     * FALL 7c — leeres oder gar nicht definiertes applicant.extra_field
     * liefert leeren String. Der Alltagsfall, nicht der Sonderfall: neun der
     * elf Live-Vorlagen mappen applicant.extra_field.geburtsort, und ob der
     * Bewerber es ausgefuellt hat, entscheidet er selbst.
     * Console/Commands/DebugContractFieldResolution hat fuer genau diesen
     * Zustand eigens eine "LEER"-Anzeige.
     *
     * War nicht festgenagelt: den Early-Return statt auf '' auf
     * '[' . $efName . ']' zu mutieren (also "zeig den Feldnamen, damit man den
     * Fehler sieht" — eine plausible, gut gemeinte Aenderung) liess die Suite
     * gemessen gruen. Im Arbeitsvertrag stuende dann "[geburtsort]".
     */
    public function test_applicant_extra_field_leer_oder_undefiniert_liefert_leeren_string(): void
    {
        $applicant = $this->applicantWithContact();
        $this->definition('geburtsort_leer_pin', 'text', [], RecApplicant::class);
        $applicant->setExtraField('geburtsort_leer_pin', '');

        $this->assertSame(
            '[]',
            $this->render(
                '[{{geburtsort}}]',
                ['geburtsort' => 'applicant.extra_field.geburtsort_leer_pin'],
                $applicant
            ),
            'Ein leeres Extra-Field liefert leeren String — nicht den Feldnamen, nicht "null".'
        );

        $this->assertSame(
            '[]',
            $this->render(
                '[{{geburtsort}}]',
                ['geburtsort' => 'applicant.extra_field.gibt_es_gar_nicht_pin'],
                $applicant
            ),
            'Ein Extra-Field ohne Definition liefert ebenfalls leeren String und wirft nicht.'
        );
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

    /**
     * FALL 14 — contact.email und contact.phone. Von keiner der 11
     * Live-Vorlagen benutzt, aber HR und dem MCP-Agenten ALS QUELLE ANGEBOTEN
     * (Tools/CreateContractTemplateTool, Tools/UpdateContractTemplateTool
     * listen sie auf). "Heute nicht benutzt" ist damit kein Argument: der Weg
     * von "nicht benutzt" zu "benutzt" ist ein Klick im Vorlagen-Editor, und
     * dann traegt eine ungetestete Quelle Kontaktdaten in einen
     * Arbeitsvertrag. Gemessen war der email-Zweig ohne diesen Fall auf
     * "liefert immer ''" mutierbar, ohne dass irgendein Test rot wurde.
     *
     * Festgenagelt ist jeweils die AUSWAHLREGEL, nicht nur "nicht leer":
     * bei email gewinnt is_primary ueber die Einfuegereihenfolge, bei phone
     * gewinnt `international` ueber `national` — und faellt international weg,
     * traegt national. Ein Test auf "enthaelt ein @" bliebe gruen, wenn die
     * Zweitadresse im Vertrag landet.
     */
    public function test_contact_email_und_telefon_folgen_der_primaer_und_format_auswahl(): void
    {
        $applicant = $this->applicantWithContact();
        $contact = $this->contactOf($applicant);

        // Nicht-primaere ZUERST: waere is_primary egal, gewaenne diese hier.
        $contact->emailAddresses()->create([
            'email_address' => 'zweitadresse@example.org',
            'is_primary' => false,
            'email_type_id' => 1, // NOT NULL im echten Schema (FK auf crm_email_types)
        ]);
        $contact->emailAddresses()->create([
            'email_address' => 'marie@example.org',
            'is_primary' => true,
            'email_type_id' => 1,
        ]);
        $contact->phoneNumbers()->create([
            'raw_input' => '0151 1234567',
            'international' => '+49 151 1234567',
            'national' => '0151 1234567',
            'is_primary' => true,
            'phone_type_id' => 1, // NOT NULL im echten Schema (FK auf crm_phone_types)
        ]);

        $this->assertSame(
            'marie@example.org|+49 151 1234567',
            $this->render(
                '{{mail}}|{{tel}}',
                ['mail' => 'contact.email', 'tel' => 'contact.phone'],
                $applicant
            ),
            'Die primaere E-Mail gewinnt ueber die Einfuegereihenfolge; beim Telefon gewinnt die '
            . 'internationale Schreibweise.'
        );

        // Zweiter Kontakt ohne international → national traegt.
        $nurNational = $this->applicantWithContact();
        $this->contactOf($nurNational)->phoneNumbers()->create([
            'raw_input' => '0151 7654321',
            'national' => '0151 7654321',
            'is_primary' => true,
            'phone_type_id' => 1,
        ]);

        $this->assertSame(
            '0151 7654321',
            $this->render('{{tel}}', ['tel' => 'contact.phone'], $nurNational),
            'Ohne internationale Schreibweise faellt contact.phone auf national zurueck.'
        );

        $this->assertSame(
            '|',
            $this->render(
                '{{mail}}|{{tel}}',
                ['mail' => 'contact.email', 'tel' => 'contact.phone'],
                $this->applicantWithContact()
            ),
            'Ohne Kontaktdaten liefern beide Quellen leeren String, keine Exception.'
        );
    }

    /**
     * FALL 15 — `text:` gibt den Literal nach dem Praefix aus. Diese Quelle
     * wird HR NICHT angeboten (anders als contact.email/phone), das Restrisiko
     * ist also kleiner — aber "wird nirgends angeboten" ist eine Aussage ueber
     * heutige UI, nicht ueber den Code: das Feld field_mappings ist ein
     * freies JSON, und der Zweig war gemessen ersatzlos entfernbar, ohne dass
     * ein Test rot wurde. Eine Assertion kostet weniger als die Ueberlegung,
     * ob man sie braucht.
     *
     * Mitgenagelt: `text:` schneidet NUR das Praefix ab und laesst den Rest
     * unangetastet — auch Doppelpunkte darin.
     */
    public function test_text_quelle_gibt_den_literal_nach_dem_praefix_aus(): void
    {
        $applicant = $this->applicantWithContact();

        $this->assertSame(
            '[Moenchengladbach, den]',
            $this->render('[{{ort_literal}}]', ['ort_literal' => 'text:Moenchengladbach, den'], $applicant),
            'text: gibt den Rest unveraendert aus.'
        );

        $this->assertSame(
            '[a:b]',
            $this->render('[{{doppelpunkt}}]', ['doppelpunkt' => 'text:a:b'], $applicant),
            'Nur das erste "text:" ist Praefix; weitere Doppelpunkte bleiben Inhalt.'
        );

        $this->assertSame(
            '[]',
            $this->render('[{{leer}}]', ['leer' => 'text:'], $applicant),
            'text: ohne Rest liefert leeren String.'
        );
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

    /**
     * Der verknuepfte CRM-Kontakt eines Fixture-Bewerbers — ueber denselben
     * Weg, den personalizeContent() nimmt (crmContactLinks → contact), damit
     * ein Relation-Key-Change hier genauso auffaellt wie dort.
     */
    private function contactOf(RecApplicant $applicant): CrmContact
    {
        return $applicant->crmContactLinks()->first()->contact;
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
