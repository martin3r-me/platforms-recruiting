<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Carbon;
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

    /** Die Adresse, von der die Anfragen in diesen Tests kommen. */
    private const IP = '203.0.113.7';

    private const ANDERE_IP = '198.51.100.9';

    private Capsule $capsule;

    private Repository $cache;

    /** @var object{geschrieben: list<string>} die Cache-Attrappe hinter $cache */
    private object $store;

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

        // Der Wirt faehrt CACHE_STORE=database, und cache.key ist dort
        // varchar(255) PRIMARY KEY bei striktem MySQL. Ein ArrayStore kennt
        // keine Grenze und wuerde einen ueberlangen Schluessel klaglos
        // schlucken — die Attrappe zieht die Grenze des Wirts ein und merkt
        // sich, welche Schluessel ueberhaupt geschrieben wurden.
        $this->store = new class extends ArrayStore {
            /** @var list<string> */
            public array $geschrieben = [];

            public function put($key, $value, $seconds): bool
            {
                if (strlen((string) $key) > 255) {
                    throw new \RuntimeException(
                        'SQLSTATE[22001]: Data too long for column key (' . strlen((string) $key) . ' Zeichen)',
                    );
                }
                $this->geschrieben[] = (string) $key;

                return parent::put($key, $value, $seconds);
            }
        };
        $this->cache = new Repository($this->store);

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

        // Die IP-Bremse (Ruling GD-11) braucht eine Anfrage. Ohne sie fiele
        // alles auf einen gemeinsamen Schluessel, und die Tests, die zwei
        // Adressen unterscheiden, waeren stumm gruen.
        $this->vonIp(self::IP);

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
        $container->forgetInstance('request');

        // setTrustedProxies ist PROZESSWEIT statisch. Bleibt der Eintrag
        // stehen, liest jede spaetere Testklasse ihre Adresse ploetzlich aus
        // einer Kopfzeile — ein Schaden, der nur im Gesamtlauf auffaellt.
        Request::setTrustedProxies([], 0);

        // Die Uhr ist PROZESSWEIT statisch — bleibt sie gestellt, rechnen
        // alle spaeteren Testklassen mit einem Datum aus dieser Klasse.
        Carbon::setTestNow();

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

    /**
     * Alle folgenden Anfragen kommen von dieser Verbindung — wahlweise mit
     * einer gefaelschten Kopfzeile X-Forwarded-For.
     *
     * DER WIRT WIRD DABEI NACHGEBAUT, und zwar genau so, wie Laravels
     * TrustProxies-Werk es bei at: '*' tut: die anrufende Adresse gilt als
     * vertrauenswuerdiger Vermittler (meingedeck/bootstrap/app.php). Ohne
     * das waere diese Attrappe GROSSZUEGIGER als der Wirt — ip() lieferte
     * dann brav REMOTE_ADDR, und der Test bliebe gruen, egal welche Quelle
     * die Bremse nimmt.
     */
    private function vonIp(string $ip, ?string $gefaelschteKopfzeile = null): Request
    {
        $server = ['REMOTE_ADDR' => $ip];

        if ($gefaelschteKopfzeile !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $gefaelschteKopfzeile;
        }

        $anfrage = Request::create('/recruiting/konto', 'GET', [], [], [], $server);
        $anfrage->setTrustedProxies([$ip], Request::HEADER_X_FORWARDED_FOR);

        Container::getInstance()->instance('request', $anfrage);

        return $anfrage;
    }

    /**
     * Die Nummern, die ein Durchprobierer der Reihe nach abarbeitet. Jede
     * bekommt ihren eigenen Zaehler, keiner erreicht die Fuenf — genau
     * deshalb braucht es die zweite Bremse.
     */
    private function fremdeNummer(int $lauf): string
    {
        return '+4915190' . str_pad((string) $lauf, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Legt die Konten an, gegen die der Durchprobierer laeuft — alle mit
     * DEMSELBEN, einmal gerechneten Hash.
     *
     * Das ist kein Schoenheitsfehler, sondern Absicht: eine Nummer, die es
     * gar nicht gibt, laesst pruefeAnmeldung() gegen den Leerlauf-Hash
     * rechnen, und der traegt bewusst zwoelf Runden. Dreissig solcher Laeufe
     * je Test kosten Sekunden. Gegen ein vorhandenes Konto laeuft der
     * Vergleich mit den vier Runden des Test-Hashers. Am Gepruefen aendert
     * das nichts: die IP-Bremse zaehlt jeden Fehlschlag gleich, ob die Nummer
     * unbekannt ist oder das Passwort nicht passt.
     */
    private function fremdeKonten(int $anzahl): void
    {
        // Gezaehlt wird ab eins: fremdeNummer(0) bleibt bewusst OHNE Konto,
        // damit bisKurzVorDieGrenze() einen echten Leerlauf-Fall hat (N2).
        $hash = Hash::make(self::PASSWORT);

        for ($i = 1; $i <= $anzahl; $i++) {
            DB::table('rec_persons')->insert([
                'uuid'          => 'p-fremd-' . $i,
                'team_id'       => self::TEAM,
                'phone'         => $this->fremdeNummer($i),
                'password_hash' => $hash,
                'created_at'    => self::ANGEFASST,
                'updated_at'    => self::ANGEFASST,
            ]);
        }
    }

    /**
     * Die Adresse bis dicht unter die Grenze fahren: ein Fehlversuch weniger,
     * als sie sperrt.
     */
    private function bisKurzVorDieGrenze(PortalAuth $auth): void
    {
        for ($i = 1; $i <= PortalAuth::MAX_IP_ATTEMPTS - 1; $i++) {
            // DER ERSTE VERSUCH LAEUFT GEGEN EINE NUMMER OHNE KONTO (Fund
            // N2). Das ist der teure Fall — pruefeAnmeldung() rechnet dafuer
            // gegen den Leerlauf-Hash mit zwoelf Runden —, aber genau er
            // deckt die tragende Zusicherung ab: der IP-Zaehler haengt an der
            // Adresse und NICHT daran, ob es zu einer Nummer ein Konto gibt.
            // Liefe er nur bei vorhandenen Konten mit, waere er ein Orakel
            // ueber deren Bestand. Einer genuegt dafuer, die uebrigen
            // achtundzwanzig bleiben billig.
            //
            // Die Beschleunigung dieser Schleife (22 s auf 1,8 s) hatte genau
            // diese eine Zusicherung stillgelegt: die zugehoerige Mutation
            // blieb gruen, weil alle Versuche gegen vorhandene Konten liefen.
            $nummer = $i === 1 ? $this->fremdeNummer(0) : $this->fremdeNummer($i);

            $ergebnis = $auth->anmeldenMitNummer(null, $nummer, self::FALSCHES_PASSWORT);

            $this->assertSame(
                PortalAuth::FALSCH,
                $ergebnis['status'],
                "Vorflug: Versuch {$i} darf die Bremse noch nicht ausloesen",
            );
        }
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

    /**
     * Die Eingabe kommt von einer oeffentlichen Seite und ist unbegrenzt
     * lang. Landete sie roh im Cache-Schluessel, wuerde das Schreiben des
     * Zaehlers beim Wirt (CACHE_STORE=database, cache.key varchar(255))
     * SQLSTATE[22001] werfen — eine 500er-Antwort auf der Anmeldeseite, von
     * jedem ausloesbar. Die Cache-Attrappe zieht dieselbe Grenze.
     */
    public function test_eine_ueberlange_eingabe_antwortet_falsch_statt_zu_werfen(): void
    {
        $ergebnis = $this->auth()->anmeldenMitNummer(null, str_repeat('9', 500), self::PASSWORT);

        $this->assertSame(PortalAuth::FALSCH, $ergebnis['status']);
        $this->assertNotEmpty($this->store->geschrieben, 'der Fehlversuch muss trotzdem gezaehlt worden sein');
    }

    /**
     * Die vollstaendige Handynummer darf nicht fuenfzehn Minuten im Klartext
     * in der Cache-Tabelle stehen — KontoWriter kuerzt dieselbe Nummer vor
     * einer blossen Log-Zeile mit Datenschutz-Begruendung auf vier Stellen.
     */
    public function test_der_cache_schluessel_traegt_die_nummer_nicht_im_klartext(): void
    {
        $this->auth()->anmeldenMitNummer(self::TEAM, self::NUMMER_GETIPPT, self::FALSCHES_PASSWORT);

        $this->assertNotEmpty($this->store->geschrieben);

        foreach ($this->store->geschrieben as $schluessel) {
            $this->assertStringNotContainsString(self::NUMMER, $schluessel);
            $this->assertStringNotContainsString(self::NUMMER_GETIPPT, $schluessel);
            $this->assertStringNotContainsString('15111111111', $schluessel, 'auch nicht ohne Vorwahl-Schreibweise');
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
     * bekaeme HR "Portalzugang entsperrt" zu sehen, und der Mensch kaeme
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

    /**
     * SCHLUSSPRUEFUNG C2: die Umkehrung der Stufe-1-Migration scheiterte auf
     * SQLite, weil die Spalte gedroppt wurde, ohne ihren Index vorher zu
     * loeschen ("error in index rec_employees_rec_person_id_index after drop
     * column").
     *
     * Auf MySQL — Produktion und Demo — war das folgenlos: MySQL raeumt
     * einen Index ueber genau diese eine Spalte selbst mit ab. Getroffen
     * haette es nur einen Rollback auf SQLite, also genau die Umgebung, in
     * der man eine Migration ueblicherweise ausprobiert; und wer sie je als
     * Vorlage kopiert, erbt den Fehler.
     *
     * Der Test faehrt den vollen Kreis gegen das handgebaute Schema. Die
     * Spalte wird vorher entfernt, sonst legte up() sie (und ihren Index)
     * gar nicht erst an und die Umkehrung haette nichts zu tun.
     */
    public function test_die_personen_klammer_laesst_sich_umkehren(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php';

        Schema::table('rec_employees', function ($t) {
            $t->dropColumn('rec_person_id');
        });

        $migration->up();

        // Vorflug: erst wenn der Index WIRKLICH da ist, prueft die Umkehrung
        // unten etwas. Ohne ihn liefe down() klaglos durch, auch in der
        // kaputten Fassung.
        $this->assertTrue(Schema::hasColumn('rec_employees', 'rec_person_id'));
        $this->assertTrue(
            Schema::hasIndex('rec_employees', 'rec_employees_rec_person_id_index'),
            'Vorflug: ohne Index ist die Umkehrung nicht auf die Probe gestellt',
        );

        // Die Wache: auf der Demo kann die Migration schon gelaufen sein.
        $migration->up();
        $this->assertTrue(Schema::hasColumn('rec_employees', 'rec_person_id'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('rec_employees', 'rec_person_id'));
        $this->assertFalse(Schema::hasIndex('rec_employees', 'rec_employees_rec_person_id_index'));

        // down() darf auch dann nicht scheitern, wenn es nichts zu tun gibt.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('rec_employees', 'rec_person_id'));
    }

    // ------------------------------------------- Ruling GD-11: die IP-Bremse

    /**
     * Der Angriff, den die Nummern-Bremse NICHT trifft: EIN verbreitetes
     * Passwort gegen VIELE Nummern. Jede Nummer bekommt ihren eigenen
     * Zaehler, keiner erreicht die Fuenf — ohne die zweite Bremse liefe das
     * unbegrenzt, und jeder Versuch kostete uns einen vollen bcrypt-Lauf.
     */
    public function test_dreissig_fehlversuche_ueber_viele_nummern_sperren_die_ip(): void
    {
        $this->fremdeKonten(PortalAuth::MAX_IP_ATTEMPTS);
        $auth = $this->auth();

        $this->bisKurzVorDieGrenze($auth);

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(
                null,
                $this->fremdeNummer(PortalAuth::MAX_IP_ATTEMPTS),
                self::FALSCHES_PASSWORT,
            )['status'],
            'Der dreissigste Fehlversuch von derselben Adresse muss sperren.',
        );

        // Und danach kommt auch das RICHTIGE Passwort nicht mehr durch —
        // sonst waere die Bremse an der entscheidenden Stelle offen.
        $danach = $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT);
        $this->assertSame(PortalAuth::GESPERRT, $danach['status']);
        $this->assertNull($danach['personId']);
    }

    /**
     * Die Bremse soll die KOSTEN deckeln, nicht bloss die Antwort aendern:
     * hinter ihr darf kein bcrypt-Lauf mehr stattfinden.
     */
    public function test_die_ip_sperre_spart_den_passwortvergleich(): void
    {
        $this->fremdeKonten(PortalAuth::MAX_IP_ATTEMPTS);
        $auth = $this->auth();

        $this->bisKurzVorDieGrenze($auth);
        $auth->anmeldenMitNummer(null, $this->fremdeNummer(PortalAuth::MAX_IP_ATTEMPTS), self::FALSCHES_PASSWORT);

        $vorher = count($this->hasher->gepruefteHashes);
        $this->assertGreaterThan(0, $vorher, 'Vorflug: vor der Sperre wurde sehr wohl verglichen');

        $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT);

        $this->assertSame(
            $vorher,
            count($this->hasher->gepruefteHashes),
            'Hinter der IP-Sperre wurde noch ein bcrypt-Lauf gerechnet — genau dessen Kosten soll sie deckeln.',
        );
    }

    /**
     * Die beiden Bremsen sind verschiedene Schluessel. Fuenf Fehlversuche auf
     * EINER Nummer sperren diese Nummer — aber nicht die ganze Adresse, sonst
     * sperrte ein Fremder ein ganzes Buero aus, indem er eine oeffentlich
     * bekannte Nummer durchprobiert.
     */
    public function test_die_nummern_sperre_sperrt_nicht_die_ganze_ip(): void
    {
        $auth = $this->auth();

        for ($i = 0; $i < PortalAuth::MAX_ATTEMPTS; $i++) {
            $auth->anmeldenMitNummer(null, self::NUMMER, self::FALSCHES_PASSWORT);
        }
        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Vorflug: die Nummer muss jetzt gesperrt sein',
        );

        // Ein Kollege am selben Anschluss kommt weiterhin hinein.
        $kollegeId = $this->person(self::TEAM, '+4915144444444', 'p-kollege');
        $this->anstellung($kollegeId, 'tok-kollege');

        $ergebnis = $auth->anmeldenMitNummer(null, '+4915144444444', self::PASSWORT);

        $this->assertSame(PortalAuth::OK, $ergebnis['status']);
        $this->assertSame($kollegeId, $ergebnis['personId']);
    }

    /**
     * Eine richtige Anmeldung raeumt den Zaehler DER NUMMER ab, nicht den der
     * Adresse. Sonst haette jeder Durchprobierer mit einem eigenen Konto in
     * der Hand alle dreissig Versuche einen Knopf "Zaehler auf null".
     */
    public function test_eine_richtige_anmeldung_raeumt_den_ip_zaehler_nicht_ab(): void
    {
        $this->fremdeKonten(PortalAuth::MAX_IP_ATTEMPTS);
        $auth = $this->auth();

        $this->bisKurzVorDieGrenze($auth);

        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Vorflug: vor der Grenze kommt das eigene Konto noch durch',
        );

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(
                null,
                $this->fremdeNummer(PortalAuth::MAX_IP_ATTEMPTS),
                self::FALSCHES_PASSWORT,
            )['status'],
            'Die richtige Anmeldung hat den IP-Zaehler abgeraeumt.',
        );
    }

    /**
     * Ruling GD-12: eine gefaelschte Kopfzeile verschiebt den Zaehler NICHT.
     *
     * Der Wirt vertraut allen Vermittlern (bootstrap/app.php, trustProxies
     * at: '*'), also bestimmt der Anfragende, was $request->ip() liefert.
     * Haengte der Zaehler daran, gaebe ein neuer Kopfzeilen-Wert je Anfrage
     * einen frischen Zaehler — die Bremse waere wirkungslos —, und umgekehrt
     * liesse sich damit ein fremdes Buero aussperren.
     */
    public function test_eine_gefaelschte_kopfzeile_verschiebt_den_zaehler_nicht(): void
    {
        $this->fremdeKonten(PortalAuth::MAX_IP_ATTEMPTS);
        $auth = $this->auth();

        // Vorflug: der Wirt IST faelschbar. Ohne diesen Nachweis pruefte der
        // Test unten nur, dass zwei gleiche Dinge gleich sind.
        $anfrage = $this->vonIp(self::IP, '9.9.9.9');
        $this->assertSame('9.9.9.9', $anfrage->ip(), 'Vorflug: ip() muss hier die Kopfzeile liefern');
        $this->assertSame(self::IP, $anfrage->server->get('REMOTE_ADDR'));

        // Jede Anfrage mit einer ANDEREN Kopfzeile. Haengte der Zaehler an
        // ip(), bekaeme jede ihren eigenen und keiner erreichte je die
        // Grenze.
        for ($i = 1; $i <= PortalAuth::MAX_IP_ATTEMPTS; $i++) {
            $this->vonIp(self::IP, '9.9.9.' . $i);
            $auth->anmeldenMitNummer(null, $this->fremdeNummer($i), self::FALSCHES_PASSWORT);
        }

        $this->vonIp(self::IP, '9.9.9.200');

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Die Bremse laesst sich mit einer selbstgeschriebenen Kopfzeile abschuetteln.',
        );
    }

    public function test_eine_andere_adresse_ist_nicht_mitgesperrt(): void
    {
        $this->fremdeKonten(PortalAuth::MAX_IP_ATTEMPTS);
        $auth = $this->auth();

        $this->bisKurzVorDieGrenze($auth);
        $auth->anmeldenMitNummer(null, $this->fremdeNummer(PortalAuth::MAX_IP_ATTEMPTS), self::FALSCHES_PASSWORT);

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Vorflug: diese Adresse muss gesperrt sein',
        );

        $this->vonIp(self::ANDERE_IP);

        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Die Sperre einer Adresse hat eine andere mitgetroffen.',
        );
    }

    // ----------------------------- Die ZWEI ZAHLEN der IP-Bremse, ausgeschrieben

    /*
     * WARUM HIER 30 UND 60 AUSGESCHRIEBEN STEHEN und nicht
     * PortalAuth::MAX_IP_ATTEMPTS / ::IP_LOCKOUT_MINUTES.
     *
     * Alle Tests darueber lesen die Konstanten selbst. Sie belegen damit die
     * TRENNUNG der beiden Bremsen (je Nummer / je Adresse), aber nicht ihre
     * GROESSE: setzt jemand MAX_IP_ATTEMPTS auf 3000, zaehlen ihre Schleifen
     * brav bis 3000 mit und bleiben gruen — beide Mutationen (30 -> 3000 und
     * 60 -> 1) liessen die volle Suite gruen (Schlusspruefung B3, Variante 7:
     * Code und Test lesen dieselbe Konstante).
     *
     * Die beiden folgenden Tests nennen die Zahlen deshalb selbst und pruefen
     * jede Grenze von BEIDEN Seiten: eine Probe allein liesse eine Grenze von
     * drei oder von dreitausend durchgehen.
     *
     * Kosten, falls die Zahlen sich aendern sollen: diese beiden Tests
     * scheitern und muessen mitgeaendert werden. Genau das ist der Zweck —
     * eine Kostenbremse, die sich lautlos verstellen laesst, ist keine.
     */

    /**
     * Die GRENZE: neunundzwanzig Fehlversuche sperren noch nicht, dreissig
     * sperren.
     */
    public function test_die_ip_bremse_sperrt_bei_dreissig_fehlversuchen_und_keinem_frueher(): void
    {
        $this->fremdeKonten(30);
        $auth = $this->auth();

        for ($i = 1; $i <= 29; $i++) {
            // Der erste Versuch laeuft gegen eine Nummer OHNE Konto (Fund
            // N2) — der Zaehler haengt an der Adresse und nicht daran, ob es
            // zu einer Nummer ein Konto gibt.
            $nummer = $i === 1 ? $this->fremdeNummer(0) : $this->fremdeNummer($i);

            $this->assertSame(
                PortalAuth::FALSCH,
                $auth->anmeldenMitNummer(null, $nummer, self::FALSCHES_PASSWORT)['status'],
                "Untere Seite der Grenze: Fehlversuch {$i} darf noch nicht sperren (die Grenze ist 30).",
            );
        }

        // Untere Seite, zweite Probe: nach neunundzwanzig Fehlversuchen kommt
        // ein richtiges Passwort von derselben Adresse noch durch. Eine
        // erfolgreiche Anmeldung raeumt den IP-Zaehler bewusst NICHT ab (s.
        // Test darueber), der naechste Fehlversuch ist also der dreissigste.
        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Nach 29 Fehlversuchen darf die Adresse noch nicht gesperrt sein.',
        );

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, $this->fremdeNummer(30), self::FALSCHES_PASSWORT)['status'],
            'Obere Seite der Grenze: der DREISSIGSTE Fehlversuch muss sperren.',
        );

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Und danach kommt auch das richtige Passwort nicht mehr durch.',
        );
    }

    /**
     * Die DAUER: nach neunundfuenfzig Minuten noch gesperrt, nach
     * einundsechzig wieder frei.
     *
     * Die Sperre laeuft ueber die Lebensdauer des Cache-Eintrags ab und nicht
     * ueber einen eigenen Zeitvergleich — deshalb wird hier die Uhr gestellt
     * und nicht gewartet. Die Cache-Attrappe erbt die Ablaufrechnung des
     * ArrayStore und folgt der gestellten Uhr.
     */
    public function test_die_ip_sperre_haelt_eine_stunde_und_keine_minute_laenger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00'));

        $this->fremdeKonten(30);
        $auth = $this->auth();

        for ($i = 1; $i <= 30; $i++) {
            $nummer = $i === 1 ? $this->fremdeNummer(0) : $this->fremdeNummer($i);
            $auth->anmeldenMitNummer(null, $nummer, self::FALSCHES_PASSWORT);
        }

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Vorflug: die Adresse muss jetzt gesperrt sein, sonst prueft der Rest nichts.',
        );

        Carbon::setTestNow(Carbon::parse('2026-09-29 10:59:00'));

        $this->assertSame(
            PortalAuth::GESPERRT,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Nach 59 Minuten muss die Sperre noch stehen — sie gilt eine volle Stunde.',
        );

        Carbon::setTestNow(Carbon::parse('2026-09-29 11:01:00'));

        $this->assertSame(
            PortalAuth::OK,
            $auth->anmeldenMitNummer(null, self::NUMMER, self::PASSWORT)['status'],
            'Nach 61 Minuten muss die Sperre abgelaufen sein — sie ist eine Bremse, keine Verbannung.',
        );
    }

    /**
     * Eine IP ist ein personenbezogenes Datum und gehoert nicht im Klartext
     * in die Cache-Tabelle — dieselbe Regel, nach der KontoWriter sogar vor
     * einer Log-Zeile die Handynummer auf vier Stellen kuerzt.
     */
    public function test_die_adresse_steht_nicht_im_klartext_im_speicher(): void
    {
        $this->auth()->anmeldenMitNummer(null, $this->fremdeNummer(1), self::FALSCHES_PASSWORT);

        $this->assertNotSame([], $this->store->geschrieben, 'Vorflug: es wurde ueberhaupt geschrieben');

        foreach ($this->store->geschrieben as $schluessel) {
            $this->assertStringNotContainsString(self::IP, $schluessel);
        }
    }
}
