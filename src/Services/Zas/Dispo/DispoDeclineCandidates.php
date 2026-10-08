<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Carbon;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoFilialeSettings;

/**
 * Wer kommt fuer eine Absage-Pruefung ueberhaupt in Frage (Spec 2026-10-08,
 * Entscheidung 5)? Liefert die Person hinter dem Gespraech und ihre
 * Kandidaten-Einbuchungen je Veranstaltung — oder null, dann wird gar nicht
 * geprueft.
 *
 * Kandidat ist eine Einbuchung in einer Veranstaltung einer EINGESCHALTETEN
 * Filiale, die kommend ist, im Rennen (nicht abgesagt, nicht verschwunden,
 * nicht zur Loeschung gemeldet) und VOR der Nachricht angeschrieben wurde.
 * Bestaetigte zaehlen ausdruecklich mit ("erst zugesagt, dann abgesagt").
 *
 * Die Person wird nicht vorwaerts (Thread -> MA) geraten, sondern rueckwaerts
 * ueber DispoThreadDirectory aufgeloest: nur wer Kandidat IST und dessen
 * Gespraech dieses ist, zaehlt. Damit gilt dieselbe Mehrdeutigkeits-Regel
 * wie im VA-Chat (geteilte Nummer -> kein Treffer).
 */
class DispoDeclineCandidates
{
    public function __construct(
        private DispoIdentityResolver $identity,
        private DispoThreadDirectory $threads,
    ) {}

    /**
     * @param list<int> $channelIds Dispo-Kanal-Set
     * @return array{employee_id:int, by_event: array<int, list<RecDispoAssignment>>}|null
     */
    public function forMessage(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message, array $channelIds): ?array
    {
        // Abkuerzung, kein Schutz: allThreadsFor() findet ohnehin nur Gespraeche
        // im Kanal-Set. Spart bei Bewerber-Nachrichten die Kandidaten-Query.
        if (!in_array((int) $thread->comms_channel_id, array_map('intval', $channelIds), true)) {
            return null;
        }

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: 0);
        if ($teamId <= 0) {
            return null;
        }

        $at = Carbon::parse($message->created_at ?? now());

        // Untergrenze je Filiale: nur Nachrichten NACH dem Einschalten (Entscheidung 4).
        $filialen = RecDispoFilialeSettings::query()
            ->where('team_id', $teamId)
            ->whereNotNull('decline_check_enabled_at')
            ->where('decline_check_enabled_at', '<=', $at)
            ->pluck('filial_nr')
            ->map(fn ($v) => (int) $v)
            ->all();
        if ($filialen === []) {
            return null;
        }

        $rows = RecDispoAssignment::query()
            ->whereIn('rec_dispo_event_id', fn ($q) => $q->select('id')->from('rec_dispo_events')->whereIn('filial_nr', $filialen))
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNotNull('rec_employee_id')
            ->whereDate('datum', '>=', $at->toDateString())
            ->whereNull('declined_at')
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            ->whereNotNull('reminder_sent_at')
            ->where('reminder_sent_at', '<=', $at)
            ->orderBy('datum')->orderBy('von')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $ids = $rows->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $canon = DispoIdentityGroups::canonicalMap($this->identity->groupsFor($ids));

        $person = null;
        foreach ($this->threads->allThreadsFor($channelIds, $ids) as $canonId => $list) {
            foreach ($list as $t) {
                if ((int) $t['thread_id'] === (int) $thread->id) {
                    $person = (int) $canonId;
                    break 2;
                }
            }
        }
        if ($person === null) {
            return null;
        }

        $byEvent = [];
        foreach ($rows as $row) {
            $id = (int) $row->rec_employee_id;
            if (($canon[$id] ?? $id) === $person) {
                $byEvent[(int) $row->rec_dispo_event_id][] = $row;
            }
        }

        return $byEvent === [] ? null : ['employee_id' => $person, 'by_event' => $byEvent];
    }
}
