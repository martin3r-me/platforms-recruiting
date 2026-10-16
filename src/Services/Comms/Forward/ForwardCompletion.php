<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Recruiting\Models\RecConversationForward;

/**
 * "Erledigt" im HR-Chat erledigt auch die offenen Weiterleitungen dieses
 * Chats (Spec Runde 2, 02.10.2026) — sonst haekt HR an zwei Stellen ab.
 * Bereits erledigte bleiben unberuehrt (done_at wird nie ueberschrieben).
 *
 * Seit 16.10.2026 ist Oeffnen = erledigt: completeForThreadIds() beim
 * Oeffnen, reopen() bei „ungelesen schliessen“ — nur fuer genau die IDs,
 * die das Oeffnen erledigt hat.
 */
final class ForwardCompletion
{
    /** @param array<int, int|string> $threadIds */
    public function completeForThreads(int $teamId, array $threadIds, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $threadIds),
            static fn (int $id) => $id > 0,
        )));
        if ($ids === []) {
            return 0;
        }

        return RecConversationForward::query()
            ->openForTeam($teamId, ForwardTargets::HR)
            ->whereIn('target_thread_id', $ids)
            ->update(['done_at' => now(), 'done_by_user_id' => $userId, 'updated_at' => now()]);
    }

    /**
     * Wie completeForThreads(), fuer EINEN Chat, und liefert die IDs der
     * jetzt erledigten — damit „ungelesen schliessen“ genau diese
     * zurueckholen kann und keine, die schon vorher erledigt war.
     *
     * @return list<int>
     */
    public function completeForThreadIds(int $teamId, int $threadId, ?int $userId): array
    {
        if ($threadId <= 0) {
            return [];
        }

        $ids = RecConversationForward::query()
            ->openForTeam($teamId, ForwardTargets::HR)
            ->where('target_thread_id', $threadId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids !== []) {
            RecConversationForward::query()
                ->whereIn('id', $ids)
                ->whereNull('done_at')
                ->update(['done_at' => now(), 'done_by_user_id' => $userId, 'updated_at' => now()]);
        }

        return $ids;
    }

    /**
     * Holt beim Oeffnen erledigte Weiterleitungen zurueck. Die ID-Liste kommt
     * aus der Livewire-Komponente; deshalb zusaetzlich scharf auf Team, Chat,
     * Ziel HR und den erledigenden Nutzer — eine praeparierte ID trifft so
     * nichts Fremdes.
     *
     * @param array<int, int|string> $forwardIds
     */
    public function reopen(int $teamId, int $threadId, array $forwardIds, ?int $userId): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $forwardIds),
            static fn (int $id) => $id > 0,
        )));
        if ($ids === [] || $threadId <= 0) {
            return 0;
        }

        return RecConversationForward::query()
            ->where('team_id', $teamId)
            ->where('target', ForwardTargets::HR)
            ->where('target_thread_id', $threadId)
            ->where('done_by_user_id', $userId)
            ->whereNotNull('done_at')
            ->whereIn('id', $ids)
            ->update(['done_at' => null, 'done_by_user_id' => null, 'updated_at' => now()]);
    }
}
