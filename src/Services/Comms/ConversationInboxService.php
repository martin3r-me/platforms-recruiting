<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Baut die Kommunikations-Übersicht für ein Team: lädt die WhatsApp-Threads
 * der Bewerber (aus dem CRM, nur lesend), reichert Kontakt-/Owner-Daten an und
 * delegiert die Eskalations-/Sortier-Logik an das reine DTO ConversationInboxReport.
 *
 * Eloquent statt DB::table, weil hier Kontaktname/Owner über Relationen aufgelöst
 * werden und SoftDeletes automatisch greifen. Die testbare Logik liegt im DTO.
 */
final class ConversationInboxService
{
    public function build(int $teamId, ?int $now = null): ConversationInboxReport
    {
        $now ??= time();

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);

        $morphClass = (new RecApplicant)->getMorphClass();
        $fullClass = RecApplicant::class;

        // Threads dieses Teams, die an einen Bewerber gebunden sind und je
        // mindestens eine eingehende Nachricht hatten (sonst nichts zu lesen/eskalieren).
        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereIn('context_model', [$morphClass, $fullClass])
            ->whereNotNull('context_model_id')
            ->whereNotNull('last_inbound_at')
            ->get();

        // Pro Bewerber den relevantesten Thread (neuester Eingang) — analog
        // zu Applicant\Index::whatsAppThreadMap().
        $byApplicant = [];
        foreach ($threads as $thread) {
            $oid = (int) $thread->context_model_id;
            $existing = $byApplicant[$oid] ?? null;
            if ($existing === null
                || ($thread->last_inbound_at
                    && $thread->last_inbound_at->greaterThan($existing->last_inbound_at))) {
                $byApplicant[$oid] = $thread;
            }
        }

        $applicants = RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->whereIn('id', array_keys($byApplicant))
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($byApplicant as $oid => $thread) {
            $applicant = $applicants->get($oid);
            $name = $applicant?->crmContactLinks->first()?->contact?->full_name;

            $rows[] = [
                'thread_id' => (int) $thread->id,
                'applicant_id' => $oid,
                'contact_name' => $name ?: ($thread->remote_phone_number ?: 'Unbekannt'),
                'preview' => $thread->last_message_preview,
                'phone' => $thread->remote_phone_number,
                'owner_user_id' => $applicant?->owned_by_user_id,
                'is_unread' => (bool) $thread->is_unread,
                'last_inbound_at' => $thread->last_inbound_at?->getTimestamp(),
                'last_outbound_at' => $thread->last_outbound_at?->getTimestamp(),
            ];
        }

        return ConversationInboxReport::fromRows($rows, $now, $yellow, $red);
    }

    /**
     * Leichtgewichtige Kennzahlen für Sidebar-Badges — ohne Bewerber-/Kontakt-
     * Daten zu laden (nur Thread-Timestamps + Eskalations-Mathematik).
     *
     * @return array{unread: int, escalation: int}
     */
    public function counts(int $teamId, ?int $now = null): array
    {
        $now ??= time();

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);

        $morphClass = (new RecApplicant)->getMorphClass();
        $fullClass = RecApplicant::class;

        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereIn('context_model', [$morphClass, $fullClass])
            ->whereNotNull('context_model_id')
            ->whereNotNull('last_inbound_at')
            ->get(['id', 'context_model_id', 'is_unread', 'last_inbound_at', 'last_outbound_at']);

        // Pro Bewerber den relevantesten Thread (neuester Eingang).
        $byApplicant = [];
        foreach ($threads as $thread) {
            $oid = (int) $thread->context_model_id;
            $existing = $byApplicant[$oid] ?? null;
            if ($existing === null
                || ($thread->last_inbound_at
                    && $thread->last_inbound_at->greaterThan($existing->last_inbound_at))) {
                $byApplicant[$oid] = $thread;
            }
        }

        $unread = 0;
        $escalation = 0;
        foreach ($byApplicant as $thread) {
            if ($thread->is_unread) {
                $unread++;
            }
            $level = ConversationEscalation::compute(
                $thread->last_inbound_at?->getTimestamp(),
                $thread->last_outbound_at?->getTimestamp(),
                $now,
                $yellow,
                $red,
            )->level;

            if ($level === ConversationEscalation::LEVEL_RED
                || $level === ConversationEscalation::LEVEL_MISSED) {
                $escalation++;
            }
        }

        return ['unread' => $unread, 'escalation' => $escalation];
    }
}
