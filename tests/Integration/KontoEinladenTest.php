<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\Dispatcher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Console\Commands\KontoEinladen;
use Platform\Recruiting\Console\Commands\KontoZuruecksetzen;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\KontoWriter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Der Knopf, mit dem HR einlaedt und den Stand sieht (Aufgabe 10, Spec §3).
 *
 * WAS HIER FALSIFIZIERT WIRD — jede Zusage waere ohne ihren Test eine
 * Behauptung:
 *
 *  1. DER RIEGEL AM KNOPF (Befund F6). Wer kein hinterlegtes Geburtsdatum
 *     hat, bekommt KEINE Einladung — und zwar auch dann nicht, wenn HR seine
 *     Kennung ausdruecklich eintippt. Die Registrierungsseite kann diesen
 *     Fall nicht von einem Tippfehler unterscheiden; sie antwortet fuenfmal
 *     "pruef dein Geburtsdatum" und sperrt dann eine Stunde. Geprueft wird
 *     deshalb nicht, dass irgendwo eine Bedingung steht, sondern dass nach
 *     dem Lauf KEIN Einladungs-Hash an der Zeile haengt.
 *  2. OHNE --welle KEIN VERSAND AN ALLE. Geprueft werden BEIDE Ebenen: der
 *     Rueckgabewert (daran haengt, ob ein Aufruf als Fehler auffaellt) UND
 *     dass wirklich keine Einladung entstanden ist.
 *  3. DER GEDRUCKTE CODE IST DAS ECHTE GEHEIMNIS. Nicht "es steht etwas
 *     Achtstelliges da": der Code aus der Ausgabe wird durch
 *     KontoWriter::personFuerEinladung() geschickt und muss genau diese
 *     Person zurueckgeben. Eine blosse Anzeige-Ableitung faellt hier durch.
 *  4. DER CODE STEHT NIE IM LOG, die Ausgabe nennt nie einen NAMEN und nie
 *     eine VOLLE Rufnummer.
 *  5. RULING GD-8. Die Spalte heisst "letzter Passwortnachweis", nicht
 *     "zuletzt angemeldet" — der Stempel faellt, sobald das Passwort stimmt,
 *     auch wenn die Dispo-Sperre den Menschen unmittelbar danach abweist.
 *  6. DIE OFFENEN NOTFALL-ANTRAEGE STEHEN IM BERICHT, und zwar in DERSELBEN
 *     Darstellung wie unter recruiting:konto-zuruecksetzen --offen. Geprueft
 *     wird das als Zeichenvergleich der beiden Ausgaben — zwei Fassungen
 *     derselben Tafel laufen auseinander, und in diesem Zweig ist genau das
 *     schon zweimal passiert.
 *  7. OBSERVER-FREI: eine Welle fasst rec_employees nicht an. Der Beleg ist
 *     rec_employees.updated_at (das fasst nur Eloquent automatisch an), bei
 *     registriertem ECHTEN Beobachter.
 *
 * Schema und Aufbau von Hand (Migrationen laufen in dieser Suite nicht),
 * Vorbild KontoZuruecksetzenTest — inklusive Log-Attrappe vor
 * Facade::clearResolvedInstances().
 */
final class KontoEinladenTest extends TestCase
{
    private const TEAM = 3;

    private const ANDERES_TEAM = 4;

    private const NUMMER = '+4915111111111';

    private const NUMMER_B = '+4915122222222';

    private const NUMMER_C = '+4915133333333';

    private const NUMMER_D = '+4915144444444';

    private const PASSWORT = 'ganz-geheim-2026';

    private const GEBURT = '1995-03-14';

    private const PFEFFER = 'pfeffer-fuer-den-test';

    private const ANGEFASST = '2026-09-28 09:00:00';

    private const JETZT = '2026-09-29 12:00:00';

    private Capsule $capsule;

    private ?Container $vorherigerContainer = null;

    private Container $container;

    /** @var array<string, class-string> */
    private array $vorherigeMorphMap = [];

