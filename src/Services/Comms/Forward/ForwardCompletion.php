<?php

namespace Platform\Recruiting\Services\Comms\Forward;

use Platform\Recruiting\Models\RecConversationForward;

/**
 * "Erledigt" im HR-Chat erledigt auch die offenen Weiterleitungen dieses
 * Chats (Spec Runde 2, 02.10.2026) — sonst haekt HR an zwei Stellen ab.
 * Bereits erledigte bleiben unberuehrt (done_at wird nie ueberschrieben).
 *
 * $target: dieselbe Regel gilt im Dispo-Postfach fuer Weiterleitungen von HR
 * (ForwardTargets::DISPO, Spec 16.10.2026 HR -> Dispo).
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
    public function completeForThreadIds(int $teamId, int $threadId, ?int $userId, string $target = ForwardTargets::HR, bool $ohneEigene = false): array
    {
        if ($threadId <= 0) {
            return [];
        }

        $ids = $this->ohneEigene(
            RecConversationForward::query()->openForTeam($teamId, $target)->where('target_thread_id', $threadId),
            $ohneEigene ? $userId : null,
        )
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
     * Liegt an diesem Chat eine offene Weiterleitung, die DIESER Nutzer selbst
     * aus der Dispo abgeschickt hat? Das Dispo-Postfach zeigt jedem alle
     * Niederlassungen — der Absender sieht also auch den Ziel-Chat. Schaut er
     * hinein, darf das weder die Weiterleitung erledigen noch den Chat fuer die
     * Empfaenger als gelesen markieren (Review 18.11.2026).
     */
    public function hatEigeneOffene(int $teamId, int $threadId, ?int $userId, string $target = ForwardTargets::DISPO): bool
    {
        if ($threadId <= 0 || $userId === null) {
            return false;
        }

        return RecConversationForward::query()
            ->openForTeam($teamId, $target)
            ->where('target_thread_id', $threadId)
            ->where('source', ForwardTargets::SOURCE_DISPO)
            ->where('forwarded_by_user_id', $userId)
            ->exists();
    }

    /**
     * Nimmt die Weiterleitungen aus, die $userId selbst aus der Dispo abgeschickt
     * hat. Zeilen ohne Absender (Altbestand, Automatik) bleiben drin.
     */
    private function ohneEigene(\Illuminate\Database\Eloquent\Builder $query, ?int $userId): \Illuminate\Database\Eloquent\Builder
    {
        if ($userId === null) {
            return $query;
        }

        return $query->where(function ($q) use ($userId) {
            $q->where('source', '!=', ForwardTargets::SOURCE_DISPO)
                ->orWhereNull('forwarded_by_user_id')
                ->orWhere('forwarded_by_user_id', '!=', $userId);
        });
    }

    /**
     * Holt beim Oeffnen erledigte Weiterleitungen zurueck. Die ID-Liste kommt
     * aus der Livewire-Komponente; deshalb zusaetzlich scharf auf Team, Chat,
     * Ziel HR und den erledigenden Nutzer — eine praeparierte ID trifft so
     * nichts Fremdes.
     *
     * @param array<int, int|string> $forwardIds
     */
    public function reopen(int $teamId, int $threadId, array $forwardIds, ?int $userId, string $target = ForwardTargets::HR): int
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
            ->where('target', $target)
            ->where('target_thread_id', $threadId)
            ->where('done_by_user_id', $userId)
            ->whereNotNull('done_at')
            ->whereIn('id', $ids)
            ->update(['done_at' => null, 'done_by_user_id' => null, 'updated_at' => now()]);
    }
}
