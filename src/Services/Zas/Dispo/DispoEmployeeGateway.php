<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecEmployee;

/**
 * ZWEITE Tuer vom Dispo-Code zum Recruiting-Personal (neben
 * DispoEmployeeDirectory, Entkopplungs-Leitplanke 3): liefert Kontaktdaten
 * fuer den Bestaetigungs-Versand. Beim Staffing-Auszug wird diese Klasse
 * gegen den dortigen Personen-Adapter getauscht.
 */
class DispoEmployeeGateway
{
    /** @return array<int, array{name: string, first_name: string, phone: ?string, portal_token: string, personnel_number: string, company: string}> */
    public function contacts(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return RecEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(['id', 'first_name', 'last_name', 'phone', 'portal_token', 'personnel_number', 'company'])
            ->mapWithKeys(fn ($e) => [(int) $e->id => [
                'name'              => trim($e->first_name . ' ' . $e->last_name),
                'first_name'        => trim((string) $e->first_name),
                'phone'             => ($e->phone !== null && trim($e->phone) !== '') ? trim($e->phone) : null,
                'portal_token'      => (string) $e->portal_token,
                'personnel_number'  => (string) ($e->personnel_number ?? ''),
                'company'           => (string) ($e->company ?? ''),
            ]])
            ->all();
    }

    /** @return array<int, ?string> employee_id => phone */
    public function phones(array $employeeIds): array
    {
        return array_map(fn ($c) => $c['phone'], $this->contacts($employeeIds));
    }

    /**
     * Sperrt das MA-Portal (Eskalations-Stufe 3: 16-Uhr-Rausnahme). Idempotent —
     * ein bereits gesperrter MA wird NICHT ueberschrieben (Grund/Zeitpunkt der
     * ERSTEN Sperre bleiben erhalten). Kein Employee zu einer ID -> no-op fuer
     * diese ID. Nimmt eine einzelne id ODER mehrere (Dispo-Identitaetsgruppe,
     * damit z. B. RG- und MA-Datensatz derselben Person gemeinsam gesperrt werden).
     *
     * @param int|list<int> $employeeIds
     */
    public function lockPortal(int|array $employeeIds, string $reason): void
    {
        foreach ((array) $employeeIds as $employeeId) {
            $employee = RecEmployee::find($employeeId);
            if ($employee === null || $employee->portal_locked_at !== null) {
                continue;
            }

            $employee->portal_locked_at = now();
            $employee->portal_locked_reason = $reason;
            $employee->save();
        }
    }

    /** @return array<int, ?string> employee_id => Roh-Telefonnummer (nur aktive MA mit Nummer) */
    public function phoneDirectory(): array
    {
        // Team-Anker wie Resolver/Settings — Cross-Tenant-Nummern duerfen weder matchen noch Ambiguitaet ausloesen.
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()?->currentTeam?->id);

        return RecEmployee::query()
            ->when($teamId > 0, fn ($q) => $q->where('team_id', $teamId))
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone', 'id')
            ->map(fn ($p) => (string) $p)
            ->all();
    }
}
