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
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Livewire\Mechanisms\DataStore;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Livewire\Public\KontoAnmelden;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Der Token-Link aus der WhatsApp fuehrt Konto-Inhaber auf die
 * Konto-Anmeldung — und nach dem Passwort zurueck auf genau diese Adresse.
 *
 * Geprueft wird vor allem, was still schiefgehen koennte:
 *  - DER KREIS. Huelle -> Konto -> Huelle -> Konto ... Er ist nur dann
 *    ausgeschlossen, wenn die Konto-Anmeldung die Sitzung fuer GENAU diese
 *    Anstellung oeffnet und die Huelle sie beim Ruecksprung erkennt. Das wird
 *    hier Ende-zu-Ende gefahren, nicht bloss angenommen.
 *  - DIE SACKGASSE. Wer auf die Konto-Anmeldung geschickt wird, muss dort
 *    auch durchkommen koennen. Ein zurueckgesetztes, gesperrtes oder
 *    mehrdeutiges Konto behaelt deshalb die alte Abfrage.
 *  - DIE VERLORENE QUERY. Alte Route -> neue Route -> Konto -> zurueck: am
 *    Ende muss dieselbe Query dastehen.
 *
 * Aufbau von Hand wie KontoAnmeldenTest (Migrationen laufen in dieser Suite
 * nicht). Die Routen OHNE den "recruiting"-Praefix der Produktion — die
 * Adressen werden deshalb vollstaendig, aber praefixlos verglichen.
 */
final class PortalShellKontoWeiterTest extends TestCase
{
    private const TEAM = 3;
    private const NUMMER = '+4915111111111';
    private const NUMMER_GETIPPT = '0151 11111111';
    private const PASSWORT = 'ganz-geheim-2026';
    private const ANGEFASST = '2026-09-28 09:00:00';

    private const PERSON = 2;
    private const ANSTELLUNG = 7;
    private const TOKEN = 'tok-gregor';

    private Capsule $capsule;
    private Repository $cache;
    private Store $session;
    private ?Container $vorherigerContainer = null;
    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vorherigerContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->container = $container;

        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $container->instance('config', new ConfigRepository([
            'recruiting' => [
                'konto' => ['pepper' => 'pfeffer-fuer-den-test'],
                'zas'   => ['inbound_team_id' => self::TEAM],
            ],
            'livewire' => ['render_on_redirect' => false],
        ]));
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
        $container->instance('cookie', new CookieJar());

        // EIN DataStore — sonst schreibt redirect() in den einen und der Test
        // liest aus einem anderen, und jede Weiterleitungs-Zusicherung waere
        // stumm gruen.
        $container->instance(DataStore::class, new DataStore());

