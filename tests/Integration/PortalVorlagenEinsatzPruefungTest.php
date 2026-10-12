<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalVorlagen;

/** einsatzPruefungAktiv liest ueber getOrCreateForTeam — braucht eine echte Zeile auf SQLite. */
class PortalVorlagenEinsatzPruefungTest extends TestCase
{
    private const TEAM = 4712;

    public static function setUpBeforeClass(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Capsule::schema()->create('rec_applicant_settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_applicant_settings')->delete();
    }

    private function setze(mixed $wert): void
    {
        Capsule::table('rec_applicant_settings')->delete();
        Capsule::table('rec_applicant_settings')->insert([
            'team_id' => self::TEAM,
            'settings' => json_encode([PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV => $wert]),
        ]);
    }

    public function test_vorgabe_ist_aus(): void
    {
        $this->assertFalse(PortalVorlagen::einsatzPruefungAktiv(self::TEAM));
    }

    public function test_strikte_bool_auslegung(): void
    {
        foreach ([true, 1, '1', 'true'] as $an) {
            $this->setze($an);
            $this->assertTrue(PortalVorlagen::einsatzPruefungAktiv(self::TEAM), var_export($an, true));
        }
        foreach ([false, 0, '0', 'nein', 'false', null, ''] as $aus) {
            $this->setze($aus);
            $this->assertFalse(PortalVorlagen::einsatzPruefungAktiv(self::TEAM), var_export($aus, true));
        }
    }
}
