<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Models\RecInterviewWaitlist;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignSender;
use Platform\Recruiting\Support\CampaignSegment;

/**
 * Kampagne „Neue Termine“ (Spec §5.4). Pro Bewerber, in dieser Reihenfolge:
 *  1. Re-Check ueber den Loader (Stand kann sich seit dem Oeffnen des Modals
 *     geaendert haben: inzwischen gebucht, Telefon weg, Team-fremd)
 *  2. Template nach Segment (A = Formular, B = Terminauswahl)
 *  3. Senden
 *  4. Offene Ort-Wartelisten-Eintraege schliessen — NUR bei Erfolg
 *  5. Fortschritt im Cache
 *
 * KEIN Re-Arm des Auto-Piloten beim Versand (Kundenentscheid 28.08.): die
 * Kampagne ist eine einzelne Nachricht, keine neue Erinnerungskette. Wer
 * reagiert (bucht, Formular ausfuellt), setzt den Auto-Pilot selbst wieder in
 * Gang — RecApplicant::registerSelfServiceReaction() bzw. der Formular-Save.
 *
 * Ein Fehlschlag laesst den Zustand der Person unangetastet: sie wurde nicht
 * erreicht, also bleibt sie, wie sie war. Fortschritt liegt unter cacheKey()
 * und wird vom Statistik-Modal gepollt.
 */
class SendNewDatesCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public const CACHE_TTL_SECONDS = 86400;
    public const MAX_ERRORS_KEPT = 20;

    /**
     * @param list<int> $applicantIds bereits gegen Kohorte und Waehlbarkeit geschnitten (CampaignSegment::selectedIds)
     */
    public function __construct(
        public readonly string $campaignUuid,
        public readonly int $teamId,
        public readonly ?int $userId,
        public readonly array $applicantIds,
        public readonly ?int $templateAId,
        public readonly ?int $templateBId,
    ) {
    }

    public static function cacheKey(string $uuid): string
    {
        return 'recruiting:campaign:' . $uuid;
    }

    /** @return array{total:int, sent:int, failed:int, skipped:int, done:bool, errors:list<string>} */
    public static function initialProgress(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => false, 'errors' => []];
    }

    /**
     * Wird nach dem letzten (hier: einzigen, $tries = 1) fehlgeschlagenen
     * Versuch aufgerufen — z. B. bei Timeout oder einer nicht abgefangenen
     * Exception in handle(). Ohne diesen Handler bliebe der Fortschritt im
     * Cache bei `done: false` stehen und das Statistik-Modal poll(t) endlos
     * weiter, ohne dass die Person je erfaehrt, dass der Job abgebrochen ist.
     *
     * Guard in try/catch: ein Cache-Ausfall darf hier selbst keine Exception
     * werfen (failed() liegt im Fehlerbehandlungspfad der Queue).
     */
    public function failed(?\Throwable $e): void
    {
        try {
            $this->markFailed(app(Cache::class), 'Job abgebrochen: ' . ($e?->getMessage() ?? 'unbekannt'));
        } catch (\Throwable) {
            // Cache-Ausfall darf failed() nicht seinerseits zum Werfen bringen.
        }
    }

    /**
     * Extrahiert fuer den Test: direkt mit einer injizierten Cache-Attrappe
     * aufrufbar, ohne den Container/Queue-Failure-Pfad nachzubauen.
     */
    public function markFailed(Cache $cache, string $message): void
    {
        $key = self::cacheKey($this->campaignUuid);
        $progress = $cache->get($key) ?? self::initialProgress(count($this->applicantIds));
        $progress['done'] = true;
        $this->keepError($progress, $message);
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    }

    public function handle(Cache $cache, NewDatesCampaignRecipients $recipients, NewDatesCampaignSender $sender): void
    {
        $key = self::cacheKey($this->campaignUuid);
        $progress = $cache->get($key) ?? self::initialProgress(count($this->applicantIds));
        $now = new \DateTimeImmutable();

        $rows = $recipients->load($this->teamId, $this->applicantIds, $now);

        foreach ($this->applicantIds as $id) {
            $row = $rows[(int) $id] ?? null;

            if ($row === null || $row['selectable'] !== true) {
                $progress['skipped']++;
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $templateId = $row['template'] === CampaignSegment::TEMPLATE_FORM ? $this->templateAId : $this->templateBId;
            if (!$templateId) {
                $progress['skipped']++;
                $this->keepError($progress, $row['name'] . ': kein Template ' . $row['template'] . ' gewählt');
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $applicant = RecApplicant::forTeam($this->teamId)->find((int) $id);
            if ($applicant === null) {
                $progress['skipped']++;
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $result = $sender->send($applicant, (int) $templateId, $row['template'], $this->campaignUuid, $this->userId);

            if ($result['status'] !== NewDatesCampaignSender::STATUS_SENT) {
                $progress['failed']++;
                $this->keepError($progress, $row['name'] . ': ' . ($result['error'] ?? $result['status']));
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            // Bewusst KEIN Re-Arm des Auto-Piloten (Kundenentscheid 28.08.):
            // wer die Nachricht nur liest, soll nicht zwei Erinnerungen
            // hinterher bekommen. Der Auto-Pilot geht erst bei einer Reaktion
            // wieder an — Buchung (RecApplicant::registerSelfServiceReaction,
            // aufgerufen von Public/InterviewBooking) oder Formular-Save
            // (Core ruft checkAutoPilotCompletion, der Aufstieg setzt den
            // Status zurueck).
            $this->closeOrtWaitlist($applicant);

            $progress['sent']++;
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
        }

        $progress['done'] = true;
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    }

    /**
     * Offene Ort-Eintraege schliessen — die Kampagne IST die Benachrichtigung,
     * und ein offener Eintrag wuerde den re-armten Auto-Pilot sofort wieder
     * pausieren (ProcessAutoPilotApplicants::170). Termin-Abos
     * (rec_interview_id gesetzt) bleiben: die haben eigene Re-Arm-Logik.
     */
    private function closeOrtWaitlist(RecApplicant $applicant): void
    {
        $closed = RecInterviewWaitlist::query()
            ->where('rec_applicant_id', $applicant->id)
            ->ortBased()
            ->open()
            ->update(['cancelled_at' => now()]);

        if ($closed > 0) {
            try {
                $log = new RecAutoPilotLog([
                    'rec_applicant_id' => $applicant->id,
                    'type' => 'waitlist_replaced',
                    'summary' => 'Ort-Warteliste durch Kampagne „Neue Termine“ abgelöst (' . $closed . ' Eintrag/Einträge geschlossen).',
                    'details' => ['campaign' => $this->campaignUuid],
                ]);
                $log->created_at = now();
                $log->save();
            } catch (\Throwable) {
                // Log darf den Versand nicht kippen.
            }
        }
    }

    private function keepError(array &$progress, string $line): void
    {
        if (count($progress['errors']) < self::MAX_ERRORS_KEPT) {
            $progress['errors'][] = $line;
        }
    }
}
