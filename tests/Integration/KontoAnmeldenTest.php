<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Attributes\Locked;
use Livewire\Mechanisms\DataStore;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\KontoAnmelden;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Die Anmeldeseite des Mitarbeiterkontos: Handynummer und Passwort.
 *
 * Geprueft wird vor allem, was still falsch sein koennte:
 *
 *  - DIE PERSONEN-/ANSTELLUNGS-VERWECHSLUNG. anmeldenMitNummer() liefert
 *    eine PERSONEN-Kennung, sessionKey() erwartet eine ANSTELLUNGS-Kennung.
 *    Beide sind int. Wer sie vertauscht, oeffnet mit einem richtigen
 *    Passwort die Portal-Sitzung eines FREMDEN Menschen — naemlich der
 *    Anstellung, deren Kennung zufaellig gleich der Personen-Kennung ist.
 *    Die Daten dieses Tests sind genau so gebaut, dass dieser Zufall
 *    eintritt.
 *  - DER OFFENE WEITERLEITER. Das Ziel faehrt in der Adresse mit; ohne
 *    Positivliste waere die Seite ein bequemer Absprung auf fremde Adressen,
 *    der fuer den Menschen nach unserer Anmeldung aussieht.
 *  - DIE AUSKUNFTSFREUDIGE MELDUNG. 'falsch' und 'gesperrt' duerfen nach
 *    aussen nicht unterscheidbar sein; die Handynummer ist der Benutzername
 *    und kein Geheimnis.
 *  - DAS ALTE VERFAHREN ALS NEBENTUER. Geburtsdatum plus Ausweisziffern darf
 *    auf dieser Seite nicht danebenstehen.
 *  - Alles, was ueber Identitaet oder Zustand entscheidet, traegt #[Locked]
 *    (Auth-Bypass vom 19.08.2026, $wire.set state=verified).
 *
 * Schema und Aufbau von Hand, Vorbild PortalAuthKontoTest/KontoAnlegenTest
 * (Migrationen laufen in dieser Suite nicht) — inklusive Log-Attrappe vor
 * Facade::clearResolvedInstances().
 */
final class KontoAnmeldenTest extends TestCase
{
    private const TEAM = 3;

    /** Die Nummer in E.164 — so steht sie in rec_persons.phone. */
    private const NUMMER = '+4915111111111';

    /** Dieselbe Nummer, wie ein Mensch sie tippt. */
    private const NUMMER_GETIPPT = '0151 11111111';

    private const PASSWORT = 'ganz-geheim-2026';

    private const FALSCHES_PASSWORT = 'ganz-daneben-2026';

    private const ANGEFASST = '2026-09-28 09:00:00';

    /**
     * Die Personen-Kennung von Gregor — und ZUGLEICH die Anstellungs-Kennung
     * einer FREMDEN Person (s. setUp()). Genau diese Kollision macht die
     * Verwechslung sichtbar.
     */
    private const PERSON_GREGOR = 2;

    /** Gregors eigene Anstellung. Bewusst eine andere Zahl. */
    private const ANSTELLUNG_GREGOR = 7;

    /** Die fremde Person und ihre Anstellung mit der Kennung 2. */
    private const PERSON_FREMD = 9;

    private const ANSTELLUNG_FREMD = 2;

    private Capsule $capsule;

    private Repository $cache;

    /** @var object{geschrieben: list<string>} die Attrappe hinter $cache */
    private object $store;

    private Store $session;

    private CookieJar $cookies;

    private ?Container $vorherigerContainer = null;

    private Container $container;

    private string $tmpDir;

