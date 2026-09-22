<?php

namespace Platform\Recruiting\Services\Campaign;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Baut aus der Kohorten-ID-Liste des Statistik-Modals die Zeilen fuer die
 * Kampagne „Neue Termine“: Anzeige-Daten plus das Ergebnis der Segmentregel.
 *
 * Gebuendelte Queries (eine pro Tabelle), nicht pro Zeile — auf der
 * Statistik-Seite ist das Query-Budget Abnahmekriterium. Ergebnis ist nach
 * applicant_id geschluesselt, Reihenfolge wie angefragt; Team-fremde oder
 * fehlende IDs tauchen nicht auf (forTeam ist das aeussere Schloss, wie in
 * Statistics\Index::drillApplicants).
 *
 * Nicht final: Task 8 (Job) haengt sich per anonymer Unterklasse dran
 * (Test-Doppel).
 */
class NewDatesCampaignRecipients
{
    // Eine Quelle der Wahrheit (Final-Review): NewDatesCampaignSender::LOG_TYPE
    // ist derselbe String ('campaign_sent'), dieser Loader referenziert ihn
    // statt eine zweite woertliche Kopie zu pflegen, die auseinanderlaufen
    // koennte.
    public const LOG_TYPE_CAMPAIGN = NewDatesCampaignSender::LOG_TYPE;

    /**
     * @param list<int> $applicantIds
     * @return array<int, array{applicant_id:int, name:string, applied_at:?string, phase:string, template:string, selectable:bool, checked:bool, badges:list<string>}>
     */
    public function load(int $teamId, array $applicantIds, \DateTimeImmutable $now): array
    {
        $ids = array_values(array_unique(array_map('intval', $applicantIds)));
        if ($ids === []) {
            return [];
        }

        $applicants = RecApplicant::forTeam($teamId)
            ->whereIn('id', $ids)
            // Re-Check des Loader-Standes (Final-Review): NICHT scopeActive()
            // verwenden — der schliesst is_on_hr_desk aus, und die Kampagne muss
            // HR-Desk-Zeilen weiter zeigen (sie sind ein eigenes Badge, kein
            // Ausschlussgrund). Inaktive, geparkte oder abgesagte Bewerbungen
            // sind dagegen kein Kampagnenziel mehr.
            ->where('is_active', true)
            ->where('is_parked', false)
            ->whereNull('rejected_at')
            ->with([
                'phase',
                'position.phases',
                'postings.position.phases',
                'interviewBookings',
                'crmContactLinks.contact.phoneNumbers',
            ])
            ->get()
            ->keyBy('id');

        $waitlist = RecInterviewWaitlist::query()
            ->whereIn('rec_applicant_id', $ids)
            ->ortBased()
            ->open()
            ->orderBy('id')
            ->get()
            ->groupBy('rec_applicant_id');

        $lastCampaign = RecAutoPilotLog::query()
            ->whereIn('rec_applicant_id', $ids)
            ->where('type', self::LOG_TYPE_CAMPAIGN)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('rec_applicant_id')
            ->map(fn ($logs) => $logs->first());

        $rows = [];
        foreach ($ids as $id) {
            $a = $applicants->get($id);
            if ($a === null) {
                continue;
            }

            $phases = $a->primaryPosition()?->phases
                ?->map(fn ($p) => [
                    'order' => (int) $p->order,
                    'completion_type' => $p->completion_type,
                    'completion_config' => $p->completion_config,
                    'is_active' => (bool) $p->is_active,
                ])->values()->all() ?? [];

            $cancelled = $a->interviewBookings
                ->where('status', 'cancelled')
                ->map(fn ($b) => [
                    'cancelled_by' => $b->cancelled_by,
                    'cancelled_at' => $b->cancelled_at?->format('Y-m-d H:i:s'),
                ])->values()->all();

            $wl = $waitlist->get($id)?->first();
            $lc = $lastCampaign->get($id);

            $segment = CampaignSegment::classify([
                'phase_order' => $a->phase?->order,
                'booking_order' => CampaignSegment::bookingOrder($phases),
                'has_phone' => $a->primaryContactPhone() !== null,
                'has_active_booking' => $a->interviewBookings->where('status', '!=', 'cancelled')->isNotEmpty(),
                'on_hr_desk' => (bool) $a->is_on_hr_desk,
                'last_campaign_at' => $lc?->created_at?->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
                'cancelled_bookings' => $cancelled,
                // Drei Zustaende, in denen eine Kampagnen-Nachricht nicht passt.
                // Die Bremse an einer Phase ist eine AutoPilot-Einstellung — der
                // Sammelversand las sie nie, und genau daran lag es, dass #2906
                // am 15.09.2026 noch `statistik_p1` bekam.
                'sammelstelle' => (bool) $a->primaryPosition()?->is_sammelstelle,
                'stille_phase' => self::phaseIstStill($a->phase),
                'abgeschlossen_am' => $a->auto_pilot_completed_at?->format('Y-m-d H:i:s'),
                'waitlist' => $wl === null ? null : [
                    'enrolled_at' => $wl->enrolled_at?->format('Y-m-d H:i:s'),
                    'notified_at' => $wl->notified_at?->format('Y-m-d H:i:s'),
                ],
            ]);

            $rows[$id] = [
                'applicant_id' => (int) $a->id,
                'name' => $this->displayName($a),
                'applied_at' => $a->applied_at?->format('Y-m-d'),
                'phase' => (string) ($a->phase?->name ?? 'ohne Phase'),
            ] + $segment;
        }

        return $rows;
    }

    /**
     * Eine Phase ist still, wenn sie den Auto-Piloten ausdruecklich abschaltet
     * (`auto_pilot_enabled: false`, die Bremse der Sammel-Stelle) oder nur den
     * Template-Versand stoppt (`auto_pilot_disabled: true`, z.B. die Phase
     * „Schulung & Vertraege versenden"). Beides heisst: hier soll von selbst
     * nichts rausgehen — was fuer einen Sammelversand genauso gilt.
     */
    private static function phaseIstStill(?\Platform\Recruiting\Models\RecPhase $phase): bool
    {
        if ($phase === null) {
            return false;
        }

        return $phase->getAutoPilotSetting('auto_pilot_enabled', true) === false
            || $phase->getAutoPilotSetting('auto_pilot_disabled', false) === true;
    }

    /**
     * Name aus Vor-/Nachname statt ueber den full_name-Accessor: der
     * Accessor laedt academicTitle/salutation (BelongsTo) nach — ohne diese
     * im Eager-Load waere das ein N+1 pro Zeile, und die beiden Relationen
     * extra nachzuladen lohnt sich fuer eine reine Anzeigezeile nicht
     * (Query-Budget der Statistik-Seite).
     */
    private function displayName(RecApplicant $a): string
    {
        $contact = $a->crmContactLinks->first()?->contact;
        $name = trim(((string) $contact?->first_name) . ' ' . ((string) $contact?->last_name));

        return $name !== '' ? $name : ('Bewerber #' . $a->id);
    }
}
