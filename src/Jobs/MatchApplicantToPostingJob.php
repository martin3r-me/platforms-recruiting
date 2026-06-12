<?php

namespace Platform\Recruiting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\CoreAiProvider;
use Platform\Core\Services\OpenAiService;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Services\ApplicationMatchingService;
use Platform\Recruiting\Services\IncomingApplicationService;
use Platform\Recruiting\Services\MatchResult;

class MatchApplicantToPostingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = 60;

    public function __construct(
        private int $applicantId,
        private int $channelId,
        private ?string $subject,
        private ?string $body,
    ) {
    }

    public function handle(ApplicationMatchingService $matching, IncomingApplicationService $applications): void
    {
        $applicant = RecApplicant::find($this->applicantId);

        // Schon zugeordnet (manuell/parallel) oder gelöscht → nichts tun
        if (!$applicant || !$applicant->is_unrouted || $applicant->postings()->exists()) {
            return;
        }

        $channel = CommsChannel::find($this->channelId);

        // Kandidaten: offene Ausschreibungen, älteste zuerst (Regel "Ort unklar → älteste offene")
        $candidates = RecPosting::query()
            ->forTeam($applicant->team_id)
            ->open()
            ->with('position')
            ->orderBy('id')
            ->get();

        $llm = $candidates->isEmpty() ? null : $this->askLlm($applicant, $candidates);

        // Stufe 2a: LLM sagt "keine Bewerbung" → kein Auto-Discard, aber Inbox mit Begründung
        if ($llm && $llm['is_application'] === false) {
            $applicant->forceFill([
                'match_reason' => 'Vermutlich keine Bewerbung: ' . $llm['reason'],
            ])->save();
            return;
        }

        // Stufe 2b: hohe Konfidenz → automatisch zuordnen
        if ($llm && $llm['posting'] && $llm['confidence'] === 'high') {
            $applications->assignPosting(
                $applicant,
                new MatchResult($llm['posting'], MatchResult::VIA_LLM, 'high', $llm['reason']),
            );
            return;
        }

        // Stufe 3: Kanal-Default
        if ($channel && ($default = $matching->defaultPostingForChannel($channel))) {
            $applications->assignPosting(
                $applicant,
                new MatchResult($default, MatchResult::VIA_CHANNEL_DEFAULT),
            );
            return;
        }

        // Stufe 4: Inbox mit Vorschlag (falls vorhanden)
        if ($llm && $llm['posting']) {
            $applicant->forceFill([
                'suggested_posting_id' => $llm['posting']->id,
                'match_reason' => $llm['reason'],
            ])->save();
        }
    }

    /**
     * Ein einzelner Klassifikations-Call, strikt validiert.
     * Liefert null bei unparsbarer Antwort; transiente Fehler werden bis zum letzten
     * Versuch retried, danach läuft die Pipeline ohne LLM weiter (Stufe 3/4).
     *
     * @return array{is_application: bool, posting: ?RecPosting, confidence: string, reason: string}|null
     */
    private function askLlm(RecApplicant $applicant, \Illuminate\Database\Eloquent\Collection $candidates): ?array
    {
        $list = $candidates->map(fn (RecPosting $p) => [
            'uuid' => $p->uuid,
            'titel' => $p->title,
            'stelle' => $p->position?->title,
            'ort' => $p->position?->location,
            'beschreibung' => mb_substr((string) $p->description, 0, 300),
        ])->values()->all();

        $messages = [
            [
                'role' => 'system',
                'content' => 'Du ordnest eingehende Bewerbungen der passenden Stellenanzeige zu. '
                    . 'Antworte AUSSCHLIESSLICH mit einem JSON-Objekt, ohne Markdown: '
                    . '{"is_application": true|false, "posting_uuid": "<uuid aus der Liste>"|null, '
                    . '"confidence": "high"|"medium"|"low", "reason": "<max 200 Zeichen, deutsch>"}. '
                    . 'Waehle posting_uuid NUR aus der mitgegebenen Liste. '
                    . 'confidence=high nur, wenn die Rolle eindeutig zu genau einer Anzeige passt. '
                    . 'Wenn dieselbe Rolle an mehreren Orten ausgeschrieben ist und der Ort unklar bleibt: '
                    . 'waehle die ERSTE passende Anzeige in der Liste mit confidence=high. '
                    . 'is_application=false fuer Systemmails, Newsletter, Spam.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'nachricht' => [
                        'betreff' => $this->subject,
                        'text' => mb_substr((string) $this->body, 0, 4000),
                    ],
                    'offene_ausschreibungen' => $list,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $result = app(OpenAiService::class)->chat($messages, $this->determineModel(), [
                'max_tokens' => 300,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MatchApplicantToPostingJob] LLM call failed', [
                'applicant_id' => $applicant->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            // Bei transienten Fehlern erst Job-Retry; erst nach letztem Versuch
            // ohne LLM weitermachen (Stufe 3/4 statt Datenverlust).
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            return null;
        }

        $raw = trim((string) ($result['content'] ?? ''));
        // tolerant gegen ```json ... ```-Wrapper
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
        $json = json_decode(trim((string) $raw), true);

        if (!is_array($json)) {
            Log::warning('[MatchApplicantToPostingJob] LLM response not parseable', [
                'applicant_id' => $applicant->id,
                'raw' => mb_substr($raw, 0, 500),
            ]);
            return null;
        }

        $posting = null;
        if (!empty($json['posting_uuid'])) {
            // NUR UUIDs aus der Kandidatenliste akzeptieren (Manipulationsschutz)
            $posting = $candidates->firstWhere('uuid', $json['posting_uuid']);
        }

        $confidence = in_array($json['confidence'] ?? null, ['high', 'medium', 'low'], true)
            ? $json['confidence']
            : 'low';

        return [
            'is_application' => ($json['is_application'] ?? true) !== false,
            'posting' => $posting,
            'confidence' => $confidence,
            'reason' => mb_substr((string) ($json['reason'] ?? ''), 0, 500),
        ];
    }

    private function determineModel(): string
    {
        try {
            $provider = CoreAiProvider::where('key', 'openai')->where('is_active', true)->with('defaultModel')->first();
            $fallback = $provider?->defaultModel?->model_id;
            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        } catch (\Throwable $e) {
        }

        return 'gpt-5.2';
    }
}