    /** @var object{zeilen: list<array{stufe: string, nachricht: string, daten: array}>} */
    private object $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vorherigerContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->container = $container;

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden
        // (reference_log_facade_test_stub.md). An ihr haengt die Zusicherung
        // "der Einladungscode steht nie im Log".
        $this->log = new class {
            /** @var list<array{stufe: string, nachricht: string, daten: array}> */
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = [
                    'stufe'     => $m,
                    'nachricht' => (string) ($a[0] ?? ''),
                    'daten'     => (array) ($a[1] ?? []),
                ];
            }
        };
        $container->instance('log', $this->log);

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['konto' => ['pepper' => self::PFEFFER]],
        ]));

        // Vier Runden statt zwoelf: bcrypt ist absichtlich langsam.
        $container->instance('hash', new BcryptHasher(['rounds' => 4]));

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

        // Die Morph-Karte des Wirts nachstellen: der CrmServiceProvider
        // traegt 'crm_contact' ein, und genau diese Schreibweise steht in
        // crm_phone_numbers.phoneable_type. Ohne sie lieferte
        // getMorphClass() hier den vollen Klassennamen — die Testumgebung
        // koennte den Unterschied gar nicht herstellen, den der
        // WhatsApp-Test zu pruefen behauptet.
        $this->vorherigeMorphMap = Relation::morphMap() ?: [];
        Relation::morphMap(['crm_contact' => CrmContact::class], false);

        Carbon::setTestNow(Carbon::parse(self::JETZT));

        $this->tabellen();

        // Der ECHTE Beobachter, nicht seine Abwesenheit: sonst belegte der
        // Observer-Test nur, dass es in diesem Lauf gar keinen gibt.
        RecEmployeeExportObserver::register();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        // Relation::morphMap ist PROZESSWEIT statisch — bleibt der Eintrag
        // stehen, sieht jede spaetere Testklasse ploetzlich eine fremde
        // Karte.
        Relation::morphMap($this->vorherigeMorphMap, false);

        Container::setInstance($this->vorherigerContainer);

        parent::tearDown();
    }

    // ----------------------------------------------------------------- Aufbau

    private function tabellen(): void
    {
        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();

            // Deckungsgleich mit 2026_09_29_000001_add_konto_felder_to_rec_persons.php.
            $t->string('invite_token_hash', 64)->nullable();
            $t->timestamp('invite_expires_at')->nullable();
            $t->timestamp('invite_used_at')->nullable();
            $t->string('code_hash', 64)->nullable();
            $t->timestamp('code_expires_at')->nullable();
            $t->unsignedTinyInteger('code_versuche')->default(0);
            $t->string('code_zweck', 20)->nullable();
            $t->string('code_neue_nummer', 32)->nullable();
            $t->timestamp('letzte_anmeldung_at')->nullable();

            // Deckungsgleich mit 2026_09_29_000003_add_nummernwechsel_zu_rec_persons.php.
            $t->string('wechsel_neue_nummer', 32)->nullable();
            $t->timestamp('wechsel_beantragt_at')->nullable();
            $t->timestamp('wechsel_wirksam_ab')->nullable();
            $t->string('wechsel_quelle', 20)->nullable();

            // Deckungsgleich mit 2026_09_29_000004_add_notfall_sperre_zu_rec_persons.php.
            $t->timestamp('notfall_gesperrt_bis')->nullable();

            $t->timestamps();

            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('uuid', 64)->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        // Deckungsgleich mit platform-crm
        // 2024_01_01_000020_create_crm_contact_links_table.php, ohne die
        // Fremdschluessel (crm_contacts/teams/users gibt es hier nicht).
        $this->capsule->schema()->create('crm_contact_links', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('contact_id')->nullable();
            $t->integer('team_id')->nullable();
            $t->integer('created_by_user_id')->nullable();
            $t->integer('linkable_id')->nullable();
            $t->string('linkable_type')->nullable();
            $t->timestamps();
        });

        // Deckungsgleich mit platform-crm
        // 2024_01_01_000014_create_crm_phone_numbers_table.php und
        // 2025_02_18_000001_add_whatsapp_status_to_crm_phone_numbers_table.php
        // — einschliesslich der Vorgaben is_active=1 und
        // whatsapp_status='unknown'. Die Vorgabe traegt: sie ist der Grund,
        // warum ein unbekannter Status niemanden aufhaelt.
        $this->capsule->schema()->create('crm_phone_numbers', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->string('phoneable_type')->nullable();
            $t->integer('phoneable_id')->nullable();
            $t->string('raw_input')->nullable();
            $t->string('international')->nullable();
            $t->string('national')->nullable();
            $t->integer('phone_type_id')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('whatsapp_status', 20)->default('unknown');
            $t->timestamps();
        });
    }

    // ----------------------------------------------------------------- Hilfen

    private function person(?string $nummer, string $uuid, array $attr = []): int
    {
        return (int) DB::table('rec_persons')->insertGetId(array_merge([
            'uuid'       => $uuid,
            'team_id'    => self::TEAM,
            'phone'      => $nummer,
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ], $attr));
    }

    private function anstellung(int $personId, string $token, array $attr = []): int
    {
        return (int) DB::table('rec_employees')->insertGetId(array_merge([
            'team_id'       => self::TEAM,
            'portal_token'  => $token,
            'first_name'    => 'Gregor',
            'last_name'     => 'Unverwechselbar',
            'birth_date'    => self::GEBURT,
            'rec_person_id' => $personId,
            'company'       => 'RG',
            'phone'         => self::NUMMER,
            'is_active'     => 1,
            'created_at'    => self::ANGEFASST,
            'updated_at'    => self::ANGEFASST,
        ], $attr));
    }

    /**
     * Ein CRM-Kontakt an einer Anstellung, mit einer Nummer und deren
     * WhatsApp-Zustand.
     *
     * Die Schreibweisen sind die des Wirts: linkable_type traegt den vollen
     * Klassennamen (ContactPhoneSync sucht mit LIKE '%RecEmployee'),
     * phoneable_type den Kurzschluessel 'crm_contact' aus der Morph-Karte
     * des CrmServiceProvider.
     */
    private function kontaktMitNummer(int $anstellungId, int $kontaktId, string $nummer, string $status): void
    {
        DB::table('crm_contact_links')->insert([
            'contact_id'    => $kontaktId,
            'team_id'       => self::TEAM,
            'linkable_id'   => $anstellungId,
            'linkable_type' => RecEmployee::class,
            'created_at'    => self::ANGEFASST,
            'updated_at'    => self::ANGEFASST,
        ]);

        DB::table('crm_phone_numbers')->insert([
            'phoneable_type'  => (new CrmContact())->getMorphClass(),
            'phoneable_id'    => $kontaktId,
            'international'   => $nummer,
            'is_active'       => 1,
            'whatsapp_status' => $status,
            'created_at'      => self::ANGEFASST,
            'updated_at'      => self::ANGEFASST,
        ]);
    }

    private function zeile(int $personId): object
    {
        return DB::table('rec_persons')->where('id', $personId)->first();
    }

    /**
     * Das Kommando laufen lassen.
     *
     * @param  array<string, string|bool>  $optionen
     * @return array{0: int, 1: string}  Rueckgabewert und Ausgabe
     */
    private function kommando(array $optionen): array
    {
        $command = new KontoEinladen();
        $command->setLaravel(new KontoEinladenFakeLaravel());

        $input  = new ArrayInput($optionen, $command->getDefinition());
        $output = new BufferedOutput();

        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }

    /**
     * @param  array<string, string|bool>  $optionen
     * @return array{0: int, 1: string}
     */
    private function zuruecksetzen(array $optionen): array
    {
        $command = new KontoZuruecksetzen();
        $command->setLaravel(new KontoEinladenFakeLaravel());

        $input  = new ArrayInput($optionen, $command->getDefinition());
        $output = new BufferedOutput();

        $code = $command->run($input, $output);

        return [$code, $output->fetch()];
    }

    /** Der achtstellige Code aus der Zeile einer Person in der Ausgabe. */
    private function codeAusDerAusgabe(string $ausgabe, int $personId): string
    {
        $treffer = [];
        foreach (explode("\n", $ausgabe) as $zeile) {
            if (!str_contains($zeile, "/recruiting/konto/anlegen/")) {
                continue;
            }
            if (preg_match('#/recruiting/konto/anlegen/([A-Z2-9]{8})#', $zeile, $m) === 1) {
                $treffer[] = ['zeile' => $zeile, 'code' => $m[1]];
            }
        }

        foreach ($treffer as $eintrag) {
            if (preg_match('/\b' . $personId . '\b/', $eintrag['zeile']) === 1) {
                return $eintrag['code'];
            }
        }

        $this->fail("Kein Einladungscode fuer Person {$personId} in der Ausgabe:\n{$ausgabe}");
    }

    // -------------------------------------------------------------- Einladen

    public function test_eine_welle_erzeugt_eine_einladung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando(['--welle' => '10']);

        $this->assertSame(0, $code, $ausgabe);

        $zeile = $this->zeile($person);
        $this->assertNotNull($zeile->invite_token_hash, "keine Einladung entstanden:\n{$ausgabe}");
        $this->assertNotNull($zeile->invited_at);
        $this->assertNull($zeile->invite_used_at);
    }

    /**
     * Der gedruckte Code IST das Geheimnis, nicht seine Anzeigeform.
     *
     * Geprueft wird das ueber KontoWriter::personFuerEinladung() — dieselbe
     * Aufloesung, die die Registrierungsseite faehrt. Eine abgeleitete
     * Anzeige (die es in diesem Zweig schon einmal gab, Ruling GD-4) faellt
     * hier durch, weil sie sich nicht eintippen laesst.
     */
    public function test_der_gedruckte_code_oeffnet_die_registrierung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [, $ausgabe] = $this->kommando(['--welle' => '10']);
        $token = $this->codeAusDerAusgabe($ausgabe, $person);

        $this->assertSame($person, KontoWriter::personFuerEinladung($token));
    }

    /**
     * Der Link steht mit in der Ausgabe — Canvas 68 verlangt beides, den
     * Link und den abtippbaren Code, und beides ist derselbe Wert.
     */
    public function test_die_ausgabe_traegt_den_link_und_denselben_code(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [, $ausgabe] = $this->kommando(['--welle' => '10']);
        $token = $this->codeAusDerAusgabe($ausgabe, $person);

        $this->assertStringContainsString('/recruiting/konto/anlegen/' . $token, $ausgabe);
        // Und derselbe Wert steht auch fuer sich als Code da (abtippbar).
        $this->assertMatchesRegularExpression(
            '/\|\s*' . $token . '\s*\|/',
            $ausgabe,
            "der Code steht nur im Link, nicht zum Abtippen:\n{$ausgabe}",
        );
    }

    /**
     * Die Einladung gilt sieben Tage. Die Zahl steht hier AUSGESCHRIEBEN und
     * wird nicht aus EinladungsToken::GUELTIG_TAGE abgeleitet — sonst laesen
     * Code und Test dieselbe Konstante, und eine Aenderung der Frist bliebe
     * unbemerkt.
     */
    public function test_die_einladung_gilt_sieben_tage(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        $this->kommando(['--welle' => '10']);

        $this->assertSame(
            '2026-10-06 12:00:00',
            Carbon::parse($this->zeile($person)->invite_expires_at)->format('Y-m-d H:i:s'),
        );
    }

    /**
     * OHNE --welle KEIN VERSAND AN ALLE — eine Welle ist eine bewusste
     * Handlung.
     *
     * Geprueft werden BEIDE Ebenen: der Rueckgabewert (daran haengt, ob ein
     * versehentlicher Aufruf auffaellt) und die Wirkung (es darf wirklich
     * keine Einladung entstanden sein). Ein Test nur auf ein Wort in der
     * Ausgabe haette die Stelle nicht gesehen, an der trotzdem geschrieben
     * wird.
     */
    public function test_ohne_welle_geht_nichts_an_alle(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando([]);

        $this->assertSame(1, $code, $ausgabe);
        $this->assertNull($this->zeile($person)->invite_token_hash, 'ohne --welle darf nichts entstehen');
    }

    public function test_mit_genannten_kennungen_braucht_es_keine_welle(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando(['--ids' => (string) $person]);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNotNull($this->zeile($person)->invite_token_hash);
    }

    public function test_die_welle_begrenzt_die_zahl(): void
    {
        $a = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($a, 'tok-a');
        $b = $this->person(self::NUMMER_B, 'p-b');
        $this->anstellung($b, 'tok-b', ['phone' => self::NUMMER_B]);

        [, $ausgabe] = $this->kommando(['--welle' => '1']);

        $eingeladen = (int) DB::table('rec_persons')->whereNotNull('invite_token_hash')->count();
        $this->assertSame(1, $eingeladen, "die Welle haelt ihre Grenze nicht ein:\n{$ausgabe}");
    }

    public function test_wer_schon_ein_konto_hat_wird_uebersprungen(): void
    {
        $person = $this->person(self::NUMMER, 'p-a', [
            'password_hash' => Hash::make(self::PASSWORT),
            'registered_at' => self::ANGEFASST,
        ]);
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando(['--welle' => '10']);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNull($this->zeile($person)->invite_token_hash, 'ein bestehendes Konto darf nicht ueberschrieben werden');
        $this->assertNotNull($this->zeile($person)->password_hash);
    }

    /**
     * DER RIEGEL (Befund F6): ohne hinterlegtes Geburtsdatum keine
     * Einladung.
     *
     * Ohne ihn bekaeme der Mensch fuenfmal "pruef dein Geburtsdatum" und
     * danach eine Stunde lang eine 404 — ohne dass etwas an ihm falsch
     * waere, und ohne dass er es je richtig machen koennte.
     */
    public function test_ohne_geburtsdatum_keine_einladung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a', ['birth_date' => null]);

        [$code, $ausgabe] = $this->kommando(['--welle' => '10']);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNull($this->zeile($person)->invite_token_hash);
    }

    /**
     * Der Riegel haengt am KNOPF, nicht an der Welle: auch eine
     * ausdruecklich eingetippte Kennung kommt nicht daran vorbei.
     */
    public function test_ohne_geburtsdatum_auch_nicht_ueber_die_kennung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a', ['birth_date' => null]);

        [, $ausgabe] = $this->kommando(['--ids' => (string) $person]);

        $this->assertNull($this->zeile($person)->invite_token_hash, "der Riegel faellt bei --ids:\n{$ausgabe}");
        $this->assertStringContainsString('kein Geburtsdatum', $ausgabe, 'HR hat die Kennung getippt und braucht die Antwort');
    }

    public function test_ohne_nummer_keine_einladung(): void
    {
        $person = $this->person(null, 'p-a');
        $this->anstellung($person, 'tok-a', ['phone' => null]);

        $this->kommando(['--welle' => '10']);

        $this->assertNull($this->zeile($person)->invite_token_hash);
    }

    /**
     * "Ohne WhatsApp bekommen kein Konto" (Spec §3). Bekannt heisst:
     * crm_phone_numbers fuehrt die Nummer als 'unavailable'.
     */
    public function test_wessen_nummer_kein_whatsapp_hat_wird_nicht_eingeladen(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $anstellung = $this->anstellung($person, 'tok-a');
        $this->kontaktMitNummer($anstellung, 901, self::NUMMER, 'unavailable');

        $this->kommando(['--welle' => '10']);

        $this->assertNull($this->zeile($person)->invite_token_hash);
    }

    /**
     * Ein UNBEKANNTER Status haelt niemanden auf — 'unknown' ist die Vorgabe
     * der Spalte und steht beim halben Bestand. Wer daraus "kein WhatsApp"
     * machte, schloesse den Bestand von der Umstellung aus.
     */
    public function test_ein_unbekannter_whatsapp_status_haelt_niemanden_auf(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $anstellung = $this->anstellung($person, 'tok-a');
        $this->kontaktMitNummer($anstellung, 901, self::NUMMER, 'unknown');

        $this->kommando(['--welle' => '10']);

        $this->assertNotNull($this->zeile($person)->invite_token_hash);
    }

    /**
     * Ein 'unavailable' an einer FREMDEN Nummer desselben Kontakts sperrt
     * nicht: verglichen wird die Nummer der Person, nicht der Kontakt.
     */
    public function test_ein_unavailable_an_einer_anderen_nummer_sperrt_nicht(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $anstellung = $this->anstellung($person, 'tok-a');
        $this->kontaktMitNummer($anstellung, 901, self::NUMMER_C, 'unavailable');

        $this->kommando(['--welle' => '10']);

        $this->assertNotNull($this->zeile($person)->invite_token_hash);
    }

    public function test_gesperrte_und_stillgelegte_bekommen_keine_einladung(): void
    {
        $gesperrt = $this->person(self::NUMMER, 'p-a', ['locked_at' => self::ANGEFASST]);
        $this->anstellung($gesperrt, 'tok-a');
        $still = $this->person(self::NUMMER_B, 'p-b', ['merged_into_person_id' => $gesperrt]);
        $this->anstellung($still, 'tok-b', ['phone' => self::NUMMER_B]);

        [$code, $ausgabe] = $this->kommando(['--welle' => '10']);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNull($this->zeile($gesperrt)->invite_token_hash);
        $this->assertNull($this->zeile($still)->invite_token_hash);
    }

    /**
     * Ohne aktive Anstellung kein Konto (Canvas 1793): wer ausgeschieden
     * ist, kaeme mit der Einladung ohnehin nicht hinein — darfSichAnmelden()
     * weist ihn ab.
     */
    public function test_ohne_aktive_anstellung_keine_einladung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a', ['is_active' => 0]);

        $this->kommando(['--welle' => '10']);

        $this->assertNull($this->zeile($person)->invite_token_hash);
    }

    public function test_ein_anderes_team_bleibt_unberuehrt(): void
    {
        $hier = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($hier, 'tok-a');
        $dort = $this->person(self::NUMMER_B, 'p-b', ['team_id' => self::ANDERES_TEAM]);
        $this->anstellung($dort, 'tok-b', ['team_id' => self::ANDERES_TEAM, 'phone' => self::NUMMER_B]);

        $this->kommando(['--welle' => '10', '--team' => (string) self::TEAM]);

        $this->assertNotNull($this->zeile($hier)->invite_token_hash);
        $this->assertNull($this->zeile($dort)->invite_token_hash);
    }

    public function test_der_probelauf_schreibt_nichts(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando(['--welle' => '10', '--dry-run' => true]);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNull($this->zeile($person)->invite_token_hash, 'ein Probelauf darf nichts schreiben');
        $this->assertNull($this->zeile($person)->invited_at);
        $this->assertStringContainsString((string) $person, $ausgabe, 'der Probelauf soll die Kandidaten zeigen');
    }

    /**
     * Der Probelauf braucht keine Welle: er schreibt nichts, und er ist der
     * Weg, auf dem HR ueberhaupt sieht, wie gross die Welle waere, bevor sie
     * eine bewusste Zahl eintippt.
     */
    public function test_der_probelauf_braucht_keine_welle(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando(['--dry-run' => true]);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNull($this->zeile($person)->invite_token_hash);
        $this->assertStringContainsString((string) $person, $ausgabe);
    }

    /**
     * Ein zweiter Lauf erneuert die Einladung (und entwertet die vorige).
     *
     * ENTSCHEIDUNG, und sie hat einen Grund: verschickt wird hier noch
     * nichts (Gate E, die Meta-Vorlage fehlt) — der Code existiert
     * AUSSCHLIESSLICH auf diesem Bildschirm, gespeichert ist nur sein Hash.
     * Wuerde ein zweiter Lauf offene Einladungen ueberspringen, waere eine
     * verlorene Ausgabe unwiederbringlich: HR saehe die Person als
     * "eingeladen" und kaeme nie wieder an ihren Code.
     */
    public function test_ein_zweiter_lauf_erneuert_die_einladung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        $this->kommando(['--welle' => '10']);
        $erster = $this->zeile($person)->invite_token_hash;

        [, $ausgabe] = $this->kommando(['--welle' => '10']);
        $zweiter = $this->zeile($person)->invite_token_hash;

        $this->assertNotNull($zweiter);
        $this->assertNotSame($erster, $zweiter, "der zweite Lauf erneuert die Einladung nicht:\n{$ausgabe}");
    }

    /**
     * Eine Kennung, die nicht in die Zielgruppe gehoert, wird BENANNT — HR
     * hat sie getippt und wartet auf eine Antwort. Stumm zu ueberspringen
     * saehe aus wie "erledigt".
     */
    public function test_eine_unbekannte_kennung_wird_benannt(): void
    {
        [$code, $ausgabe] = $this->kommando(['--ids' => '4711']);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertStringContainsString('4711', $ausgabe);
    }

    // --------------------------------------------------------- Datenschutz

    /**
     * Kennungen, nie Namen — und nie eine volle Rufnummer (Muster:
     * recruiting:mitarbeiter-grenzfaelle).
     */
    public function test_die_ausgabe_nennt_keine_namen_und_keine_vollen_nummern(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');
        DB::table('rec_persons')->where('id', $person)->update([
            'wechsel_neue_nummer'  => self::NUMMER_D,
            'wechsel_beantragt_at' => self::JETZT,
            'wechsel_wirksam_ab'   => '2026-09-30 12:00:00',
            'wechsel_quelle'       => 'notfall',
        ]);

        [, $einladen] = $this->kommando(['--welle' => '10']);
        [, $bericht]  = $this->kommando(['--bericht' => true]);

        foreach (['Einladung' => $einladen, 'Bericht' => $bericht] as $was => $ausgabe) {
            $this->assertStringNotContainsString('Unverwechselbar', $ausgabe, "{$was} nennt einen Namen");
            $this->assertStringNotContainsString(self::NUMMER, $ausgabe, "{$was} nennt eine volle Nummer");
            $this->assertStringNotContainsString(self::NUMMER_D, $ausgabe, "{$was} nennt eine volle Nummer");
        }
    }

    /** Der Einladungscode ist ein Geheimnis und geht NICHT ins Log. */
    public function test_der_code_steht_nicht_im_log(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [, $ausgabe] = $this->kommando(['--welle' => '10']);
        $token = $this->codeAusDerAusgabe($ausgabe, $person);

        $alles = json_encode($this->log->zeilen, JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString($token, $alles, 'der Einladungscode steht im Log');
        $this->assertStringNotContainsString(self::NUMMER, $alles, 'die volle Nummer steht im Log');
    }

    /**
     * OBSERVER-FREI: eine Welle fasst rec_employees nicht an.
     *
     * Der Beleg ist rec_employees.updated_at — das fasst nur Eloquent
     * automatisch an. zas_changed_at kaeme hier ohnehin nicht in Frage (die
     * Kontofelder stehen nicht in RELEVANT_EMPLOYEE_FIELDS); updated_at
     * faellt dagegen bei JEDEM Eloquent-Speichern.
     */
    public function test_eine_welle_fasst_die_anstellung_nicht_an(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $anstellung = $this->anstellung($person, 'tok-a');

        $this->kommando(['--welle' => '10']);

        $zeile = DB::table('rec_employees')->where('id', $anstellung)->first();
        $this->assertSame(self::ANGEFASST, (string) $zeile->updated_at);
        $this->assertNull($zeile->zas_changed_at);
    }

    // ---------------------------------------------------------------- Bericht

    public function test_der_bericht_erzeugt_keine_einladung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [$code, $ausgabe] = $this->kommando(['--bericht' => true, '--welle' => '10']);

        $this->assertSame(0, $code, $ausgabe);
        $this->assertNull($this->zeile($person)->invite_token_hash, '--bericht verschickt nichts');
    }

    /**
     * Spec §3: HR sieht durchgaengig, wer eingeladen ist, wer registriert
     * ist und wer nicht erreichbar ist.
     */
    public function test_der_bericht_zaehlt_die_drei_gruppen(): void
    {
        $registriert = $this->person(self::NUMMER, 'p-a', [
            'password_hash' => Hash::make(self::PASSWORT),
            'registered_at' => self::ANGEFASST,
        ]);
        $this->anstellung($registriert, 'tok-a');

        $eingeladen = $this->person(self::NUMMER_B, 'p-b', [
            'invited_at'        => self::ANGEFASST,
            'invite_token_hash' => str_repeat('a', 64),
            'invite_expires_at' => '2026-10-05 09:00:00',
        ]);
        $this->anstellung($eingeladen, 'tok-b', ['phone' => self::NUMMER_B]);

        $ohneNummer = $this->person(null, 'p-c');
        $this->anstellung($ohneNummer, 'tok-c', ['phone' => null]);

        $ohneGeburt = $this->person(self::NUMMER_C, 'p-d');
        $this->anstellung($ohneGeburt, 'tok-d', ['phone' => self::NUMMER_C, 'birth_date' => null]);

        $offen = $this->person(self::NUMMER_D, 'p-e');
        $this->anstellung($offen, 'tok-e', ['phone' => self::NUMMER_D]);

        [, $ausgabe] = $this->kommando(['--bericht' => true]);

        $this->assertMatchesRegularExpression('/registriert\s*\|\s*1\s/', $ausgabe, $ausgabe);
        $this->assertMatchesRegularExpression('/eingeladen, kein Konto\s*\|\s*1\s/', $ausgabe, $ausgabe);
        $this->assertMatchesRegularExpression('/noch nicht eingeladen\s*\|\s*1\s/', $ausgabe, $ausgabe);
        $this->assertMatchesRegularExpression('/nicht erreichbar\s*\|\s*2\s/', $ausgabe, $ausgabe);

        // Und die Gruende stehen einzeln da — "nicht erreichbar" allein
        // sagt HR nicht, was zu tun ist.
        $this->assertStringContainsString('keine Nummer', $ausgabe);
        $this->assertStringContainsString('kein Geburtsdatum', $ausgabe);
    }

    /**
     * RULING GD-8. letzte_anmeldung_at bedeutet "letzter erfolgreicher
     * Passwortnachweis" — der Stempel faellt in
     * KontoWriter::pruefeAnmeldung(), sobald das Passwort stimmt, auch wenn
     * die Dispo-Sperre den Menschen unmittelbar danach abweist.
     *
     * DIESER TEST PRUEFT EINE BESCHRIFTUNG, und das ist hier die Sache
     * selbst: HR entscheidet nach dieser Spalte, ob ein Konto funktioniert.
     * "zuletzt angemeldet" waere bei einem gesperrten Konto eine
     * Falschaussage. Gezeigt wird der Stempel deshalb gerade auch fuer ein
     * portal-gesperrtes Konto.
     */
    public function test_der_bericht_nennt_den_passwortnachweis_und_nicht_die_anmeldung(): void
    {
        $person = $this->person(self::NUMMER, 'p-a', [
            'password_hash'       => Hash::make(self::PASSWORT),
            'registered_at'       => self::ANGEFASST,
            'letzte_anmeldung_at' => '2026-09-29 08:30:00',
        ]);
        $this->anstellung($person, 'tok-a', ['portal_locked_at' => self::ANGEFASST]);

        [, $ausgabe] = $this->kommando(['--bericht' => true]);

        $this->assertStringContainsString('Passwortnachweis', $ausgabe);
        $this->assertStringNotContainsString('zuletzt angemeldet', $ausgabe);
        $this->assertStringNotContainsString('Zuletzt angemeldet', $ausgabe);
        $this->assertStringContainsString('2026-09-29 08:30', $ausgabe, 'der Stempel selbst fehlt');
    }

    /**
     * Die offenen Notfall-Antraege aus Weg 4 stehen im Bericht.
     *
     * OHNE DIESEN ABSCHNITT ist das Stopp-Recht aus Spec §5 theoretisch: es
     * entstuende nur eine Log-Zeile und ein Eintrag, den ausschliesslich
     * sieht, wer von sich aus recruiting:konto-zuruecksetzen --offen faehrt.
     * Ein Recht, von dem niemand erfaehrt, ist keines.
     */
    public function test_der_bericht_fuehrt_die_offenen_nummernwechsel(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');
        DB::table('rec_persons')->where('id', $person)->update([
            'wechsel_neue_nummer'  => self::NUMMER_D,
            'wechsel_beantragt_at' => self::JETZT,
            'wechsel_wirksam_ab'   => '2026-09-30 12:00:00',
            'wechsel_quelle'       => 'notfall',
        ]);

        [, $ausgabe] = $this->kommando(['--bericht' => true]);

        $this->assertStringContainsString('Nummernwechsel beantragt', $ausgabe);
        $this->assertStringContainsString('2026-09-30 12:00:00', $ausgabe, 'wirksam ab fehlt');
        $this->assertStringContainsString('...' . substr(self::NUMMER_D, -4), $ausgabe, 'die gekuerzte Zielnummer fehlt');
    }

    /** Die faelligen zuerst — HR soll oben sehen, was gleich wirksam wird. */
    public function test_im_bericht_stehen_die_faelligen_antraege_zuerst(): void
    {
        $spaeter = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($spaeter, 'tok-a');
        DB::table('rec_persons')->where('id', $spaeter)->update([
            'wechsel_neue_nummer'  => self::NUMMER_C,
            'wechsel_beantragt_at' => self::JETZT,
            'wechsel_wirksam_ab'   => '2026-09-30 12:00:00',
            'wechsel_quelle'       => 'notfall',
        ]);

        // Spaeter angelegt, aber laengst faellig: eine Sortierung nach
        // Kennung wuerde ihn nach unten schieben.
        $faellig = $this->person(self::NUMMER_B, 'p-b');
        $this->anstellung($faellig, 'tok-b', ['phone' => self::NUMMER_B]);
        DB::table('rec_persons')->where('id', $faellig)->update([
            'wechsel_neue_nummer'  => self::NUMMER_D,
            'wechsel_beantragt_at' => '2026-09-28 10:00:00',
            'wechsel_wirksam_ab'   => '2026-09-29 10:00:00',
            'wechsel_quelle'       => 'notfall',
        ]);

        [, $ausgabe] = $this->kommando(['--bericht' => true]);

        $abschnitt = substr($ausgabe, (int) strpos($ausgabe, 'Nummernwechsel beantragt'));
        $this->assertLessThan(
            strpos($abschnitt, '2026-09-30 12:00:00'),
            strpos($abschnitt, '2026-09-29 10:00:00'),
            "der faellige Antrag steht nicht oben:\n{$abschnitt}",
        );
    }

    /**
     * EINE Fassung der Tafel, nicht zwei.
     *
     * Verglichen werden die Zeilen, die beide Kommandos fuer denselben
     * Antrag drucken — Zeichen fuer Zeichen. Zwei Fassungen derselben
     * Darstellung laufen auseinander (in diesem Zweig schon zweimal
     * geschehen), und dann sagt HR dasselbe zweimal verschieden.
     */
    public function test_beide_kommandos_zeigen_denselben_antrag_gleich(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');
        DB::table('rec_persons')->where('id', $person)->update([
            'wechsel_neue_nummer'  => self::NUMMER_D,
            'wechsel_beantragt_at' => self::JETZT,
            'wechsel_wirksam_ab'   => '2026-09-30 12:00:00',
            'wechsel_quelle'       => 'notfall',
            'locked_at'            => self::ANGEFASST,
        ]);

        [, $bericht] = $this->kommando(['--bericht' => true]);
        [, $offen]   = $this->zuruecksetzen(['--offen' => true]);

        $zeileBericht = $this->antragsZeile($bericht);
        $zeileOffen   = $this->antragsZeile($offen);

        $this->assertSame($zeileOffen, $zeileBericht);
        // Und der Zustand steht wirklich drin — sonst verglichen wir zwei
        // gleich leere Zeilen.
        $this->assertStringContainsString('gesperrt, wird nie angewendet', $zeileBericht);
    }

    /** Die Tabellenzeile des Antrags (die mit der Quelle 'notfall'). */
    private function antragsZeile(string $ausgabe): string
    {
        foreach (explode("\n", $ausgabe) as $zeile) {
            if (str_contains($zeile, 'notfall')) {
                return trim($zeile);
            }
        }

        $this->fail("Keine Antragszeile in der Ausgabe:\n{$ausgabe}");
    }

    public function test_ohne_antrag_sagt_der_bericht_das_auch(): void
    {
        $person = $this->person(self::NUMMER, 'p-a');
        $this->anstellung($person, 'tok-a');

        [, $bericht] = $this->kommando(['--bericht' => true]);
        [, $offen]   = $this->zuruecksetzen(['--offen' => true]);

        $this->assertStringContainsString('Kein offener Notfall-Antrag.', $bericht);
        $this->assertStringContainsString('Kein offener Notfall-Antrag.', $offen);
    }

    /**
     * Das Kommando ist im ServiceProvider eingetragen.
     *
     * Ohne diese Zeile gibt es die Datei, aber kein Kommando — und jeder
     * Test hier bliebe trotzdem gruen, weil er die Klasse selbst baut.
     */
    public function test_das_kommando_ist_registriert(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/RecruitingServiceProvider.php');

        $this->assertStringContainsString('Commands\\KontoEinladen::class', (string) $quelle);
    }
}

/**
 * Nur so viel Laravel, wie Illuminate\Console\Command::run() braucht
 * (Muster: KontoZuruecksetzenFakeLaravel): runningUnitTests(), sonst nichts.
 */
final class KontoEinladenFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
