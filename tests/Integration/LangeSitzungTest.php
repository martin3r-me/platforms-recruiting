<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Configuration\Middleware as MiddlewareKonfiguration;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Middleware\LangeSitzungVerlaengern;
use Platform\Recruiting\Http\Middleware\RecruitingAccessGate;
use Platform\Recruiting\RecruitingServiceProvider;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Support\LangeSitzung;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Auf diesem Geraet angemeldet bleiben": die Sitzungsseite.
 *
 * Geprueft wird mit dem ECHTEN StartSession und dem ECHTEN
 * DatabaseSessionHandler gegen eine SQLite-Sitzungstabelle — nicht mit
 * einer Attrappe der Lebensdauer. Die ganze Schwierigkeit der Aufgabe sitzt
 * darin, WANN der Handler seine Minuten bekommt; ein Test, der nur
 * config('session.lifetime') abfragt, saehe das nicht.
 *
 * Die Kernfrage jedes End-to-End-Tests: eine Sitzung, die DREI STUNDEN ruht
 * (laenger als die 2 Stunden des Wirts), wird sie noch gelesen?
 */
final class LangeSitzungTest extends TestCase
{
    private const COOKIE_SITZUNG = 'meingedeck_session';

    private Capsule $capsule;

    private Container $container;

    private ?Container $vorherigerContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vorherigerContainer = Container::getInstance();
        $container = new Container();
        Container::setInstance($container);
        $this->container = $container;

        $container->instance('config', new ConfigRepository([
            'session' => [
                'driver'          => 'database',
                'lifetime'        => 120,
                'expire_on_close' => false,
                'encrypt'         => false,
                'connection'      => null,
                'table'           => 'sessions',
                'lottery'         => [2, 100],
                'cookie'          => self::COOKIE_SITZUNG,
                'path'            => '/',
                'domain'          => null,
                'secure'          => null,
                'http_only'       => true,
                'same_site'       => 'lax',
                'partitioned'     => false,
            ],
        ]));

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        // Die Sitzungstabelle des Wirts, woertlich aus
        // platform-core/database/migrations/0001_01_01_000000_create_users_table.php.
        $this->capsule->schema()->create('sessions', function ($t) {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        // Die ECHTE Migration des Moduls, nicht ein Nachbau.
        $migration = require dirname(__DIR__, 2)
            . '/database/migrations/2026_10_13_000002_create_rec_portal_lange_sitzungen_table.php';
        $migration->up();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->vorherigerContainer);

        parent::tearDown();
    }

    // ----------------------------------------------------------------- Hilfen

    private static function kennung(string $zeichen): string
    {
        return str_repeat($zeichen, 40);
    }

