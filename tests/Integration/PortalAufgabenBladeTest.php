<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Aufgabe 11: das Portal zeigt die offenen Punkte MIT ihrem Einsatz-Bezug —
 * die Huelle (PortalShell::ansichtsDaten() + portal-shell.blade.php) ist der
 * einzige Ort, an dem sichtbar wird, was die zehn Aufgaben davor
 * (OffenePunkte, Aufgabe 8) berechnet haben.
 *
 * GERENDERT, NICHT NUR GELESEN: rendereHuelle() kompiliert das GANZE
 * portal-shell.blade.php mit dem echten BladeCompiler und fuehrt es aus --
 * eine reine Quelltextsuche (Muster PortalShellProfilBladeTest) haette hier
 * nichts ueber das tatsaechlich erzeugte Markup ausgesagt, und genau DAS ist
 * die Zusicherung dieser Klasse (falsche Ebene, siehe Suchraster). Gebunden
 * wird an eine ECHTE PortalShell-Instanz (Closure::bind), weil das Blatt
 * $this->lookupOptionen() ruft (Zeile mit $feld['type'] === 'lookup') --
 * eine Attrappe waere hier die falsche Antwort.
 *
 * HANDGEBAUTES SCHEMA (Modul-Konvention: Migrationen laufen hier NICHT).
 * Nur die Spalten, die OffenePunkte/ProofReader/PersonScopeResolver lesen
 * sowie die, die PortalShell::berechtigterMitarbeiter()/anstellungen()
 * braucht -- eine fehlende Spalte macht SQLite stillschweigend zum
 * String-Literal (reference_pruefmuster_gruenes_nichts, elfte Falle). Alle
 * Spalten der Profil-Feldgruppen (editableFieldGroups()) fehlen bewusst:
 * $employee->getAttribute() liest sie aus dem schon geladenen
 * Attribut-Array, nicht per neuer Abfrage -- eine fehlende Spalte liefert
 * dort schlicht null, keinen SQL-Fehler.
 *
 * ET-12 (Brief-Mangel, vom Auftraggeber selbst geprueft): der vorgegebene
 * vierte Test `test_der_waechter_kennt_jede_neue_eigenschaft` ruft eine
 * Hilfsmethode `assertWeltIstGeschlossen()`, die es nirgends im Modul gibt.
 * Der echte Waechter steht bereits ausgeschrieben in
 * PortalGleichstandTest::test_alles_was_ueber_identitaet_entscheidet_ist_gesperrt
 * (geschlossene Welt ueber ReflectionProperty::IS_PUBLIC) und deckt jede neue
 * oeffentliche Eigenschaft schon ab -- er faellt von selbst, sobald eine
 * dazukommt. Diese Klasse fuegt KEINE neue oeffentliche Eigenschaft hinzu
 * (siehe Bericht), braucht also auch keine eigene, schwaechere Kopie dieses
 * Waechters. Der erfundene Test ist hier bewusst NICHT nachgebaut.
 */
final class PortalAufgabenBladeTest extends TestCase
{
    private const TEAM = 9111;

    private Capsule $capsule;

    private int $naechsteId = 1;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->schemaBauen();