    private ?Router $router = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vorherigerContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->container = $container;

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden
        // (reference_log_facade_test_stub.md) — pruefeAnmeldung() protokolliert
        // die mehrdeutige Nummer.
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $container->instance('config', new ConfigRepository([
            'recruiting' => [
                'konto' => ['pepper' => 'pfeffer-fuer-den-test'],
                // Der Team-Anker des DispoIdentityResolver — ohne ihn
                // gruppiert er fail closed gar nicht, und der Sperr-Test waere
                // stumm gruen.
                'zas'   => ['inbound_team_id' => self::TEAM],
            ],
            // Livewires redirect() fragt danach; fehlt der Schluessel, kommt
            // der Vorgabewert.
            'livewire' => ['render_on_redirect' => false],
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

        $this->session = new Store('test', new ArraySessionHandler(60));
        $container->instance('session', $this->session);

        // Der Cookie-Korb ist hier kein Beiwerk: an ihm laesst sich belegen,
        // dass "angemeldet bleiben" KEIN dauerhaftes Geheimnis im Browser
        // ablegt. Ohne ihn liefe der Beleg ins Leere.
        $this->cookies = new CookieJar();
        $container->instance('cookie', $this->cookies);

        // Livewires store() haengt an EINEM DataStore. Ein blanker Container
        // baut bei jedem app()-Aufruf einen neuen — dann schriebe redirect()
        // in den einen und der Test laese aus dem anderen, und jede
        // Weiterleitungs-Zusicherung waere stumm gruen.
        $container->instance(DataStore::class, new DataStore());

        // Der Speicher merkt sich, WAS ueberhaupt geschrieben wurde. Ein
        // blanker ArrayStore sieht das nicht — und eine Zusicherung ueber den
        // Zaehler, die bloss einen Schluessel abfragt, ist genau die Art
        // Zusicherung, die strukturell gruen bleibt (Fund F1 der Pruefung).
        $this->store = new class extends ArrayStore {
            /** @var list<string> */
            public array $geschrieben = [];

            public function put($key, $value, $seconds): bool
            {
                $this->geschrieben[] = (string) $key;

                return parent::put($key, $value, $seconds);
            }

            public function increment($key, $value = 1)
            {
                $this->geschrieben[] = (string) $key;

                return parent::increment($key, $value);
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
            $t->integer('rec_person_id')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamps();
        });

        // Die Dispo-Identitaet haengt am CRM-Kontakt — der DispoIdentityResolver
        // liest diese Tabelle.
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

        // Gregor: Person 2, Anstellung 7.
        $this->person(self::PERSON_GREGOR, self::TEAM, self::NUMMER, 'p-gregor');
        $this->anstellung(self::ANSTELLUNG_GREGOR, self::PERSON_GREGOR, 'tok-gregor');

        // Die Fremde: Person 9, Anstellung 2. Ihre ANSTELLUNGS-Kennung ist
        // gleich Gregors PERSONEN-Kennung — wer die beiden verwechselt,
        // oeffnet mit Gregors Passwort ihre Sitzung.
        $this->person(self::PERSON_FREMD, self::TEAM, '+4915122222222', 'p-fremd');
        $this->anstellung(self::ANSTELLUNG_FREMD, self::PERSON_FREMD, 'tok-fremd');

        $this->tmpDir = sys_get_temp_dir() . '/konto-anmelden-blade-' . getmypid();
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->vorherigerContainer);
        $this->router = null;

        foreach (glob($this->tmpDir . '/*.php') ?: [] as $datei) {
            @unlink($datei);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    // ----------------------------------------------------------------- Hilfen

    private function person(int $id, int $teamId, string $nummer, string $uuid, string $passwort = self::PASSWORT): void
    {
        DB::table('rec_persons')->insert([
            'id'            => $id,
            'uuid'          => $uuid,
            'team_id'       => $teamId,
            'phone'         => $nummer,
            'password_hash' => Hash::make($passwort),
            'created_at'    => self::ANGEFASST,
            'updated_at'    => self::ANGEFASST,
        ]);
    }

    private function anstellung(int $id, int $personId, string $token, array $attr = []): void
    {
        DB::table('rec_employees')->insert(array_merge([
            'id'              => $id,
            'team_id'         => self::TEAM,
            'portal_token'    => $token,
            'first_name'      => 'Test',
            'last_name'       => 'Person',
            'rec_person_id'   => $personId,
            'phone'           => self::NUMMER,
            'is_active'       => 1,
            'portal_v2_since' => self::ANGEFASST,
            'created_at'      => self::ANGEFASST,
            'updated_at'      => self::ANGEFASST,
        ], $attr));
    }

    private function auth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    /** Die Seite, so wie sie nach dem Aufruf einer Adresse dasteht. */
    private function seite(string $adresse = '/konto'): KontoAnmelden
    {
        $this->router();

        $seite = new KontoAnmelden();
        $seite->mount(Request::create($adresse, 'GET'));

        return $seite;
    }

    private function anmeldung(string $adresse = '/konto'): KontoAnmelden
    {
        $seite = $this->seite($adresse);
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;

        return $seite;
    }

    /** Wohin Livewire nach dem Aufruf weiterleiten wuerde — oder null. */
    private function weiterleitung(KontoAnmelden $seite): ?string
    {
        $ziel = \Livewire\store($seite)->get('redirect');

        return $ziel === null ? null : (string) $ziel;
    }

    private function router(): Router
    {
        if ($this->router !== null) {
            return $this->router;
        }

        $router = new Router(new Dispatcher($this->container), $this->container);
        $this->container->instance('router', $router);
        $this->router = $router;

        require dirname(__DIR__, 2) . '/routes/public.php';

        $router->getRoutes()->refreshNameLookups();

        // route() in der Komponente und im Blade braucht den Erzeuger — und
        // zwar denselben Routenbestand, sonst prueft die Positivliste etwas
        // anderes, als die Seite anspringt.
        $this->container->instance('url', new UrlGenerator(
            $router->getRoutes(),
            Request::create('http://localhost/konto', 'GET'),
        ));

        return $router;
    }

    private function blade(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/public/konto-anmelden.blade.php',
        );
    }

    /**
     * Das GANZE Blade wird mit dem echten BladeCompiler uebersetzt und
     * ausgefuehrt — nicht bloss nach Zeichenketten durchsucht. Ein
     * Quelltext-Waechter haette den Fall nicht gesehen, in dem eine Direktive
     * still nicht kompiliert und der falsche Zweig rendert.
     */
    private function rendere(KontoAnmelden $seite): string
    {
        $this->router();

        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $datei = $this->tmpDir . '/konto-anmelden-' . md5($seite->state) . '.php';
        file_put_contents($datei, $compiler->compileString($this->blade()));

        $variablen = get_object_vars($seite);
        $variablen['__datei'] = $datei;

        $lauf = function (array $__v): string {
            extract($__v);
            ob_start();
            include $__datei;

            return (string) ob_get_clean();
        };

        return \Closure::bind($lauf, $seite, KontoAnmelden::class)($variablen);
    }

    // ------------------------------------------------------------ Die Anmeldung

    public function test_nummer_und_passwort_melden_an(): void
    {
        $seite = $this->anmeldung();

        $seite->anmelden($this->auth());

        $this->assertSame('', $seite->fehler);
        $this->assertTrue($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
    }

    /**
     * DER TEST, um den es hier geht.
     *
     * anmeldenMitNummer() gibt Gregors PERSONEN-Kennung (2) zurueck.
     * sessionKey() will eine ANSTELLUNGS-Kennung. Gregors Anstellung ist die
     * 7; die 2 gehoert einem anderen Menschen. Wuerde die Seite die
     * Personen-Kennung durchreichen, stuende der Sitzungsschluessel auf der
     * FREMDEN Anstellung — Gregor waere mit seinem eigenen, richtigen
     * Passwort in der Akte einer anderen Person.
     */
    public function test_die_sitzung_steht_auf_einer_anstellung_dieser_person(): void
    {
        // Vorflug: die Kollision, auf der dieser Test beruht, muss bestehen.
        $this->assertSame(
            self::PERSON_GREGOR,
            self::ANSTELLUNG_FREMD,
            'Vorflug: die fremde Anstellung muss dieselbe Zahl tragen wie Gregors Personen-Kennung',
        );

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertTrue(
            $this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)),
            'Die Sitzung steht nicht auf Gregors eigener Anstellung.',
        );
        $this->assertFalse(
            $this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_FREMD)),
            'Der Sitzungsschluessel steht auf einer FREMDEN Anstellung — die Personen-Kennung '
            . 'wurde als Anstellungs-Kennung durchgereicht.',
        );
    }

    /**
     * Zwei Anstellungen derselben Person bekommen beide eine Sitzung: das
     * Konto gehoert der Person, und wer RG und MA hat, soll nicht bei der
     * zweiten wieder vor einer Anmeldung stehen. Das ist die Personen-
     * Klammer, keine Verschraenkung zweier Menschen — die Zeilen haengen
     * ausdruecklich an derselben rec_person_id.
     */
    public function test_beide_anstellungen_derselben_person_werden_geoeffnet(): void
    {
        $this->anstellung(11, self::PERSON_GREGOR, 'tok-gregor-2');

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertTrue($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
        $this->assertTrue($this->session->has(PortalAuth::sessionKey(11)));
    }

    /**
     * Eine BEENDETE Anstellung bekommt keine Sitzung. Dieselbe Menge, die in
     * KontoWriter darueber entscheidet, ob sich jemand ueberhaupt anmelden
     * darf (is_active) — eine andere Menge hier hiesse: jemand kommt durch
     * die Anmeldung und oeffnet dabei eine Akte, die er nicht mehr fuehrt.
     */
    public function test_eine_beendete_anstellung_bekommt_keine_sitzung(): void
    {
        $this->anstellung(12, self::PERSON_GREGOR, 'tok-gregor-alt', ['is_active' => 0]);

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertTrue($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
        $this->assertFalse($this->session->has(PortalAuth::sessionKey(12)));
    }

    public function test_ein_falsches_passwort_kommt_nicht_hinein(): void
    {
        $seite = $this->seite();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::FALSCHES_PASSWORT;

        $seite->anmelden($this->auth());

        $this->assertSame(KontoAnmelden::MELDUNG, $seite->fehler);
        $this->assertFalse($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
        $this->assertNull($this->weiterleitung($seite));

        // Das Passwort faehrt nach dem Versuch nicht im Livewire-Schnappschuss
        // weiter mit.
        $this->assertSame('', $seite->passwort);
    }

    /**
     * 'falsch' und 'gesperrt' duerfen nach aussen nicht unterscheidbar sein.
     * Die Handynummer ist der Benutzername und kein Geheimnis: wer sie kennt,
     * erfuehre sonst an der Meldung, dass es dazu ein Konto gibt und in
     * welchem Zustand es ist.
     *
     * Geprueft werden alle vier Wege, die PortalAuth kennt — WORTGLEICH, und
     * zwar gegen den ersten Fall statt gegen die Konstante: die Konstante
     * koennte jemand versehentlich in einen Zweig einsetzen und im anderen
     * einen eigenen Satz schreiben.
     */
    public function test_falsch_und_gesperrt_sind_nach_aussen_nicht_zu_unterscheiden(): void
    {
        $meldungen = [];

        // 1. Falsches Passwort.
        $a = $this->seite();
        $a->nummer = self::NUMMER_GETIPPT;
        $a->passwort = self::FALSCHES_PASSWORT;
        $a->anmelden($this->auth());
        $meldungen['falsches Passwort'] = $a->fehler;

        // 2. Unbekannte Nummer.
        $b = $this->seite();
        $b->nummer = '0151 99999999';
        $b->passwort = self::PASSWORT;
        $b->anmelden($this->auth());
        $meldungen['unbekannte Nummer'] = $b->fehler;

        // 3. Gesperrte Person (HR/Datenschutz).
        DB::table('rec_persons')->where('id', self::PERSON_GREGOR)->update(['locked_at' => self::ANGEFASST]);
        $c = $this->anmeldung();
        $c->anmelden($this->auth());
        $meldungen['gesperrte Person'] = $c->fehler;
        DB::table('rec_persons')->where('id', self::PERSON_GREGOR)->update(['locked_at' => null]);

        // 4. Dispo-Sperre (Eskalationsstufe 3) — hier gibt PortalAuth
        // ausdruecklich 'gesperrt' zurueck.
        DB::table('rec_employees')->where('id', self::ANSTELLUNG_GREGOR)
            ->update(['portal_locked_at' => self::ANGEFASST]);
        $d = $this->anmeldung();
        $d->anmelden($this->auth());
        $meldungen['Dispo-Sperre'] = $d->fehler;

        $erste = $meldungen['falsches Passwort'];
        $this->assertNotSame('', $erste, 'ohne Meldung stuende der Mensch vor einer stummen Seite');

        foreach ($meldungen as $fall => $text) {
            $this->assertSame($erste, $text, "Der Fall \"{$fall}\" ist an der Meldung zu erkennen.");
        }

        // Und keine der Meldungen benennt den Grund.
        $this->assertStringNotContainsStringIgnoringCase('gesperrt', $erste);
        $this->assertStringNotContainsStringIgnoringCase('unbekannt', $erste);
        $this->assertStringNotContainsStringIgnoringCase('nicht gefunden', $erste);
    }

    public function test_eine_dispo_gesperrte_person_bekommt_keine_sitzung(): void
    {
        DB::table('rec_employees')->where('id', self::ANSTELLUNG_GREGOR)
            ->update(['portal_locked_at' => self::ANGEFASST]);

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertFalse($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
        $this->assertNull($this->weiterleitung($seite));
    }

    /**
     * Leere Eingaben kosten keinen Versuch — sonst sperrte sich mit fuenf
     * Leeraufrufen von $wire.call('anmelden') jeder selbst aus.
     *
     * BERICHTIGT NACH DER PRUEFUNG (Fund F1): hier stand eine Abfrage auf
     * 'employee_portal_attempts:nummer:'. Diesen Schluessel gibt es nicht —
     * PortalAuth haengt einen Hash daran —, und mit dem Standardwert konnte
     * die Zusicherung gar nicht fehlschlagen. Geprueft wird jetzt der
     * Speicher selbst: es darf UEBERHAUPT nichts geschrieben worden sein.
     */
    public function test_leere_eingaben_melden_ohne_einen_versuch_zu_kosten(): void
    {
        $seite = $this->seite();
        $seite->nummer = '   ';
        $seite->passwort = '';

        $seite->anmelden($this->auth());

        $this->assertNotSame('', $seite->fehler);
        $this->assertFalse($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
        $this->assertSame(
            [],
            $this->store->geschrieben,
            'Ein Leeraufruf hat einen Zaehler geschrieben.',
        );

        // Und der Beobachtungsweg taugt etwas: ein ECHTER Fehlversuch
        // schreibt sehr wohl. Ohne diese Gegenprobe pruefte die Zusicherung
        // oben nur, dass die Attrappe blind ist.
        $echt = $this->seite();
        $echt->nummer = self::NUMMER_GETIPPT;
        $echt->passwort = self::FALSCHES_PASSWORT;
        $echt->anmelden($this->auth());

        $this->assertNotSame([], $this->store->geschrieben, 'Der Speicher sieht gar keine Schreibvorgaenge.');
    }

    // --------------------------------------------------------------- Ruling GD-2

    /**
     * Die Seite uebergibt KEIN Team (Ruling GD-2). Wer hier eines einsetzt,
     * erfindet einen Kontext, den die oeffentliche Seite nicht hat — und
     * sperrt jeden aus, der nicht in diesem Team steht. Deshalb steht diese
     * Person in einem beliebigen anderen Team.
     */
    public function test_eine_person_aus_einem_beliebigen_team_meldet_sich_an(): void
    {
        $this->person(41, 77, '+4915133333333', 'p-anderes-team');
        $this->anstellung(42, 41, 'tok-anderes-team', ['team_id' => 77]);

        $seite = $this->seite();
        $seite->nummer = '+4915133333333';
        $seite->passwort = self::PASSWORT;
        $seite->anmelden($this->auth());

        $this->assertSame('', $seite->fehler);
        $this->assertTrue($this->session->has(PortalAuth::sessionKey(42)));
    }

    /**
     * Ruling GD-2, fail closed: die Eindeutigkeit der Nummer gilt nur JE
     * TEAM. Findet die Suche ueber alle Teams mehr als eine lebende Person,
     * wird NICHT geraten — die Antwort ist dieselbe wie bei falschem
     * Passwort.
     */
    public function test_dieselbe_nummer_in_zwei_teams_meldet_niemanden_an(): void
    {
        $this->person(51, 88, self::NUMMER, 'p-doppelt');
        $this->anstellung(52, 51, 'tok-doppelt', ['team_id' => 88]);

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertSame(KontoAnmelden::MELDUNG, $seite->fehler);
        $this->assertFalse($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
        $this->assertFalse($this->session->has(PortalAuth::sessionKey(52)));
    }

    // ---------------------------------------------------------- Das Ziel danach

    public function test_ohne_ziel_geht_es_ins_eigene_portal(): void
    {
        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $ziel = $this->weiterleitung($seite);

        $this->assertNotNull($ziel);
        $this->assertStringEndsWith('/mitarbeiter/neu/tok-gregor', $ziel);
        $this->assertStringNotContainsString('tok-fremd', (string) $ziel);
    }

    public function test_ein_eigenes_ziel_wird_angesprungen(): void
    {
        $seite = $this->anmeldung('/konto?weiter=' . rawurlencode('/einsaetze/tok-gregor'));

        $this->assertSame('/einsaetze/tok-gregor', $seite->weiter);

        $seite->anmelden($this->auth());

        $this->assertSame('/einsaetze/tok-gregor', $this->weiterleitung($seite));
    }

    /**
     * Ein offener Weiterleiter waere eine Einladung: der Link
     * /konto?weiter=https://fremde.seite sieht fuer den Menschen aus wie
     * unsere Anmeldung und schickt ihn danach weiter.
     *
     * Jede Form einzeln, mit eigener Meldung — ein Sammel-assert haette nur
     * gezeigt, dass EINE davon abgewehrt wird.
     */
    public function test_ein_fremdes_ziel_wird_verworfen(): void
    {
        $faelle = [
            'volle fremde Adresse'        => 'https://fremde.seite/einsaetze/tok-gregor',
            'ohne Verschluesselung'       => 'http://fremde.seite/',
            'schemarelativ'               => '//fremde.seite/einsaetze/tok-gregor',
            'Rueckwaerts-Schraegstriche'  => '/\\fremde.seite/x',
            'Skript-Schema'               => 'javascript:alert(1)',
            // Ein Schema OHNE Host, dessen Pfad eine erlaubte Route trifft.
            // Diese Form ist der einzige Fall, in dem allein die Frage nach
            // dem Schema abwehrt: ohne Host greift die Host-Frage nicht, und
            // der Pfad beginnt mit einem Schraegstrich, also greift auch die
            // Pfad-Wache nicht. Gefunden durch eine Mutation, die vorher
            // gruen blieb.
            'Schema mit eigenem Pfad'     => 'javascript:/einsaetze/tok-gregor',
            'mit Benutzer davor'          => 'https://uns.de@fremde.seite/x',
            'Zeilenumbruch'               => "/einsaetze/tok-gregor\nhttps://fremde.seite",
            'gar kein Pfad'               => 'einsaetze/tok-gregor',
            // Die drei folgenden Formen treffen sonst eine ERLAUBTE Route und
            // kaemen ohne ihre jeweilige Wache durch. Sie stehen hier, weil
            // die erste Fassung dieser Liste sie nicht enthielt und drei
            // Mutationen deshalb stumm gruen blieben.
            //
            // Rueckwaerts-Schraegstrich am Ende: der Browser liest ihn wie
            // einen Schraegstrich und landet woanders als unsere Pruefung.
            'Schraegstrich rueckwaerts am Ende' => '/einsaetze/tok-gregor\\',
            // Drei Schraegstriche: parse_url findet keinen Host, der Browser
            // liest '//einsaetze' aber als fremden Host.
            'drei Schraegstriche'         => '///einsaetze/tok-gregor',
            // Zeilenumbruch mitten drin: das Ziel landet in einem
            // Location-Kopf, und ein Umbruch dort beginnt einen neuen Kopf.
            'Kopfzeilen-Einschleusung'    => "/einsaetze/tok-gregor\r\nX-Beliebig: 1",
        ];

        foreach ($faelle as $fall => $eingabe) {
            $seite = $this->seite('/konto?weiter=' . rawurlencode($eingabe));

            $this->assertSame('', $seite->weiter, "Das fremde Ziel \"{$fall}\" wurde angenommen.");
        }
    }

    /**
     * VERWORFEN, nicht bereinigt. Wer aus "https://fremde.seite/einsaetze/x"
     * den Pfad herausschneidet, baut aus einer fremden Adresse eine eigene
     * zusammen und springt am Ende doch etwas an, das ihm niemand gegeben
     * hat — hier die Einsatz-Seite eines fremden Tokens.
     */
    public function test_ein_fremdes_ziel_wird_nicht_zurechtgeschnitten(): void
    {
        $seite = $this->seite(
            '/konto?weiter=' . rawurlencode('https://fremde.seite/einsaetze/tok-fremd'),
        );

        $this->assertSame('', $seite->weiter);

        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;
        $seite->anmelden($this->auth());

        $ziel = (string) $this->weiterleitung($seite);

        $this->assertStringNotContainsString('fremde.seite', $ziel);
        $this->assertStringNotContainsString('tok-fremd', $ziel);
        $this->assertStringEndsWith('/mitarbeiter/neu/tok-gregor', $ziel);
    }

    /**
     * Die Positivliste ist eine Positivliste: eine eigene Route, die NICHT
     * darin steht, ist kein Ziel. Sonst waere jede oeffentliche Seite des
     * Moduls ein Absprungpunkt — auch die, die ein Geheimnis in der Adresse
     * traegt.
     */
    public function test_eine_eigene_route_ausserhalb_der_liste_ist_kein_ziel(): void
    {
        $ausserhalb = [
            'die Entwurfs-Vorschau'  => '/entwurf/portal',
            'das alte Portal'        => '/mitarbeiter/tok-gregor',
            'die Anmeldung selbst'   => '/konto',
            'die Registrierung'      => '/konto/anlegen/ABCD2345',
        ];

        foreach ($ausserhalb as $fall => $pfad) {
            // Vorflug: der Pfad trifft wirklich eine eigene Route — sonst
            // pruefte dieser Test nur, dass Unsinn abgewiesen wird.
            $treffer = $this->router()->getRoutes()->match(Request::create($pfad, 'GET'));
            $this->assertNotNull($treffer->getName(), "Vorflug: {$fall} trifft keine benannte Route");
            $this->assertNotContains(
                $treffer->getName(),
                KontoAnmelden::ZIEL_ROUTEN,
                "Vorflug: {$fall} steht doch in der Positivliste",
            );

            $seite = $this->seite('/konto?weiter=' . rawurlencode($pfad));

            $this->assertSame('', $seite->weiter, "Die Route \"{$fall}\" wurde als Ziel angenommen.");
        }
    }

    /**
     * Fund F2 der Pruefung: /recruiting/konto?weiter[]=a liefert ein ARRAY.
     * Eine Umwandlung nach string warf dort "Array to string conversion" —
     * auf dem Wirt eine 500er-Antwort, von jedem beliebig oft ausloesbar,
     * auf einer oeffentlichen Seite.
     *
     * Der eigene Fehlerbehandler ist noetig, weil eine PHP-Warnung sonst
     * bloss im Protokoll landet; hier soll sie den Test umwerfen.
     */
    public function test_ein_array_als_ziel_wirft_die_seite_nicht_um(): void
    {
        set_error_handler(static function (int $stufe, string $text): bool {
            throw new \ErrorException($text, 0, $stufe);
        });

        try {
            $seite = $this->seite('/konto?weiter[]=' . rawurlencode('/einsaetze/tok-gregor'));
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $seite->weiter);
    }

    public function test_ein_pfad_ohne_route_ist_kein_ziel(): void
    {
        $seite = $this->seite('/konto?weiter=' . rawurlencode('/gibt-es-nicht'));

        $this->assertSame('', $seite->weiter);
    }

    /**
     * Ohne umgestellte Anstellung gibt es nichts zu oeffnen (die Huelle
     * antwortet ohne portal_v2_since mit 404). Die Anmeldung gilt trotzdem —
     * das Passwort war ja richtig —, aber die Seite bleibt stehen und sagt
     * es, statt auf eine 404 zu springen.
     */
    public function test_ohne_umgestelltes_portal_bleibt_die_seite_stehen(): void
    {
        DB::table('rec_employees')->where('id', self::ANSTELLUNG_GREGOR)
            ->update(['portal_v2_since' => null]);

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertSame('ohne-ziel', $seite->state);
        $this->assertNull($this->weiterleitung($seite));
        $this->assertTrue($this->session->has(PortalAuth::sessionKey(self::ANSTELLUNG_GREGOR)));
    }

    public function test_ohne_portal_token_ist_die_anstellung_kein_ziel(): void
    {
        DB::table('rec_employees')->where('id', self::ANSTELLUNG_GREGOR)
            ->update(['portal_token' => null]);
        $this->anstellung(13, self::PERSON_GREGOR, 'tok-gregor-3');

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $this->assertStringEndsWith('/mitarbeiter/neu/tok-gregor-3', (string) $this->weiterleitung($seite));
    }

    // -------------------------------------------------- Angemeldet bleiben

    /**
     * "Angemeldet bleiben" verlaengert die Sitzung — es legt KEIN dauerhaftes
     * Geheimnis in einen Cookie. Ein solches Geheimnis waere ein zweiter
     * Anmeldeweg ohne Passwort, und genau den soll es nicht geben.
     */
    public function test_angemeldet_bleiben_legt_kein_geheimnis_in_einen_cookie(): void
    {
        $seite = $this->anmeldung();
        $seite->angemeldetBleiben = true;

        $seite->anmelden($this->auth());

        $this->assertTrue($this->session->get(KontoAnmelden::LANGE_SITZUNG, false));
        $this->assertSame(
            [],
            $this->cookies->getQueuedCookies(),
            'Die Anmeldung hat einen Cookie gesetzt — dort gehoert kein dauerhaftes Geheimnis hin.',
        );
    }

    public function test_ohne_haken_bleibt_die_sitzung_kurz(): void
    {
        $seite = $this->anmeldung();

        $seite->anmelden($this->auth());

        $this->assertFalse($this->session->get(KontoAnmelden::LANGE_SITZUNG, false));
    }

    public function test_ein_fehlversuch_verlaengert_nichts(): void
    {
        $seite = $this->seite();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::FALSCHES_PASSWORT;
        $seite->angemeldetBleiben = true;

        $seite->anmelden($this->auth());

        $this->assertFalse($this->session->get(KontoAnmelden::LANGE_SITZUNG, false));
    }

    // ---------------------------------------------------------- Der Waechter

    /**
     * DER AUTH-BYPASS. Am 19.08.2026 war `$wire.set('state', 'verified')`
     * verifiziert ausnutzbar und umging die Pruefung vollstaendig. Eine
     * Livewire-Eigenschaft ohne #[Locked] setzt der Browser direkt.
     *
     * GESCHLOSSENE WELT: geprueft wird nicht nur, ob die eingetragenen Felder
     * gesperrt sind, sondern dass es KEIN oeffentliches Feld gibt, das in
     * keiner der beiden Listen steht. Eine blosse Namensliste haette ein
     * spaeter hinzugefuegtes Zustands-Feld lautlos durchgelassen — derselbe
     * Bypass, nur eine Runde spaeter.
     *
     * Die Listen werden AUSGESCHRIEBEN und nicht abgeleitet: eine abgeleitete
     * Liste sagte "was gesperrt ist, ist gesperrt", also gar nichts.
     */
    public function test_alles_was_ueber_identitaet_entscheidet_ist_gesperrt(): void
    {
        $gesperrt = [
            'state'  => 'der Zustand der Seite — genau der Bypass vom 19.08.2026',
            'weiter' => 'das gepruefte Weiterleitungsziel; ohne Sperre setzte $wire.set nach der '
                . 'Pruefung eine beliebige Adresse, und die Positivliste waere Zierat',
        ];

        // Absichtlich OFFEN, jede mit ihrem Grund. Ihre Sicherheit sitzt nicht
        // in der Unveraenderlichkeit, sondern darin, dass anmelden() bei JEDEM
        // Aufruf Nummer und Passwort erneut pruefen laesst.
        $offen = [
            'nummer'            => 'die Eingabe des Menschen — gesperrt kann er nichts eintippen',
            'passwort'          => 'dito',
            'angemeldetBleiben' => 'der Haken des Menschen; er entscheidet ueber die Dauer der '
                . 'Sitzung, nicht ueber den Zutritt',
            'fehler'            => 'nur eine Anzeige; wer sie sich selbst setzt, beschreibt seinen '
                . 'eigenen Bildschirm',
        ];

        $klasse = new \ReflectionClass(KontoAnmelden::class);

        foreach ($gesperrt as $name => $warum) {
            $this->assertTrue(
                $klasse->hasProperty($name),
                "Die Eigenschaft {$name} gibt es nicht mehr — umbenannt? Dann gehoert der neue "
                . "Name hier hinein, sonst faellt der Schutz still weg ({$warum})",
            );
            $this->assertNotSame(
                [],
                $klasse->getProperty($name)->getAttributes(Locked::class),
                "#[Locked] fehlt an KontoAnmelden::\${$name} — {$warum}. "
                . 'Ohne das Attribut setzt $wire.set die Eigenschaft direkt.',
            );
        }

        foreach ($offen as $name => $warum) {
            $this->assertSame(
                [],
                $klasse->getProperty($name)->getAttributes(Locked::class),
                "{$name} ist gesperrt — {$warum}",
            );
        }

        foreach ($klasse->getProperties(\ReflectionProperty::IS_PUBLIC) as $eigenschaft) {
            if ($eigenschaft->isStatic()) {
                continue;
            }

            $name = $eigenschaft->getName();

            $this->assertTrue(
                isset($gesperrt[$name]) || isset($offen[$name]),
                "Die oeffentliche Eigenschaft \${$name} steht in keiner der beiden Listen. "
                . 'Entscheide: gehoert sie zu dem, was ueber Identitaet oder Zustand entscheidet '
                . '(dann #[Locked] und oben eintragen), oder ist sie eine Eingabe des Menschen '
                . '(dann unten eintragen, mit Grund)? Genau hier ist am 19.08.2026 ein '
                . 'Auth-Bypass entstanden.',
            );
        }
    }

    // -------------------------------------------------------------- Das Blade

    public function test_das_formular_zeigt_nummer_und_passwort(): void
    {
        $html = $this->rendere($this->seite());

        $this->assertStringContainsString('wire:model="nummer"', $html);
        $this->assertStringContainsString('wire:model="passwort"', $html);
        $this->assertStringContainsString('wire:model="angemeldetBleiben"', $html);
        $this->assertStringContainsString('wire:submit="anmelden"', $html);
    }

    /**
     * DAS ALTE VERFAHREN STEHT HIER NICHT DANEBEN. Der Benutzername ist die
     * Handynummer und kein Geheimnis: waere "Geburtsdatum + Ausweisziffern"
     * hier als zweiter Weg offen, kaeme jeder, der eine Nummer kennt, ueber
     * die Nebentuer hinein — unsicherer als der heutige Token-Weg.
     */
    public function test_die_seite_bietet_das_alte_verfahren_nicht_an(): void
    {
        $html = $this->rendere($this->seite());

        foreach (['birthDate', 'geburtsdatum', 'idLast4', 'ausweis', 'type="date"'] as $verboten) {
            $this->assertStringNotContainsStringIgnoringCase(
                $verboten,
                $html,
                "Die Anmeldeseite zeigt \"{$verboten}\" — das alte Verfahren darf hier nicht danebenstehen.",
            );
        }
    }

    public function test_der_verweis_auf_den_einladungscode_steht_da(): void
    {
        $html = $this->rendere($this->seite());

        $this->assertStringContainsString('Einladungscode', $html);
        $this->assertStringContainsString('/konto/anlegen"', $html);
    }

    public function test_das_passwort_steht_nicht_im_markup(): void
    {
        $seite = $this->seite();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;

        $this->assertStringNotContainsString(self::PASSWORT, $this->rendere($seite));
    }

    public function test_kein_zwang_zur_zahlentastatur(): void
    {
        // Login-Blocker vom 06.08.2026: auf der iOS-Zahlentastatur liessen
        // sich Buchstaben nicht eingeben. Gilt fuer das ganze Blade, auch fuer
        // Kommentare — deshalb nennt es das Attribut nicht beim Namen.
        $this->assertStringNotContainsString('inputmode', $this->blade());
    }

    public function test_die_meldung_erscheint_im_formular(): void
    {
        $seite = $this->seite();
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::FALSCHES_PASSWORT;
        $seite->anmelden($this->auth());

        $this->assertStringContainsString($seite->fehler, $this->rendere($seite));
    }

    public function test_ohne_ziel_zeigt_die_seite_kein_formular_mehr(): void
    {
        DB::table('rec_employees')->where('id', self::ANSTELLUNG_GREGOR)
            ->update(['portal_v2_since' => null]);

        $seite = $this->anmeldung();
        $seite->anmelden($this->auth());

        $html = $this->rendere($seite);

        $this->assertStringNotContainsString('wire:model="passwort"', $html);
        $this->assertStringContainsString('angemeldet', $html);
    }

    public function test_blade_kompiliert(): void
    {
        $datei = dirname(__DIR__, 2) . '/resources/views/livewire/public/konto-anmelden.blade.php';
        $werkzeug = dirname(__DIR__, 2) . '/tools/blade-check.php';

        exec('php ' . escapeshellarg($werkzeug) . ' ' . escapeshellarg($datei), $ausgabe, $code);

        $this->assertSame(0, $code, implode("\n", $ausgabe));
    }

    // -------------------------------------------------------------- Die Route

    public function test_die_route_konto_faehrt_diese_seite(): void
    {
        $treffer = $this->router()->getRoutes()->match(Request::create('/konto', 'GET'));

        $this->assertSame('recruiting.public.konto', $treffer->getName());
        $this->assertStringStartsWith(
            KontoAnmelden::class,
            (string) $treffer->getAction('uses'),
            'Die Adresse /konto faehrt eine andere Komponente.',
        );
    }
}
