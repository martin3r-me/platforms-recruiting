<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Die Waechter im Anmeldeweg des neuen Portals.
 *
 * Sie stehen hier, weil sie beim Herausloesen der Anmeldeschicht aus dem
 * alten EmployeePortal zunaechst verlorengegangen sind — ein Review hat es
 * gefunden. Ein Waechter ohne Test ist ein Waechter, der beim naechsten
 * Umbau wieder verschwindet.
 */
final class PortalShellVerifyTest extends TestCase
{
    private Capsule $capsule;
    private Repository $cache;
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

        // Die DB-Fassade wird im Erfolgsfall gebraucht (portal_verified_at).
        // Ohne clearResolvedInstances haelt die Fassade eine alte Wurzel fest.
        $container->instance('db', $this->capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->session = new Store('test', new ArraySessionHandler(60));
        $container->instance('session', $this->session);

        $this->cache = new Repository(new ArrayStore());

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('identity_card_number')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_verified_at')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function ma(array $attr = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'portal_token'         => 'tok-1',
            'first_name'           => 'Kevin',
            'last_name'            => 'Muster',
            'birth_date'           => '1995-03-14',
            'identity_card_number' => 'L01X00T47',   // letzte vier: 0T47
            'is_active'            => true,
            'portal_v2_since'      => '2026-09-24 08:00:00',
        ], $attr));
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->token = 'tok-1';
        $shell->employeeId = $ma->id;
        $shell->state = 'unverified';

        return $shell;
    }

    private function auth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    public function test_richtige_angaben_melden_an(): void
    {
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';

        $shell->verify($this->auth());

        $this->assertSame('verified', $shell->state);
        $this->assertSame('Kevin', $shell->displayName);
        $this->assertTrue($this->session->has(PortalAuth::sessionKey($ma->id)));
    }

    public function test_buchstaben_in_der_ausweisnummer_funktionieren(): void
    {
        // Der Grund, warum am Feld kein inputmode="numeric" stehen darf.
        $ma = $this->ma(['identity_card_number' => 'L01X00T47']);
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0t47';   // klein geschrieben — strcasecmp

        $shell->verify($this->auth());

        $this->assertSame('verified', $shell->state);
    }

    public function test_anmeldung_setzt_den_stempel(): void
    {
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';

        $shell->verify($this->auth());

        $this->assertNotNull(RecEmployee::find($ma->id)->portal_verified_at);
    }

    public function test_sperre_zwischen_aufruf_und_anmeldung_greift(): void
    {
        // Der Eskalations-Cron laeuft unabhaengig vom Request: zwischen dem
        // Aufbau der Seite und dem Absenden koennen Minuten liegen.
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';

        RecEmployee::where('id', $ma->id)->update(['portal_locked_at' => now()]);

        $shell->verify($this->auth());

        $this->assertSame('gesperrt', $shell->state);
        $this->assertFalse($this->session->has(PortalAuth::sessionKey($ma->id)));
    }

    public function test_zuruecknahme_des_piloten_greift_sofort(): void
    {
        // recruiting:portal-umstellen --zurueck ist die Notbremse. Sie muss
        // auch wirken, wenn jemand die Anmeldeseite schon offen hat.
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';

        RecEmployee::where('id', $ma->id)->update(['portal_v2_since' => null]);

        $shell->verify($this->auth());

        $this->assertSame('weg', $shell->state);
        $this->assertFalse($this->session->has(PortalAuth::sessionKey($ma->id)));
    }

    public function test_leere_eingabe_kostet_keinen_versuch(): void
    {
        // Ueber $wire.call('verify') sind Leeraufrufe trivial. Wuerden sie
        // zaehlen, koennte jeder mit dem Link fremde Tokens 15 Minuten sperren.
        $ma = $this->ma();

        for ($i = 0; $i < 6; $i++) {
            $shell = $this->shell($ma);
            $shell->birthDate = '';
            $shell->idLast4 = '';
            $shell->verify($this->auth());
            $this->assertSame('unverified', $shell->state);
        }

        $this->assertFalse($this->auth()->isRateLimited('tok-1'));

        // Und die richtigen Angaben kommen danach noch durch.
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';
        $shell->verify($this->auth());
        $this->assertSame('verified', $shell->state);
    }

    public function test_falsche_angaben_zaehlen_und_sperren_nach_fuenf(): void
    {
        $ma = $this->ma();

        for ($i = 0; $i < 4; $i++) {
            $shell = $this->shell($ma);
            $shell->birthDate = '1995-03-14';
            $shell->idLast4 = '9999';
            $shell->verify($this->auth());
            $this->assertSame('unverified', $shell->state);
            $this->assertNotSame('', $shell->fehler);
        }

        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '9999';
        $shell->verify($this->auth());

        $this->assertSame('rateLimited', $shell->state);
        $this->assertTrue($this->auth()->isRateLimited('tok-1'));
    }

    public function test_name_steht_vor_der_anmeldung_nicht_im_zustand(): void
    {
        // Alles, was in oeffentlichen Eigenschaften steht, faehrt im
        // wire:snapshot jeder Antwort mit — auch auf der Anmeldeseite.
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '9999';

        $shell->verify($this->auth());

        $this->assertSame('', $shell->displayName);
        $this->assertSame('', $shell->initialen);
    }

    public function test_abmelden_raeumt_die_sitzung_und_den_namen(): void
    {
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';
        $shell->verify($this->auth());

        $shell->logout();

        $this->assertSame('unverified', $shell->state);
        $this->assertSame('', $shell->displayName);
        $this->assertFalse($this->session->has(PortalAuth::sessionKey($ma->id)));
    }

    public function test_zweiter_anlauf_nach_erfolg_tut_nichts(): void
    {
        $ma = $this->ma();
        $shell = $this->shell($ma);
        $shell->birthDate = '1995-03-14';
        $shell->idLast4 = '0T47';
        $shell->verify($this->auth());

        $shell->idLast4 = '9999';
        $shell->verify($this->auth());

        $this->assertSame('verified', $shell->state);
    }
}
