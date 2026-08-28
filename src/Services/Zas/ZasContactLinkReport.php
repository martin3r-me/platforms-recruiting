<?php

namespace Platform\Recruiting\Services\Zas;

use Platform\Recruiting\Models\RecEmployee;

/**
 * Offene CRM-Zuordnungen fuer die Anzeige (Disposition -> Einstellungen,
 * Runde 4 #0): aktive MA ohne crm_contact_links samt Grund aus dem Linker.
 * Liest nur — schreibt nie (decide() ist der Dry-Run des Backfills).
 * Skips sind KEIN Fehler (Namens-Guard/Mehrdeutigkeit sind gewollt), deshalb
 * gehoeren sie ins UI und nicht ins Log.
 */
class ZasContactLinkReport
{
    public function __construct(private ZasEmployeeContactLinker $linker) {}

    /**
     * @return array{total:int, rows:list<array{employee_id:int, name:string, personnel_number:string, state:string, reason:string}>}
     *         state: 'skip' = manuell zuordnen | 'pending' = naechster Backfill-Lauf erledigt es
     */
    public function openCases(int $teamId, int $limit = 100): array
    {
        $query = RecEmployee::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereDoesntHave('crmContactLinks')
            ->orderBy('last_name')->orderBy('first_name');

        $total = (clone $query)->count();

        $rows = [];
        foreach ($query->limit($limit)->get() as $e) {
            $d = $this->linker->decide($e);
            $rows[] = [
                'employee_id'      => (int) $e->id,
                'name'             => trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')),
                'personnel_number' => (string) ($e->personnel_number ?? ''),
                'state'            => $d['action'] === 'skip' ? 'skip' : 'pending',
                'reason'           => match ($d['action']) {
                    'skip'   => (string) $d['reason'],
                    'link'   => "wird beim nächsten Lauf mit Kontakt #{$d['contact_id']} ({$d['contact_name']}) verknüpft",
                    default  => 'wird beim nächsten Lauf als neuer CRM-Kontakt angelegt',
                },
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }
}
