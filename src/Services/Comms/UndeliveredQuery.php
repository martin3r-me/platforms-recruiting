<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecDeliveryFailureAck;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Reiter "Nicht zugestellt" der Kommunikation.
 *
 * Warum ein eigener Lesepfad neben InboxQuery: die Chat-Liste zeigt nur
 * Threads mit last_inbound_at — wer nie geschrieben hat, steht dort nicht.
 * Genau das ist der frische Bewerber, dessen erstes Template gescheitert ist.
 * Ohne diese Abfrage sieht ihn niemand.
 *
 * Ein Thread steht hier, wenn
 *  - er auf dem Recruiting-Kanal-Set liegt (wie InboxQuery, inkl. Rueckfall),
 *  - seine LETZTE ausgehende Nachricht innerhalb von DAYS Tagen liegt und
 *    nicht zugestellt ist (Status failed ODER Fehler im meta_payload, ohne
 *    delivered_at/read_at — der Payload-Zweig faengt Altfaelle, deren
 *    failed vor dem CRM-Fix #924 von einem spaeten sent ueberschrieben wurde),
 *  - danach nichts mehr von der Person kam (last_inbound_at davor),
 *  - und HR genau diese Nachricht noch nicht abgehakt hat.
 * Eine spaetere erfolgreiche Nachricht raeumt ihn also von selbst ab.
 */
final class UndeliveredQuery
{
    /** Aeltere Fehlschlaege bleiben draussen — sonst steht die Liste am ersten Tag voller Altlasten. */
    public const DAYS = 30;

    public function count(int $teamId, ?int $now = null): int
    {
        return count($this->failures($teamId, $now ?? time()));
    }

    /**
     * @return list<array{thread_id:int, message_id:int, failed_at:int, phone:string, what:string,
     *                    reason:DeliveryFailureReason, subject_type:string, subject_id:?int, name:string,
     *                    owner_user_id:?int, url:?string, url_label:?string}>
     */
    public function rows(int $teamId, ?int $now = null): array
    {
        $failures = $this->failures($teamId, $now ?? time());
        usort($failures, static fn (array $a, array $b) => [$b['failed_at'], $b['message_id']] <=> [$a['failed_at'], $a['message_id']]);

        return $this->hydrate($failures);
    }

    /**
     * Ein Thread des Teams auf dem Kanal-Set — Schutz fuer Livewire-Aufrufe mit
     * beliebigen IDs (gleiches Muster wie Inbox::threadForTeam()).
     */
    public function threadForTeam(int $teamId, int $threadId): ?CommsWhatsAppThread
    {
        return $this->threadQuery($teamId)->whereKey($threadId)->first();
    }

    /** Zustellproblem abhaken — gilt bis zur naechsten gescheiterten Nachricht. */
    public function acknowledge(int $teamId, int $threadId, ?int $userId, ?int $now = null): bool
    {
        $failure = null;
        foreach ($this->failures($teamId, $now ?? time()) as $row) {
            if ($row['thread_id'] === $threadId) {
                $failure = $row;
                break;
            }
        }
        if ($failure === null) {
            return false;
        }

        RecDeliveryFailureAck::updateOrCreate(
            ['comms_whatsapp_thread_id' => $threadId],
            [
                'team_id' => $teamId,
                'comms_whatsapp_message_id' => $failure['message_id'],
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => $userId,
            ],
        );

        return true;
    }

    /** @return list<array<string, mixed>> duenne Zeilen, noch ohne Namen */
    private function failures(int $teamId, int $now): array
    {
        // Letzte ausgehende Nachricht je Thread im Zeitfenster. MAX(id) statt
        // MAX(created_at): eindeutig, und Nachrichten werden in Versandreihenfolge
        // angelegt (Status-Webhooks legen keine neuen Zeilen an). Das Kanal-Set
        // geht als Unterabfrage mit — die Zahl laeuft in Sidebar und 20s-Poll.
        $lastOutboundIds = CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', $this->threadQuery($teamId)->select('id'))
            ->where('direction', 'outbound')
            ->where('created_at', '>=', date('Y-m-d H:i:s', $now - self::DAYS * 86400))
            ->groupBy('comms_whatsapp_thread_id')
            ->selectRaw('MAX(id) AS last_id')
            ->pluck('last_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($lastOutboundIds === []) {
            return [];
        }

        // Grobfilter in SQL (laeuft im 20s-Poll), die Entscheidung trifft isUndelivered().
        // LIKE trifft "error" UND "errors" im JSON-Text — auf MySQL wie SQLite.
        $messages = CommsWhatsAppMessage::query()
            ->whereIn('id', $lastOutboundIds)
            ->whereNull('delivered_at')
            ->where(fn ($q) => $q->where('status', 'failed')->orWhere('meta_payload', 'like', '%"error%'))
            ->get(['id', 'comms_whatsapp_thread_id', 'status', 'meta_payload', 'template_name', 'body',
                   'delivered_at', 'read_at', 'created_at'])
            ->filter(fn (CommsWhatsAppMessage $m) => self::isUndelivered($m));
        if ($messages->isEmpty()) {
            return [];
        }

        $failedThreadIds = $messages->pluck('comms_whatsapp_thread_id')->map(fn ($id) => (int) $id)->all();
        $threads = CommsWhatsAppThread::query()
            ->whereIn('id', $failedThreadIds)
            ->get(['id', 'remote_phone_number', 'context_model', 'context_model_id', 'last_inbound_at'])
            ->keyBy('id');
        $acked = RecDeliveryFailureAck::query()
            ->where('team_id', $teamId)
            ->whereIn('comms_whatsapp_thread_id', $failedThreadIds)
            ->pluck('comms_whatsapp_message_id', 'comms_whatsapp_thread_id');

        $out = [];
        foreach ($messages as $m) {
            $threadId = (int) $m->comms_whatsapp_thread_id;
            $thread = $threads->get($threadId);
            if ($thread === null) {
                continue;
            }
            $failedAt = $m->created_at?->getTimestamp() ?? 0;

            // Person hat danach geschrieben -> erreichbar, steht in der normalen Liste.
            if ($thread->last_inbound_at !== null && $thread->last_inbound_at->getTimestamp() >= $failedAt) {
                continue;
            }
            if (isset($acked[$threadId]) && (int) $acked[$threadId] >= (int) $m->id) {
                continue;
            }

            $out[] = [
                'thread_id' => $threadId,
                'message_id' => (int) $m->id,
                'failed_at' => $failedAt,
                'phone' => (string) $thread->remote_phone_number,
                'context_model' => (string) ($thread->context_model ?? ''),
                'context_model_id' => $thread->context_model_id ? (int) $thread->context_model_id : null,
                'what' => !empty($m->template_name)
                    ? 'Vorlage „' . $m->template_name . '“'
                    : 'Nachricht „' . mb_strimwidth(trim((string) $m->body), 0, 60, '…') . '“',
                'reason' => DeliveryFailureReason::fromPayload(is_array($m->meta_payload) ? $m->meta_payload : null)
                    ?? DeliveryFailureReason::unknown(),
            ];
        }

        return $out;
    }

    /** Nicht zugestellt = failed oder Fehler im Payload, und nie als zugestellt/gelesen gemeldet. */
    public static function isUndelivered(CommsWhatsAppMessage $m): bool
    {
        if ($m->delivered_at !== null || $m->read_at !== null) {
            return false;
        }
        if (in_array($m->status, ['delivered', 'read'], true)) {
            return false;
        }
        if ($m->status === 'failed') {
            return true;
        }

        return DeliveryFailureReason::fromPayload(is_array($m->meta_payload) ? $m->meta_payload : null) !== null;
    }

    private function threadQuery(int $teamId)
    {
        $query = CommsWhatsAppThread::query()->where('team_id', $teamId);
        $channelIds = RecruitingChannelResolver::channelIds($teamId);

        if ($channelIds !== []) {
            return $query->whereIn('comms_channel_id', $channelIds);
        }

        // Rueckfall ohne konfiguriertes Konto: dieselbe engere Altmenge wie InboxQuery.
        return $query
            ->whereIn('context_model', [(new RecApplicant)->getMorphClass(), RecApplicant::class, RecEmployee::class])
            ->whereNotNull('context_model_id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows): array
    {
        $applicantMorph = (new RecApplicant)->getMorphClass();
        $isApplicant = fn (array $r) => $r['context_model_id'] !== null
            && in_array($r['context_model'], [$applicantMorph, RecApplicant::class], true);
        $isEmployee = fn (array $r) => $r['context_model_id'] !== null && $r['context_model'] === RecEmployee::class;

        $applicants = RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->whereIn('id', array_column(array_filter($rows, $isApplicant), 'context_model_id'))
            ->get()
            ->keyBy('id');
        $employees = RecEmployee::query()
            ->whereIn('id', array_column(array_filter($rows, $isEmployee), 'context_model_id'))
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $type = 'unassigned';
            $name = null;
            $owner = null;
            $url = null;
            $urlLabel = null;

            if ($isApplicant($row)) {
                $type = 'applicant';
                $applicant = $applicants->get($row['context_model_id']);
                $name = $applicant?->crmContactLinks->first()?->contact?->full_name;
                $owner = $applicant?->owned_by_user_id !== null ? (int) $applicant->owned_by_user_id : null;
                $url = $applicant ? $this->safeRoute('recruiting.applicants.show', ['applicant' => $row['context_model_id']]) : null;
                $urlLabel = 'Bewerberakte';
            } elseif ($isEmployee($row)) {
                $type = 'employee';
                $employee = $employees->get($row['context_model_id']);
                $name = $employee ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : null;
                $url = $employee ? $this->safeRoute('recruiting.employees.show', ['employee' => $row['context_model_id']]) : null;
                $urlLabel = 'MA-Akte';
            }

            $out[] = [
                'thread_id' => $row['thread_id'],
                'message_id' => $row['message_id'],
                'failed_at' => $row['failed_at'],
                'phone' => $row['phone'],
                'what' => $row['what'],
                'reason' => $row['reason'],
                'subject_type' => $type,
                'subject_id' => $type === 'unassigned' ? null : $row['context_model_id'],
                'name' => $name ?: ($row['phone'] !== '' ? $row['phone'] : 'Unbekannt'),
                'owner_user_id' => $owner,
                'url' => $url,
                'url_label' => $urlLabel,
            ];
        }

        return $out;
    }

    private function safeRoute(string $name, array $params): ?string
    {
        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
