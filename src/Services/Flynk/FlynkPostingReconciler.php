<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingFlynkSync;

class FlynkPostingReconciler
{
    public function __construct(private readonly FlynkClient $client)
    {
    }

    public function run(): array
    {
        $cap = (int) config('recruiting.flynk.per_run_cap', 50);
        $maxAttempts = (int) config('recruiting.flynk.max_attempts', 5);
        $careersUrl = config('recruiting.flynk.careers_url');
        $careersUrl = is_string($careersUrl) && $careersUrl !== '' ? $careersUrl : null;

        $summary = [
            'sent' => 0, 'retried' => 0, 'stale_deleted' => 0,
            'failed' => 0, 'permanent' => 0, 'skipped' => 0,
        ];
        $sends = 0;
        $abort = false; // 401 → Lauf hart abbrechen
        $stop = false;  // 429 → Lauf höflich beenden

        $publishedIds = RecPosting::query()->where('status', 'published')->pluck('id')->all();
        $syncedIds = RecPostingFlynkSync::query()->distinct()->pluck('rec_posting_id')->all();
        $candidateIds = array_values(array_unique(array_merge($publishedIds, $syncedIds)));
        if ($candidateIds === []) {
            return $summary;
        }

        $postings = RecPosting::query()->with(['position:id,title', 'externalRefs.sourcePlatform'])
            ->whereIn('id', $candidateIds)->get()->keyBy('id');
        $rowsByPosting = RecPostingFlynkSync::query()
            ->whereIn('rec_posting_id', $candidateIds)->get()->groupBy('rec_posting_id');

        // ---- 0/1. Analyze + Revalidate (stale löschen) ----
        $context = [];
        foreach ($postings as $pid => $p) {
            $isOpen = $p->status === 'published' && (bool) $p->is_active
                && ($p->closes_at === null || $p->closes_at->isFuture());

            $rows = $rowsByPosting->get($pid, collect())->map(fn ($r) => [
                'id' => (int) $r->id,
                'generation' => (int) $r->generation,
                'event_type' => $r->event_type,
                'seq' => (int) $r->seq,
                'content_hash' => (string) $r->content_hash,
                'status' => $r->status,
                'attempts' => (int) $r->attempts,
                'permanent_failure' => (bool) $r->permanent_failure,
            ])->all();

            $undelivered = array_values(array_filter(
                $rows,
                fn ($r) => in_array($r['status'], ['pending', 'failed'], true)
            ));
            $staleIds = FlynkPostingSyncDecider::staleRowIds($isOpen, $undelivered);
            if ($staleIds !== []) {
                RecPostingFlynkSync::query()->whereIn('id', $staleIds)->delete();
                $summary['stale_deleted'] += count($staleIds);
                Log::info('flynk: stale sync rows deleted', ['posting_id' => $pid, 'ids' => $staleIds]);
                $rows = array_values(array_filter($rows, fn ($r) => !in_array($r['id'], $staleIds, true)));
            }

            $context[$pid] = ['posting' => $p, 'isOpen' => $isOpen, 'rows' => $rows];
        }

        // ---- 2. Retry-Pass (Vorrang für hängende Zustellungen) ----
        foreach ($context as $pid => $ctx) {
            if ($abort || $stop || $sends >= $cap) {
                break;
            }
            foreach ($ctx['rows'] as $i => $row) {
                if ($abort || $stop || $sends >= $cap) {
                    break;
                }
                if (!in_array($row['status'], ['pending', 'failed'], true) || $row['permanent_failure']) {
                    continue;
                }
                if ($row['attempts'] >= $maxAttempts) {
                    $summary['skipped']++;
                    continue;
                }

                $model = RecPostingFlynkSync::find($row['id']);
                if ($model === null) {
                    continue;
                }
                $task = FlynkPostingPayloadBuilder::build(
                    $this->postingData($ctx['posting'], $row['generation']),
                    $row['event_type'],
                    $careersUrl
                );
                $model->content_hash = $task->contentHash; // Hash des tatsächlich gesendeten Payloads persistieren
                $outcome = $this->dispatch($model, $task->payload, $maxAttempts, $summary);
                $sends++;
                $summary['retried']++;
                $context[$pid]['rows'][$i]['status'] = $model->status; // in-memory für Detect-Pass syncen
                $context[$pid]['rows'][$i]['content_hash'] = $task->contentHash;
                if ($outcome === 'abort') {
                    $abort = true;
                    break;
                }
                if ($outcome === 'stop') {
                    $stop = true;
                    break;
                }
            }
        }

        // ---- 3. Detect-Pass ----
        foreach ($context as $pid => $ctx) {
            if ($abort || $stop || $sends >= $cap) {
                break;
            }
            $p = $ctx['posting'];
            $rows = $ctx['rows'];

            $contentHash = FlynkPostingPayloadBuilder::contentHash($p->title, $p->description, $p->activity, $this->refCodeOf($p));
            $state = FlynkPostingSyncDecider::buildState($rows, $ctx['isOpen'], $contentHash);
            $event = FlynkPostingSyncDecider::decide($state);
            if ($event === null) {
                continue;
            }

            $gen = $state->generation;
            $seq = $event === FlynkEvent::UPDATE
                ? FlynkPostingSyncDecider::nextUpdateSeq($this->updateSeqs($rows, $gen))
                : 0;

            $task = FlynkPostingPayloadBuilder::build($this->postingData($p, $gen), $event, $careersUrl);

            // Claim: nur bei frischem Insert senden (affectedRows === 1)
            $inserted = RecPostingFlynkSync::query()->insertOrIgnore([
                'rec_posting_id' => $pid,
                'generation' => $gen,
                'event_type' => $event,
                'seq' => $seq,
                'content_hash' => $task->contentHash,
                'status' => 'pending',
                'attempts' => 0,
                'permanent_failure' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                $summary['skipped']++;
                continue;
            }

            $model = RecPostingFlynkSync::query()
                ->where('rec_posting_id', $pid)->where('generation', $gen)
                ->where('event_type', $event)->where('seq', $seq)->first();
            if ($model === null) {
                continue;
            }

            $outcome = $this->dispatch($model, $task->payload, $maxAttempts, $summary);
            $sends++;
            if ($outcome === 'abort') {
                $abort = true;
                break;
            }
            if ($outcome === 'stop') {
                $stop = true;
                break;
            }
        }

        if ($abort) {
            Log::error('flynk: Lauf abgebrochen (401) — RECRUITING_FLYNK_TOKEN prüfen');
        } elseif ($stop) {
            Log::info('flynk: Lauf beendet (429 Rate-Limit) — Rest im nächsten Lauf');
        }

        return $summary;
    }

    /** @return 'ok'|'abort'|'stop' */
    private function dispatch(RecPostingFlynkSync $model, array $payload, int $maxAttempts, array &$summary): string
    {
        $result = $this->client->createTask($payload);
        $model->http_status = $result->httpStatus;

        if ($result->unauthorized) {
            // NICHT permanent — globales Token-Problem, Zeile bleibt retrybar.
            // Zählt NICHT gegen das Retry-Budget der Zeile (kein attempts++).
            $model->last_error = 'unauthorized (401)';
            $model->save();
            return 'abort';
        }

        if ($result->rateLimited) {
            // Globales Rate-Limit-Problem, zählt ebenfalls NICHT gegen das Retry-Budget.
            $model->last_error = 'rate_limited (429)';
            $model->save();
            return 'stop';
        }

        $model->attempts = $model->attempts + 1;

        if ($result->ok) {
            $model->status = 'sent';
            $model->flynk_task_id = $result->taskId;
            $model->sent_at = now();
            $model->last_error = null;
            $model->save();
            $summary['sent']++;
            return 'ok';
        }

        if ($result->permanent) {
            $model->status = 'failed';
            $model->permanent_failure = true;
            $model->last_error = $result->error;
            $model->save();
            $summary['permanent']++;
            return 'ok';
        }

        // transient (5xx / Netzwerk)
        $model->status = $model->attempts >= $maxAttempts ? 'failed' : 'pending';
        $model->last_error = $result->error;
        $model->save();
        $summary['failed']++;
        return 'ok';
    }

    private function refCodeOf(RecPosting $p): ?string
    {
        return $p->externalRefs
            ->sortBy('id')
            ->first(fn ($r) => $r->sourcePlatform?->ref_parser === 'ref_code')
            ?->external_ref;
    }

    private function postingData(RecPosting $p, int $generation): array
    {
        return [
            'uuid' => $p->uuid,
            'title' => $p->title,
            'description' => $p->description,
            'activity' => $p->activity,
            'ref_code' => $this->refCodeOf($p),
            'position_title' => $p->position?->title,
            'team_id' => $p->team_id,
            'generation' => $generation,
            'closes_at' => $p->closes_at?->toIso8601String(),
        ];
    }

    /** @return int[] */
    private function updateSeqs(array $rows, int $gen): array
    {
        $seqs = [];
        foreach ($rows as $r) {
            if ($r['event_type'] === FlynkEvent::UPDATE && (int) $r['generation'] === $gen) {
                $seqs[] = (int) $r['seq'];
            }
        }
        return $seqs;
    }
}
