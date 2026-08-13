<?php

namespace Platform\Recruiting\Support;

use Illuminate\Database\Eloquent\Builder;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecHrDeskCase;

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
 *  4. Nicht geparkt, nicht als Dublette markiert, kein offener HR-Fall —
 *     ABER: ein Fall mit reason=applicant_cancelled_training zaehlt nicht als
 *     Sperre. Diese Zustaende hat der alte Filter implizit miterledigt (sie
 *     haben nie auto_pilot_completed_at), is_active bleibt bei allen true.
 *
 *     Die Ausnahme ist der Kern des Umbuchens: sagt ein Bewerber seine Schulung
 *     ueber den Portal-Link ab, legt Public\InterviewBooking einen HR-Fall mit
 *     genau diesem Reason an und setzt is_on_hr_desk. Wuerde der sperren, waere
 *     die Person, die HR am dringendsten in einen anderen Termin setzen will,
 *     unsichtbar — und der HR-Schreibtisch selbst hat keine Buchungs-Aktion.
 *     Bei den uebrigen Reasons (Nicht-EU, keine Deutschkenntnisse,
 *     minderjaehrig) ist die Sperre gewollt: dort haengt eine Freigabe dran.
 *
 *     Gefiltert wird ueber die offenen Faelle, nicht ueber das
 *     is_on_hr_desk-Flag: das Flag kennt den Reason nicht.
 */
final class ManualBookingCandidates
{
    public static function query(int $teamId, ?int $positionId = null): Builder
    {
        $query = RecApplicant::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->where('is_parked', false)
            ->whereNull('duplicate_of_applicant_id')
            ->whereDoesntHave('hrDeskCases', function ($c) {
                $c->open()->where('reason', '!=', RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING);
            })
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
            // Stellen-Filter mit Bypass fuer Importierte OHNE Stelle: Legacy-CSV-
            // Importe haben keine Postings, ihnen fehlt also die Grundlage fuer
            // diesen Filter — sie sollen in jeden Termin buchbar sein.
            //
            // Der Bypass haengt bewusst am fehlenden Posting und nicht allein an
            // import_source: sobald ein Import einer Stelle zugeordnet ist (HR
            // haengt ihn um, Enrichment schluesselt ihn), traegt die Begruendung
            // nicht mehr, und ein Koelner Altbestands-Bewerber waere sonst in
            // jedem Duesseldorfer Termin buchbar.
            //
            // rec_position_id direkt am Posting statt ueber einen Join auf
            // rec_positions: die Spalte ist ein FK mit cascadeOnDelete
            // (2026_02_09_000002), verwaiste Postings gibt es also nicht.
            $query->where(function (Builder $q) use ($positionId) {
                $q->whereHas('postings', function ($pq) use ($positionId) {
                    $pq->where('rec_position_id', $positionId);
                })->orWhere(function (Builder $import) {
                    $import->whereNotNull('import_source')->whereDoesntHave('postings');
                });
            });
        }

        return $query;
    }
}