        $this->tmpDir = sys_get_temp_dir() . '/recruiting-aufgaben-blade-' . getmypid() . '-' . uniqid();
        if (!is_dir($this->tmpDir) && !mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            $this->fail('Temp-Verzeichnis nicht anlegbar: ' . $this->tmpDir);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('db');
        $container->forgetInstance('db.schema');
        Facade::clearResolvedInstances();

        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $rest) {
                if (is_file($rest)) {
                    unlink($rest);
                }
            }
            rmdir($this->tmpDir);
        }
        $this->tmpDir = '';

        parent::tearDown();
    }

    private function schemaBauen(): void
    {
        $schema = $this->capsule->schema();

        // Dokumente (Task 1): OffenePunkte liest jetzt auch rec_document_recipients — die Tabellen kommen aus den Migrationen.
        foreach (['2026_10_09_000001_create_rec_documents_table', '2026_10_09_000002_create_rec_document_recipients_table'] as $m) {
            (require dirname(__DIR__, 2) . '/database/migrations/' . $m . '.php')->up();
        }

        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('personnel_number')->nullable();
            $t->string('company')->nullable();
            $t->boolean('is_active')->nullable();
            $t->boolean('is_eu_citizen')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_first_aider')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamps();
        });

        // ProofReader::current() liest hier.
        $schema->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
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

        $schema->create('rec_dispo_events', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->string('einsatz_ref')->unique();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_dispo_assignments', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->string('ds_ref')->unique();
            $t->integer('rec_dispo_event_id')->nullable();
            $t->integer('rec_employee_id')->nullable();
            $t->date('datum');
            $t->unsignedTinyInteger('status_id')->default(0);
            $t->string('taetigkeit')->nullable();
            $t->timestamp('missing_since')->nullable();
            $t->timestamps();
        });
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** Aktiver, umgestellter EU-Buerger-Aushilfe ohne einen einzigen Nachweis. */
    private function mitarbeiterOhneNachweise(array $attr = []): RecEmployee
    {
        $id = (int) DB::table('rec_employees')->insertGetId(array_merge([
            'uuid'            => 'e-' . $this->naechsteId++,
            'team_id'         => self::TEAM,
            'phone'           => '+49151' . str_pad((string) $this->naechsteId++, 8, '0', STR_PAD_LEFT),
            'first_name'      => 'Kevin',
            'last_name'       => 'Muster',
            'is_active'       => true,
            'is_eu_citizen'   => true,
            'employment_type' => 'aushilfe',
            'is_first_aider'  => false,
            'portal_v2_since' => '2026-09-24 00:00:00',
            'created_at'      => '2026-09-01 09:00:00',
            'updated_at'      => '2026-09-01 09:00:00',
        ], $attr));

        return RecEmployee::find($id);
    }

    /**
     * Derselbe Mensch, aber mit einem gueltigen Ausweis -- requiredFor()
     * verlangt bei EU-Buerger/Aushilfe/kein-Ersthelfer NUR 'ausweis'
     * (ProofTypes::requiredFor()), eine weitere Nachweisart ist fuer den
     * leeren Kasten also nicht noetig.
     */
    private function mitarbeiterMitAllenNachweisen(array $attr = []): RecEmployee
    {
        $ma = $this->mitarbeiterOhneNachweise($attr);
        $this->nachweis($ma, 'ausweis', '2030-01-01');

        return $ma->fresh();
    }

    private function nachweis(RecEmployee $employee, string $code, ?string $validUntil = null): void
    {
        DB::table('rec_employee_proofs')->insert([
            'uuid'             => 'pf-' . $this->naechsteId++,
            'team_id'          => self::TEAM,
            'rec_employee_id'  => $employee->id,
            'proof_type_code'  => $code,
            'valid_until'      => $validUntil,
            'created_at'       => '2026-09-01 09:00:00',
            'updated_at'       => '2026-09-01 09:00:00',
        ]);
    }

    private function einbuchung(RecEmployee $employee, array $attr = []): void
    {
        $eventId = (int) DB::table('rec_dispo_events')->insertGetId([
            'uuid'        => 'ev-' . $this->naechsteId++,
            'einsatz_ref' => 'RG-' . $this->naechsteId,
            'name'        => $attr['event_name'] ?? null,
            'created_at'  => '2026-09-01 09:00:00',
            'updated_at'  => '2026-09-01 09:00:00',
        ]);
        unset($attr['event_name']);

        DB::table('rec_dispo_assignments')->insert(array_merge([
            'uuid'               => 'as-' . $this->naechsteId++,
            'ds_ref'             => 'DS-' . $this->naechsteId,
            'rec_dispo_event_id' => $eventId,
            'rec_employee_id'    => $employee->id,
            'datum'              => '2026-10-20',
            'status_id'          => 1,
            'created_at'         => '2026-09-01 09:00:00',
            'updated_at'         => '2026-09-01 09:00:00',
        ], $attr));
    }

    // -----------------------------------------------------------------
    // Rendern
    // -----------------------------------------------------------------

    private function bladeQuelle(): string
    {
        $pfad = dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php';
        $this->assertFileExists($pfad);

        return (string) file_get_contents($pfad);
    }

    private function privat(object $objekt, string $methode, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($objekt, $methode);
        $ref->setAccessible(true);

        return $ref->invoke($objekt, ...$args);
    }

    /**
     * Das GANZE Blatt rendern -- nicht nur einen Ausschnitt. Das Portal
     * mischt im echten Betrieb die oeffentlichen Eigenschaften der
     * Komponente mit den Werten aus ansichtsDaten() (Livewire tut das bei
     * jedem render() automatisch); get_object_vars() liefert von AUSSERHALB
     * der Klasse aufgerufen nur die OEFFENTLICHEN Eigenschaften -- exakt
     * dieselbe Sicht, die Livewire der View gibt.
     *
     * $heute kommt ueber Carbon::setTestNow(), nicht als Parameter an
     * OffenePunkte::fuer(): PortalShell::ansichtsDaten() ruft die Klasse
     * ohne zweites Argument auf (Produktionscode), genau wie der Brief es
     * vorschreibt ("Keine neue oeffentliche Eigenschaft").
     *
     * $duzen default true (der Normalfall im Portal); die Siez-Probe
     * (Nachbesserung, M17) ruft explizit mit false.
     */
    private function rendereHuelle(RecEmployee $ma, string $heute, bool $duzen = true): string
    {
        Carbon::setTestNow($heute);

        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';
        $shell->duzen = $duzen;

        $ansichtsDaten = $this->privat($shell, 'ansichtsDaten');
        $variablen = array_merge(get_object_vars($shell), $ansichtsDaten);

        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $datei = $this->tmpDir . '/huelle-' . uniqid('', true) . '.php';
        // Das Dokument-Blatt (Task 11) bindet <x-ui-input-signature> ein -- eine Core-
        // Komponente, deren Aufloesung den View-Factory des Hosts braucht, den dieser
        // Container-Aufbau nicht hat. Das Blatt rendert hier nie (dokumentBlatt === null),
        // also faellt nur die Tag-Aufloesung beim Kompilieren weg, kein Verhalten.
        $quelle = (string) preg_replace('/<x-ui-input-signature\b[^>]*\/>/s', '', $this->bladeQuelle());
        file_put_contents($datei, $compiler->compileString($quelle));

        $variablen['__env'] = new class {
            use \Illuminate\View\Concerns\ManagesLoops;
        };
        $variablen['__datei'] = $datei;

        $lauf = function (array $__v): string {
            extract($__v);
            ob_start();
            include $__datei;

            return (string) ob_get_clean();
        };

        return \Closure::bind($lauf, $shell, PortalShell::class)($variablen);
    }

    // -----------------------------------------------------------------
    // Die drei vorgegebenen Tests (ohne den erfundenen vierten, siehe
    // Klassen-Docblock ET-12)
    // -----------------------------------------------------------------

    public function test_der_huelle_nennt_den_einsatz_zum_offenen_punkt(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, [
            'datum' => '2026-10-12', 'status_id' => 1, 'taetigkeit' => 'Service',
            'event_name' => 'Messe Duesseldorf',
        ]);

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        $this->assertStringContainsString('Ausweis', $markup);
        // Der Bezug macht aus einer Liste eine Aufforderung.
        // ET-25: volles Jahr, nicht nur 'd.m.' -- ueber den Jahreswechsel
        // waere '12.10.' sonst mehrdeutig, und der Mensch bekommt denselben
        // Tag vorher schon als 'TT.MM.JJJJ' per WhatsApp.
        $this->assertStringContainsString('12.10.2026', $markup);
        // M18 (Nachbesserung): der Projektname ist die halbe Aussage des
        // Features -- "fuer deinen Einsatz am 12.10.2026" allein waere nur
        // die Haelfte von dem, was OffenePunkte::fuer()['einsatz']['event']
        // zusaetzlich zum Datum liefert.
        $this->assertStringContainsString('Messe Duesseldorf', $markup);
    }

    /**
     * M17 (Nachbesserung): "Die Seite duzt" ist eine bindende Vorgabe --
     * geprueft war bisher nur, dass sie beim Normalfall ($duzen=true)
     * stimmt, nie die Gegenprobe. Ein vertauschter Ternary-Zweig
     * ($duzen ? 'Sie' : 'du') waere unentdeckt geblieben, solange nie mit
     * $duzen=false gerendert wurde.
     */
    public function test_die_seite_siezt_auf_wunsch_beim_einsatz_bezug(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->einbuchung($ma, ['datum' => '2026-10-12', 'status_id' => 1, 'taetigkeit' => 'Service']);

        $markup = $this->rendereHuelle($ma, '2026-10-01', duzen: false);

        $this->assertStringContainsString('Für Ihren Einsatz am', $markup);
        $this->assertStringNotContainsString('Für deinen Einsatz am', $markup);
    }

    public function test_ein_ko_punkt_ist_als_solcher_erkennbar(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        // Ein Arbeitsverbot darf nicht aussehen wie ein fehlendes Passfoto.
        $this->assertStringContainsString('Aufenthaltstitel', $markup);
        $this->assertStringContainsString('aufgabe-ko', $markup);
    }

    public function test_ohne_offene_punkte_steht_kein_kasten_da(): void
    {
        $ma = $this->mitarbeiterMitAllenNachweisen();

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        // Wer alles hat, soll nicht jeden Tag einen leeren Kasten sehen.
        $this->assertStringNotContainsString('aufgaben-kasten', $markup);
    }

    // -----------------------------------------------------------------
    // Eigene Ergaenzungen (Mutationsprobe, Step 5 des Briefs + Brief-Hinweis
    // "die Aufgabenzeile muss ohne Bezug tragen koennen")
    // -----------------------------------------------------------------

    /**
     * Gegenprobe zu test_ein_ko_punkt_ist_als_solcher_erkennbar: jener Test
     * prueft nur, DASS 'aufgabe-ko' vorkommt -- eine Mutation, die die
     * ko-Klasse IMMER setzt, bliebe dort unentdeckt gruen. Erst dieser Test
     * (ein Mensch OHNE KO-Punkt) macht die Unterscheidung in BEIDE
     * Richtungen scharf.
     */
    public function test_ein_normaler_punkt_bekommt_keine_ko_klasse(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        $this->assertStringNotContainsString('aufgabe-ko', $markup);
    }

    /**
     * Kein Einsatz storniert/fehlt in der ZAS-Lieferung -> OffenePunkte::fuer()
     * liefert 'einsatz' => null, "und zwar regelmaessig" (Aufgabentext). Der
     * Kasten mit den offenen Punkten muss trotzdem stehen, nur ohne den
     * Bezug-Satz -- das ist kein Randfall, siehe Aufgabentext.
     */
    public function test_der_kasten_traegt_auch_ohne_einsatz(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        $this->assertStringContainsString('aufgaben-kasten', $markup);
        $this->assertStringNotContainsString('aufgaben-bezug', $markup);
    }

    // -----------------------------------------------------------------
    // Nachbesserung (Auftrag des Koordinators): das Sperr-Kennzeichen muss
    // auf KASTEN-Ebene sichtbar sein, nicht nur als Farbnuance an der
    // einzelnen Zeile (aufgabe-ko). "Dir fehlt noch was" ist etwas anderes
    // als "du darfst mit diesem Stand nicht zum Einsatz" -- beide Richtungen
    // geprueft, derselbe Fehlertyp wie beim ko-Negativtest oben (eine
    // Zusicherung nur in EINER Richtung waere wertlos).
    // -----------------------------------------------------------------

    /**
     * Arbeitserlaubnis::istGesperrt() ist wahr, sobald ein KO-Code (hier:
     * 'aufenthaltstitel', weil is_eu_citizen=false) FEHLT oder ABGELAUFEN
     * ist -- derselbe Datensatz wie test_ein_ko_punkt_ist_als_solcher_erkennbar,
     * der die Vorbedingung schon einmal belegt (er faellt unter derselben
     * Fixture bereits auf 'aufgabe-ko').
     */
    public function test_eine_sperre_zeigt_sich_auf_kastenebene(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        $this->assertStringContainsString('aufgaben-sperre', $markup);
        // M19 (Nachbesserung): bei einem Arbeitsverbot ist der SATZ die ganze
        // Information -- ein roter Balken mit Punkt, aber ohne Text, waere
        // bisher unbemerkt gruen geblieben (die Klasse allein reicht nicht).
        $this->assertStringContainsString('nicht zum Einsatz.', $markup);
    }

    /**
     * Gegenprobe: derselbe Mensch wie im allerersten Test (EU-Buerger, nur
     * 'ausweis' offen, kein KO-Code) darf das Sperr-Kennzeichen NICHT sehen
     * -- sonst waere "aufgaben-sperre" ein Textbaustein, der immer mitkommt,
     * und keine Aussage ueber den Zustand.
     */
    public function test_ohne_sperre_erscheint_kein_sperr_hinweis(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        $this->assertStringNotContainsString('aufgaben-sperre', $markup);
    }

    /**
     * "Eine Stilregel laesst sich schlecht testen" (Koordinator) -- das
     * stimmt fuer das AUSSEHEN (Farben, Abstaende, Markenkonformitaet: nur
     * gesehen, nicht gemessen). Was sich sehr wohl messen laesst: dass die
     * vier neuen Klassennamen nicht nur im Blade stehen, sondern auch eine
     * Stilregel in der Stilvorlage HABEN -- sonst erscheint der Kasten
     * ungestaltet, der genaue Befund des Koordinators. Reiner
     * Quelltext-Grep, Muster PortalShellProfilBladeTest.
     */
    public function test_die_neuen_klassen_haben_eine_stilregel(): void
    {
        $stile = (string) file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/layouts/portal-styles.blade.php'
        );

        foreach (['.aufgaben-kasten', '.aufgabe', '.aufgabe-ko', '.aufgaben-bezug', '.aufgaben-sperre'] as $klasse) {
            $this->assertStringContainsString(
                $klasse . '{',
                $stile,
                "Fuer {$klasse} fehlt eine eigene Stilregel -- der Kasten erscheint sonst ungestaltet."
            );
        }
    }

    /**
     * M9/M10 (Nachbesserung): der Test oben bewacht nur EIN Ende der
     * Kopplung -- dass die Stilvorlage die Klasse kennt. Er sagt nichts
     * darueber, ob das BLADE sie wirklich noch so benennt. Wird eine Klasse
     * im Blade umbenannt (`aufgaben-bezug` -> `aufgaben-bezugX`), bleibt die
     * Stilregel unberuehrt gruen, und der Kasten erscheint trotzdem
     * ungestaltet -- derselbe Defekt, von der anderen Seite. Diese Schleife
     * schliesst die zweite Richtung: sie prueft den exakten, im Blade
     * STEHENDEN Textbaustein, nicht nur eine Teilstring-Erwaehnung.
     *
     * Grenzgenau statt bloss `assertStringContainsString($klasse, ...)`:
     * ein Anhaengen am ENDE ('aufgaben-kastenX') waere sonst eine
     * Teilstring-Falle (die laengere Zeichenkette enthaelt die kuerzere) --
     * dieselbe Lehre, die der Pruefer bei M11/M12 gezogen hat. Jede Probe
     * unten verlangt deshalb das Zeichen, das im Original UNMITTELBAR auf
     * den Klassennamen folgt (schliessendes Anfuehrungszeichen bzw.
     * Semikolon) -- ein angehaengtes 'X' schiebt dieses Zeichen weg und
     * macht die Probe scharf in beide Richtungen.
     */
    public function test_die_neuen_klassen_stehen_auch_wirklich_so_im_blade(): void
    {
        $blade = $this->bladeQuelle();

        foreach ([
            'aufgaben-kasten' => 'class="aufgaben-kasten"',
            'aufgaben-bezug'  => 'class="aufgaben-bezug"',
            'aufgaben-sperre' => 'class="aufgaben-sperre"',
            // Keine literale class="aufgabe" im Markup (sie kommt ueber
            // $offenerPunktKlasse) -- bewacht wird deshalb die Stelle, an
            // der der String tatsaechlich entsteht.
            'aufgabe'         => ": 'aufgabe';",
            'aufgabe-ko'      => "'aufgabe aufgabe-ko'",
        ] as $klasse => $beleg) {
            $this->assertStringContainsString(
                $beleg,
                $blade,
                "Die Klasse {$klasse} steht nicht mehr so im Blade wie erwartet -- "
                . 'entweder umbenannt (dann haengt die Stilregel ins Leere) oder entfernt.'
            );
        }
    }

    // -----------------------------------------------------------------
    // Zusammenlegung der beiden Kaesten (Kunde hat den Zuschnitt
    // freigegeben): der Kasten uebernimmt Satz, Farbe und Klickweg des
    // alten Blocks "Das fehlt noch".
    // -----------------------------------------------------------------

    /** Nur der Kasten -- vom Anfang bis zum Beginn der Spalten darunter. */
    private function kasten(string $markup): string
    {
        $start = strpos($markup, 'class="aufgaben-kasten"');
        $this->assertNotFalse($start, 'Kein Kasten im Markup.');
        $ende = strpos($markup, 'class="bcols"', $start);
        $this->assertNotFalse($ende, 'Der Kasten endet nicht vor den Spalten.');

        return substr($markup, $start, $ende - $start);
    }

    /**
     * DER EINZIGE UPLOAD-WEG. Der alte Block trug wire:click="oeffneUpload(..)";
     * faellt er weg, kann niemand mehr einen Nachweis hochladen. Geprueft am
     * gerenderten Markup und fuer JEDE Zeile, nicht nur fuer die erste.
     */
    public function test_jede_zeile_im_kasten_oeffnet_den_upload(): void
    {
        $ma = $this->mitarbeiterOhneNachweise(['is_eu_citizen' => false]);

        $markup = $this->rendereHuelle($ma, '2026-10-01');
        $kasten = $this->kasten($markup);

        $this->assertStringContainsString("wire:click=\"oeffneUpload('ausweis')\"", $kasten);
        $this->assertStringContainsString("wire:click=\"oeffneUpload('aufenthaltstitel')\"", $kasten);
        // Eine Zeile je offenem Punkt, keine Zeile ohne Klick.
        $this->assertSame(
            substr_count($kasten, 'class="aufgabe"') + substr_count($kasten, 'class="aufgabe aufgabe-ko"'),
            substr_count($kasten, 'wire:click="oeffneUpload('),
            'Es gibt Zeilen im Kasten, die nicht anklickbar sind.'
        );
    }

    /** Der Klickweg liegt im Kasten -- und nicht mehr in einem zweiten Block. */
    public function test_der_alte_nachweis_block_ist_weg_und_der_kasten_hat_genau_einen_einstieg(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();

        $markup = $this->rendereHuelle($ma, '2026-10-01');

        // Die Nachweis-Schleife des alten Blocks ist aus dem Quelltext
        // verschwunden; die Pflichtangaben (andere Liste) bleiben dort.
        $this->assertStringNotContainsString('@foreach ($offeneAufgaben as', $this->bladeQuelle());
        $this->assertStringContainsString('@foreach ($pflichtAufgaben as', $this->bladeQuelle());
        // Im Kasten genau EIN Einstieg je Nachweisart. (Die Reiter
        // "Dokumente" und "Liegt vor" haben eigene, ebenfalls anklickbare
        // Zeilen -- deshalb wird hier der Kasten gemessen, nicht die Seite.)
        $this->assertSame(1, substr_count($this->kasten($markup), "oeffneUpload('ausweis')"));
    }

    public function test_fehlend_zeigt_den_satz_und_rot(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();

        $kasten = $this->kasten($this->rendereHuelle($ma, '2026-10-01'));

        $this->assertStringContainsString('Fehlt noch', $kasten);
        $this->assertStringContainsString('dot crit', $kasten);
        $this->assertStringNotContainsString('dot warn', $kasten);
    }

    public function test_abgelaufen_zeigt_das_datum_und_rot(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->nachweis($ma, 'ausweis', '2026-03-12');

        $kasten = $this->kasten($this->rendereHuelle($ma, '2026-10-01'));

        $this->assertStringContainsString('Abgelaufen am 12.03.2026', $kasten);
        $this->assertStringContainsString('dot crit', $kasten);
        $this->assertStringNotContainsString('dot warn', $kasten);
    }

    public function test_laeuft_ab_zeigt_das_datum_und_gelb(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->nachweis($ma, 'ausweis', '2026-10-15');

        $kasten = $this->kasten($this->rendereHuelle($ma, '2026-10-01'));

        $this->assertStringContainsString('Läuft ab am 15.10.2026', $kasten);
        $this->assertStringContainsString('dot warn', $kasten);
        $this->assertStringNotContainsString('dot crit', $kasten);
    }

    /** Das Label bleibt neben dem Satz stehen -- der Satz ersetzt es nicht. */
    public function test_label_und_satz_stehen_beide_in_der_zeile(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();

        $kasten = $this->kasten($this->rendereHuelle($ma, '2026-10-01'));

        $this->assertMatchesRegularExpression('#class="t">\s*Personalausweis oder Reisepass\s*</div>\s*<div class="s">\s*Fehlt noch\s*</div>#u', $kasten);
    }

    /** Pflichtangaben sind eine andere Liste und bleiben unberuehrt. */
    public function test_die_pflichtangaben_stehen_weiter_unter_das_fehlt_noch(): void
    {
        $ma = $this->mitarbeiterOhneNachweise();
        $this->nachweis($ma, 'ausweis', '2030-01-01');
        $ma = $ma->fresh();
        $markup = $this->rendereHuelle($ma, '2026-10-01');

        $this->assertStringContainsString('Das fehlt noch', $markup);
        $this->assertStringContainsString('Hauptarbeitgeber', $markup);
    }
}
