<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Support\Facades\Log;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDeclineCheck;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Ablauf der Absage-Erkennung fuer EINE eingehende Nachricht (Spec 2026-10-08).
 *
 * Schreibt je betroffener Veranstaltung genau eine Zeile in
 * rec_dispo_decline_checks — auch ohne Treffer (Zaehler, Nachvollziehbarkeit).
 * Fasst NIE eine Einbuchung an: abgesagt wird ausschliesslich ueber das
 * Absage-Fenster der VA (Entscheidung 1).
 */
class DispoDeclineCheckRunner
{
    public function __construct(
        private DispoDeclineCandidates $candidates,
        private DispoDeclineClassifier $classifier,
        private DispoDeclineAlarm $alarm,
    ) {}

    /**
     * @param bool $finalAttempt false = Transportfehler werden geworfen (Job-Retry)
     * @param list<int>|null $channelIds null = Dispo-Kanal-Set des Teams
     */
    public function run(int $messageId, bool $finalAttempt = true, ?array $channelIds = null): void
    {
        $message = CommsWhatsAppMessage::find($messageId);
        if ($message === null || $message->direction !== 'inbound') {
            return;
        }
        // Pruefbar ist, was Text traegt — das CRM legt auch Bildunterschriften
        // und Knopf-Antworten in body. Sprachnachrichten bleiben leer (Grenze).
        $text = trim((string) $message->body);
        if ($text === '') {
            return;
        }
        // Doppelt zugestellter Webhook / Job-Wiederholung nach Erfolg: nie zweimal pruefen.
        if (RecDispoDeclineCheck::query()->where('comms_whatsapp_message_id', $messageId)->exists()) {
            return;
        }

        $thread = CommsWhatsAppThread::find($message->comms_whatsapp_thread_id);
        if ($thread === null) {
            return;
        }

        $found = $this->candidates->forMessage($thread, $message, $channelIds ?? DispoChannelResolver::dispoChannelIds());
        if ($found === null) {
            return;
        }

        $events = RecDispoEvent::query()->whereIn('id', array_keys($found['by_event']))->get()->keyBy('id');
        $base = fn (int $eventId) => [
            'team_id'                   => (int) (config('recruiting.zas.inbound_team_id') ?: 0),
            'filial_nr'                 => $events[$eventId]?->filial_nr,
            'rec_dispo_event_id'        => $eventId,
            'rec_employee_id'           => $found['employee_id'],
            'comms_whatsapp_message_id' => $messageId,
            'excerpt'                   => mb_substr($text, 0, 1000),
        ];

        if (DispoDeclinePrefilter::isObviousAck($text)) {
            foreach (array_keys($found['by_event']) as $eventId) {
                $this->record($base($eventId) + ['outcome' => RecDispoDeclineCheck::OUTCOME_PATTERN_SKIP, 'used_llm' => false]);
            }

            return;
        }

        $list = [];
        foreach ($found['by_event'] as $eventId => $rows) {
            foreach ($rows as $row) {
                $list[] = [
                    'id'            => (int) $row->id,
                    'veranstaltung' => (string) ($events[$eventId]?->name ?? $events[$eventId]?->einsatz_ref ?? ''),
                    'datum'         => $row->datum->format('d.m.Y'),
                    'zeit'          => trim(($row->von ?? '') . ($row->bis ? '–' . $row->bis : '')),
                    'bestaetigt'    => $row->confirmed_at !== null,
                ];
            }
        }

        try {
            $verdict = $this->classifier->classify($thread, $message, $list);
        } catch (\Throwable $e) {
            if (!$finalAttempt) {
                throw $e;
            }
            Log::warning('[DispoDeclineCheck] Einstufung fehlgeschlagen', ['message_id' => $messageId, 'error' => $e->getMessage()]);
            $verdict = null;
        }

        if ($verdict === null) {
            foreach (array_keys($found['by_event']) as $eventId) {
                $this->record($base($eventId) + ['outcome' => RecDispoDeclineCheck::OUTCOME_FAILED, 'used_llm' => true]);
            }

            return;
        }

        $report = DispoDeclineVerdict::shouldReport($verdict);
        $named = array_flip($verdict['assignment_ids']);

        foreach ($found['by_event'] as $eventId => $rows) {
            $eventIds = array_map(fn ($r) => (int) $r->id, $rows);
            $hit = array_values(array_filter($eventIds, fn ($id) => isset($named[$id])));

            // Nennt das Modell keine Einbuchung, gelten alle Kandidaten — lieber
            // einmal zu viel melden; der Disponent waehlt die Tage im Fenster.
            $affected = $verdict['assignment_ids'] === [] ? $eventIds : $hit;
            $reported = $report && $affected !== [];

            $check = $this->record($base($eventId) + [
                'outcome'        => $verdict['absage'] && $affected !== [] ? RecDispoDeclineCheck::OUTCOME_DECLINE : RecDispoDeclineCheck::OUTCOME_NO_DECLINE,
                'used_llm'       => true,
                'confidence'     => $verdict['confidence'],
                'assignment_ids' => $affected,
                'reason'         => $verdict['reason'],
                'review_status'  => $reported ? RecDispoDeclineCheck::REVIEW_OPEN : null,
            ]);

            // Alarm nur fuer die Zeile, die DIESER Lauf angelegt hat: ein
            // paralleler Lauf derselben Nachricht (doppelter Webhook) findet sie
            // per firstOrCreate vor und darf nicht ein zweites Mal alarmieren.
            if ($reported && $check !== null && $check->wasRecentlyCreated && isset($events[$eventId])) {
                try {
                    $alarmId = $this->alarm->send($events[$eventId], $this->name($found['employee_id']), self::dates($rows, $affected));
                    if ($alarmId !== null) {
                        $check->forceFill(['alarm_message_id' => $alarmId])->save();
                    }
                } catch (\Throwable $e) {
                    // Die Markierung steht schon; ein Wurf hier darf weder die
                    // uebrigen VAs dieser Nachricht noch den Job abbrechen (ein
                    // Retry wuerde an der Dublettensperre ohnehin enden).
                    Log::warning('[DispoDeclineCheck] Alarm fehlgeschlagen', ['message_id' => $messageId, 'event_id' => $eventId, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /** @param list<RecDispoAssignment> $rows @param list<int> $ids */
    public static function dates(array $rows, array $ids): string
    {
        $wanted = array_flip($ids);
        $dates = [];
        foreach ($rows as $row) {
            if (isset($wanted[(int) $row->id])) {
                $dates[$row->datum->format('Y-m-d')] = $row->datum->format('d.m.');
            }
        }
        ksort($dates);

        return implode(', ', $dates);
    }

    private function name(int $employeeId): string
    {
        $e = RecEmployee::find($employeeId);

        return $e ? trim($e->first_name . ' ' . $e->last_name) : 'MA #' . $employeeId;
    }

    /** firstOrCreate statt create: zwei parallele Laeufe derselben Nachricht schreiben nicht doppelt (Alarm: siehe wasRecentlyCreated). */
    private function record(array $attributes): ?RecDispoDeclineCheck
    {
        try {
            return RecDispoDeclineCheck::query()->firstOrCreate(
                [
                    'comms_whatsapp_message_id' => $attributes['comms_whatsapp_message_id'],
                    'rec_dispo_event_id'        => $attributes['rec_dispo_event_id'],
                ],
                $attributes,
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Eindeutigkeit gewann ein paralleler Lauf — der hat schon gemeldet.
            return null;
        }
    }
}
