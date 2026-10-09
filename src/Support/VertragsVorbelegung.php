<?php

namespace Platform\Recruiting\Support;

/**
 * Vorbelegung des Fensters "Vertrag erstellen" aus einem Einsatztag und die
 * Notiz des HR-Falls "Vertrag fehlt fuer Einsatz" (Spec §3.3).
 *
 * DIE MONATSREGEL IST EINE ANNAHME aus Markus' "meist einen Monat gueltig"
 * (Telefonat 09.10.2026, offene Frage 1 im Spec §9). Sie steht NUR hier —
 * kommt seine Antwort, wird diese eine Methode ersetzt.
 *
 * notiz() und einsatztagAusNotiz() gehoeren zusammen: der HR-Schreibtisch
 * liest den Tag aus der Notiz zurueck (keine neue Spalte, Spec §4).
 */
final class VertragsVorbelegung
{
    /** @return array{beginn:string, ende:string}|null */
    public static function fuerEinsatz(string $einsatztag): ?array
    {
        if (!YmdDate::isValid($einsatztag)) {
            return null;
        }
        $tag = new \DateTimeImmutable($einsatztag, new \DateTimeZone('UTC'));

        return ['beginn' => $tag->format('Y-m-01'), 'ende' => $tag->format('Y-m-t')];
    }

    public static function notiz(string $einsatztag, ?string $event, ?string $taetigkeit, string $firma): string
    {
        $klammer = implode(', ', array_filter(
            [trim((string) $event), trim((string) $taetigkeit)],
            static fn (string $s) => $s !== ''
        ));

        return sprintf(
            '%s-Einsatz am %s%s — kein unterschriebener Arbeitsvertrag der Gesellschaft %s deckt diesen Tag.',
            $firma,
            self::deutsch($einsatztag),
            $klammer !== '' ? ' (' . $klammer . ')' : '',
            $firma
        );
    }

    public static function einsatztagAusNotiz(?string $notiz): ?string
    {
        if (!preg_match('/-Einsatz am (\d{2})\.(\d{2})\.(\d{4})/', (string) $notiz, $m)) {
            return null;
        }
        $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];

        return YmdDate::isValid($ymd) ? $ymd : null;
    }

    /** Y-m-d → dd.mm.yyyy. Die EINE Stelle dafuer; spaetere Tasks nutzen sie mit. */
    public static function deutsch(string $ymd): string
    {
        [$jahr, $monat, $tag] = explode('-', $ymd) + [null, null, null];

        return $tag . '.' . $monat . '.' . $jahr;
    }
}
