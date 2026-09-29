<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Der zweite Einstieg in die Anmeldeschicht: Handynummer + Passwort.
 *
 * Diese Tests zielen auf die Stellen, an denen ein Fehler still bliebe:
 *
 *  - Der Token-Weg aendert sich beim Einhaengen des Kontos. Er laeuft
 *    waehrend der Umstellung daneben weiter und muss unveraendert gruen
 *    bleiben; verschraenkt sich beides, oeffnet ein Konto-Nachweis den
 *    Token-Weg oder umgekehrt.
 *  - Der Versuchszaehler haengt am falschen Schluessel. Haengt er am Token,
 *    kann man Passwoerter beliebig oft raten; haengt er an der Person,
 *    verrieten fuenf Fehlversuche, dass es die Nummer gibt.
 *  - Die Antwortzeit verraet, ob es die Nummer gibt. KontoWriter rechnet
 *    dafuer IMMER einen Passwortvergleich — eine fruehe Rueckgabe HIER
 *    haette denselben Schaden wie eine dort.
 *  - Die Dispo-Sperre (rec_employees.portal_locked_at) faellt unter den
 *    Tisch: KontoWriter prueft sie bewusst nicht, weil sie an der
 *    ANSTELLUNG haengt und nicht an der Person.
 *
 * Das Schema wird von Hand gebaut (Migrationen laufen hier nicht), Vorbild
 * ist PersonLinkerTest/KontoWriterTest — inklusive Log-Attrappe vor
 * Facade::clearResolvedInstances() und dem mitschreibenden Hasher, ohne den
 * sich der Leerlauf-Vergleich nicht belegen laesst.
 */
final class PortalAuthKontoTest extends TestCase
{
    private const TEAM = 3;

    /** Die Nummer in E.164 — so steht sie in rec_persons.phone. */
    private const NUMMER = '+4915111111111';

    /** Dieselbe Nummer, wie ein Mensch sie tippt. */
    private const NUMMER_GETIPPT = '0151 11111111';

    private const PASSWORT = 'ganz-geheim-2026';

    private const FALSCHES_PASSWORT = 'ganz-daneben-2026';

    private const GEBURT = '1995-03-14';

    private const AUSWEIS = 'L01X00T47';

    private const ANGEFASST = '2026-09-28 09:00:00';

    private Capsule $capsule;

    private Repository $cache;

    /** @var object{gepruefteHashes: list<string>} */
    private object $hasher;

    private int $personId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden —
        // sonst fliegt eine ReflectionException, sobald irgendetwas
        // \Log::warning(...) ruft (reference_log_facade_test_stub.md).
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        // inbound_team_id ist der Team-Anker des DispoIdentityResolver — ohne
        // ihn gruppiert er fail closed gar nicht, und die Gruppen-Tests waeren
        // stumm gruen.
        $container->instance('config', new ConfigRepository([
            'recruiting' => [
                'konto' => ['pepper' => 'pfeffer-fuer-den-test'],
                'zas'   => ['inbound_team_id' => self::TEAM],
            ],
        ]));

