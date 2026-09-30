<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as RateLimiterWerk;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\Compilers\BladeCompiler;
use Livewire\Attributes\Locked;
use Livewire\Drawer\Utils as LivewireUtils;
use Livewire\Mechanisms\DataStore;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\KontoAnlegen;
use Platform\Recruiting\Services\KontoWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die erste sichtbare Seite des Mitarbeiterkontos: die Registrierung.
 *
 * Geprueft wird hier vor allem, was still falsch sein koennte:
 *
 *  - Ein ungueltiger, abgelaufener oder verbrauchter Token muss wie eine
 *    nicht existierende Seite aussehen. Jede eigene Meldung waere die
 *    Auskunft, dass es diesen Token gibt.
 *  - Die Drossel auf das falsche Geburtsdatum ist TRAGEND: acht Zeichen aus
 *    31 sind rund 850 Milliarden Moeglichkeiten, aber ein plausibler
 *    Geburtsjahrgang-Bereich nur rund 25.000. Ohne Bremse reicht ein
 *    gefundener Token.
 *  - Ein Tippfehler im Geburtsdatum darf die Einladung NICHT verbrennen —
 *    die Drossel ist die Grenze, nicht der Token.
 *  - Alles, was ueber Identitaet oder Zustand entscheidet, traegt #[Locked].
 *    Das ist die Lehre aus dem verifiziert ausnutzbaren Auth-Bypass vom
 *    19.08.2026 ($wire.set state=verified).
 *
 * Schema und Aufbau von Hand, Vorbild PersonLinkerTest/KontoWriterTest
 * (Migrationen laufen in dieser Suite nicht) — inklusive Log-Attrappe vor
 * Facade::clearResolvedInstances().
 *
 * Der Container ist hier eine Unterklasse mit abort(): das echte abort()
 * ruft app()->abort(), und ein blanker Container kennt die Methode nicht.
 * Die Attrappe wirft, was Foundation\Application wirft — eine
 * NotFoundHttpException bei 404 — und ist damit nicht grosszuegiger als der
 * Wirt.
 */
final class KontoAnlegenTest extends TestCase
{
    private const TEAM = 3;

    private const GEBURT = '1995-03-14';

    private const FALSCHE_GEBURT = '1995-03-15';

    private const PASSWORT = 'ganz-geheim-2026';

    private const PFEFFER = 'pfeffer-fuer-den-test';

    private const ANGEFASST = '2026-09-28 09:00:00';

    private Capsule $capsule;

    private Repository $cache;

    private Store $session;

    /** @var object{geschrieben: list<string>} die Cache-Attrappe hinter $cache */
    private object $store;

    private ?Container $vorherigerContainer = null;

    private Container $container;

    private int $personId;

    private string $tmpDir;

    private ?Router $router = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Den bisherigen Container merken und am Ende zurueckgeben: diese
        // Klasse braucht einen eigenen mit abort(), und ein zurueckgelassener
        // Sonder-Container waere fuer jede spaetere Testklasse eine
        // Ueberraschung.
        $this->vorherigerContainer = Container::getInstance();

        $container = new class extends Container {
            /** Wortgleich mit Foundation\Application::abort(). */
            public function abort($code, $message = '', array $headers = [])
            {
                if ($code == 404) {
                    throw new NotFoundHttpException($message);
                }

                throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
            }
        };
        Container::setInstance($container);
        $this->container = $container;

