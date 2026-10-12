<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Recruiting\Models\RecDispoJobOffer;
use Platform\Recruiting\Models\RecDispoJobRequest;
use Platform\Recruiting\Models\RecDispoJobWave;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobKandidaten;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobOfferSender;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobRecipientPlanner;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine Welle „Jobs anbieten". Geplant wird erst beim Start (nicht beim Anlegen):
 * Stand der Gebuchten, Sperren und Schichten ist der von jetzt. Fortschritt im
 * Cache unter cacheKey(), Endstand zusaetzlich als summary an der Welle.
 */
class SendDispoJobWave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public const CACHE_TTL_SECONDS = 86400;
    public const MAX_ERRORS_KEPT = 20;

    public function __construct(
        public readonly int $waveId,
        public readonly int $teamId,
        public readonly ?int $userId,
    ) {
    }

    public static function cacheKey(string $uuid): string
    {
        return 'recruiting:dispo-job-wave:' . $uuid;
    }

    /** @return array{total:int, sent:int, failed:int, skipped:int, done:bool, errors:list<string>} */
    public static function initialProgress(int $total): array
    {
        return ['total' => $total, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'done' => false, 'errors' => []];
    }

    /** Nach dem einzigen fehlgeschlagenen Versuch (z. B. Timeout): Fortschritt auf done, damit das UI nicht endlos pollt. */
    public function failed(?\Throwable $e): void
    {
        try {
            $this->markFailed(app(Cache::class), 'Job abgebrochen: ' . ($e?->getMessage() ?? 'unbekannt'));
        } catch (\Throwable) {
            // Cache-Ausfall darf failed() nicht seinerseits zum Werfen bringen.
        }
    }

    public function markFailed(Cache $cache, string $message): void
    {
        $wave = RecDispoJobWave::query()->find($this->waveId);
        $progress = self::initialProgress(0);
        if ($wave !== null && !empty($wave->job_uuid)) {
            $key = self::cacheKey($wave->job_uuid);
            $progress = $cache->get($key) ?? $progress;
        }
        $progress['done'] = true;
        $this->keepError($progress, $message);
        if ($wave !== null && !empty($wave->job_uuid)) {
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
        }
        // Welle schliessen, sonst gilt sie im UI fuer immer als laufend.
        try {
            if ($wave !== null && $wave->finished_at === null) {
                $wave->update(['finished_at' => now(), 'summary' => $progress]);
            }
        } catch (\Throwable) {
            // DB-Ausfall darf failed() nicht seinerseits zum Werfen bringen.
        }
    }

    public function handle(Cache $cache, DispoJobOfferSender $sender): void
    {
        $wave = RecDispoJobWave::query()->with('request')->find($this->waveId);
        if ($wave === null || $wave->started_at !== null) {
            return;
        }
        if ($wave->scheduled_for !== null && $wave->scheduled_for->isFuture()) {
            return; // verschoben/abgebrochen — der Disponent hat eine neue Welle geplant
        }
        $request = $wave->request;
        if ($request === null || $request->closed_at !== null) {
            return;
        }

        // Atomar beanspruchen: zwei Zustellungen derselben Welle duerfen nicht beide durch.
        $claimed = RecDispoJobWave::query()->whereKey($wave->id)->whereNull('started_at')
            ->update(['started_at' => now(), 'job_uuid' => $wave->job_uuid ?: (string) UuidV7::generate()]);
        if ($claimed === 0) {
            return;
        }
        $wave->refresh();
        $key = self::cacheKey($wave->job_uuid);

        $schichten = $request->shifts()->whereNull('closed_at')->whereDate('datum', '>=', now()->toDateString())->get()
            ->map(fn ($s) => ['id' => (int) $s->id, 'datum' => $s->datum->format('Y-m-d'), 'taetigkeit' => (string) $s->taetigkeit])->all();
        $tage = array_values(array_unique(array_column($schichten, 'datum')));
        $daten = DispoJobKandidaten::laden($this->teamId, $tage, now()->format('Y-m'));
        $schon = RecDispoJobOffer::query()->where('request_id', $request->id)->pluck('anchor_employee_id')
            ->mapWithKeys(fn ($v) => [(int) $v => true])->all();
        $plan = DispoJobRecipientPlanner::plan($daten['kandidaten'], $schichten, $daten['einsaetze'], $daten['gebucht'], $schon, [
            'gruppen' => (array) $wave->gruppen, 'bloecke' => (array) $wave->ma_bloecke, 'nur_passend' => (bool) $wave->nur_passende_taetigkeit,
        ]);

        $progress = self::initialProgress(count($plan['recipients']));
        $progress['skipped'] = array_sum($plan['skipped']);
        $progress['skipped_toepfe'] = $plan['skipped'];
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);

        if ($request->status === RecDispoJobRequest::STATUS_ENTWURF) {
            $request->update(['status' => RecDispoJobRequest::STATUS_LAEUFT]);
        }

        // Pakete, damit der Fortschritt waehrend des Laufs sichtbar ist.
        foreach (array_chunk($plan['recipients'], 10) as $paket) {
            if ($request->whatsapp_template_id === null) {
                $progress['failed'] += count($paket);
                $this->keepError($progress, 'keine Vorlage gewählt');
                continue;
            }
            $r = $sender->send($wave, $paket, (int) $request->whatsapp_template_id);
            if (!$r['ok']) {
                $progress['failed'] += count($paket);
                $this->keepError($progress, (string) $r['message']);
            } else {
                $progress['sent'] += $r['sent'];
                $progress['failed'] += count($r['failed']);
                foreach ($r['failed'] as $f) {
                    $this->keepError($progress, 'MA ' . $f['anchor'] . ': ' . $f['error']);
                }
            }
            $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
        }

        $progress['done'] = true;
        $cache->put($key, $progress, self::CACHE_TTL_SECONDS);
        $wave->update(['finished_at' => now(), 'summary' => $progress]);
    }

    private function keepError(array &$progress, string $line): void
    {
        if (count($progress['errors']) < self::MAX_ERRORS_KEPT) {
            $progress['errors'][] = $line;
        }
    }
}
