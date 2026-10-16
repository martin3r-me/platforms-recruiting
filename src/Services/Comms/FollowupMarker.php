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
    /** Bis zu so vielen Threads geht die Einschraenkung per IN an die DB (Review Task 6, M1). */
    private const IN_GRENZE = 500;

    /**
     * Upsert auf die offene Zeile: solange cleared_at leer ist, gibt es je
     * Thread keine zweite. Ein erneutes Markieren schreibt Wer/Wann neu.
     * Threads anderer Teams werden nicht markiert.
     *
     * Transaktion + lockForUpdate gegen den Doppelklick (Review Task 6, M3):
     * ein partieller Unique-Index (thread, cleared_at IS NULL) ist nicht
     * portabel. Schutz greift, sobald eine offene Zeile existiert; zwei
     * gleichzeitige ERSTE Klicks koennen weiter zwei Zeilen anlegen — das
     * fangen erledigen() (schliesst alle) und offeneFuer() (juengste) ab.
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

        (new RecConversationFollowup())->getConnection()->transaction(function () use ($teamId, $threadId, $userId): void {
            $jetzt = Carbon::now();
            $offen = RecConversationFollowup::query()
                ->where('team_id', $teamId)
                ->where('comms_whatsapp_thread_id', $threadId)
                ->whereNull('cleared_at')
                ->orderByDesc('id')
                ->lockForUpdate()
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
        });
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
     * Bis IN_GRENZE Threads (Kopfzeile, kleine Teams) schraenkt die DB per IN
     * ein; darueber (Grundmenge der Liste, ~1000 Threads) werden alle offenen
     * Marker des Teams gelesen und in PHP geschnitten — offene Marker sind
     * wenige, eine riesige IN-Liste waere teurer. Bei mehreren offenen
     * Zeilen gewinnt die juengste.
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

        $abfrage = (new RecConversationFollowup())->getConnection()
            ->table('rec_conversation_followups as f')
            ->leftJoin('users as u', 'u.id', '=', 'f.marked_by_user_id')
            ->where('f.team_id', $teamId)
            ->whereNull('f.cleared_at');
        if (count($gesucht) <= self::IN_GRENZE) {
            $abfrage->whereIn('f.comms_whatsapp_thread_id', array_keys($gesucht));
        }
        $zeilen = $abfrage
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