    /** Eine Sitzungszeile mit Portal-Anmeldung fuer Anstellung 7. */
    private function sitzung(string $id, int $ruhtSekunden, ?int $userId = null): void
    {
        DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $userId,
            'payload'       => base64_encode(serialize([
                '_token' => str_repeat('t', 40),
                PortalAuth::sessionKey(7) => '2026-10-13T08:00:00+02:00',
            ])),
            'last_activity' => time() - $ruhtSekunden,
        ]);
    }

    private function eintragen(string $id): void
    {
        DB::table(LangeSitzung::TABELLE)->insert(['session_id' => $id, 'rec_person_id' => 2, 'created_at' => '2026-10-13 08:00:00']);
    }

    /** @param array<string, string> $cookies */
    private function anfrage(array $cookies): Request
    {
        // /livewire/update: jede Livewire-Aktion laeuft dorthin — eine
        // Pfad-Weiche koennte die Portal-Anfragen gar nicht erkennen.
        return Request::create('/livewire/update', 'POST', [], $cookies);
    }

    /**
     * Die Anfrage durch [LangeSitzungVerlaengern, StartSession] schicken —
     * in genau der Reihenfolge der web-Gruppe — und zurueckgeben, ob die
     * Portal-Anmeldung in der Sitzung angekommen ist.
     *
     * @return array{angemeldet: bool, antwort: Response}
     */
    private function durchlauf(Request $anfrage, bool $treiberVorher = false): array
    {
        $manager = new SessionManager($this->container);
        $this->container->instance('session', $manager);

        if ($treiberVorher) {
            // Ein ServiceProvider, der session() schon VOR StartSession
            // anfasst: der Treiber steht dann mit 120 Minuten.
            $manager->driver();
        }

        $angemeldet = false;
        $antwort = (new LangeSitzungVerlaengern())->handle($anfrage, function (Request $r) use ($manager, &$angemeldet) {
            return (new StartSession($manager))->handle($r, function (Request $innen) use (&$angemeldet) {
                $angemeldet = $innen->session()->get(PortalAuth::sessionKey(7)) !== null;

                return new Response('ok');
            });
        });

        return ['angemeldet' => $angemeldet, 'antwort' => $antwort];
    }

    private function sitzungsCookie(Response $antwort): ?Cookie
    {
        foreach ($antwort->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::COOKIE_SITZUNG) {
                return $cookie;
            }
        }

        return null;
    }

    // -------------------------------------------------------- Die Middleware

    public function test_mit_merker_und_eintragung_gelten_180_tage_und_die_lotterie_ist_aus(): void
    {
        $id = self::kennung('a');
        $this->sitzung($id, 60);
        $this->eintragen($id);

        (new LangeSitzungVerlaengern())->handle(
            $this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']),
            fn () => new Response('ok'),
        );

        $this->assertSame(259200, config('session.lifetime'));
        $this->assertSame([0, 100], config('session.lottery'));
    }

    /** Jede web-Anfrage schaltet die Lotterie ab — auch ohne Merker. */
    public function test_ohne_merker_bleiben_zwei_stunden_aber_die_lotterie_ist_trotzdem_aus(): void
    {
        (new LangeSitzungVerlaengern())->handle($this->anfrage([]), fn () => new Response('ok'));

        $this->assertSame(120, config('session.lifetime'));
        $this->assertSame([0, 100], config('session.lottery'), 'Eine normale Anfrage, die die Lotterie gewinnt, raeumte jede lange Sitzung ab.');
    }

    /** Mit einem anderen Treiber raeumt niemand anderes auf — dort bleibt alles beim Alten. */
    public function test_ohne_datenbank_treiber_bleibt_alles_wie_es_war(): void
    {
        config(['session.driver' => 'file']);
        $id = self::kennung('a');
        $this->eintragen($id);

        (new LangeSitzungVerlaengern())->handle(
            $this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']),
            fn () => new Response('ok'),
        );

        $this->assertSame(120, config('session.lifetime'));
        $this->assertSame([2, 100], config('session.lottery'));
    }

    // ------------------------------------------------- Die ganze Kette, echt

    public function test_eine_drei_stunden_ruhende_lange_sitzung_wird_gelesen(): void
    {
        $id = self::kennung('a');
        $this->sitzung($id, 3 * 3600);
        $this->eintragen($id);

        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']));

        $this->assertTrue($lauf['angemeldet'], 'Die lange Sitzung wurde nach drei Stunden verworfen.');

        // Der Browser behaelt den Sitzungs-Cookie 180 Tage (gleitend: er wird
        // mit jeder Antwort neu ausgestellt).
        $cookie = $this->sitzungsCookie($lauf['antwort']);
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time() + 179 * 86400, $cookie->getExpiresTime());

        // Und die Zeile ist nachgezogen — das macht die Frist gleitend.
        $this->assertGreaterThan(time() - 60, (int) DB::table('sessions')->where('id', $id)->value('last_activity'));
    }

    /**
     * Gegenprobe: DIESELBE Zeile ohne Merker ist nach drei Stunden
     * abgelaufen — genau wie vorher. Ohne diese Gegenprobe bewiese der Test
     * darueber nur, dass der Handler gar nicht auf das Alter schaut.
     */
    public function test_ohne_merker_ist_dieselbe_sitzung_nach_drei_stunden_abgelaufen(): void
    {
        $id = self::kennung('a');
        $this->sitzung($id, 3 * 3600);
        $this->eintragen($id);

        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id]));

        $this->assertFalse($lauf['angemeldet']);
        $cookie = $this->sitzungsCookie($lauf['antwort']);
        $this->assertNotNull($cookie);
        $this->assertLessThan(time() + 3 * 3600, $cookie->getExpiresTime(), 'Ohne Merker muss der Cookie bei zwei Stunden bleiben.');
    }

    /** Kurze Sitzungen innerhalb der zwei Stunden: unveraendert gueltig. */
    public function test_eine_kurze_sitzung_innerhalb_von_zwei_stunden_gilt_wie_bisher(): void
    {
        $id = self::kennung('k');
        $this->sitzung($id, 3600);

        $this->assertTrue($this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id]))['angemeldet']);
    }

    /**
     * DER MERKER ALLEIN GILT NICHTS. Wer sich den Cookie selbst setzt, ohne
     * dass seine Sitzung bei einer Anmeldung mit Haken eingetragen wurde,
     * bekommt weder eine Anmeldung noch eine Verlaengerung.
     */
    public function test_der_merker_ohne_eingetragene_sitzung_verlaengert_nichts(): void
    {
        $id = self::kennung('a');
        $this->sitzung($id, 3 * 3600);

        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']));

        $this->assertFalse($lauf['angemeldet']);
        $this->assertSame(120, config('session.lifetime'));
    }

    /** Merker ohne jede Sitzung: nicht angemeldet, nichts verlaengert. */
    public function test_der_merker_ohne_sitzung_meldet_niemanden_an(): void
    {
        $lauf = $this->durchlauf($this->anfrage([LangeSitzung::COOKIE => '1']));

        $this->assertFalse($lauf['angemeldet']);
        $this->assertSame(120, config('session.lifetime'));
    }

    /** Merker mit erfundener Sitzungskennung, die eingetragen ist, aber keine Zeile hat. */
    public function test_eine_eingetragene_aber_verschwundene_sitzung_meldet_niemanden_an(): void
    {
        $id = self::kennung('z');
        $this->eintragen($id);

        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']));

        $this->assertFalse($lauf['angemeldet']);
        $this->assertSame(120, config('session.lifetime'));
    }

    /**
     * HR HAT DAS PORTAL IM SELBEN BROWSER AUSPROBIERT: die Sitzung traegt
     * einen Backend-Benutzer. Sie wird NICHT verlaengert, auch wenn Merker
     * und Eintragung stimmen — HR-Sitzungen bleiben bei zwei Stunden.
     */
    public function test_eine_sitzung_mit_backend_benutzer_bleibt_kurz(): void
    {
        $id = self::kennung('h');
        $this->sitzung($id, 3 * 3600, 5);
        $this->eintragen($id);

        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']));

        $this->assertFalse($lauf['angemeldet']);
        $this->assertSame(120, config('session.lifetime'));
    }

    /** Hat ein ServiceProvider den Treiber schon vorher gebaut, greift die Verlaengerung trotzdem. */
    public function test_ein_schon_gebauter_treiber_wird_neu_gebaut(): void
    {
        $id = self::kennung('a');
        $this->sitzung($id, 3 * 3600);
        $this->eintragen($id);

        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $id, LangeSitzung::COOKIE => '1']), treiberVorher: true);

        $this->assertTrue($lauf['angemeldet']);
    }

    // -------------------------------------------------- Session Fixation

    /**
     * SESSION FIXATION, die ganze Kette mit echtem StartSession und echtem
     * DatabaseSessionHandler: eine untergeschobene Kennung — sogar eine, die
     * der Angreifer mit seinem EIGENEN Konto als lange Sitzung eingetragen
     * hat — ist nach der Anmeldung tot. Die Antwort setzt schon den Cookie
     * mit der neuen Kennung, eingetragen ist die neue, das CSRF-Token ist
     * dasselbe, und nur die neue Kennung wird noch verlaengert.
     */
    public function test_die_anmeldung_wechselt_die_kennung_und_nur_die_neue_wird_verlaengert(): void
    {
        $alt = self::kennung('f');
        $this->sitzung($alt, 60);
        $this->eintragen($alt);

        $manager = new SessionManager($this->container);
        $this->container->instance('session', $manager);

        $antwort = (new LangeSitzungVerlaengern())->handle(
            $this->anfrage([self::COOKIE_SITZUNG => $alt, LangeSitzung::COOKIE => '1']),
            function (Request $r) use ($manager) {
                return (new StartSession($manager))->handle($r, function () {
                    // Was KontoAnmelden::anmelden() nach dem Passwortnachweis tut.
                    PortalAuth::erneuereSitzungskennung();
                    LangeSitzung::beginne(2);

                    return new Response('ok');
                });
            },
        );

        $cookie = $this->sitzungsCookie($antwort);
        $this->assertNotNull($cookie);
        $neu = (string) $cookie->getValue();
        $this->assertNotSame($alt, $neu, 'Die Antwort setzt noch die untergeschobene Kennung.');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{40}$/', $neu);

        $this->assertSame([$neu], DB::table('sessions')->pluck('id')->all(), 'Die alte Sitzungszeile lebt weiter.');
        $this->assertSame([$neu], DB::table(LangeSitzung::TABELLE)->pluck('session_id')->all());

        $nutzlast = unserialize(base64_decode((string) DB::table('sessions')->where('id', $neu)->value('payload')));
        $this->assertSame(str_repeat('t', 40), $nutzlast['_token'], 'Das CSRF-Token wurde getauscht — die offene Seite bekaeme 419.');
        $this->assertArrayHasKey(PortalAuth::sessionKey(7), $nutzlast, 'Die Inhalte der Sitzung sind beim Wechsel verloren gegangen.');

        // Die alte Kennung: nichts mehr — weder Anmeldung noch Verlaengerung.
        config(['session.lifetime' => 120]);
        $lauf = $this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $alt, LangeSitzung::COOKIE => '1']));
        $this->assertFalse($lauf['angemeldet']);
        $this->assertSame(120, config('session.lifetime'));

        // Die neue Kennung: drei Stunden Ruhe, trotzdem angemeldet.
        DB::table('sessions')->where('id', $neu)->update(['last_activity' => time() - 3 * 3600]);
        $this->assertTrue($this->durchlauf($this->anfrage([self::COOKIE_SITZUNG => $neu, LangeSitzung::COOKIE => '1']))['angemeldet']);
        $this->assertSame(LangeSitzung::MINUTEN, config('session.lifetime'));
    }

    // -------------------------------------------------- Die Stelle im Stapel

    /**
     * Die Middleware sitzt in der web-Gruppe NACH EncryptCookies (sie liest
     * entschluesselte Cookies) und VOR StartSession (danach waere der
     * Handler schon gebaut) — auch nachdem der Router die Middleware einer
     * Route nach seiner Prioritaetsliste sortiert hat.
     */
    public function test_die_middleware_sitzt_zwischen_encrypt_cookies_und_start_session(): void
    {
        $router = new Router(new Dispatcher($this->container), $this->container);
        // Die Gruppe und die Prioritaet, wie der Wirt sie setzt (bootstrap/app.php
        // aendert an web nichts): Laravels eigene Vorgaben.
        $router->middlewareGroup('web', (new MiddlewareKonfiguration())->getMiddlewareGroups()['web']);
        $router->middlewarePriority = (new \ReflectionClass(HttpKernel::class))
            ->getProperty('middlewarePriority')->getDefaultValue();
        // Ein anderer Provider hat vorher schon etwas angehaengt — das darf nicht verloren gehen.
        $router->pushMiddlewareToGroup('web', RecruitingAccessGate::class);

        LangeSitzungVerlaengern::einhaengen($router);
        LangeSitzungVerlaengern::einhaengen($router); // zweimal: harmlos

        $gruppe = $router->getMiddlewareGroups()['web'];
        $this->assertSame(1, count(array_keys($gruppe, LangeSitzungVerlaengern::class, true)));
        $this->assertContains(RecruitingAccessGate::class, $gruppe);
        // Erstes Netz: die Gruppe selbst, unmittelbar vor StartSession — ohne
        // die Sortierung des Routers, die sonst einen Fehler hier verdeckte.
        $this->assertSame(
            array_search(StartSession::class, $gruppe, true) - 1,
            array_search(LangeSitzungVerlaengern::class, $gruppe, true),
        );
        $this->assertLessThan(
            array_search(LangeSitzungVerlaengern::class, $gruppe, true),
            array_search(EncryptCookies::class, $gruppe, true),
        );

        $route = new Route(['POST'], 'livewire/update', fn () => 'ok');
        $route->middleware(['web']);
        $route->setRouter($router);
        $route->setContainer($this->container);
        $gesammelt = $router->gatherRouteMiddleware($route);

        $encrypt = array_search(EncryptCookies::class, $gesammelt, true);
        $unsere = array_search(LangeSitzungVerlaengern::class, $gesammelt, true);
        $start = array_search(StartSession::class, $gesammelt, true);

        $this->assertIsInt($encrypt);
        $this->assertIsInt($unsere);
        $this->assertIsInt($start);
        $this->assertLessThan($unsere, $encrypt, 'Vor EncryptCookies saehe die Middleware nur verschluesselte Cookies.');
        $this->assertLessThan($start, $unsere, 'Hinter StartSession ist der Handler schon mit 120 Minuten gebaut.');

        $prioritaet = $router->middlewarePriority;
        $this->assertSame(
            array_search(StartSession::class, $prioritaet, true) - 1,
            array_search(LangeSitzungVerlaengern::class, $prioritaet, true),
        );
    }

    /** Ohne StartSession in der Gruppe (Konsole) passiert nichts. */
    public function test_ohne_start_session_wird_nichts_eingehaengt(): void
    {
        $router = new Router(new Dispatcher($this->container), $this->container);

        LangeSitzungVerlaengern::einhaengen($router);

        $this->assertArrayNotHasKey('web', $router->getMiddlewareGroups());
    }

    /** Der ServiceProvider haengt sie wirklich ein. */
    public function test_der_service_provider_haengt_die_middleware_ein(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/RecruitingServiceProvider.php');

        $this->assertStringContainsString('LangeSitzungVerlaengern::einhaengen(', $quelle);
        $this->assertStringContainsString('Commands\\SitzungenAufraeumen::class', $quelle);
    }

    // ------------------------------------------------------- Die Aufraeumung

    public function test_die_aufraeumung_trifft_die_richtigen_zeilen(): void
    {
        $kurzAlt = self::kennung('1');
        $kurzFrisch = self::kennung('2');
        $langAktiv = self::kennung('3');
        $langAbgelaufen = self::kennung('4');
        $langMitHr = self::kennung('5');
        $kurzUraltOhneEintrag = self::kennung('6');
        $verwaist = self::kennung('7');

        $this->sitzung($kurzAlt, 3 * 3600);
        $this->sitzung($kurzFrisch, 3600);
        $this->sitzung($langAktiv, 30 * 86400);
        $this->sitzung($langAbgelaufen, 181 * 86400);
        $this->sitzung($langMitHr, 3 * 3600, 5);
        $this->sitzung($kurzUraltOhneEintrag, 200 * 86400);
        $this->eintragen($langAktiv);
        $this->eintragen($langAbgelaufen);
        $this->eintragen($langMitHr);
        $this->eintragen($verwaist);

        // Trockenlauf loescht nichts.
        LangeSitzung::raeumeAuf(null, true);
        $this->assertSame(6, DB::table('sessions')->count());
        $this->assertSame(4, DB::table(LangeSitzung::TABELLE)->count());

        $ergebnis = LangeSitzung::raeumeAuf();

        $uebrig = DB::table('sessions')->orderBy('id')->pluck('id')->all();
        $this->assertSame([$kurzFrisch, $langAktiv], $uebrig);
        $this->assertSame([$langAktiv], DB::table(LangeSitzung::TABELLE)->pluck('session_id')->all());
        $this->assertSame(['abgelaufen_lang' => 2, 'abgelaufen_kurz' => 2, 'eintragungen' => 3], $ergebnis);
    }

    /** Die Grenze ist dieselbe wie im Treiber (gc: last_activity <= jetzt - Lebensdauer). */
    public function test_die_kurze_grenze_folgt_der_lebensdauer_des_wirts(): void
    {
        config(['session.lifetime' => 60]);
        $id = self::kennung('8');
        $this->sitzung($id, 90 * 60);

        LangeSitzung::raeumeAuf();

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_die_aufraeumung_laeuft_stuendlich_im_zeitplan(): void
    {
        $treffer = array_values(array_filter(
            $this->zeitplanEintraege(),
            static fn (array $e): bool => str_contains($e['befehl'], 'recruiting:sitzungen-aufraeumen'),
        ));

        $this->assertCount(1, $treffer, 'Ohne den Lauf waechst die Sitzungstabelle unbegrenzt — die Lotterie ist aus.');
        $this->assertSame('0 * * * *', $treffer[0]['ausdruck']);
    }

    /**
     * Wie in KontoZuruecksetzenTest: registerSchedule() des echten
     * ServiceProviders gegen einen echten Schedule.
     *
     * @return list<array{befehl: string, ausdruck: string}>
     */
    private function zeitplanEintraege(): array
    {
        $cacheFabrik = new class implements \Illuminate\Contracts\Cache\Factory {
            public function store($name = null)
            {
                return new CacheRepository(new ArrayStore());
            }
        };

        $wirt = new class extends Container {
            public function storagePath($pfad = '')
            {
                return sys_get_temp_dir() . ($pfad !== '' ? '/' . $pfad : '');
            }
        };
        $wirt->instance(\Illuminate\Contracts\Cache\Factory::class, $cacheFabrik);
        $wirt->instance(EventMutex::class, new CacheEventMutex($cacheFabrik));
        $wirt->instance(SchedulingMutex::class, new CacheSchedulingMutex($cacheFabrik));

        $vorher = Container::getInstance();
        Container::setInstance($wirt);
        Facade::setFacadeApplication($wirt);

        $zeitplan = new Schedule();
        $wirt->instance(Schedule::class, $zeitplan);

        try {
            $anbieter = new RecruitingServiceProvider($wirt);
            $methode = new \ReflectionMethod(RecruitingServiceProvider::class, 'registerSchedule');
            $methode->invoke($anbieter);
        } finally {
            Container::setInstance($vorher);
            Facade::setFacadeApplication($this->container);
            Facade::clearResolvedInstances();
        }

        $eintraege = [];
        foreach ($zeitplan->events() as $ereignis) {
            $eintraege[] = ['befehl' => (string) $ereignis->command, 'ausdruck' => (string) $ereignis->expression];
        }

        return $eintraege;
    }
}
