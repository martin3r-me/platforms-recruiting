<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Services\Zeit\RheingedeckZeitRegeln;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitRegeln;

/**
 * Einbindung von „Meine Zeiten“ in PortalShell::ansichtsDaten(): die Wache
 * liegt in berechtigterMitarbeiter() (Sitzung, Zustand), Welt aus den Migrationen.
 */
final class PortalShellMeineZeitenTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $c = Container::getInstance();
        $c->instance('session', new Store('test', new ArraySessionHandler(60)));
        Facade::clearResolvedInstance('session');
        $c->instance(ZeitSchalter::class, new class extends ZeitSchalter {
            public function aktiv(): bool
            {
                return true;
            }

            public function teamId(): int
            {
                return 1101;
            }
        });
        $c->instance(ZeitRegeln::class, new RheingedeckZeitRegeln());
        Carbon::setTestNow(Carbon::parse('2026-10-01 23:00', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        TestPortalSitzung::aufraeumen();
        $c = Container::getInstance();
        foreach ([ZeitSchalter::class, ZeitRegeln::class] as $n) {
            $c->forgetInstance($n);
            unset($c[$n]);
        }
        $c->forgetInstance('session');
        unset($c['session']);
        Facade::clearResolvedInstance('session');
        Carbon::setTestNow();
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function shell(int $id, string $state = 'verified'): PortalShell
    {
        $s = new PortalShell();
        $s->employeeId = $id;
        $s->state = $state;
        $s->duzen = true;

        return $s;
    }

    /** @return array<string,mixed> */
    private function ansicht(PortalShell $s): array
    {
        $m = new \ReflectionMethod($s, 'ansichtsDaten');
        $m->setAccessible(true);

        return $m->invoke($s);
    }

    private function zeit(int $person): void
    {
        ZeitEintrag::create([
            'team_id' => 3, 'person_typ' => 'rec_employee', 'person_id' => $person,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => 77, 'tag' => '2026-10-01',
            'kommen_at' => '2026-10-01 11:50:00', 'gehen_at' => '2026-10-01 20:00:00',
        ]);
    }

    public function test_eigene_zeiten_nur_bei_gueltiger_sitzung(): void
    {
        $ma = $this->anstellung();
        $this->zeit((int) $ma->id);

        // gueltige Sitzung: die eigenen Zeiten
        TestPortalSitzung::oeffne((int) $ma->id);
        $daten = $this->ansicht($this->shell((int) $ma->id));
        $this->assertCount(1, $daten['meineZeiten']);
        $this->assertSame('13:50', $daten['meineZeiten'][0]['kommen']);
        $this->assertSame('ausgecheckt', $daten['meineZeiten'][0]['zustand']);

        // Zustand nicht verified
        $this->assertSame([], $this->ansicht($this->shell((int) $ma->id, 'unverified'))['meineZeiten']);

        // Sitzung geschlossen: state verified, aber PortalAuth::sitzungGueltig() verneint
        TestPortalSitzung::aufraeumen();
        $zu = $this->shell((int) $ma->id);
        $this->assertSame([], $this->ansicht($zu)['meineZeiten']);
        $this->assertSame('unverified', $zu->state);
    }
}
