<?php

namespace Platform\Recruiting\Support;

/**
 * Deckt ein Arbeitsvertrag EINER Anstellung einen Einsatztag? (Spec Vertrag
 * aus der Akte §3.1). Rein, ohne Datenbank — die Zeilen baut
 * Services\VertragsZeilen aus rec_contracts und den Vertrags-Extrafeldern.
 *
 * ALTBESTAND: ein unterschriebener AV OHNE Laufzeitfelder deckt jeden Tag.
 * Vor den Extrafeldern vertragsbeginn/vertragsende gab es keine Laufzeit am
 * Vertrag; ein alter unbefristeter Vertrag darf keinen HR-Fall erzeugen.
 * Ein unlesbares Datum zaehlt aus demselben Grund wie ein fehlendes.
 */
final class VertragsDeckung
{
    public const UNTERSCHRIEBEN = 'unterschrieben';
    public const UNTERWEGS = 'unterwegs';
    public const KEINER = 'keiner';

    /**
     * @param  list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}>  $vertraege  Vertraege EINER Anstellung
     * @return array{deckung:string, vertrag_id:?int}
     */
    public static function amTag(array $vertraege, string $tag): array
    {
        $unterschrieben = null;
        $unterwegs = null;

        foreach ($vertraege as $v) {
            if (!self::istAv($v['code']) || $v['superseded'] || !self::laufzeitDeckt($v['beginn'], $v['ende'], $tag)) {
                continue;
            }
            if ($v['status'] === 'completed' && $v['signed_at'] !== null && $v['signed_at'] !== '') {
                $unterschrieben = max($unterschrieben ?? 0, (int) $v['id']);
            } elseif ($v['status'] === 'sent') {
                $unterwegs = max($unterwegs ?? 0, (int) $v['id']);
            }
        }

        if ($unterschrieben !== null) {
            return ['deckung' => self::UNTERSCHRIEBEN, 'vertrag_id' => $unterschrieben];
        }
        if ($unterwegs !== null) {
            return ['deckung' => self::UNTERWEGS, 'vertrag_id' => $unterwegs];
        }

        return ['deckung' => self::KEINER, 'vertrag_id' => null];
    }

    /**
     * Der Waechter gegen Doppelabdeckung bei der Neuanlage (Spec §2.1): jeder
     * nicht stornierte, nicht ersetzte AV, dessen Laufzeit den Tag abdeckt —
     * egal in welchem Status. Bei mehreren der juengste.
     *
     * @param  list<array{id:int, code:string, status:string, signed_at:?string, superseded:bool, beginn:?string, ende:?string}>  $vertraege
     * @return array{id:int, ende:?string}|null
     */
    public static function ueberschneidung(array $vertraege, string $tag): ?array
    {
        $treffer = null;

        foreach ($vertraege as $v) {
            if (!self::istAv($v['code']) || $v['superseded'] || $v['status'] === 'cancelled') {
                continue;
            }
            if (!self::laufzeitDeckt($v['beginn'], $v['ende'], $tag)) {
                continue;
            }
            if ($treffer === null || (int) $v['id'] > $treffer['id']) {
                $treffer = ['id' => (int) $v['id'], 'ende' => self::datum($v['ende'])];
            }
        }

        return $treffer;
    }

    /** Arbeitsvertrag = Code `AV-…` oder der blanke Altcode `AV`. */
    public static function istAv(?string $code): bool
    {
        $c = trim((string) $code);

        return $c === 'AV' || str_starts_with($c, 'AV-');
    }

    /** Y-m-d aus `Y-m-d…`, `d.m.Y` oder einem Datumsobjekt — sonst null. */
    public static function datum(mixed $wert): ?string
    {
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }

        $s = trim((string) $wert);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
            return YmdDate::isValid($m[1]) ? $m[1] : null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
            $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];

            return YmdDate::isValid($ymd) ? $ymd : null;
        }

        return null;
    }

    private static function laufzeitDeckt(mixed $beginn, mixed $ende, string $tag): bool
    {
        $b = self::datum($beginn);
        $e = self::datum($ende);

        return ($b === null || $b <= $tag) && ($e === null || $e >= $tag);
    }
}
