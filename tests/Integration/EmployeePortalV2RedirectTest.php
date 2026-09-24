<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Livewire\Mechanisms\DataStore;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\EmployeePortal;
use Platform\Recruiting\Models\RecEmployee;

use function Livewire\store;

/**
 * M2 (Schlusspruefung): Beide Portale standen gleichzeitig offen.
 *
 * `portal_v2_since` entschied nur, wer die NEUE Adresse sehen darf — die alte
 * /mitarbeiter/{token} blieb fuer alle erreichbar, und genau dorthin zeigen
 * saemtliche bereits verschickten WhatsApp-Links. Weil sich beide Portale den
 * Sitzungsschluessel teilen (PortalAuth::sessionKey), war ein umgestellter
 * Mensch dort sogar schon angemeldet.
 *
 * Die Folge ist kein Schoenheitsfehler: das alte Portal schreibt ueber
 * Eloquent (setzt also den ZAS-Export-Marker) und legt KEINE Nachweis-Zeile
 * an, das neue schreibt ueber den Query Builder und fuehrt die neue Tabelle.
 * Ein Pilot-Teilnehmer konnte also am alten Portal etwas eintragen, das im
 * neuen nie ankommt.
 *
 * Geprueft wird der Seam, den Livewire selbst benutzt: `$this->redirect()`
 * legt das Ziel im DataStore ab (SupportRedirects holt es dort beim
 * Dehydrieren wieder heraus und macht daraus bei einem normalen Seitenaufruf
 * ein `abort(redirect(...))`).
 */
final class EmployeePortalV2RedirectTest extends TestCase
{
    private Capsule $capsule;
    private Store $session;

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

        // route() im Kommando-losen Test: Router laedt routes/public.php,
        // der UrlGenerator baut daraus die Adresse. Ohne den "recruiting"-
        // Praefix, der in der Produktion aus der Route::prefix()-Gruppe im
        // ServiceProvider kommt — deshalb prueft der Test nur das Ende der
        // Adresse. Muster: PortalTokenRouteTest.
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        require __DIR__ . '/../../routes/public.php';
        $router->getRoutes()->refreshNameLookups();
        $container->instance('url', new UrlGenerator($router->getRoutes(), Request::create('http://portal.test/')));

        // Livewires $this->redirect() legt das Ziel in den DataStore. Der
        // MUSS dieselbe Instanz bleiben, sonst liest der Test ins Leere.
        // Die Konfiguration bleibt die, die Capsule selbst angelegt hat —
        // wird sie ersetzt, findet die Kapsel ihre Verbindung nicht mehr.
        $container->instance(DataStore::class, new DataStore());

        $this->session = new Store('test', new ArraySessionHandler(60));
        $container->instance('session', $this->session);
        $container->instance('cache', new CacheRepository(new ArrayStore()));

        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('identity_card_number')->nullable();
            $t->string('employment_type')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->string('portal_locked_reason')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_verified_at')->nullable();
            $t->timestamps();
        });

        // usesInformalAddress() im alten mount() liest die Team-Einstellungen.
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Container::setInstance(null);
        parent::tearDown();
    }

    private function ma(array $attr = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'              => 7,
            'portal_token'         => 'tok-' . bin2hex(random_bytes(4)),
            'first_name'           => 'Kevin',
            'last_name'            => 'Muster',
            'birth_date'           => '1995-03-14',
            'identity_card_number' => 'L01X00T47',
            'is_active'            => true,
        ], $attr));
    }

    private function mount(RecEmployee $ma): EmployeePortal
    {
        $portal = new EmployeePortal();
        $portal->mount((string) $ma->portal_token);

        return $portal;
    }

    private function ziel(EmployeePortal $portal): ?string
    {
        return store($portal)->get('redirect');
    }

    public function test_umgestellter_mitarbeiter_wird_ans_neue_portal_umgeleitet(): void
    {
        $ma = $this->ma(['portal_v2_since' => '2026-09-24 08:00:00']);

        $portal = $this->mount($ma);

        $ziel = $this->ziel($portal);
        $this->assertNotNull($ziel, 'Wer umgestellt ist, darf das alte Portal nicht mehr sehen.');
        $this->assertStringEndsWith('/mitarbeiter/neu/' . $ma->portal_token, $ziel,
            'Umgeleitet wird auf die neue Route — mit demselben Token.');
    }

    public function test_nicht_umgestellter_mitarbeiter_bekommt_das_alte_portal_wie_gehabt(): void
    {
        $ma = $this->ma(['portal_v2_since' => null]);

        $portal = $this->mount($ma);

        $this->assertNull($this->ziel($portal), 'Ohne Umstellung wird nicht umgeleitet.');
        $this->assertSame('unverified', $portal->state);
        $this->assertSame($ma->id, $portal->employeeId);
        $this->assertSame('Kevin Muster', $portal->displayName);
    }

    /**
     * Der Auth-Fix vom September bleibt unberuehrt: die Sperre wirkt weiter,
     * und zwar OHNE dass die Weiche vorher jemanden wegschickt.
     */
    public function test_gesperrter_nicht_umgestellter_mitarbeiter_sieht_weiter_den_sperrschirm(): void
    {
        $ma = $this->ma(['portal_v2_since' => null, 'portal_locked_at' => '2026-09-01 10:00:00']);

        $portal = $this->mount($ma);

        $this->assertNull($this->ziel($portal));
        $this->assertTrue($portal->portalLocked);
    }

    public function test_unbekannter_token_bleibt_unbekannt(): void
    {
        $portal = new EmployeePortal();
        $portal->mount('gibt-es-nicht');

        $this->assertNull($this->ziel($portal));
        $this->assertSame('tokenInvalid', $portal->state);
    }

    /**
     * Ein stillgelegter Mitarbeiter wird NICHT umgeleitet: das neue Portal
     * wuerde ihn ohnehin abweisen, und die Auskunft "diesen Token gibt es
     * nicht" soll dieselbe bleiben wie bisher.
     */
    public function test_stillgelegter_mitarbeiter_wird_nicht_umgeleitet(): void
    {
        $ma = $this->ma(['portal_v2_since' => '2026-09-24 08:00:00', 'is_active' => false]);

        $portal = $this->mount($ma);

        $this->assertNull($this->ziel($portal));
        $this->assertSame('tokenInvalid', $portal->state);
    }
}
