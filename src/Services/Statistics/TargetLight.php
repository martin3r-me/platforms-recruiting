<?php

namespace Platform\Recruiting\Services\Statistics;

use Platform\Recruiting\Support\YmdDate;

/**
 * Ampel-Logik der Ausschreibungs-Tabelle (Spec §7).
 *
 * Zwei Ampeln mit absichtlich unterschiedlichem Bezug:
 *
 *  - pipeline(): Bewerbungen gegen Bedarf x Faktor, als HOCHRECHNUNG auf das
 *    Laufzeitende. Grund: dieselbe Zahl bedeutet je nach Restlaufzeit Alarm
 *    oder Plan — 33 Bewerbungen sind bei drei Wochen Restlaufzeit ein Problem
 *    und bei sechs Monaten im Plan. Eine absolute Schwelle stuende am
 *    Kampagnenanfang immer auf Rot und am Ende immer auf Gruen und beantwortet
 *    damit die Frage nicht, fuer die die Ampel gebaut wird.
 *    Mathematisch ist die Hochrechnung identisch mit "Ist / Soll-Fortschritt";
 *    kommuniziert wird die Hochrechnung, weil "44 von 280" verstaendlich ist
 *    und "16 % des Soll-Fortschritts" nicht.
 *
 *  - fulfilment(): Unterschriften gegen Bedarf, ABSOLUT. Keine Hochrechnung,
 *    weil Unterschriften nicht gleichmaessig eintreffen, sondern schubweise
 *    nach jeder Schulung — ein linearer Verlauf waere irreführend.
 *
 * Nichts wird geraten: fehlt Bedarf oder Faktor, ist die Ampel grau.
 */
final class TargetLight
{
    public const GREY = 'grey';
    public const RED = 'red';
    public const YELLOW = 'yellow';
    public const GREEN = 'green';

    /** Unter dieser Laufzeit ist jede Hochrechnung Kaffeesatz. */
    public const MIN_DAYS = 7;

    private const THRESHOLD_YELLOW = 60;
    private const THRESHOLD_GREEN = 90;

    /**
     * @return array{status:string, pct:?int, projected:?int, target:?int, reason:string}
     */
    public static function pipeline(
        int $bewerbungen,
        ?int $bedarf,
        ?float $faktor,
        ?string $publishedYmd,
        ?string $closesYmd,
        string $todayYmd,
    ): array {
        if ($bedarf === null || $faktor === null || $bedarf <= 0 || $faktor <= 0) {
            return self::grey('Bedarf oder Faktor ist an dieser Ausschreibung nicht gepflegt.');
        }

        // Aufrunden: 22,5 noetige Bewerbungen heisst 23, nicht 22.
        $target = (int) ceil($bedarf * $faktor);

        $elapsed = ($publishedYmd !== null && $closesYmd !== null)
            ? YmdDate::daysBetween($publishedYmd, $todayYmd)
            : null;
        $total = ($publishedYmd !== null && $closesYmd !== null)
            ? YmdDate::daysBetween($publishedYmd, $closesYmd)
            : null;

        // Keine oder unbrauchbare Laufzeit -> absolute Lesart statt gar nichts.
        if ($elapsed === null || $total === null || $total <= 0) {
            return self::rate(
                $bewerbungen,
                $target,
                null,
                'Kein Start- oder Laufzeitende gepflegt — verglichen wird gegen das Gesamtziel '
                . "von {$target} Bewerbungen, ohne Hochrechnung.",
            );
        }

        if ($elapsed < 0) {
            return self::grey('Die Ausschreibung startet erst — noch keine Aussage möglich.');
        }

        if ($elapsed < self::MIN_DAYS) {
            return self::grey(
                "Läuft erst {$elapsed} von {$total} Tagen — zu früh für eine Aussage."
            );
        }

        // Laufzeit vorbei: es kommt nichts mehr dazu, der Ist-Wert IST das Ergebnis.
        $projected = $elapsed >= $total
            ? $bewerbungen
            : (int) round($bewerbungen / $elapsed * $total);

        return self::rate(
            $projected,
            $target,
            $projected,
            "{$bewerbungen} Bewerbungen an Tag {$elapsed} von {$total} — "
            . "Hochrechnung {$projected} von {$target} benötigten.",
        );
    }

    /**
     * @return array{status:string, pct:?int, reason:string}
     */
    public static function fulfilment(int $unterschriften, ?int $bedarf): array
    {
        if ($bedarf === null || $bedarf <= 0) {
            $grey = self::grey('Bedarf ist an dieser Ausschreibung nicht gepflegt.');

            return ['status' => $grey['status'], 'pct' => null, 'reason' => $grey['reason']];
        }

        $pct = (int) round($unterschriften / $bedarf * 100);

        return [
            'status' => self::statusFor($pct),
            'pct' => $pct,
            'reason' => "{$unterschriften} von {$bedarf} benötigten Einstellungen unterschrieben.",
        ];
    }

    /** @return array{status:string, pct:?int, projected:?int, target:?int, reason:string} */
    private static function rate(int $value, int $target, ?int $projected, string $reason): array
    {
        $pct = (int) round($value / $target * 100);

        return [
            'status' => self::statusFor($pct),
            'pct' => $pct,
            'projected' => $projected,
            'target' => $target,
            'reason' => $reason,
        ];
    }

    private static function statusFor(int $pct): string
    {
        if ($pct >= self::THRESHOLD_GREEN) {
            return self::GREEN;
        }

        return $pct >= self::THRESHOLD_YELLOW ? self::YELLOW : self::RED;
    }

    /** @return array{status:string, pct:?int, projected:?int, target:?int, reason:string} */
    private static function grey(string $reason): array
    {
        return [
            'status' => self::GREY,
            'pct' => null,
            'projected' => null,
            'target' => null,
            'reason' => $reason,
        ];
    }
}
