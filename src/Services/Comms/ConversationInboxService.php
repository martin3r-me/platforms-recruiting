<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Baut die Kommunikations-Übersicht für ein Team: lädt die WhatsApp-Threads
 * von Bewerbern UND Mitarbeitern (aus dem CRM, nur lesend), reichert
 * Kontakt-/Owner-Daten an und delegiert die Eskalations-/Sortier-Logik an das
 * reine DTO ConversationInboxReport.
 *
 * Getrackt wird jede Konversation, bei der mindestens eine eingehende Nachricht
 * vorliegt (last_inbound_at) — egal ob das Gegenüber Bewerber oder Mitarbeiter ist.
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

        $bySubject = $this->dedupedThreads($teamId);

        $humanOutbound = $this->humanOutboundTimestamps(
            array_map(fn ($t) => (int) $t->id, array_values($bySubject)),
        );

        // IDs je Typ sammeln und Subjekte laden.
        $applicantIds = [];
        $employeeIds = [];
        foreach ($bySubject as $key => $thread) {
            [$type, $id] = explode(':', $key, 2);
            if ($type === 'employee') {
                $employeeIds[] = (int) $id;
            } else {
                $applicantIds[] = (int) $id;
            }
        }

        $applicants = RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->whereIn('id', $applicantIds)
            ->get()
            ->keyBy('id');

        $employees = RecEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(['id', 'first_name', 'last_name', 'rec_applicant_id'])
            ->keyBy('id');

        $rows = [];
        foreach ($bySubject as $key => $thread) {
            [$type, $idStr] = explode(':', $key, 2);
            $id = (int) $idStr;

            if ($type === 'employee') {
                $employee = $employees->get($id);
                $name = $employee
                    ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? ''))
                    : null;
                $firstName = $employee?->first_name;
                $owner = null; // Mitarbeiter haben kein owned_by_user_id
                // Deep-Link bevorzugt auf die Bewerber-Detailseite (dort lebt der
                // Chat); fällt zurück auf die MA-Detailseite.
                if ($employee && $employee->rec_applicant_id) {
                    $url = route('recruiting.applicants.show', ['applicant' => $employee->rec_applicant_id]);
                } elseif ($employee) {
                    $url = route('recruiting.employees.show', ['employee' => $id]);
                } else {
                    $url = null;
                }
            } else {
                $applicant = $applicants->get($id);
                $contact = $applicant?->crmContactLinks->first()?->contact;
                $name = $contact?->full_name;
                $firstName = $contact?->first_name;
                $owner = $applicant?->owned_by_user_id;
                $url = $applicant ? route('recruiting.applicants.show', ['applicant' => $id]) : null;
            }

            $rows[] = [
                'thread_id' => (int) $thread->id,
                'subject_type' => $type,
                'subject_id' => $id,
                'url' => $url,
                'contact_name' => $name ?: ($thread->remote_phone_number ?: 'Unbekannt'),
                'first_name' => $firstName,
                'preview' => $thread->last_message_preview,
                'phone' => $thread->remote_phone_number,
                'owner_user_id' => $owner,
                'is_unread' => (bool) $thread->is_unread,
                'last_inbound_at' => $thread->last_inbound_at?->getTimestamp(),
                // Effektiver letzter MENSCHLICHER Ausgang; fehlt der Thread in
                // der Map (nur Auto-Reply-Outbounds), gilt null = nie beantwortet.
                'last_outbound_at' => $humanOutbound[(int) $thread->id] ?? null,
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

        $bySubject = $this->dedupedThreads($teamId);

        $humanOutbound = $this->humanOutboundTimestamps(
            array_map(fn ($t) => (int) $t->id, array_values($bySubject)),
        );

        $unread = 0;
        $escalation = 0;
        foreach ($bySubject as $thread) {
            if ($thread->is_unread) {
                $unread++;
            }
            $level = ConversationEscalation::compute(
                $thread->last_inbound_at?->getTimestamp(),
                $humanOutbound[(int) $thread->id] ?? null,
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

    /**
     * Lädt die relevanten Threads (Bewerber + Mitarbeiter, mind. ein Eingang)
     * und reduziert sie pro Subjekt auf den Thread mit dem neuesten Eingang.
     *
     * @return array<string, CommsWhatsAppThread> Key = "applicant:<id>" | "employee:<id>"
     */
    private function dedupedThreads(int $teamId): array
    {
        $applicantMorph = (new RecApplicant)->getMorphClass();
        $applicantFull = RecApplicant::class;
        $employeeFull = RecEmployee::class; // nicht in der morphMap → Full-Class

        $threads = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereIn('context_model', [$applicantMorph, $applicantFull, $employeeFull])
            ->whereNotNull('context_model_id')
            ->whereNotNull('last_inbound_at')
            ->get();

        $bySubject = [];
        foreach ($threads as $thread) {
            $type = $thread->context_model === $employeeFull ? 'employee' : 'applicant';
            $key = $type . ':' . (int) $thread->context_model_id;

            $existing = $bySubject[$key] ?? null;
            if ($existing === null
                || ($thread->last_inbound_at
                    && $thread->last_inbound_at->greaterThan($existing->last_inbound_at))) {
                $bySubject[$key] = $thread;
            }
        }

        return $bySubject;
    }

    /**
     * Letzter MENSCHLICHER Outbound je Thread (Auto-Quittungen wie OOO/Voice
     * via is_auto_reply ausgeschlossen). EINE gruppierte, index-gestuetzte
     * Query (Composite-Index thread_id+created_at) — kein N+1. Laeuft bewusst
     * unconditional fuer alle Teams (fixt auch den Voice-Fall ohne Config).
     *
     * WICHTIG: Threads ohne menschlichen Outbound fehlen im Ergebnis —
     * der Aufrufer MUSS das als null (nie beantwortet) werten. KEIN Fallback
     * auf thread.last_outbound_at (wurde von der Auto-Reply gebumpt; ein
     * Fallback regressiert still in genau den Bug, den das Flag fixt).
     *
     * @param array<int, int> $threadIds
     * @return array<int, int> thread_id => Unix-TS des letzten menschlichen Outbounds
     */
    private function humanOutboundTimestamps(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', $threadIds)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            ->groupBy('comms_whatsapp_thread_id')
            ->selectRaw('comms_whatsapp_thread_id, MAX(created_at) AS last_human_outbound_at')
            ->pluck('last_human_outbound_at', 'comms_whatsapp_thread_id')
            ->map(fn ($v) => \Carbon\Carbon::parse((string) $v)->getTimestamp())
            ->all();
    }
}
