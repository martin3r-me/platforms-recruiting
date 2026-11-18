<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecPosting;

/**
 * Abgelaufene Ausschreibungen automatisch inaktiv schalten (16.11.2026).
 *
 * Vorher passierte am Enddatum nur die Haelfte: FLYNK bekam „entfernen" und neue
 * Bewerbungen wurden nicht mehr zugeordnet — auf dem Bildschirm stand aber
 * weiter „Aktiv". Jetzt wird der Schalter mitgezogen.
 *
 * Abgelaufen = der Tag des Enddatums ist RUM (RecPosting::isExpired). Ohne
 * Enddatum passiert nie etwas (Initiativ-Ausschreibungen bleiben offen).
 *
 * Seit 17.11.2026 wird auch der Status auf „Geschlossen" gesetzt: die
 * Uebersicht zeigt nur den Status, und dort stand eine abgelaufene
 * Ausschreibung weiter als „Published". Das gilt auch fuer Ausschreibungen,
 * die schon inaktiv, aber noch „Veröffentlicht" sind (die erste Fassung
 * schaltete nur den Schalter). Verlaengern heisst: neues Enddatum eintragen
 * und „Veröffentlichen" klicken — der Knopf schaltet auch wieder aktiv.
 *
 * Einweg: nichts schaltet hier wieder ein.
 */
class PostingExpiry
{
    /** @return int[] IDs der in diesem Lauf deaktivierten Ausschreibungen */
    public function deactivateExpired(?\DateTimeInterface $now = null): array
    {
        $now = $now ? \Illuminate\Support\Carbon::instance($now) : now();

        $ids = [];
        RecPosting::query()
            ->where('status', 'published') // Entwuerfe und Geschlossene bleiben, wie sie sind
            ->whereNotNull('closes_at')
            ->where('closes_at', '<', $now->copy()->startOfDay())
            ->orderBy('id')
            ->get()
            ->each(function (RecPosting $posting) use (&$ids, $now) {
                if (!$posting->isExpired($now)) {
                    return; // Guertel: Query und Model-Regel muessen dasselbe sagen
                }
                $posting->is_active = false;
                $posting->status = 'closed';
                $posting->save();
                $ids[] = (int) $posting->id;
            });

        if ($ids !== []) {
            Log::info('recruiting: abgelaufene Ausschreibungen geschlossen und inaktiv geschaltet', ['posting_ids' => $ids]);
        }

        return $ids;
    }
}
