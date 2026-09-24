<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Die Anmeldeschicht ist Sicherheitscode. Sie wurde aus EmployeePortal
 * herausgeloest, damit das Konto aus Canvas 68 spaeter eingehaengt werden kann
 * — beim Herausloesen darf sich das Verhalten NICHT aendern.
 *
 * Besonders die Cache-Schluessel: Solange altes und neues Portal nebeneinander
 * laufen, muss eine Sperre in beiden gelten.
 */
final class PortalAuthTest extends TestCase
{
    private Capsule $capsule;
    private Repository $cache;

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
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function ma(array $attr = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'portal_token'         => 'tok-1',
            'first_name'           => 'Test',
            'last_name'            => 'Person',
            'birth_date'           => '1995-03-14',
            'identity_card_number' => 'L01X00T47',
            'is_active'            => true,
        ], $attr));
    }

    private function auth(): PortalAuth
    {
        return new PortalAuth($this->cache);
    }

    public function test_findet_den_mitarbeiter_zum_token(): void
    {
        $ma = $this->ma();

        $this->assertSame($ma->id, $this->auth()->employeeForToken('tok-1')?->id);
        $this->assertNull($this->auth()->employeeForToken('falsch'));
    }

    public function test_inaktive_kommen_nicht_hinein(): void
    {
        $this->ma(['is_active' => false]);

        $this->assertNull($this->auth()->employeeForToken('tok-1'));
    }

    public function test_erkennt_die_dispo_sperre(): void
    {
        $offen = $this->ma();
        $gesperrt = $this->ma(['portal_token' => 'tok-2', 'portal_locked_at' => now()]);

        $this->assertFalse($this->auth()->isLocked($offen));
        $this->assertTrue($this->auth()->isLocked($gesperrt));
    }

    public function test_richtige_daten_lassen_durch(): void
    {
        $ma = $this->ma();

        $ergebnis = $this->auth()->attempt($ma, 'tok-1', '1995-03-14', '0T47');

        $this->assertSame(PortalAuth::OK, $ergebnis['status']);
    }

    public function test_endziffern_ohne_ruecksicht_auf_gross_und_kleinschreibung(): void
    {
        $ma = $this->ma();

        $this->assertSame(PortalAuth::OK, $this->auth()->attempt($ma, 'tok-1', '1995-03-14', '0t47')['status']);
    }

    public function test_falsche_daten_zaehlen_herunter(): void
    {
        $ma = $this->ma();
        $auth = $this->auth();

        $this->assertSame(4, $auth->attempt($ma, 'tok-1', '1995-03-14', '9999')['verbleibend']);
        $this->assertSame(3, $auth->attempt($ma, 'tok-1', '1995-03-14', '9999')['verbleibend']);
    }

    public function test_nach_fuenf_versuchen_gesperrt(): void
    {
        $ma = $this->ma();
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->attempt($ma, 'tok-1', '1995-03-14', '9999');
        }

        $this->assertTrue($auth->isRateLimited('tok-1'));
        $this->assertSame(PortalAuth::GESPERRT, $auth->attempt($ma, 'tok-1', '1995-03-14', '0T47')['status'],
            'auch mit RICHTIGEN Daten bleibt gesperrt');
    }

    public function test_die_sperre_haengt_am_token_nicht_am_mitarbeiter(): void
    {
        $ma = $this->ma();
        $zweiter = $this->ma(['portal_token' => 'tok-2']);
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->attempt($ma, 'tok-1', '1995-03-14', '9999');
        }

        $this->assertTrue($auth->isRateLimited('tok-1'));
        $this->assertFalse($auth->isRateLimited('tok-2'));
    }

    public function test_erfolg_raeumt_den_zaehler_ab(): void
    {
        $ma = $this->ma();
        $auth = $this->auth();

        $auth->attempt($ma, 'tok-1', '1995-03-14', '9999');
        $auth->attempt($ma, 'tok-1', '1995-03-14', '0T47');

        $this->assertSame(4, $auth->attempt($ma, 'tok-1', '1995-03-14', '9999')['verbleibend'],
            'nach erfolgreichem Login beginnt die Zaehlung von vorn');
    }

    /**
     * Solange altes und neues Portal nebeneinander laufen, muessen sie
     * dieselben Schluessel benutzen — sonst waere jemand im alten gesperrt
     * und im neuen offen.
     */
    public function test_nutzt_dieselben_cache_schluessel_wie_das_alte_portal(): void
    {
        $ma = $this->ma();
        $auth = $this->auth();

        for ($i = 0; $i < 5; $i++) {
            $auth->attempt($ma, 'tok-1', '1995-03-14', '9999');
        }

        $this->assertTrue((bool) $this->cache->get('employee_portal_locked:tok-1'));
        $this->assertSame(5, (int) $this->cache->get('employee_portal_attempts:tok-1'));
        $this->assertSame('employee_portal_verified:1', PortalAuth::sessionKey(1));
    }
}
