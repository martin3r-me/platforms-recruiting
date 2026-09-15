<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationHandled;

/**
 * Buendeltes Abhaken vieler Chats auf einmal (Sammel-Erledigen) — genau der
 * Fall, fuer den der Erledigt-Haken gebaut wurde: 411 Altchats in einem
 * Rutsch. Ein Aufruf je Thread (Inbox::markHandled() im Einzelfall) waere
 * hier O(n) Query-Runden; dieser Handler prueft die Team-Zugehoerigkeit
 * EINMAL per whereIn und schreibt die Stempel EINMAL per upsert().
 *
 * Die Team-Pruefung faellt dabei NICHT weg — sie ist der Grund, warum keine
 * fremde Thread-ID abgehakt werden kann. Sie wandert nur aus der Schleife
 * heraus: nur eine ID, die tatsaechlich zu einem Thread DIESES Teams
 * gehoert, bekommt einen Stempel.
 */
final class ConversationBulkHandler
{
    /**
     * @param  list<int> $threadIds
     * @return list<int> die tatsaechlich gestempelten IDs (Teilmenge von
     *                   $threadIds, nach Team gefiltert) — der Aufrufer kann
     *                   daran erkennen, ob etwas aus der Auswahl herausfiel.
     */
    public function markManyHandled(int $teamId, array $threadIds, ?int $userId): array
    {
        if ($threadIds === []) {
            return [];
        }

        $validIds = CommsWhatsAppThread::query()
            ->whereIn('id', $threadIds)
            ->where('team_id', $teamId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($validIds === []) {
            return [];
        }

        $now = now();
        $rows = array_map(static fn (int $id) => [
            'team_id' => $teamId,
            'comms_whatsapp_thread_id' => $id,
            'handled_at' => $now,
            'handled_by_user_id' => $userId,
            'handled_reason' => RecConversationHandled::REASON_MANUAL,
            'created_at' => $now,
            'updated_at' => $now,
        ], $validIds);

        RecConversationHandled::query()->upsert(
            $rows,
            ['comms_whatsapp_thread_id'],
            ['team_id', 'handled_at', 'handled_by_user_id', 'handled_reason', 'updated_at'],
        );

        return $validIds;
    }
}
