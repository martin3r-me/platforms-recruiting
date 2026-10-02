<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Recruiting\Models\RecConversationForward;

/**
 * "Erledigt" im HR-Chat erledigt auch die offenen Weiterleitungen dieses
 * Chats (Spec Runde 2, 02.10.2026) — sonst haekt HR an zwei Stellen ab.
 * Bereits erledigte bleiben unberuehrt (done_at wird nie ueberschrieben).
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
}
