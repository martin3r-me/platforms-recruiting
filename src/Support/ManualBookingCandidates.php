<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Recruiting\Models\RecApplicant;

/**
 * Wer erscheint im Buchungs-Dialog (InterviewBookings\Index) als Kandidat?
 *
 * Eigene Klasse, damit die Regel ohne Livewire-Runtime gegen eine echte DB
 * testbar ist (tests/Integration/ManualBookingCandidatesTest) — der Dialog
 * selbst ruft nur noch durch.
 *
 * Vier Bedingungen, mit UND verknuepft:
 *
 *  1. Die Phase des Bewerbers ist aktiv und erlaubt manuelles Einbuchen
 *     (allow_manual_booking), ODER es ist ein CSV-Altbestands-Import
 *     (import_source gesetzt) — die sollen wie bisher in jede Schulung
 *     buchbar bleiben.
 *
 *     Die Import-Bedingung fragt bewusst NICHT zusaetzlich nach einer fehlenden
 *     Phase: ein Import startet phasenlos (ImportApplicantsCsvService:238-240),
 *     bleibt es aber nicht. Sobald ein Posting verknuepft wird, setzt
 *     reconcilePositionState() ihn auf die erste Phase der Stelle
 *     (RecApplicant:1966 fasst "Phase fehlt" ausdruecklich mit) — und die
 *     traegt den Schalter nicht.
 *
 *     is_active auf der Phase, weil der Backfill-Planner inaktive Phasen
 *     bewusst ueberspringt: eine nach dem Schalten stillgelegte Phase soll
 *     ihre Bewerber nicht fuer immer buchbar halten.
 *
 *  2. Es sind noch keine Vertraege versendet (RecContract::scopeSent —
 *     dieselbe Definition wie RecApplicant::hasAnyContractSent). Vertrag,
 *     MA-Anlage und MA-Portal-Link gehen in einem Zug raus
 *     (ContractDispatchService:34-86); ab da ist der Bewerber durch. Das ist
 *     bewusst der einzige Punkt, an dem die Umstellung etwas WEGNIMMT: vorher
 *     (Filter auf auto_pilot_completed_at) waren genau diese Bewerber die
 *     einzigen buchbaren.
 *
 *  3. Keine nicht-stornierte Buchung — unveraendert zur bisherigen Logik.
 *     'teilgenommen' und 'nicht erschienen' sperren also weiter; Umbuchen
 *     heisst absagen und im Zieltermin neu buchen.
 *
 *  4. Nicht geparkt, nicht am HR-Schreibtisch, nicht als Dublette markiert.
 *     Diese drei hat der alte Filter implizit miterledigt (sie haben nie
 *     auto_pilot_completed_at) — ohne sie taucht z.B. ein Bewerber mit offenem
 *     HR-Fall als buchbarer Kandidat auf, obwohl der Fall genau das verhindern
 *     soll. is_active bleibt bei allen drei Zustaenden true.
 */
final class ManualBookingCandidates
{
    public static function query(int $teamId, ?int $positionId = null): Builder
    {
        $query = RecApplicant::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->where('is_parked', false)
            ->where('is_on_hr_desk', false)
            ->whereNull('duplicate_of_applicant_id')
            // Ueber ALLE Termine hinweg, nicht nur den aktuellen: ein Bewerber
            // darf nie in zwei Schulungen gleichzeitig stehen. Als
            // whereDoesntHave und nicht als pluck+whereNotIn, damit nicht jede
            // Buchung jedes Teams als IN-Liste in PHP landet.
            ->whereDoesntHave('interviewBookings', function ($b) {
                $b->whereNotIn('status', ['cancelled']);
            })
            ->where(function (Builder $q) {
                $q->whereHas('phase', function ($p) {
                    $p->where('allow_manual_booking', true)->where('is_active', true);
                })->orWhereNotNull('import_source');
            })
            ->whereDoesntHave('contracts', function ($c) {
                $c->sent();
            });

        if ($positionId !== null) {
            // Stellen-Filter mit Bypass fuer Importierte: Legacy-CSV-Importe
            // haben keine Postings/Positions — sie sollen aber in jede
            // Schulung buchbar sein, unabhaengig von der Termin-Stelle.
            //
            // rec_position_id direkt am Posting statt ueber einen Join auf
            // rec_positions: die Spalte ist ein FK mit cascadeOnDelete
            // (2026_02_09_000002), verwaiste Postings gibt es also nicht.
            $query->where(function (Builder $q) use ($positionId) {
                $q->whereHas('postings', function ($pq) use ($positionId) {
                    $pq->where('rec_position_id', $positionId);
                })->orWhereNotNull('import_source');
            });
        }

        return $query;
    }
}
