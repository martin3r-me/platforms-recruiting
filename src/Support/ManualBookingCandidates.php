<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecInterviewBooking;

/**
 * Wer erscheint im Buchungs-Dialog (InterviewBookings\Index) als Kandidat?
 *
 * Eigene Klasse, damit die Regel ohne Livewire-Runtime gegen eine echte DB
 * testbar ist (tests/Integration/ManualBookingCandidatesTest) — der Dialog
 * selbst ruft nur noch durch.
 *
 * Drei Bedingungen, mit UND verknuepft:
 *
 *  1. Die Phase des Bewerbers erlaubt manuelles Einbuchen
 *     (allow_manual_booking), ODER es ist ein CSV-Altbestands-Import:
 *     import_source gesetzt UND keine Phase — genau so legt
 *     ImportApplicantsCsvService sie an (auto_pilot=false, kein rec_phase_id).
 *     Solche Importe sollen wie bisher in jede Schulung buchbar bleiben.
 *
 *  2. Es sind noch keine Vertraege versendet. Vertragsversand, MA-Anlage und
 *     MA-Portal-Link passieren in einem Zug (ContractDispatchService:34-86) —
 *     ab da ist der Bewerber durch und darf nicht mehr umgebucht werden. Dies
 *     ist bewusst der einzige Punkt, an dem die Umstellung etwas WEGNIMMT:
 *     vorher (Filter auf auto_pilot_completed_at) waren genau diese Bewerber
 *     die einzigen buchbaren.
 *
 *  3. Keine nicht-stornierte Buchung — unveraendert zur bisherigen Logik.
 *     'teilgenommen' und 'nicht erschienen' sperren also weiter; Umbuchen
 *     heisst absagen und im Zieltermin neu buchen.
 */
final class ManualBookingCandidates
{
    public static function query(int $teamId, ?int $positionId = null): Builder
    {
        // Ueber ALLE Termine hinweg, nicht nur den aktuellen: ein Bewerber
        // darf nie in zwei Schulungen gleichzeitig stehen.
        $bookedIds = RecInterviewBooking::query()
            ->whereNotIn('status', ['cancelled'])
            ->pluck('rec_applicant_id');

        $query = RecApplicant::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotIn('id', $bookedIds)
            ->where(function (Builder $q) {
                $q->whereHas('phase', function ($p) {
                    $p->where('allow_manual_booking', true);
                })->orWhere(function (Builder $legacy) {
                    $legacy->whereNull('rec_phase_id')->whereNotNull('import_source');
                });
            })
            ->whereDoesntHave('contracts', function ($c) {
                $c->whereNotIn('status', ['cancelled'])->whereNotNull('sent_at');
            });

        if ($positionId !== null) {
            // Stellen-Filter mit Bypass fuer Importierte: Legacy-CSV-Importe
            // haben keine Postings/Positions — sie sollen aber in jede
            // Schulung buchbar sein, unabhaengig von der Termin-Stelle.
            $query->where(function (Builder $q) use ($positionId) {
                $q->whereHas('postings', function ($pq) use ($positionId) {
                    $pq->whereHas('position', function ($p) use ($positionId) {
                        $p->where('rec_positions.id', $positionId);
                    });
                })->orWhereNotNull('import_source');
            });
        }

        return $query;
    }
}
