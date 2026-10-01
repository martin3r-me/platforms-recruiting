<?php

namespace Platform\Recruiting\Support;

use DateTimeImmutable;

/**
 * Welcher Einsatz gehoert zu einem offenen Punkt — und loest er ueberhaupt
 * etwas aus?
 *
 * Rein: keine Datenbank, keine Uhr. Das Heute wird hereingereicht, die drei
 * Zahlen ebenfalls — sonst koennten die Tests sie nicht ausschreiben, und
 * eine Mutation der Konstante koennte nicht fallen.
 *
 * Ausgeloest wird nur bei AUFTRAG (status_id 1), nicht bei ANGEBOT (0):
 * angefragt ist nicht gebucht, und wer absagt, soll nichts bekommen.
 *
 * Die Mindest-Vorlaufzeit ist keine Bequemlichkeit: eine Nachricht, die am
 * Vorabend „lade deinen Ausweis hoch" sagt, ist keine Hilfe. Der Fall gehoert
 * dann auf die HR-Liste, nicht ins Handy des Mitarbeiters.
 */
final class EinsatzBezug
{
    public const VORLAUF_TAGE     = 4;
    public const ERINNERUNG_TAGE  = 2;
    public const PAUSE_TAGE       = 7;

    private const STATUS_AUFTRAG = 1;

    /** @param array{datum?:string, status_id?:int} $einsatz */
    public static function loestAus(array $einsatz, string $heute, int $vorlaufTage): bool
    {
        if ((int) ($einsatz['status_id'] ?? -1) !== self::STATUS_AUFTRAG) {
            return false;
        }

        $tage = self::tageBis($einsatz['datum'] ?? '', $heute);

        return $tage !== null && $tage >= $vorlaufTage;
    }

    /**
     * ET-7-Korrektur: ohne untere Schranke erfuellte ein VERGANGENER Einsatz
     * (tageBis negativ) "tage <= schwelle" ebenso wie ein kommender — die
     * Erinnerung waere fuer etwas Vorbeies faellig gewesen. "$tage >= 0"
     * schliesst das aus; ein Einsatz HEUTE (tage = 0) loest weiterhin aus.
     *
     * @param array{datum?:string, status_id?:int} $einsatz
     */
    public static function erinnerungFaellig(array $einsatz, string $heute, int $schwelleTage): bool
    {
        if ((int) ($einsatz['status_id'] ?? -1) !== self::STATUS_AUFTRAG) {
            return false;
        }

        $tage = self::tageBis($einsatz['datum'] ?? '', $heute);

        return $tage !== null && $tage >= 0 && $tage <= $schwelleTage;
    }

    /**
     * @param list<array{datum?:string, status_id?:int}> $einsaetze
     * @return array{datum?:string, status_id?:int}|null
     */
    public static function naechster(array $einsaetze, string $heute): ?array
    {
        $kommende = array_filter(
            $einsaetze,
            static fn (array $e) => (self::tageBis($e['datum'] ?? '', $heute) ?? -1) >= 0
        );

        if ($kommende === []) {
            return null;
        }

        usort($kommende, static fn ($a, $b) => ($a['datum'] ?? '') <=> ($b['datum'] ?? ''));

        return $kommende[0];
    }

    /** Tage von heute bis zum Einsatz; null, wenn das Datum unlesbar ist. */
    private static function tageBis(string $datum, string $heute): ?int
    {
        if ($datum === '' || $heute === '') {
            return null;
        }

        try {
            $ziel = new DateTimeImmutable($datum);
            $jetzt = new DateTimeImmutable($heute);
        } catch (\Throwable) {
            // Unlesbar heisst: loest nichts aus. Die sichere Richtung — ein
            // Schrottwert in der Datenbank darf keinen Versand ausloesen.
            return null;
        }

        return (int) $jetzt->setTime(0, 0)->diff($ziel->setTime(0, 0))->format('%r%a');
    }
}
