<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Automatische Folgewellen (Spec 2026-10-27 §2.4, Markus: „20er-Bloecke
 * abarbeiten, Masse eindaemmen“). Rein rechnend; der Versand-Job ruft nur auf.
 *
 * Eine Folgewelle entsteht nur, wenn die Anfrage offen ist, ein Abstand
 * eingestellt ist, hinter der Obergrenze noch jemand wartet und mindestens
 * eine Schicht nicht gedeckt ist. Alles andere ist ein Stopp — und der steht
 * mit Grund in der Zusammenfassung der Welle.
 */
final class DispoJobFolgewelle
{
    public const GRUENDE = [
        'bedarf_gedeckt' => 'Bedarf gedeckt — keine weiteren Wellen',
        'niemand_uebrig' => 'Niemand mehr übrig — keine weiteren Wellen',
    ];

    /** @return ?int Minuten bis zur naechsten Welle, null = keine */
    public static function entscheiden(bool $anfrageOffen, ?int $abstandMinuten, int $rest, bool $alleGedeckt): ?int
    {
        if (!$anfrageOffen || $alleGedeckt || $rest <= 0 || $abstandMinuten === null || $abstandMinuten <= 0) {
            return null;
        }

        return $abstandMinuten;
    }

    /** Warum diese Welle die letzte ist (null = kein Stopp). */
    public static function gestopptGrund(bool $alleGedeckt, int $empfaenger, int $rest): ?string
    {
        if ($alleGedeckt) {
            return 'bedarf_gedeckt';
        }
        if ($empfaenger === 0 && $rest === 0) {
            return 'niemand_uebrig';
        }

        return null;
    }
}
