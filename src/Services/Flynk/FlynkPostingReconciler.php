<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecPostingFlynkSync;

class FlynkPostingReconciler
{
    public function __construct(
        private readonly FlynkClient $client,
        private readonly PostingAnzeigeStore $anzeigen,
    ) {
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
            'file_rejected' => 0,
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
            // Endtag zaehlt mit — dieselbe Regel wie RecPosting::scopeOpen().
            $isOpen = $p->status === 'published' && (bool) $p->is_active && !$p->isExpired();
            $hasStarted = $p->hasStarted();

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
            $staleIds = FlynkPostingSyncDecider::staleRowIds($isOpen, $undelivered, $hasStarted);
            if ($staleIds !== []) {
                RecPostingFlynkSync::query()->whereIn('id', $staleIds)->delete();
                $summary['stale_deleted'] += count($staleIds);
                Log::info('flynk: stale sync rows deleted', ['posting_id' => $pid, 'ids' => $staleIds]);
                $rows = array_values(array_filter($rows, fn ($r) => !in_array($r['id'], $staleIds, true)));
            }

            $currentHash = FlynkPostingPayloadBuilder::contentHash($p->title, $p->description, $p->activity, $this->refCodeOf($p), $p->anzeige_hash);
            $deadIds = FlynkPostingSyncDecider::deadPublishRowIds($rows, FlynkPostingSyncDecider::generation($rows), $currentHash);
            if ($deadIds !== []) {
                RecPostingFlynkSync::query()->whereIn('id', $deadIds)->delete();
                $summary['stale_deleted'] += count($deadIds);
                Log::info('flynk: endgueltig gescheiterte publish-Zeile nach Inhaltsaenderung freigegeben', ['posting_id' => $pid, 'ids' => $deadIds]);
                $rows = array_values(array_filter($rows, fn ($r) => !in_array($r['id'], $deadIds, true)));
            }

            $context[$pid] = ['posting' => $p, 'isOpen' => $isOpen, 'hasStarted' => $hasStarted, 'rows' => $rows];
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
                // Frisch gelesen: hat ein ueberlappender Lauf die Zeile inzwischen
                // zugestellt, nicht noch einmal senden.
                if ($model === null || $model->status === 'sent') {
                    continue;
                }
                $task = FlynkPostingPayloadBuilder::build(
                    $this->postingData($ctx['posting'], $row['generation']),
                    $row['event_type'],
                    $careersUrl
                );
                $model->content_hash = $task->contentHash; // Hash des tatsächlich gesendeten Payloads persistieren
                $outcome = $this->dispatch($model, $task->payload, $maxAttempts, $summary, $ctx['posting']);
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

            $contentHash = FlynkPostingPayloadBuilder::contentHash($p->title, $p->description, $p->activity, $this->refCodeOf($p), $p->anzeige_hash);
            $state = FlynkPostingSyncDecider::buildState($rows, $ctx['isOpen'], $contentHash, $ctx['hasStarted']);
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

            $outcome = $this->dispatch($model, $task->payload, $maxAttempts, $summary, $p);
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
    private function dispatch(RecPostingFlynkSync $model, array $payload, int $maxAttempts, array &$summary, RecPosting $posting): string
    {
        // Die Datei geht nur mit „veroeffentlichen"/„aktualisieren" mit — eine
        // „entfernen"-Meldung braucht keine Anzeige.
        $attachment = null;
        if ($model->event_type !== FlynkEvent::CLOSE && $posting->hasAnzeigeDatei()) {
            $contents = $this->anzeigen->contents($posting);
            if ($contents === null) {
                // Datei ist hinterlegt, liegt aber nicht (mehr) auf der Platte.
                // NICHT ohne Datei senden: FLYNK bekaeme eine Anzeige ohne
                // Anzeige, und der Hash haette die Datei als zugestellt gebucht.
                // ENDGUELTIG, nicht „spaeter nochmal": eine fehlende Datei kommt
                // nicht von selbst zurueck. Eine neu hochgeladene (andere) Datei
                // aendert den Hash und loest eine frische Meldung aus.
                $model->attempts = $model->attempts + 1;
                $model->status = 'failed';
                $model->permanent_failure = true;
                $model->last_error = 'Anzeige-Datei nicht lesbar: ' . $posting->anzeige_path;
                $model->save();
                $summary['permanent']++;
                Log::error('flynk: Anzeige-Datei nicht lesbar — nichts gesendet', [
                    'posting_id' => $posting->id, 'path' => $posting->anzeige_path,
                ]);
                return 'ok';
            }
            $attachment = new FlynkAttachment(
                (string) ($posting->anzeige_filename ?: basename((string) $posting->anzeige_path)),
                $contents,
                $posting->anzeige_mime,
            );
        }

        $result = $this->client->createTask($payload, $attachment);
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
            // 201 mit abgelehnter Datei: die Aufgabe LIEGT bei FLYNK (mit Text),
            // also „sent" — ein Retry wuerde dort nur eine zweite Aufgabe anlegen
            // und die Datei genauso abgelehnt bekommen. Der Grund bleibt in
            // last_error stehen und wird an der Ausschreibung angezeigt; eine neu
            // hochgeladene Datei aendert den Hash und geht als Update raus.
            $model->last_error = $result->fileProblem;
            $model->save();
            $summary['sent']++;
            if ($result->fileProblem !== null) {
                $summary['file_rejected']++;
                Log::error('flynk: Aufgabe angelegt, aber Datei nicht uebernommen', [
                    'posting_id' => $posting->id, 'flynk_task_id' => $result->taskId, 'problem' => $result->fileProblem,
                ]);
            }
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
            'published_at' => $p->published_at?->toDateString(),
            // Als Datum, nicht als Zeitstempel: der Endtag zaehlt mit, und FLYNK
            // rechnet mit dem Datum (ihr Beispiel: meta[closes_at]=2026-11-19).
            // Vorher ging hier Mitternacht zu Tagesbeginn als ISO-Zeit raus.
            'closes_at' => $p->closes_at?->toDateString(),
            'anzeige_hash' => $p->anzeige_hash,
            'anzeige_filename' => $p->anzeige_filename,
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
