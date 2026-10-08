<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Liest die Antwort des Sprachmodells (Spec 2026-10-08, Entscheidung 6).
 *
 * Tolerant gegen Prosa und ```json-Huellen, streng bei den Werten: nur ids
 * aus der Kandidatenliste werden uebernommen (Manipulationsschutz wie in
 * MatchApplicantToPostingJob), unbekannte Sicherheit zaehlt als "low".
 */
final class DispoDeclineVerdict
{
    public const CONFIDENCES = ['high', 'medium', 'low'];

    /**
     * @param list<int> $candidateIds
     * @return array{absage:bool, confidence:string, assignment_ids:list<int>, reason:string}|null null = nicht lesbar
     */
    public static function parse(string $raw, array $candidateIds): ?array
    {
        if (preg_match('/\{.*\}/s', $raw, $m) !== 1) {
            return null;
        }
        $json = json_decode($m[0], true);
        if (!is_array($json) || !array_key_exists('absage', $json) || !is_bool($json['absage'])) {
            return null;
        }

        $confidence = in_array($json['sicherheit'] ?? null, self::CONFIDENCES, true) ? $json['sicherheit'] : 'low';

        $allowed = array_flip(array_map('intval', $candidateIds));
        $ids = [];
        foreach ((array) ($json['einbuchungen'] ?? []) as $id) {
            if (is_numeric($id) && isset($allowed[(int) $id])) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return [
            'absage'         => $json['absage'],
            'confidence'     => $confidence,
            'assignment_ids' => array_values($ids),
            'reason'         => mb_substr(trim((string) ($json['grund'] ?? '')), 0, 200),
        ];
    }

    /** Gemeldet wird bei Absage mit Sicherheit high oder medium; low wird nur protokolliert. */
    public static function shouldReport(array $verdict): bool
    {
        return ($verdict['absage'] ?? false) === true
            && in_array($verdict['confidence'] ?? 'low', ['high', 'medium'], true);
    }
}