        // Log-Attrappe binden, BEVOR die Facade-Instanzen geleert werden
        // (reference_log_facade_test_stub.md).
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['konto' => ['pepper' => self::PFEFFER]],
            // Livewires redirect() fragt danach; fehlt der Schluessel, kommt
            // der Vorgabewert.
            'livewire'   => ['render_on_redirect' => false],
        ]));

        // Token und Personen-Kennung liegen seit dem Schnappschuss-Fund in
        // der SITZUNG und nicht mehr in oeffentlichen Eigenschaften — ohne
        // gebundene Sitzung liefe hier gar nichts.
        $this->session = new Store('test', new ArraySessionHandler(60));
        $container->instance('session', $this->session);

        // Livewires store() haengt an EINEM DataStore. Ein blanker Container
        // baut bei jedem app()-Aufruf einen neuen — dann schriebe redirect()
        // in den einen und der Test laese aus dem anderen, und jede
        // Weiterleitungs-Zusicherung waere stumm gruen.
        $container->instance(DataStore::class, new DataStore());

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

        // Der Wirt faehrt CACHE_STORE=database, und cache.key ist dort
        // varchar(255) PRIMARY KEY. Ein blanker ArrayStore schluckt jeden
        // Schluessel klaglos — die Attrappe zieht die Grenze des Wirts ein
        // und merkt sich, was ueberhaupt geschrieben wurde (Lehre aus
        // Aufgabe 5: eine Attrappe, die grosszuegiger ist als der Wirt,
        // prueft nichts).
        $this->store = new class extends ArrayStore {
            /** @var list<string> */
            public array $geschrieben = [];

            public function put($key, $value, $seconds): bool
            {
                $this->grenze($key);
                $this->geschrieben[] = (string) $key;

                return parent::put($key, $value, $seconds);
            }

            public function increment($key, $value = 1)
            {
                $this->grenze($key);

                return parent::increment($key, $value);
            }

            private function grenze($key): void
            {
                if (strlen((string) $key) > 255) {
                    throw new \RuntimeException(
                        'SQLSTATE[22001]: Data too long for column key (' . strlen((string) $key) . ' Zeichen)',
                    );
                }
            }
        };
        $this->cache = new Repository($this->store);
        $container->instance(RateLimiterWerk::class, new RateLimiterWerk($this->cache));

        $this->capsule->schema()->create('rec_persons', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->unique();
            $t->integer('team_id')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('password_hash')->nullable();
            $t->string('email')->nullable();
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

            $t->timestamps();

            $t->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->integer('rec_person_id')->nullable();
            $t->string('company')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->timestamps();
        });

        // Die Anrede kommt aus den Team-Einstellungen (use_informal_address).
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });

        $this->personId = (int) DB::table('rec_persons')->insertGetId([
            'uuid'       => 'p-konto-anlegen',
            'team_id'    => self::TEAM,
            'phone'      => '+4915111111111',
            'created_at' => self::ANGEFASST,
            'updated_at' => self::ANGEFASST,
        ]);

        DB::table('rec_employees')->insert([
            'id' => 1, 'team_id' => self::TEAM, 'first_name' => 'Gregor', 'last_name' => 'Erste',
            'birth_date' => self::GEBURT, 'rec_person_id' => $this->personId, 'company' => 'RG',
            'is_active' => 1, 'created_at' => self::ANGEFASST, 'updated_at' => self::ANGEFASST,
        ]);

        $this->tmpDir = sys_get_temp_dir() . '/konto-anlegen-blade-' . getmypid();
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

    private function einladung(): string
    {
        return KontoWriter::ladeEin($this->personId);
    }

    private function seite(string $token): KontoAnlegen
    {
        $seite = new KontoAnlegen();
        $seite->mount($token);

        return $seite;
    }

    /**
     * Was sich die Seite serverseitig gemerkt hat.
     *
     * Bewusst ueber die Sitzung und nicht ueber eine Eigenschaft: seit dem
     * Schnappschuss-Fund liegen Token und Kennung dort, und genau das ist
     * die Zusicherung. Die Schluessel werden hier ABSICHTLICH nachgetippt —
     * liest der Test sie aus der Klasse, prueft er nur sich selbst.
     */
    private function gemerkterToken(): ?string
    {
        return $this->session->get('recruiting.konto.anlegen.token');
    }

    private function gemerktePerson(): ?int
    {
        return $this->session->get('recruiting.konto.anlegen.person');
    }

    private function zeile(): object
    {
        return DB::table('rec_persons')->where('id', $this->personId)->first();
    }

    /** Derselbe Schluessel, den die Seite bildet — hier absichtlich nachgerechnet. */
    private function drosselSchluessel(string $token): string
    {
        return 'konto-anlegen:' . sha1($token);
    }

    private function blade(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2) . '/resources/views/livewire/public/konto-anlegen.blade.php',
        );
    }

    /**
     * Das GANZE Blade wird mit dem echten BladeCompiler uebersetzt und
     * ausgefuehrt — nicht bloss nach Zeichenketten durchsucht. Ein
     * Quelltext-Waechter haette den Fall nicht gesehen, in dem eine
     * Direktive still nicht kompiliert und der falsche Zweig rendert.
     */
    private function rendere(KontoAnlegen $seite): string
    {
        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $datei = $this->tmpDir . '/konto-anlegen-' . md5($seite->state) . '.php';
        file_put_contents($datei, $compiler->compileString($this->blade()));

        $variablen = get_object_vars($seite);
        $variablen['__datei'] = $datei;

        $lauf = function (array $__v): string {
            extract($__v);
            ob_start();
            include $__datei;

            return (string) ob_get_clean();
        };

        return \Closure::bind($lauf, $seite, KontoAnlegen::class)($variablen);
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

        // route() in oeffneCode() braucht den Erzeuger — und zwar denselben
        // Routenbestand, sonst zeigt die Weiterleitung woandershin als die
        // Route, die geprueft wurde.
        $this->container->instance('url', new UrlGenerator(
            $router->getRoutes(),
            Request::create('http://localhost/konto/anlegen', 'GET'),
        ));

        return $router;
    }

    // ----------------------------------------------------------- Der Zugang

    public function test_gueltige_einladung_oeffnet_das_formular(): void
    {
        $token = $this->einladung();

        $seite = $this->seite($token);

        $this->assertSame('formular', $seite->state);
        $this->assertSame($this->personId, $this->gemerktePerson());
        $this->assertSame($token, $this->gemerkterToken());
    }

    public function test_unbekannter_token_ist_404(): void
    {
        $this->einladung();

        $this->expectException(NotFoundHttpException::class);
        $this->seite('ZZZZZZZZ');
    }

    public function test_abgelaufener_token_ist_404(): void
    {
        $token = $this->einladung();
        DB::table('rec_persons')->where('id', $this->personId)
            ->update(['invite_expires_at' => '2026-09-01 08:00:00']);

        $this->expectException(NotFoundHttpException::class);
        $this->seite($token);
    }

    public function test_verbrauchter_token_ist_404(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->registriere();
        $this->assertSame('fertig', $seite->state);

        // Der zweite Aufruf desselben Links darf kein zweites Konto anbieten.
        $this->expectException(NotFoundHttpException::class);
        $this->seite($token);
    }

    /**
     * F2 der Pruefung: der Verbraucht-Test daneben faehrt den echten Ablauf,
     * und der NULLT den Hash gleich mit — `invite_used_at` traegt dort also
     * nichts. Dieser Test stellt den Zustand DIREKT her (Hash steht noch,
     * `invite_used_at` gesetzt). Heute unerreichbar, weil nur KontoWriter
     * diese Spalten schreibt; laesst je jemand den Hash stehen, oeffnete die
     * Seite sonst eine verbrauchte Einladung wieder.
     */
    public function test_eine_verbrauchte_einladung_mit_stehengebliebenem_hash_ist_404(): void
    {
        $token = $this->einladung();
        DB::table('rec_persons')->where('id', $this->personId)
            ->update(['invite_used_at' => self::ANGEFASST]);

        $this->assertNotNull($this->zeile()->invite_token_hash, 'Vorflug: der Hash muss fuer diesen Test stehen bleiben');

        $this->expectException(NotFoundHttpException::class);
        $this->seite($token);
    }

    public function test_gesperrte_person_ist_404(): void
    {
        $token = $this->einladung();
        DB::table('rec_persons')->where('id', $this->personId)
            ->update(['locked_at' => self::ANGEFASST]);

        $this->expectException(NotFoundHttpException::class);
        $this->seite($token);
    }

    public function test_stillgelegte_person_ist_404(): void
    {
        $token = $this->einladung();
        DB::table('rec_persons')->where('id', $this->personId)
            ->update(['merged_into_person_id' => 999]);

        $this->expectException(NotFoundHttpException::class);
        $this->seite($token);
    }

    /**
     * Ruling GD-4: der lesbare Code IST der Token. Wer ihn am Rechner
     * abtippt, tippt ihn klein, mit Leerzeichen oder mit Bindestrichen —
     * beim Einlesen grosszuegig, beim Pruefen streng.
     */
    public function test_der_code_wird_grosszuegig_gelesen(): void
    {
        $token = $this->einladung();
        $getippt = strtolower(substr($token, 0, 4)) . ' - ' . strtolower(substr($token, 4));

        $seite = $this->seite($getippt);

        $this->assertSame($token, $this->gemerkterToken());
        $this->assertSame($this->personId, $this->gemerktePerson());
    }

    public function test_streng_geprueft_wird_trotzdem(): void
    {
        $token = $this->einladung();

        // Ein Zeichen daneben ist ein anderer Token, auch wenn der Rest stimmt.
        $falsch = substr($token, 0, 7) . ($token[7] === 'A' ? 'B' : 'A');

        $this->expectException(NotFoundHttpException::class);
        $this->seite($falsch);
    }

    // ------------------------------------------------------- Die Registrierung

    public function test_registrierung_legt_das_konto_an(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;

        $seite->registriere();

        $zeile = $this->zeile();
        $this->assertSame('', $seite->fehler);
        $this->assertSame('fertig', $seite->state);
        $this->assertNotNull($zeile->password_hash);
        $this->assertNotNull($zeile->registered_at);
        $this->assertNotNull($zeile->invite_used_at);
        $this->assertNull($zeile->invite_token_hash);
    }

    public function test_das_passwort_steht_nirgends_im_klartext(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;

        $seite->registriere();

        $zeile = $this->zeile();
        $this->assertNotSame(self::PASSWORT, $zeile->password_hash);
        $this->assertStringStartsWith('$2y$', (string) $zeile->password_hash);

        // Und es faehrt nach dem Erfolg auch nicht weiter im
        // Livewire-Schnappschuss mit — das Geburtsdatum ebenso wenig: es ist
        // der zweite Nachweis und hat auf einer fertigen Seite nichts mehr zu
        // suchen.
        $this->assertSame('', $seite->passwort);
        $this->assertSame('', $seite->passwortWiederholung);
        $this->assertSame('', $seite->geburtsdatum);
    }

    public function test_falsches_geburtsdatum_meldet_ohne_zu_verraten(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;

        $seite->registriere();

        $this->assertNotSame('', $seite->fehler);
        $this->assertSame('formular', $seite->state);
        $this->assertNull($this->zeile()->password_hash);

        // Die Meldung nennt nicht, WELCHER der beiden Nachweise stimmte.
        $this->assertStringNotContainsStringIgnoringCase('token', $seite->fehler);
        $this->assertStringNotContainsStringIgnoringCase('einladung', $seite->fehler);
    }

    /**
     * Ein Tippfehler im Geburtsdatum verbrennt die Einladung NICHT — die
     * Drossel ist die Grenze, nicht der Token.
     */
    public function test_ein_tippfehler_verbrennt_die_einladung_nicht(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->registriere();

        $seite->geburtsdatum = self::GEBURT;
        $seite->registriere();

        $this->assertSame('fertig', $seite->state);
        $this->assertNotNull($this->zeile()->password_hash);
    }

    public function test_zwei_verschiedene_passwoerter_werden_nicht_gespeichert(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT . 'x';

        $seite->registriere();

        $this->assertNotSame('', $seite->fehler);
        $this->assertSame('formular', $seite->state);
        $this->assertNull($this->zeile()->password_hash);
    }

    public function test_ein_zu_kurzes_passwort_meldet_die_regel(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = 'kurz';
        $seite->passwortWiederholung = 'kurz';

        $seite->registriere();

        $this->assertStringContainsString('10 Zeichen', $seite->fehler);
        $this->assertNull($this->zeile()->password_hash);
    }

    /**
     * Ein Passwort-Fehler ist KEIN Rateversuch am zweiten Nachweis. Zaehlte
     * er mit, sperrte sich aus, wer fuenfmal ein zu kurzes Passwort tippt —
     * und zwar mit einer 404-Seite, aus der niemand klug wird.
     */
    public function test_passwortfehler_zaehlen_nicht_als_rateversuch(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;

        foreach (['kurz', 'auch-kurz'] as $versuch) {
            $seite->passwort = $versuch;
            $seite->passwortWiederholung = $versuch;
            $seite->registriere();
        }

        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT . 'abweichend';
        $seite->registriere();

        $this->assertSame(0, RateLimiter::attempts($this->drosselSchluessel($token)));
    }

    /**
     * Ein zweites Absenden desselben Formulars (Doppelklick, Zurueck-Taste)
     * liefe in den inzwischen verbrauchten Token — und zaehlte damit als
     * Rateversuch, obwohl niemand geraten hat. Fuenf Doppelklicks und die
     * eigene Seite antwortet mit 404.
     */
    public function test_ein_zweites_absenden_zaehlt_nicht_als_rateversuch(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->registriere();

        $seite->registriere();

        $this->assertSame('fertig', $seite->state);
        $this->assertSame('', $seite->fehler);
        $this->assertSame(0, RateLimiter::attempts($this->drosselSchluessel($token)));
    }

    // ------------------------------------------------------------- Die Drossel

    public function test_nach_fuenf_falschen_geburtsdaten_ist_die_seite_weg(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;

        for ($i = 0; $i < 5; $i++) {
            $seite->registriere();
        }

        // Auch mit dem RICHTIGEN Datum: die Drossel steht vor der Pruefung.
        $seite->geburtsdatum = self::GEBURT;

        $this->expectException(NotFoundHttpException::class);
        $seite->registriere();
    }

    public function test_die_gesperrte_seite_laesst_sich_nicht_neu_laden(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;

        for ($i = 0; $i < 5; $i++) {
            $seite->registriere();
        }

        $this->expectException(NotFoundHttpException::class);
        $this->seite($token);
    }

    /**
     * Die Drossel haengt am NORMALISIERTEN Token: sonst schuettelt man die
     * Sperre mit einer anderen Schreibweise desselben Codes ab (dieselbe
     * Falle wie beim Nummern-Schluessel in PortalAuth).
     */
    public function test_eine_andere_schreibweise_schuettelt_die_sperre_nicht_ab(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;

        for ($i = 0; $i < 5; $i++) {
            $seite->registriere();
        }

        $this->expectException(NotFoundHttpException::class);
        $this->seite(strtolower($token));
    }

    /**
     * F4 der Pruefung: die Sperre gilt eine STUNDE. Gemessen wird nicht die
     * Konstante, sondern die Laufzeit, die wirklich im Zaehler steht — eine
     * auf eine Minute verkuerzte Sperre bremst das Durchprobieren des
     * Geburtsdatums (rund 25.000 plausible Tage) nicht mehr nennenswert.
     */
    public function test_die_sperre_gilt_eine_stunde(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->registriere();

        $rest = RateLimiter::availableIn($this->drosselSchluessel($token));

        $this->assertGreaterThan(3540, $rest, 'Die Sperre laeuft frueher ab als eine Stunde.');
        $this->assertLessThanOrEqual(3600, $rest);
    }

    public function test_der_erfolg_raeumt_den_zaehler_ab(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->registriere();
        $seite->registriere();
        $this->assertSame(2, RateLimiter::attempts($this->drosselSchluessel($token)));

        $seite->geburtsdatum = self::GEBURT;
        $seite->registriere();

        $this->assertSame('fertig', $seite->state);
        $this->assertSame(0, RateLimiter::attempts($this->drosselSchluessel($token)));
    }

    /**
     * Der Token ist ein Geheimnis. Er darf nicht als Klartext-Schluessel in
     * der Cache-Tabelle stehen — und der Schluessel muss in die
     * varchar(255)-Spalte des Wirts passen (die Attrappe zieht die Grenze).
     */
    public function test_der_token_steht_nicht_im_klartext_im_cache(): void
    {
        $token = $this->einladung();
        $seite = $this->seite($token);
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->registriere();

        $this->assertNotSame([], $this->store->geschrieben, 'Die Drossel hat gar nichts geschrieben');

        foreach ($this->store->geschrieben as $schluessel) {
            $this->assertStringNotContainsString($token, $schluessel);
            $this->assertLessThanOrEqual(255, strlen($schluessel));
        }
    }

    /**
     * Eine ueberlange Adresse ergibt 404 und schreibt NICHTS.
     *
     * Der Zaehler haengt nicht an der blossen Adresse: wer Token
     * durchprobiert, fuellt damit nicht die Cache-Tabelle des Wirts. Dagegen
     * steht an der Route die erste Drossel (throttle:20,1).
     *
     * EHRLICH GESAGT, was dieser Test NICHT beweist (nachgemessen in der
     * Mutationsrunde): die Reihenfolge in mount() — erst nachschlagen, dann
     * drosseln — bleibt auch umgedreht gruen, weil tooManyAttempts() nur
     * LIEST. Der 500er-Fund der Anmeldeseite (unbegrenzt lange Eingabe in
     * cache.key) entsteht erst beim Schreiben, und geschrieben wird nur in
     * registriere() hinter einem nachgeschlagenen, achtstelligen Token. Was
     * ihn hier endgueltig ausschliesst, ist der gehashte Schluessel — und
     * DER ist gedeckt (test_der_token_steht_nicht_im_klartext_im_cache).
     */
    public function test_ein_unbekannter_token_schreibt_nichts_in_den_cache(): void
    {
        $this->einladung();

        try {
            $this->seite(str_repeat('A', 500));
            $this->fail('Ein unbekannter Token muss 404 ergeben.');
        } catch (NotFoundHttpException) {
            // So soll es sein.
        }

        $this->assertSame([], $this->store->geschrieben, 'Ein unbekannter Token hat etwas in den Cache geschrieben.');
    }

    // ---------------------------------------------------------- Der Waechter

    /**
     * DER AUTH-BYPASS. Am 19.08.2026 war `$wire.set('state', 'verified')`
     * verifiziert ausnutzbar und umging Geburtsdatum und Ausweisziffern
     * vollstaendig. Eine Livewire-Eigenschaft ohne #[Locked] setzt der
     * Browser direkt — hier waeren das WESSEN Konto angelegt wird und MIT
     * WELCHEM Nachweis.
     *
     * GESCHLOSSENE WELT, und das ist der Punkt: geprueft wird nicht nur, ob
     * die eingetragenen Felder gesperrt sind, sondern dass es KEIN
     * oeffentliches Feld gibt, das in keiner der beiden Listen steht. Die
     * erste Fassung dieses Waechters nannte nur die vier gesperrten Namen —
     * eine spaeter hinzugefuegte Zustands-Eigenschaft ohne #[Locked] waere
     * lautlos durchgerutscht, und der Waechter haette dabei gruen geleuchtet.
     * Das ist derselbe Bypass wie am 19.08., nur eine Runde spaeter.
     *
     * Die Listen werden trotzdem AUSGESCHRIEBEN und nicht abgeleitet: eine
     * abgeleitete Liste sagte "was gesperrt ist, ist gesperrt", also gar
     * nichts. So muss sich jedes neue Feld entscheiden — gesperrt, oder mit
     * einem Satz begruendet offen.
     */
    public function test_alles_was_ueber_identitaet_entscheidet_ist_gesperrt(): void
    {
        // GAR KEINE EIGENSCHAFT, und das ist die zweite Lehre: #[Locked]
        // verhindert das SETZEN, nicht das AUSLIEFERN. Diese beiden standen
        // hier einmal als #[Locked] public und wurden damit als
        // wire:snapshot ins HTML dehydriert — der Einladungs-Token also im
        // Quelltext der Seite, obwohl das Blade das Gegenteil verspricht.
        $nichtImSchnappschuss = [
            'token'    => 'der Besitznachweis selbst UND ein Geheimnis — acht Zeichen, sieben Tage '
                . 'gueltig. Als oeffentliche Eigenschaft stuende er im wire:snapshot und damit in '
                . 'jedem Bildschirmfoto',
            'personId' => 'WESSEN Konto angelegt wird — gehoert aus demselben Grund in die Sitzung',
        ];

        $gesperrt = [
            'state'    => 'der Zustand der Seite — genau der Bypass vom 19.08.2026',
            'duzen'    => 'kommt aus den Team-Einstellungen, nicht vom Menschen',
        ];

        // Absichtlich OFFEN, jede mit ihrem Grund. Ihre Sicherheit sitzt
        // nicht in der Unveraenderlichkeit, sondern darin, dass
        // KontoWriter::registriere() bei JEDEM Aufruf beide Nachweise erneut
        // prueft.
        $offen = [
            'geburtsdatum'         => 'die Eingabe des Menschen — gesperrt kann er nichts eintippen',
            'passwort'             => 'dito',
            'passwortWiederholung' => 'dito',
            'fehler'               => 'nur eine Anzeige; wer sie sich selbst setzt, beschreibt seinen eigenen Bildschirm',
            'code'                 => 'der abgetippte Einladungscode — eine Eingabe wie jede andere. '
                . 'Er entscheidet ueber nichts: oeffneCode() leitet mit ihm nur auf die Token-Route '
                . 'weiter, und dort prueft dieselbe mount(), die auch der Link durchlaeuft',
        ];

        $klasse = new \ReflectionClass(KontoAnlegen::class);

        foreach ($nichtImSchnappschuss as $name => $warum) {
            $this->assertFalse(
                $klasse->hasProperty($name) && $klasse->getProperty($name)->isPublic(),
                "KontoAnlegen::\${$name} ist wieder eine oeffentliche Eigenschaft — {$warum}. "
                . '#[Locked] hilft dagegen NICHT: es verhindert das Setzen, nicht das Ausliefern.',
            );
        }

        foreach ($gesperrt as $name => $warum) {
            $this->assertTrue(
                $klasse->hasProperty($name),
                "Die Eigenschaft {$name} gibt es nicht mehr — umbenannt? Dann gehoert der neue "
                . "Name hier hinein, sonst faellt der Schutz still weg ({$warum})",
            );
            $this->assertNotSame(
                [],
                $klasse->getProperty($name)->getAttributes(Locked::class),
                "#[Locked] fehlt an KontoAnlegen::\${$name} — {$warum}. "
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

        // DER SCHLUSS DER GESCHLOSSENEN WELT, und zwar auf der Ebene, auf
        // der es zaehlt: gefragt wird DIESELBE Funktion, mit der Livewire
        // dehydriert (HandleComponents::dehydrateProperties ruft genau sie).
        // Eine nachgebaute Reflexionsschleife waere ein Modell des Wirts —
        // diese Liste IST der Schnappschuss.
        $erwartet = array_keys(array_merge($gesperrt, $offen));
        $tatsaechlich = array_keys(LivewireUtils::getPublicPropertiesDefinedOnSubclass(new KontoAnlegen()));
        sort($erwartet);
        sort($tatsaechlich);

        $this->assertSame(
            $erwartet,
            $tatsaechlich,
            'Der wire:snapshot traegt andere Felder als die beiden Listen. Entscheide je Feld: '
            . 'gehoert es ueberhaupt nicht in den Schnappschuss (dann in die Sitzung und oben in '
            . '$nichtImSchnappschuss eintragen), entscheidet es ueber Identitaet oder Zustand '
            . '(dann #[Locked] und in $gesperrt), oder ist es eine Eingabe des Menschen (dann in '
            . '$offen, mit Grund)? Genau hier ist am 19.08.2026 ein Auth-Bypass entstanden.',
        );
    }

    // -------------------------------------------------------------- Das Blade

    public function test_das_formular_zeigt_genau_die_drei_felder(): void
    {
        $seite = $this->seite($this->einladung());

        $html = $this->rendere($seite);

        $this->assertStringContainsString('wire:model="geburtsdatum"', $html);
        $this->assertStringContainsString('wire:model="passwort"', $html);
        $this->assertStringContainsString('wire:model="passwortWiederholung"', $html);
        $this->assertStringContainsString('wire:submit="registriere"', $html);

        // Die Nummer wird NICHT abgefragt — sie steht durch den Token fest.
        $this->assertStringNotContainsStringIgnoringCase('handynummer', $html);

        // Und der Token steht nirgends auf der Seite: ein Geheimnis gehoert
        // nicht ins Markup, wo es der naechste Screenshot mitnimmt.
        $this->assertStringNotContainsString((string) $this->gemerkterToken(), $html);
    }

    /**
     * F3 der Pruefung: das Passwort darf NICHT im gerenderten Markup stehen.
     * Ein `value="{{ $passwort }}"` am Feld ist die Bequemlichkeit, die
     * jemand einbaut, damit nach einem Fehlversuch nicht neu getippt werden
     * muss — und schreibt das Passwort damit in jeden Screenshot und in
     * jeden Seitenquelltext.
     */
    public function test_das_passwort_steht_nicht_im_markup(): void
    {
        $seite = $this->seite($this->einladung());
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;

        $this->assertStringNotContainsString(self::PASSWORT, $this->rendere($seite));
    }

    public function test_das_geburtsdatum_erzwingt_keine_zahlentastatur(): void
    {
        // Login-Blocker vom 06.08.2026: auf der iOS-Zahlentastatur liessen
        // sich Buchstaben nicht eingeben. Gilt fuer das ganze Blade, auch
        // fuer Kommentare — deshalb nennt es das Attribut nicht beim Namen.
        $this->assertStringNotContainsString('inputmode', $this->blade());
    }

    public function test_die_fertige_seite_zeigt_kein_formular_mehr(): void
    {
        $seite = $this->seite($this->einladung());
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->registriere();

        $html = $this->rendere($seite);

        $this->assertStringNotContainsString('wire:model="passwort"', $html);
        $this->assertStringContainsString('Konto', $html);
    }

    public function test_die_meldung_erscheint_im_formular(): void
    {
        $seite = $this->seite($this->einladung());
        $seite->geburtsdatum = self::FALSCHE_GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;
        $seite->registriere();

        $this->assertStringContainsString($seite->fehler, $this->rendere($seite));
    }

    public function test_blade_kompiliert(): void
    {
        $datei = dirname(__DIR__, 2) . '/resources/views/livewire/public/konto-anlegen.blade.php';
        $werkzeug = dirname(__DIR__, 2) . '/tools/blade-check.php';

        exec('php ' . escapeshellarg($werkzeug) . ' ' . escapeshellarg($datei), $ausgabe, $code);

        $this->assertSame(0, $code, implode("\n", $ausgabe));
    }

    // -------------------------------------------- Die zweite Tuer: der Code

    /**
     * Ruling GD-4, Canvas 68 Eintrag 1740: die Einladung geht "als Link UND
     * als kurzen lesbaren Code ... am Rechner kann man den Code auch
     * eintippen." Ohne Token zeigt dieselbe Komponente genau ein Feld.
     */
    public function test_ohne_token_zeigt_die_seite_das_codefeld(): void
    {
        $seite = new KontoAnlegen();
        $seite->mount('');

        $this->assertSame('code', $seite->state);
        $this->assertNull($this->gemerktePerson());
        $this->assertNull($this->gemerkterToken());
    }

    /**
     * KEIN ZWEITER PRUEFPFAD: die tokenlose Seite prueft nichts, sie leitet
     * auf die Token-Route weiter. Deshalb darf sie auch bei einem Code, den
     * es gar nicht gibt, nichts nachschlagen — sonst gaebe es die Regel
     * zweimal, und die beiden liefen auseinander.
     */
    public function test_die_tokenlose_seite_schlaegt_nichts_nach(): void
    {
        $this->einladung();
        $this->router();

        $seite = new KontoAnlegen();
        $seite->mount('');
        $seite->code = 'ZZZZZZZZ';
        $seite->oeffneCode();

        $this->assertSame('code', $seite->state);
        $this->assertSame('', $seite->fehler, 'Die tokenlose Seite hat den Code selbst beurteilt.');
        $this->assertNull($this->gemerktePerson());
    }

    public function test_der_getippte_code_landet_auf_der_token_route(): void
    {
        $token = $this->einladung();
        $this->router();

        $seite = new KontoAnlegen();
        $seite->mount('');
        // So tippt ein Mensch ab, was ihm am Telefon vorgelesen wurde.
        $seite->code = strtolower(substr($token, 0, 4)) . ' - ' . strtolower(substr($token, 4));

        $seite->oeffneCode();

        $this->assertSame(
            'http://localhost/konto/anlegen/' . $token,
            (string) \Livewire\store($seite)->get('redirect'),
            'Die Eingabe muss auf die Token-Route zeigen — mit DERSELBEN Normalisierung wie der Link.',
        );
    }

    /**
     * Dieselbe Antwort wie ein ungueltiger Token: 404. Kein "Code nicht
     * gefunden" — das waere die Auskunft, dass es ihn gibt. Geprueft wird der
     * ganze Weg, also die Weiterleitung UND was am Ziel passiert.
     */
    public function test_ein_falscher_code_endet_wie_ein_falscher_token(): void
    {
        $this->einladung();
        $this->router();

        $seite = new KontoAnlegen();
        $seite->mount('');
        $seite->code = 'zzzz-zzzz';
        $seite->oeffneCode();

        $ziel = (string) \Livewire\store($seite)->get('redirect');
        $this->assertSame('http://localhost/konto/anlegen/ZZZZZZZZ', $ziel);

        // Und am Ziel gibt es die Seite nicht.
        $this->expectException(NotFoundHttpException::class);
        $this->seite('ZZZZZZZZ');
    }

    public function test_ein_leerer_code_leitet_nicht_weiter(): void
    {
        $this->router();

        $seite = new KontoAnlegen();
        $seite->mount('');
        $seite->code = '  -  ';

        $seite->oeffneCode();

        $this->assertNull(\Livewire\store($seite)->get('redirect'));
        $this->assertNotSame('', $seite->fehler);
    }

    /**
     * Aus dem Code-Zustand fuehrt kein Weg ins Registrieren. Ohne diesen
     * Riegel liefe registriere() mit leerem Token und leerer Personen-Kennung
     * — und jeder Aufruf von $wire.call('registriere') waere ein Rateversuch
     * auf einer Seite, die gar keine Einladung kennt.
     */
    public function test_aus_dem_code_zustand_wird_nicht_registriert(): void
    {
        $seite = new KontoAnlegen();
        $seite->mount('');
        $seite->geburtsdatum = self::GEBURT;
        $seite->passwort = self::PASSWORT;
        $seite->passwortWiederholung = self::PASSWORT;

        $seite->registriere();

        $this->assertSame('code', $seite->state);
        $this->assertNull($this->zeile()->password_hash);
    }

    /**
     * Fund Q1: der Riegel in oeffneCode() war ungedeckt. Aus dem OFFENEN
     * Formular — also mit einer gueltigen Einladung in der Hand — darf ein
     * $wire.call('oeffneCode') nicht auf eine andere Einladung umleiten.
     * Ohne den Riegel waere die Seite ein bequemer Sprungbrett-Aufruf auf
     * jeden beliebigen Token, und der Zaehler der eigenen Einladung bliebe
     * dabei unberuehrt.
     */
    public function test_aus_dem_offenen_formular_leitet_der_code_nicht_um(): void
    {
        $this->router();
        $seite = $this->seite($this->einladung());
        $this->assertSame('formular', $seite->state, 'Vorflug: die Seite steht im Formular');

        $seite->code = 'ZZZZZZZZ';
        $seite->oeffneCode();

        $this->assertNull(
            \Livewire\store($seite)->get('redirect'),
            'Aus dem offenen Formular wurde auf einen fremden Token umgeleitet.',
        );
    }

    public function test_das_codefeld_steht_allein(): void
    {
        $seite = new KontoAnlegen();
        $seite->mount('');

        $html = $this->rendere($seite);

        $this->assertStringContainsString('wire:model="code"', $html);
        $this->assertStringContainsString('wire:submit="oeffneCode"', $html);

        // Geburtsdatum und Passwort haben hier nichts zu suchen — sie kommen
        // erst, wenn der Code zu einer Einladung gefuehrt hat.
        $this->assertStringNotContainsString('wire:model="geburtsdatum"', $html);
        $this->assertStringNotContainsString('wire:model="passwort"', $html);
    }

    // -------------------------------------------------------------- Die Route

    public function test_die_tokenlose_route_faehrt_dieselbe_komponente(): void
    {
        $treffer = $this->router()->getRoutes()->match(Request::create('/konto/anlegen', 'GET'));

        $this->assertSame('recruiting.public.konto-anlegen-code', $treffer->getName());
        $this->assertStringStartsWith(
            KontoAnlegen::class,
            (string) $treffer->getAction('uses'),
            'Die tokenlose Adresse muss DIESELBE Komponente fahren — eine zweite waere ein zweiter Pruefpfad.',
        );
    }

    /**
     * Dieselbe Bremse wie am Link, und zwar zwingend: ohne sie waere die
     * tokenlose Seite die bequemere Tuer zum Durchprobieren. Acht Zeichen aus
     * 31 sind rund 850 Milliarden Moeglichkeiten — aber nur mit Bremse.
     */
    public function test_die_tokenlose_route_ist_ebenso_gedrosselt(): void
    {
        $treffer = $this->router()->getRoutes()->match(Request::create('/konto/anlegen', 'GET'));

        $this->assertContains('throttle:20,1', $treffer->gatherMiddleware());
    }

    public function test_die_route_traegt_den_token_am_ende(): void
    {
        $route = $this->router()->getRoutes()->getByName('recruiting.public.konto-anlegen');

        $this->assertNotNull($route, 'Route recruiting.public.konto-anlegen ist nicht registriert.');
        $this->assertSame('konto/anlegen/{token}', $route->uri());
        $this->assertTrue(
            str_ends_with($route->uri(), '/{token}'),
            'Meta-URL-Knoepfe erlauben die Variable nur als Suffix — der Token muss das letzte Segment sein.',
        );
    }

    /**
     * Die erste der beiden Drosseln aus Ruling GD-4: sie trifft das
     * Durchprobieren von Token ueber die Route selbst. Zwanzig je Minute und
     * IP, nicht zehn — hinter einer Firmen-IP sitzen mehrere Mitarbeiter.
     */
    public function test_die_route_ist_gedrosselt(): void
    {
        $route = $this->router()->getRoutes()->getByName('recruiting.public.konto-anlegen');

        $this->assertContains('throttle:20,1', $route->gatherMiddleware());
    }

    public function test_die_adresse_trifft_die_seite(): void
    {
        $treffer = $this->router()->getRoutes()->match(Request::create('/konto/anlegen/ABCD2345', 'GET'));

        $this->assertSame('recruiting.public.konto-anlegen', $treffer->getName());
        $this->assertSame('ABCD2345', $treffer->parameter('token'));
    }
}