        // Vier Runden statt zwoelf: bcrypt ist absichtlich langsam. Der
        // Hasher merkt sich, WOGEGEN verglichen wurde — Laravel kehrt bei
        // leerem Hash sofort zurueck, ein blosser Aufrufzaehler wuerde den
        // Unterschied zwischen "verglichen" und "gar nicht gerechnet" nicht
        // bemerken.
        $this->hasher = new class(['rounds' => 4]) extends BcryptHasher {
            /** @var list<string> die zweiten Argumente jedes check()-Aufrufs */
            public array $gepruefteHashes = [];

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->gepruefteHashes[] = (string) $hashedValue;

                return parent::check($value, $hashedValue, $options);
            }
        };
        $container->instance('hash', $this->hasher);

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

        $this->cache = new Repository(new ArrayStore());

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();
            $t->timestamp('letzte_anmeldung_at')->nullable();
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
            $t->string('identity_card_number')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamps();
        });

        // Die Dispo-Identitaet haengt am CRM-Kontakt, nicht an der
        // Personen-Klammer — der DispoIdentityResolver liest diese Tabelle.
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

        $this->personId = $this->person(self::TEAM, self::NUMMER, 'p-konto-anmeldung');
        $this->anstellung($this->personId, 'tok-1');
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance('config');
        $container->forgetInstance('hash');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- Hilfen

    private function person(int $teamId, string $nummer, string $uuid, string $passwort = self::PASSWORT): int
    {
        return (int) DB::table('rec_persons')->insertGetId([
            'uuid'          => $uuid,
            'team_id'       => $teamId,
            'phone'         => $nummer,
            'password_hash' => Hash::make($passwort),
            'created_at'    => self::ANGEFASST,
            'updated_at'    => self::ANGEFASST,
        ]);
    }

    private function anstellung(int $personId, string $token, array $attr = []): int
    {
        return (int) DB::table('rec_employees')->insertGetId(array_merge([
            'team_id'              => self::TEAM,
            'portal_token'         => $token,
            'first_name'           => 'Test',
            'last_name'            => 'Person',
            'birth_date'           => self::GEBURT,
            'identity_card_number' => self::AUSWEIS,
            'rec_person_id'        => $personId,
            'phone'                => self::NUMMER,
            'is_active'            => 1,
            'created_at'           => self::ANGEFASST,
            'updated_at'           => self::ANGEFASST,
        ], $attr));
    }

    private function auth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    /** Ein CRM-Kontakt an einer Anstellung — so entsteht eine Dispo-Identitaet. */
    private function crmVerknuepfung(int $employeeId, int $contactId): void
    {
        DB::table('crm_contact_links')->insert([
            'uuid'               => 'lnk-' . $employeeId . '-' . $contactId,
            'contact_id'         => $contactId,
            'team_id'            => self::TEAM,
            'created_by_user_id' => 1,
            'linkable_id'        => $employeeId,
            'linkable_type'      => (new RecEmployee())->getMorphClass(),
            'created_at'         => self::ANGEFASST,
            'updated_at'         => self::ANGEFASST,
        ]);
    }

    /**
     * Die HR-Entsperrung, Zeile fuer Zeile wie Show.php::unlockPortal() sie
     * fuehrt (Show.php:354). Nachgebaut und nicht gerufen, weil die
     * Livewire-Komponente hier nicht laeuft — aber wenn sich der Weg dort
     * aendert, gehoert dieser Test angepasst.
     */
    private function hrEntsperrt(int $employeeId): void
    {
        $ids = app(DispoIdentityResolver::class)->groupFor($employeeId);

        RecEmployee::query()->whereIn('id', $ids)->update(['portal_locked_at' => null]);
    }

    /**
     * Beweist, dass WIRKLICH ein Passwortvergleich gerechnet wurde: Laravel
     * kehrt bei leerem Hash sofort zurueck, ein Aufrufzaehler allein wuerde
     * das nicht bemerken. bcrypt-Praefix und Laenge sind der Beleg.
     */
    private function assertEchtVerglichen(): void
    {
        $this->assertNotEmpty($this->hasher->gepruefteHashes, 'es wurde ueberhaupt nicht verglichen');

        foreach ($this->hasher->gepruefteHashes as $hash) {
            $this->assertStringStartsWith('$2y$', $hash, 'verglichen werden muss gegen einen echten bcrypt-Hash');
            $this->assertSame(60, strlen($hash), 'ein bcrypt-Hash ist 60 Zeichen lang');
        }
    }

    // ------------------------------------------------------------- Token-Weg

    /**
     * Der Token-Weg laeuft waehrend der Umstellung daneben weiter. Er darf
     * sich durch das Einhaengen des Kontos NICHT aendern — weder im Ergebnis
     * noch in den Cache-Schluesseln, sonst waere jemand im alten Portal
     * gesperrt und im neuen offen.
     */
    public function test_der_alte_token_weg_funktioniert_unveraendert(): void
    {
        $auth = $this->auth();
        $ma = $auth->employeeForToken('tok-1');

        $this->assertNotNull($ma);
        $this->assertSame(PortalAuth::OK, $auth->attempt($ma, 'tok-1', self::GEBURT, '0T47')['status']);
        $this->assertSame(4, $auth->attempt($ma, 'tok-1', self::GEBURT, '9999')['verbleibend']);

        for ($i = 0; $i < 4; $i++) {
            $auth->attempt($ma, 'tok-1', self::GEBURT, '9999');
        }

        $this->assertTrue($auth->isRateLimited('tok-1'));
        $this->assertTrue((bool) $this->cache->get('employee_portal_locked:tok-1'));
    }

    // ------------------------------------------------------------ Konto-Weg

    public function test_anmelden_mit_nummer_und_passwort(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT);

        $this->assertSame(PortalAuth::OK, $ergebnis['status']);
        $this->assertSame($this->personId, $ergebnis['personId']);
    }

    public function test_ein_falsches_passwort_kommt_nicht_hinein(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT);

        $this->assertSame(PortalAuth::FALSCH, $ergebnis['status']);
        $this->assertNull($ergebnis['personId']);
    }

    /** Die getippte Form muss dieselbe Person finden wie die gespeicherte. */
    public function test_die_getippte_nummer_findet_dieselbe_person(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER_GETIPPT, self::PASSWORT);

        $this->assertSame(PortalAuth::OK, $ergebnis['status']);
        $this->assertSame($this->personId, $ergebnis['personId']);
    }

    /**
     * Ruling GD-2: die Anmeldeseite ist OEFFENTLICH und hat keinen
     * Team-Kontext — sie uebergibt null. Wuerde ohne Team gar nicht gesucht
     * (oder nur im Team null), koennte sich niemand anmelden, und zwar
     * lautlos: die Antwort ist dieselbe wie bei falschem Passwort.
     */
    public function test_ohne_team_wird_ueber_die_teamgrenze_hinweg_gefunden(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT);

        $this->assertSame(PortalAuth::OK, $ergebnis['status']);
        $this->assertSame($this->personId, $ergebnis['personId']);
    }

    /**
     * Eine unbekannte Nummer bekommt dieselbe Antwort wie ein falsches
     * Passwort — und denselben gerechneten Vergleich. Kehrte die
     * Anmeldeschicht frueher zurueck, verriete die Antwortzeit, ob es das
     * Konto gibt, und Handynummern lassen sich durchprobieren.
     */
    public function test_eine_unbekannte_nummer_antwortet_wie_ein_falsches_passwort(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(null, '+4915999999999', self::PASSWORT);

        $this->assertSame(PortalAuth::FALSCH, $ergebnis['status']);
        $this->assertNull($ergebnis['personId']);
        $this->assertEchtVerglichen();
    }

    /** Auch eine gar nicht lesbare Eingabe darf nicht frueher zurueckkehren. */
    public function test_auch_eine_unlesbare_nummer_kostet_einen_echten_vergleich(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(null, 'keine-nummer', self::PASSWORT);

        $this->assertSame(PortalAuth::FALSCH, $ergebnis['status']);
        $this->assertEchtVerglichen();
    }

    // --------------------------------------------------------------- Drossel

    public function test_fuenf_fehlversuche_sperren_die_nummer(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(
                PortalAuth::FALSCH,
                $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT)['status'],
                'Versuch ' . ($i + 1) . ' zaehlt nur herunter',
            );
        }

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT)['status'],
            'der fuenfte Fehlversuch sperrt',
        );

        $ergebnis = $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT);
        $this->assertSame(PortalAuth::GESPERRT, $ergebnis['status'], 'auch mit RICHTIGEM Passwort bleibt gesperrt');
        $this->assertNull($ergebnis['personId']);
    }

    /**
     * Die Drossel greift auch bei einer Nummer, zu der es gar kein Konto
     * gibt — sonst waere der Unterschied im Verhalten die Auskunft, die die
     * Meldungen vermeiden.
     */
    public function test_auch_eine_unbekannte_nummer_wird_gedrosselt(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->anmeldenMitNummer(null, '+4915999999999', self::PASSWORT);
        }

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, '+4915999999999', self::PASSWORT)['status'],
        );
    }

    /**
     * Der Schluessel haengt an der NORMALISIERTEN Nummer. Sonst schuettelt
     * man die Sperre durch eine andere Schreibweise derselben Nummer ab —
     * "0151 ..." statt "+49151 ..." — und die Drossel waere wirkungslos.
     */
    public function test_eine_andere_schreibweise_schuettelt_die_sperre_nicht_ab(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT);
        }

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER_GETIPPT, self::PASSWORT)['status'],
            'dieselbe Nummer, andere Schreibweise — dieselbe Sperre',
        );
    }

    /** Eine andere Nummer ist ein anderes Ziel und hat ihren eigenen Zaehler. */
    public function test_eine_andere_nummer_bleibt_offen(): void
    {
        $zweite = $this->person(self::TEAM, '+4915222222222', 'p-zweite');
        $this->anstellung($zweite, 'tok-2', ['phone' => '+4915222222222']);
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT);
        }

        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(self::TEAM, '+4915222222222', self::PASSWORT)['status'],
        );
    }

    /**
     * Beide Wege laufen waehrend der Umstellung nebeneinander, aber sie
     * duerfen sich nicht verschraenken: eine gesperrte Nummer darf den
     * Token-Weg nicht mitsperren (sonst sperrte ein Fremder einen
     * Mitarbeiter aus, indem er dessen Nummer durchprobiert), und ein
     * gesperrter Token darf das Konto nicht mitsperren.
     */
    public function test_die_sperre_haengt_an_der_nummer_nicht_am_token(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT);
        }

        $this->assertFalse($auth->isRateLimited('tok-1'), 'die Nummern-Sperre sperrt den Token-Weg nicht mit');
        $ma = $auth->employeeForToken('tok-1');
        $this->assertSame(PortalAuth::OK, $auth->attempt($ma, 'tok-1', self::GEBURT, '0T47')['status']);
    }

    public function test_ein_gesperrter_token_sperrt_die_nummer_nicht_mit(): void
    {
        $auth = $this->auth();
        $ma = $auth->employeeForToken('tok-1');

        for ($i = 0; $i < 5; $i++) {
            $auth->attempt($ma, 'tok-1', self::GEBURT, '9999');
        }

        $this->assertTrue($auth->isRateLimited('tok-1'));
        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
        );
    }

    /**
     * Beide Einstiege teilen sich denselben Speicher und dieselben Zaehler,
     * aber nie einen Schluessel. Die Probe aufs Exempel: ein Token, der
     * zeichengleich mit der Nummer ist. Ohne eigenen Namensraum waere das
     * DERSELBE Cache-Eintrag — und ein durchprobierter Token sperrte das
     * Konto mit, obwohl beide Wege nichts miteinander zu tun haben.
     */
    public function test_token_und_nummer_teilen_sich_keinen_schluessel(): void
    {
        $auth = $this->auth();
        $zwilling = $this->auth()->employeeForToken('tok-1');
        DB::table('rec_employees')->where('id', $zwilling->id)->update(['portal_token' => self::NUMMER]);
        Model::clearBootedModels();

        $ma = $auth->employeeForToken(self::NUMMER);
        for ($i = 0; $i < 5; $i++) {
            $auth->attempt($ma, self::NUMMER, self::GEBURT, '9999');
        }

        $this->assertTrue($auth->isRateLimited(self::NUMMER));
        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
            'der gesperrte Token darf das gleichnamige Konto nicht mitsperren',
        );
    }

    public function test_eine_gelungene_anmeldung_raeumt_den_zaehler_ab(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < 4; $i++) {
            $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT);
        }

        $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT);

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(
                PortalAuth::FALSCH,
                $auth->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT)['status'],
                'nach der gelungenen Anmeldung beginnt die Zaehlung von vorn',
            );
        }
    }

    // ----------------------------------------------------------- Dispo-Sperre

    /**
     * rec_employees.portal_locked_at ist die Eskalations-Stufe 3 aus der
     * Dispo. KontoWriter prueft sie bewusst NICHT (sie haengt an der
     * Anstellung, nicht am Menschen) — also muss sie hier greifen.
     */
    public function test_eine_dispo_gesperrte_person_kommt_nicht_rein(): void
    {
        DB::table('rec_employees')->where('rec_person_id', $this->personId)
            ->update(['portal_locked_at' => self::ANGEFASST]);

        $ergebnis = $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT);

        $this->assertSame(PortalAuth::GESPERRT, $ergebnis['status']);
        $this->assertNull($ergebnis['personId'], 'gesperrt heisst: keine Identitaet herausgeben');
    }

    /**
     * Wie im Einsatz-Bereich (EmployeeAssignments) gilt die Sperre fuer die
     * ganze Gruppe: ist EINE Anstellung des Menschen gesperrt, ist das
     * Portal zu. Zwei verschiedene Antworten auf dieselbe Sperre waeren die
     * Luecke — gesperrt im Einsatz-Bereich, offen an der Anmeldung.
     */
    public function test_eine_gesperrte_von_zwei_anstellungen_genuegt(): void
    {
        $this->anstellung($this->personId, 'tok-zweit', ['portal_locked_at' => self::ANGEFASST]);

        $this->assertSame(
            PortalAuth::GESPERRT,
            $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
        );
    }

    /**
     * Die Sperre wird erst NACH dem Passwort ausgewertet. Andersherum
     * verriete die Antwort "gesperrt" jedem, der die Nummer kennt, dass es
     * dazu ein Konto gibt — und dass es gesperrt ist.
     */
    public function test_ohne_passwort_erfaehrt_niemand_von_der_sperre(): void
    {
        DB::table('rec_employees')->where('rec_person_id', $this->personId)
            ->update(['portal_locked_at' => self::ANGEFASST]);

        $this->assertSame(
            PortalAuth::FALSCH,
            $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::FALSCHES_PASSWORT)['status'],
            'falsches Passwort meldet falsch, nicht gesperrt',
        );
    }

    /**
     * Eine Sperre an einer FREMDEN Anstellung darf niemanden aussperren —
     * sonst sperrt die Eskalation eines Kollegen das halbe Haus aus.
     */
    public function test_eine_fremde_sperre_sperrt_nicht_mit(): void
    {
        $fremd = $this->person(self::TEAM, '+4915333333333', 'p-fremd');
        $this->anstellung($fremd, 'tok-fremd', [
            'phone'            => '+4915333333333',
            'portal_locked_at' => self::ANGEFASST,
        ]);

        $this->assertSame(
            PortalAuth::OK,
            $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
        );
    }

    /**
     * Ruling GD-9: Die Anmeldung muss GENAU die Menge fragen, die die
     * Entsperrung bedient. HR entsperrt ueber DispoIdentityResolver::
     * groupFor() (Show.php:354), und die kennt nur AKTIVE Anstellungen am
     * gemeinsamen CRM-Kontakt.
     *
     * Der Fall hier: die Eskalation hat beide Anstellungen gesperrt,
     * inzwischen ist die zweite beendet. HR drueckt den Knopf — er erreicht
     * nur noch die aktive. Fragte die Anmeldung eine groessere Menge,
     * bekaeme HR „Portalzugang entsperrt" zu sehen, und der Mensch kaeme
     * trotzdem nicht hinein: eine Sperre, die sich nicht mehr aufheben
     * laesst und nur per SQL zu heilen waere.
     */
    public function test_nach_der_hr_entsperrung_kommt_man_wieder_hinein(): void
    {
        $aktiv = (int) DB::table('rec_employees')->where('rec_person_id', $this->personId)->value('id');
        $beendet = $this->anstellung($this->personId, 'tok-beendet', ['is_active' => 0]);
        DB::table('rec_employees')->whereIn('id', [$aktiv, $beendet])
            ->update(['portal_locked_at' => self::ANGEFASST]);

        $this->assertSame(
            PortalAuth::GESPERRT,
            $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
            'Vorflug: die Eskalation sperrt',
        );

        $this->hrEntsperrt($aktiv);

        $this->assertNotNull(
            DB::table('rec_employees')->where('id', $beendet)->value('portal_locked_at'),
            'Vorflug: an der beendeten Anstellung bleibt die Sperre stehen — genau das ist der Fall',
        );
        $this->assertSame(
            PortalAuth::OK,
            $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
            'was HR entsperrt hat, muss auch offen sein',
        );
    }

    /**
     * Die andere Haelfte derselben Naht: ein gesperrter Datensatz, den
     * groupFor() ueber den CRM-Kontakt findet, den die Personen-Klammer aber
     * nicht kennt (rec_person_id fehlt). Im Einsatz-Bereich ist der Mensch
     * damit gesperrt — an der Anmeldung darf er es nicht anders sein.
     */
    public function test_ein_gesperrter_geschwister_datensatz_ohne_personen_klammer_sperrt_mit(): void
    {
        $aktiv = (int) DB::table('rec_employees')->where('rec_person_id', $this->personId)->value('id');
        $geschwister = $this->anstellung($this->personId, 'tok-geschwister', [
            'rec_person_id'    => null,
            'portal_locked_at' => self::ANGEFASST,
        ]);
        $this->crmVerknuepfung($aktiv, 4711);
        $this->crmVerknuepfung($geschwister, 4711);

        $this->assertSame(
            [$aktiv, $geschwister],
            app(DispoIdentityResolver::class)->groupFor($aktiv),
            'Vorflug: die Dispo sieht beide als EINE Person',
        );
        $this->assertSame(
            PortalAuth::GESPERRT,
            $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER, self::PASSWORT)['status'],
        );
    }

    // ------------------------------------------------------------- Migration

    /**
     * Seit Ruling GD-2 fragt die Anmeldung bei teamId = null ALLEIN nach
     * phone. unique(team_id, phone) traegt das nicht — mit team_id an erster
     * Stelle wird die Tabelle voll gelesen. Heute belanglos, aber die
     * Anmeldeseite ist ein oeffentlicher, durchprobierbarer Einstieg.
     *
     * Der Test faehrt die Migration gegen das handgebaute Schema: Wache
     * (zweimal up() darf nicht scheitern) und down() als echte Umkehrung.
     */
    public function test_die_migration_legt_den_index_auf_phone_an(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_09_29_000002_add_phone_index_to_rec_persons.php';

        $this->assertFalse(Schema::hasIndex('rec_persons', 'rec_persons_phone_index'), 'Vorflug: noch kein Index');

        $migration->up();
        $this->assertTrue(Schema::hasIndex('rec_persons', 'rec_persons_phone_index'));

        // Und zwar auf phone ALLEIN. Ein Index mit team_id an erster Stelle
        // traegt die Abfrage nicht — genau den gibt es als unique schon, und
        // genau deshalb steht diese Migration hier.
        $spalten = collect(Schema::getIndexes('rec_persons'))
            ->firstWhere('name', 'rec_persons_phone_index')['columns'] ?? [];
        $this->assertSame(['phone'], $spalten);

        // Die Wache: auf der Demo kann die Migration schon gelaufen sein.
        $migration->up();
        $this->assertTrue(Schema::hasIndex('rec_persons', 'rec_persons_phone_index'));

        $migration->down();
        $this->assertFalse(Schema::hasIndex('rec_persons', 'rec_persons_phone_index'));

        // down() darf auch dann nicht scheitern, wenn es nichts zu tun gibt.
        $migration->down();
        $this->assertFalse(Schema::hasIndex('rec_persons', 'rec_persons_phone_index'));
    }
}