        $this->cache = new Repository(new ArrayStore());

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->timestamp('registered_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->integer('merged_into_person_id')->nullable();
            $t->timestamp('letzte_anmeldung_at')->nullable();
            $t->timestamp('sitzungen_ungueltig_ab')->nullable();
            $t->timestamps();
        });

        (require dirname(__DIR__, 2)
            . '/database/migrations/2026_10_13_000002_create_rec_portal_lange_sitzungen_table.php')->up();

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
            $t->string('portal_locked_reason')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_verified_at')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

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

        // usesInformalAddress() in beiden mount() liest die Team-Einstellungen.
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        require dirname(__DIR__, 2) . '/routes/public.php';
        $router->getRoutes()->refreshNameLookups();
        $container->instance('url', new UrlGenerator($router->getRoutes(), Request::create('http://localhost/', 'GET')));

        $this->person();
        $this->anstellung(self::ANSTELLUNG, self::TOKEN);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->vorherigerContainer);

        parent::tearDown();
    }

    // ----------------------------------------------------------------- Hilfen

    private function person(array $attr = []): void
    {
        DB::table('rec_persons')->insert(array_merge([
            'id'            => self::PERSON,
            'uuid'          => 'p-gregor',
            'team_id'       => self::TEAM,
            'phone'         => self::NUMMER,
            'password_hash' => Hash::make(self::PASSWORT),
            'registered_at' => self::ANGEFASST,
            'created_at'    => self::ANGEFASST,
            'updated_at'    => self::ANGEFASST,
        ], $attr));
    }

    private function anstellung(int $id, string $token, array $attr = []): void
    {
        DB::table('rec_employees')->insert(array_merge([
            'id'                   => $id,
            'team_id'              => self::TEAM,
            'portal_token'         => $token,
            'first_name'           => 'Gregor',
            'last_name'            => 'Muster',
            'birth_date'           => '1995-03-14',
            'identity_card_number' => 'L01X00T47',
            'rec_person_id'        => self::PERSON,
            'phone'                => self::NUMMER,
            'is_active'            => 1,
            'portal_v2_since'      => self::ANGEFASST,
            'created_at'           => self::ANGEFASST,
            'updated_at'           => self::ANGEFASST,
        ], $attr));
    }

    private function auth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    /** Die laufende Anfrage — beide mount() lesen ihre Query von dort. */
    private function anfrage(string $adresse): Request
    {
        $anfrage = Request::create($adresse, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);
        $this->container->instance('request', $anfrage);

        return $anfrage;
    }

    /** Die Huelle, wie sie nach dem Aufruf dieser Adresse dasteht. */
    private function huelle(string $adresse, string $token = self::TOKEN): PortalShell
    {
        $this->anfrage($adresse);

        $huelle = new PortalShell();
        $huelle->mount($token, $this->auth());

        return $huelle;
    }

    private function weiterleitung(object $komponente): ?string
    {
        $ziel = \Livewire\store($komponente)->get('redirect');

        return $ziel === null ? null : (string) $ziel;
    }

    /** Das weiter-Ziel aus einer /konto-Adresse. */
    private function weiterAus(string $kontoAdresse): string
    {
        $this->assertSame('/konto', (string) parse_url($kontoAdresse, PHP_URL_PATH), 'Ziel ist nicht die Konto-Anmeldung');
        parse_str((string) parse_url($kontoAdresse, PHP_URL_QUERY), $query);

        return (string) ($query['weiter'] ?? '');
    }

    /** Die Konto-Anmeldung, aufgerufen mit dieser Adresse, Nummer + Passwort eingetragen. */
    private function kontoAnmeldung(string $adresse): KontoAnmelden
    {
        $anfrage = $this->anfrage($adresse);
        $seite = new KontoAnmelden();
        $seite->mount($anfrage);
        $seite->nummer = self::NUMMER_GETIPPT;
        $seite->passwort = self::PASSWORT;

        return $seite;
    }

    // ------------------------------------------------------------- Die Weiche

    public function test_konto_inhaber_ohne_sitzung_wird_zur_konto_anmeldung_geschickt(): void
    {
        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN . '?bereich=profil&feld=iban');

        $ziel = $this->weiterleitung($huelle);
        $this->assertNotNull($ziel, 'Konto-Inhaber sah die alte Abfrage.');
        $this->assertSame(
            'http://localhost/konto?weiter=' . rawurlencode('/mitarbeiter/neu/' . self::TOKEN . '?bereich=profil&feld=iban'),
            $ziel,
        );
        $this->assertSame('/mitarbeiter/neu/' . self::TOKEN . '?bereich=profil&feld=iban', $this->weiterAus($ziel));
        // Die Huelle zeigt nichts von der Person, bis die Sitzung steht.
        $this->assertSame('', $huelle->displayName);
    }

    public function test_ohne_query_ist_das_ziel_die_nackte_adresse(): void
    {
        $ziel = (string) $this->weiterleitung($this->huelle('/mitarbeiter/neu/' . self::TOKEN));

        $this->assertSame('/mitarbeiter/neu/' . self::TOKEN, $this->weiterAus($ziel));
    }

    /**
     * Ende-zu-Ende: Huelle -> Konto (mit weiter) -> Anmeldung -> genau die
     * Ziel-Adresse -> Huelle erkennt die Sitzung -> 'verified', KEIN zweiter
     * Sprung. Das ist der Test, der den Kreis ausschliesst.
     */
    public function test_nach_der_konto_anmeldung_landet_man_angemeldet_am_ziel_ohne_kreis(): void
    {
        $start = '/mitarbeiter/neu/' . self::TOKEN . '?bereich=profil&feld=iban';
        $zurKonto = (string) $this->weiterleitung($this->huelle($start));
        $this->assertNotSame('', $zurKonto, 'Vorflug: die Huelle hat umgeleitet');

        $seite = $this->kontoAnmeldung((string) parse_url($zurKonto, PHP_URL_PATH) . '?' . parse_url($zurKonto, PHP_URL_QUERY));
        $this->assertSame($start, $seite->weiter, 'Die Konto-Anmeldung verwarf das Ziel.');

        $seite->anmelden($this->auth());
        $this->assertSame('', $seite->fehler);
        $zurueck = $this->weiterleitung($seite);
        $this->assertSame($start, $zurueck, 'Nicht exakt zurueck am offenen Punkt.');

        $huelle = $this->huelle((string) $zurueck);
        $this->assertSame('verified', $huelle->state);
        $this->assertNull($this->weiterleitung($huelle), 'Kreis: die Huelle schickte trotz Sitzung wieder zur Konto-Anmeldung.');
        $this->assertSame('Gregor', $huelle->displayName);
    }

    public function test_ohne_konto_bleibt_die_alte_abfrage(): void
    {
        DB::table('rec_persons')->where('id', self::PERSON)->update(['password_hash' => null, 'registered_at' => null]);

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    public function test_ohne_person_bleibt_die_alte_abfrage(): void
    {
        DB::table('rec_employees')->where('id', self::ANSTELLUNG)->update(['rec_person_id' => null]);

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    /**
     * Von HR zurueckgesetzt: password_hash weg, registered_at bleibt als
     * Beleg. Das Konto ist nicht eingerichtet — wer hier auf "registered_at"
     * schaut, schickt den Menschen auf eine Anmeldung ohne Passwort.
     */
    public function test_ein_zurueckgesetztes_konto_zaehlt_nicht(): void
    {
        DB::table('rec_persons')->where('id', self::PERSON)->update(['password_hash' => null]);
        $this->assertNotNull(DB::table('rec_persons')->where('id', self::PERSON)->value('registered_at'), 'Vorflug');

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    public function test_gesperrte_anstellung_bleibt_gesperrt(): void
    {
        DB::table('rec_employees')->where('id', self::ANSTELLUNG)->update(['portal_locked_at' => self::ANGEFASST]);

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('gesperrt', $huelle->state);
    }

    public function test_rate_limitiert_bleibt_rate_limitiert(): void
    {
        $auth = $this->auth();
        for ($i = 0; $i < PortalAuth::MAX_ATTEMPTS; $i++) {
            $auth->attempt(\Platform\Recruiting\Models\RecEmployee::find(self::ANSTELLUNG), self::TOKEN, '2000-01-01', '0000');
        }
        $this->assertTrue($auth->isRateLimited(self::TOKEN), 'Vorflug');

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('rateLimited', $huelle->state);
    }

    // --------------------------------------------- Konto ohne Durchkommen

    /**
     * Gesperrtes Konto (HR/Datenschutz): die Konto-Anmeldung liesse ihn nie
     * hindurch. Wer ihn dorthin schickt, nimmt ihm den einzigen Weg, der
     * heute funktioniert — er bekommt die alte Abfrage.
     */
    public function test_ein_gesperrtes_konto_schickt_nicht_in_die_sackgasse(): void
    {
        DB::table('rec_persons')->where('id', self::PERSON)->update(['locked_at' => self::ANGEFASST]);

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    /** Dieselbe Nummer in zwei Teams: die Anmeldung scheitert fail closed (Ruling GD-2). */
    public function test_eine_mehrdeutige_nummer_schickt_nicht_in_die_sackgasse(): void
    {
        DB::table('rec_persons')->insert([
            'id' => 5, 'uuid' => 'p-zwilling', 'team_id' => 99, 'phone' => self::NUMMER,
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    /**
     * Die Dispo-Sperre haengt an einer ANDEREN aktiven Anstellung derselben
     * Person: diese Huelle ist offen, die Konto-Anmeldung aber endet in
     * GESPERRT. Erst gefahren (Vorflug), dann erwartet: alte Abfrage.
     */
    public function test_eine_dispo_sperre_der_person_schickt_nicht_in_die_sackgasse(): void
    {
        $this->anstellung(8, 'tok-gregor-2', ['portal_locked_at' => self::ANGEFASST]);

        $seite = $this->kontoAnmeldung('/konto');
        $seite->anmelden($this->auth());
        $this->assertFalse(PortalAuth::sitzungGueltig(self::ANSTELLUNG), 'Vorflug: die Konto-Anmeldung muss hier scheitern');
        $this->assertNull($this->weiterleitung($seite), 'Vorflug');

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    /** Eine stillgelegte (zusammengefuehrte) Person ist kein Konto. */
    public function test_eine_zusammengefuehrte_person_schickt_nicht_weiter(): void
    {
        DB::table('rec_persons')->where('id', self::PERSON)->update(['merged_into_person_id' => 40]);

        $huelle = $this->huelle('/mitarbeiter/neu/' . self::TOKEN);

        $this->assertNull($this->weiterleitung($huelle));
        $this->assertSame('unverified', $huelle->state);
    }

    /**
     * Meldet sich auf der Konto-Seite ein ANDERER Mensch an (geteiltes
     * Geraet), traegt das Ziel keinen seiner Tokens: KontoAnmelden verwirft
     * es und springt auf dessen eigenes Portal. Kein Kreis, keine fremde
     * Anmeldemaske.
     */
    public function test_ein_fremder_konto_inhaber_landet_im_eigenen_portal_nicht_im_kreis(): void
    {
        DB::table('rec_persons')->insert([
            'id' => 9, 'uuid' => 'p-fremd', 'team_id' => self::TEAM, 'phone' => '+4915122222222',
            'password_hash' => Hash::make(self::PASSWORT),
            'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);
        $this->anstellung(12, 'tok-fremd', ['rec_person_id' => 9, 'phone' => '+4915122222222']);

        $zurKonto = (string) $this->weiterleitung($this->huelle('/mitarbeiter/neu/' . self::TOKEN . '?feld=iban'));
        $seite = $this->kontoAnmeldung('/konto?' . parse_url($zurKonto, PHP_URL_QUERY));
        $seite->nummer = '0151 22222222';
        $seite->anmelden($this->auth());

        $this->assertSame('http://localhost/mitarbeiter/neu/tok-fremd', $this->weiterleitung($seite));
        $this->assertFalse(PortalAuth::sitzungGueltig(self::ANSTELLUNG), 'Die fremde Anmeldung oeffnete Gregors Anstellung.');
    }

    // ------------------------------------------------ Alte Route -> Ende

    /**
     * Der WhatsApp-Knopf zeigt auf die ALTE Adresse. Alte Route -> neue
     * Route -> Konto -> zurueck: am Ende steht dieselbe Query.
     */
    public function test_alte_route_mit_query_endet_mit_derselben_query(): void
    {
        $query = 'bereich=profil&feld=iban';

        $this->anfrage('/mitarbeiter/' . self::TOKEN . '?' . $query);
        $alt = new EmployeePortal();
        $alt->mount(self::TOKEN);
        $neu = (string) $this->weiterleitung($alt);
        $this->assertSame('http://localhost/mitarbeiter/neu/' . self::TOKEN . '?' . $query, $neu);

        $zurKonto = (string) $this->weiterleitung($this->huelle((string) parse_url($neu, PHP_URL_PATH) . '?' . parse_url($neu, PHP_URL_QUERY)));
        $seite = $this->kontoAnmeldung('/konto?' . parse_url($zurKonto, PHP_URL_QUERY));
        $seite->anmelden($this->auth());

        $ende = (string) $this->weiterleitung($seite);
        $this->assertSame('/mitarbeiter/neu/' . self::TOKEN . '?' . $query, $ende);
        $this->assertSame('verified', $this->huelle($ende)->state);
    }
}
