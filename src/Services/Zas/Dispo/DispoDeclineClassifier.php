<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Core\Models\CoreAiProvider;
use Platform\Core\Services\OpenAiService;
use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;

/**
 * Laesst das Sprachmodell einstufen, ob eine Nachricht eine Absage ist (Spec
 * 2026-10-08, Entscheidung 6). Das Modell waehlt Einbuchungen NUR aus der
 * mitgegebenen Liste; gelesen wird ueber DispoDeclineVerdict.
 *
 * Wirft bei Transportfehlern (der Job versucht es erneut); eine unlesbare
 * Antwort ist dagegen kein voruebergehender Fehler und ergibt null.
 */
class DispoDeclineClassifier
{
    /** So viele Nachrichten davor gehen als Kontext mit (inkl. der Bestaetigungs-Anfrage). */
    private const CONTEXT_MESSAGES = 6;

    public function __construct(private DispoThreadDirectory $threads) {}

    /**
     * @param list<array{id:int, veranstaltung:string, datum:string, zeit:string, bestaetigt:bool}> $candidates
     * @return array{absage:bool, confidence:string, assignment_ids:list<int>, reason:string}|null
     */
    public function classify(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message, array $candidates): ?array
    {
        // tools/with_context aus: sonst haengt OpenAiService alle Plattform-
        // Werkzeuge und einen eigenen Assistenten-Systemtext an — das Modell kann
        // dann mit einem Werkzeug-Aufruf statt mit Text antworten. Das Budget
        // deckt bei Denk-Modellen auch die Denk-Tokens; die Antwort selbst ist kurz.
        $result = app(OpenAiService::class)->chat($this->prompt($thread, $message, $candidates), $this->determineModel(), [
            'max_tokens'   => 1000,
            'tools'        => false,
            'with_context' => false,
        ]);

        $content = trim((string) ($result['content'] ?? ''));
        if ($content === '') {
            // Leere Antwort = Kontingent, Abbruch oder Werkzeug-Aufruf — ein
            // Fall fuer die Wiederholung, nicht fuer "nicht lesbar".
            throw new \RuntimeException('Leere Antwort des Sprachmodells');
        }

        return DispoDeclineVerdict::parse($content, array_map(fn ($c) => (int) $c['id'], $candidates));
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return list<array{role:string, content:string}>
     */
    public function prompt(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message, array $candidates): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'Du pruefst fuer eine Personaldisposition, ob ein Mitarbeiter mit seiner WhatsApp-Nachricht '
                    . 'einen oder mehrere geplante Einsaetze ABSAGT. Antworte NUR mit JSON: '
                    . '{"absage": true|false, "sicherheit": "high"|"medium"|"low", "einbuchungen": [<ids>], "grund": "<max 200 Zeichen, deutsch>"}. '
                    . 'absage=true, wenn die Person erkennbar zu mindestens einem Einsatz nicht oder nur teilweise kommt '
                    . '(Absage, Krankmeldung, kann nicht, kommt deutlich spaeter oder geht frueher). '
                    . 'Teilweise oder unklare Faelle: absage=true mit sicherheit=medium, im grund erklaeren. '
                    . 'Zusagen, Danke, Rueckfragen ohne Absage, Smalltalk: absage=false. '
                    . 'sicherheit=high nur bei eindeutiger Absage. '
                    . 'einbuchungen: nur ids aus der Liste, und nur wenn die Nachricht bestimmte Tage betrifft; '
                    . 'ist nicht erkennbar welche, gib eine leere Liste.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'verlauf'     => $this->context($thread, $message),
                    'nachricht'   => mb_substr(trim((string) $message->body), 0, 2000),
                    'einbuchungen' => $candidates,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    /** @return list<string> "Wir: …" / "MA: …" — die letzten Nachrichten VOR der geprueften. */
    private function context(CommsWhatsAppThread $thread, CommsWhatsAppMessage $message): array
    {
        $since = \Illuminate\Support\Carbon::parse($message->created_at ?? now())->subDays(14);
        $rows = array_values(array_filter(
            $this->threads->messages($thread, [], $since),
            fn ($m) => (int) $m['id'] < (int) $message->id && trim((string) $m['body']) !== '',
        ));

        return array_map(
            fn ($m) => ($m['direction'] === 'inbound' ? 'MA: ' : 'Wir: ') . mb_substr((string) $m['body'], 0, 600),
            array_slice($rows, -self::CONTEXT_MESSAGES),
        );
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
