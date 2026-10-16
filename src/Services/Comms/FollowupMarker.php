<?php

namespace Platform\Recruiting\Services\Comms;

use Illuminate\Support\Carbon;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationFollowup;

/**
 * „Offen lassen“ (Spec 2026-10-16 §3, Teil B): HR markiert einen Chat bewusst
 * als noch zu erledigen — der „doppelte Boden“ fuer 18 Uhr.
 *
 * Geloest wird der Marker NUR ueber erledigen(), also den bewussten Klick
 * auf „Erledigt“ (Sebastian 16.10.). Eine Antwort von HR loest ihn
 * ausdruecklich NICHT — deshalb liest offeneFuer() keine Nachrichten.
 *
 * Team-Grenze: jede Methode arbeitet nur auf Zeilen bzw. Threads des
 * uebergebenen Teams.
 */
final class FollowupMarker
{
    /**
     * Upsert auf die offene Zeile: solange cleared_at leer ist, gibt es je
     * Thread keine zweite. Ein erneutes Markieren schreibt Wer/Wann neu.
     * Threads anderer Teams werden nicht markiert.
     */
    public function markieren(int $teamId, int $threadId, ?int $userId): void
    {
        $gehoertZumTeam = CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('team_id', $teamId)
            ->exists();
        if (!$gehoertZumTeam) {
            return;
        }

        $jetzt = Carbon::now();
        $offen = RecConversationFollowup::query()
            ->where('team_id', $teamId)
            ->where('comms_whatsapp_thread_id', $threadId)
            ->whereNull('cleared_at')
            ->orderByDesc('id')
            ->first();

        if ($offen !== null) {
            $offen->update(['marked_by_user_id' => $userId, 'marked_at' => $jetzt]);

            return;
        }

        RecConversationFollowup::create([
            'team_id' => $teamId,
            'comms_whatsapp_thread_id' => $threadId,
            'marked_by_user_id' => $userId,
            'marked_at' => $jetzt,
        ]);
    }

    /**
     * Bewusst erledigen. Schliesst ALLE offenen Zeilen des Threads (falls ein
     * paralleler Doppelklick doch zwei angelegt hat). false = nichts offen
     * bzw. fremdes Team.
     */
    public function erledigen(int $teamId, int $threadId, ?int $userId): bool
    {
        $n = RecConversationFollowup::query()
            ->where('team_id', $teamId)
            ->where('comms_whatsapp_thread_id', $threadId)
            ->whereNull('cleared_at')
            ->update(['cleared_at' => Carbon::now(), 'cleared_by_user_id' => $userId]);

        return $n > 0;
    }

    /**
     * Offene Marker je Thread mit Namen der markierenden Person (Join auf
     * users wie HrMitarbeiterListen::gestoppte()).
     *
     * Liest alle offenen Marker des Teams und schneidet in PHP — offene
     * Marker sind wenige, die Grundmenge der Liste (~1000 Threads) als
     * IN-Liste waere teurer. Bei mehreren offenen Zeilen gewinnt die juengste.
     *
     * @param list<int> $threadIds
     * @return array<int, array{by: ?string, at: int}>
     */
    public function offeneFuer(int $teamId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }
        $gesucht = array_fill_keys(array_map('intval', $threadIds), true);

        $zeilen = (new RecConversationFollowup())->getConnection()
            ->table('rec_conversation_followups as f')
            ->leftJoin('users as u', 'u.id', '=', 'f.marked_by_user_id')
            ->where('f.team_id', $teamId)
            ->whereNull('f.cleared_at')
            ->orderBy('f.id')
            ->get(['f.comms_whatsapp_thread_id', 'f.marked_at', 'u.name as benutzer']);

        $out = [];
        foreach ($zeilen as $z) {
            $id = (int) $z->comms_whatsapp_thread_id;
            if (!isset($gesucht[$id])) {
                continue;
            }
            $out[$id] = [
                'by' => $z->benutzer !== null && trim((string) $z->benutzer) !== '' ? (string) $z->benutzer : null,
                'at' => Carbon::parse((string) $z->marked_at)->getTimestamp(),
            ];
        }

        return $out;
    }
}
