<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobOfferShift;
use Platform\Recruiting\Models\RecDispoJobWave;

/**
 * Portal-Weg einer Welle (Markus 13.11.2026, Spec 6.3): je Empfaenger dasselbe
 * Angebot samt Schichten wie DispoJobOfferSender, aber ohne Nachricht —
 * kanal = portal, sent_at = „bereitgestellt“. Gleiche Empfaenger- und
 * Ergebnisform wie der Sender, damit der Job beide gleich zaehlt. Wirft nie.
 */
class DispoJobPortalBereitsteller
{
    /**
     * @param list<array{anchor:int, employee_id:int, shift_ids:list<int>}> $recipients (weitere Schluessel wie beim Sender werden ignoriert)
     * @return array{ok:bool, message:?string, sent:int, failed:list<array{anchor:int, error:string}>}
     */
    public function bereitstellen(RecDispoJobWave $wave, array $recipients): array
    {
        $sent = 0;
        $failed = [];

        foreach ($recipients as $r) {
            $offer = null;
            try {
                $exists = RecDispoJobOffer::query()
                    ->where('request_id', $wave->request_id)
                    ->where('anchor_employee_id', $r['anchor'])
                    ->exists();
                if ($exists) {
                    $failed[] = ['anchor' => (int) $r['anchor'], 'error' => 'in dieser Anfrage schon angeschrieben'];
                    continue;
                }

                $offer = RecDispoJobOffer::create([
                    'wave_id' => $wave->id, 'request_id' => $wave->request_id,
                    'rec_employee_id' => $r['employee_id'], 'anchor_employee_id' => $r['anchor'],
                    'kanal' => 'portal',
                ]);
                foreach ($r['shift_ids'] as $shiftId) {
                    RecDispoJobOfferShift::create(['offer_id' => $offer->id, 'shift_id' => $shiftId]);
                }
                // Erst wenn alle Schichten stehen: halb angelegte Angebote gelten nicht als bereitgestellt.
                $offer->update(['sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                if ($offer !== null) {
                    try {
                        $offer->update(['send_error' => mb_substr($e->getMessage(), 0, 500)]);
                    } catch (\Throwable) {
                        // zweiter Fehler beim Stempeln: bewusst geschluckt
                    }
                }
                $failed[] = ['anchor' => (int) ($r['anchor'] ?? 0), 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => null, 'sent' => $sent, 'failed' => $failed];
    }
}
