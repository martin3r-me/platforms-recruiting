<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;

/**
 * Die HR-Entsperrung der Dispo-Sperre (Eskalationsstufe 3), umgezogen aus
 * Employees\Show::unlockPortal() (Spec HR-Bereich §5.3).
 *
 * GENAU DIE MENGE, DIE PortalAuth FRAGT (Ruling GD-9): die
 * Dispo-Identitaetsgruppe aus DispoIdentityResolver::groupFor(). Query
 * Builder: kein updated_at, kein Beobachter, kein ZAS-Marker — eine
 * Entsperrung ist keine fachliche Aenderung.
 */
final class PortalEntsperrung
{
    public function __construct(private readonly ?DispoIdentityResolver $resolver = null) {}

    /**
     * $nurTeamId: die Dispo-Gruppe haengt am Inbound-Team und kann ueber das
     * Team des Handelnden hinausreichen — HR entsperrt dann nur Akten des
     * eigenen Teams (HrKontoAktionen, Fix-Runde 1 Task 5).
     *
     * @return int Anzahl entsperrter Akten (0 = war nicht gesperrt)
     */
    public function fuerAnstellung(int $employeeId, ?int $nurTeamId = null): int
    {
        $ids = ($this->resolver ?? app(DispoIdentityResolver::class))->groupFor($employeeId);

        return DB::table('rec_employees')
            ->whereIn('id', $ids)
            ->when($nurTeamId !== null, fn ($q) => $q->where('team_id', $nurTeamId))
            ->whereNotNull('portal_locked_at')
            ->update(['portal_locked_at' => null, 'portal_locked_reason' => null]);
    }
}
