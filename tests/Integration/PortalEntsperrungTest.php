<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\PortalEntsperrung;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

final class PortalEntsperrungTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    public function test_entsperrt_die_ganze_dispo_gruppe_per_query_builder(): void
    {
        $a = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: nicht bestaetigt']);
        $b = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00', 'portal_locked_reason' => 'Dispo: nicht bestaetigt']);
        $fremd = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $vorher = DB::table('rec_employees')->pluck('updated_at', 'id')->all();
        $gruppe = new class([$a->id, $b->id]) extends DispoIdentityResolver {
            public function __construct(private array $ids) {}
            public function groupFor(int $employeeId): array { return $this->ids; }
        };

        $anzahl = (new PortalEntsperrung($gruppe))->fuerAnstellung($a->id);

        $this->assertSame(2, $anzahl);
        $this->assertSame(0, DB::table('rec_employees')->whereIn('id', [$a->id, $b->id])->whereNotNull('portal_locked_at')->count());
        $this->assertSame(0, DB::table('rec_employees')->whereIn('id', [$a->id, $b->id])->whereNotNull('portal_locked_reason')->count());
        $this->assertNotNull(DB::table('rec_employees')->where('id', $fremd->id)->value('portal_locked_at'), 'ausserhalb der Gruppe bleibt gesperrt');
        $this->assertSame($vorher, DB::table('rec_employees')->pluck('updated_at', 'id')->all(), 'kein updated_at, kein Eloquent');
        $this->assertSame(0, DB::table('rec_employees')->whereNotNull('zas_changed_at')->count(), 'kein ZAS-Marker');
        $this->assertSame(0, (new PortalEntsperrung($gruppe))->fuerAnstellung($a->id), 'zweiter Klick: keine Wirkung');
    }

    public function test_ohne_team_anker_entsperrt_der_echte_resolver_nur_die_eigene_akte(): void
    {
        $a = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);

        $this->assertSame(1, (new PortalEntsperrung())->fuerAnstellung($a->id));
    }
}
