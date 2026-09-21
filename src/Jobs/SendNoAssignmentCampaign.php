<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignSender;

/**
 * Sammelversand „ohne Einsatz" (Clara, 14.09.2026). Pro Person, in dieser
 * Reihenfolge:
 *  1. Re-Check ueber den Loader (der Stand kann sich seit dem Oeffnen des
 *     Modals geaendert haben: inzwischen disponiert, Telefon weg, ausgeschieden)
 *  2. Senden
 *  3. Fortschritt im Cache
 *
 * Ein Fehlschlag laesst die Person unangetastet und stoppt den Lauf nicht — sie
 * wurde nicht erreicht, mehr ist nicht passiert. Fortschritt liegt unter
 * cacheKey() und wird vom Statistik-Modal gepollt.
 *
 * Kein Auto-Pilot, keine Wartelisten, keine Phasenbewegung: die Empfaenger sind
 * laengst Mitarbeiter, das hier ist eine Nachfrage, kein Bewerbungsschritt.
 */
class SendNoAssignmentCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public const CACHE_TTL_SECONDS = 86400;
    public const MAX_ERRORS_KEPT = 20;

    /**
     * @param list<int> $applicantIds bereits gegen die Termin-Menge „ohne Einsatz" geschnitten
     */
    public function __construct(
        public readonly string $campaignUuid,
        public readonly int $teamId,
        public readonly ?int $userId,
        public readonly int $interviewId,
        public readonly array $applicantIds,
        public readonly int $templateId,
    ) {
    }

    public static function cacheKey(string $uuid): string
    {
        return 'recruiting:no-assignment-campaign:' . $uuid;
    }

    /** @return array{total:int, sent:int, failed:int, skipped:int, done:bool, errors:list<string>} */
    public static function initialProgress(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => false, 'errors' => []];
    }

    /**
     * Wird nach dem letzten (hier: einzigen, $tries = 1) fehlgeschlagenen
     * Versuch aufgerufen — z. B. bei Timeout. Ohne diesen Handler bliebe der
     * Fortschritt bei `done: false` stehen und das Modal pollte endlos weiter,
     * ohne dass jemand erfaehrt, dass der Job abgebrochen ist.
     */
    public function failed(?\Throwable $e): void
    {
        try {
            $this->markFailed(app(Cache::class), 'Job abgebrochen: ' . ($e?->getMessage() ?? 'unbekannt'));
        } catch (\Throwable) {
            // Cache-Ausfall darf failed() nicht seinerseits zum Werfen bringen.
        }
    }

    /** Extrahiert fuer den Test: direkt mit injizierter Cache-Attrappe aufrufbar. */
    public function markFailed(Cache $cache, string $message): void
    {
        $key = self::cacheKey($this->campaignUuid);
        $progress = $cache->get($key) ?? self::initialProgress(count($this->applicantIds));
        $progress['done'] = true;
        $this->keepError($progress, $message);
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    }

    public function handle(Cache $cache, NoAssignmentCampaignRecipients $recipients, NoAssignmentCampaignSender $sender): void
    {
        $key = self::cacheKey($this->campaignUuid);
        $progress = $cache->get($key) ?? self::initialProgress(count($this->applicantIds));

        $rows = $recipients->load($this->teamId, $this->applicantIds, $this->interviewId);

        foreach ($this->applicantIds as $id) {
            $row = $rows[(int) $id] ?? null;

            if ($row === null || $row['selectable'] !== true) {
                $progress['skipped']++;
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            if ($this->templateId <= 0) {
                $progress['skipped']++;
                $this->keepError($progress, $row['name'] . ': kein Template gewählt');
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $applicant = RecApplicant::forTeam($this->teamId)->find((int) $id);
            if ($applicant === null) {
                $progress['skipped']++;
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $result = $sender->send($applicant, $this->templateId, $this->interviewId, $this->campaignUuid, $this->userId);

            if ($result['status'] !== NoAssignmentCampaignSender::STATUS_SENT) {
                $progress['failed']++;
                $this->keepError($progress, $row['name'] . ': ' . ($result['error'] ?? $result['status']));
                $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
                continue;
            }

            $progress['sent']++;
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
        }

        $progress['done'] = true;
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
    }

    private function keepError(array &$progress, string $line): void
    {
        if (count($progress['errors']) < self::MAX_ERRORS_KEPT) {
            $progress['errors'][] = $line;
        }
    }
}
