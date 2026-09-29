<?php

namespace Platform\Recruiting\Support;

/**
 * Der sechsstellige Einmalcode fuers Konto — Anmeldung, Passwort-Reset,
 * Nummernwechsel (RecPerson.code_zweck haelt fest, wofuer; diese Klasse
 * kennt den Zweck nicht, nur die Gueltigkeit).
 *
 * `random_int()` statt `rand()`/`mt_rand()`: Letztere sind fuer
 * Sicherheitszwecke vorhersagbar. Bei nur sechs Ziffern (eine Million
 * Kombinationen) waere ein erratbarer Generator kein Nachweis mehr, sondern
 * nur noch ein Zaehler.
 *
 * Genau diese kleine Kombinationszahl ist auch der Grund fuer
 * MAX_VERSUCHE: eine Million Werte sind am selben Nachmittag automatisiert
 * durchprobiert, wenn niemand mitzaehlt. Der Versuchszaehler macht daraus
 * hoechstens MAX_VERSUCHE Rateversuche je ausgestelltem Code.
 *
 * `istGueltig()` vergleicht Hashes mit `hash_equals()`, NIE mit `===`: ein
 * Vergleich, der beim ersten abweichenden Zeichen abbricht, verraet ueber
 * die Antwortzeit, wie viele Ziffern schon stimmen — bei sechs Ziffern der
 * Unterschied zwischen einer Million Versuchen und sechzig.
 *
 * Abgelaufen, schon zu oft falsch versucht oder schlicht falsch: alle drei
 * ergeben `false`, ohne zu verraten, welcher Grund zutraf.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class Einmalcode
{
    /** Spec: ein Einmalcode gilt zehn Minuten. */
    public const GUELTIG_MINUTEN = 10;

    /** Ab dem fuenften Fehlversuch gilt auch der richtige Code nicht mehr. */
    public const MAX_VERSUCHE = 5;

    /**
     * @return array{klartext: string, hash: string}
     */
    public static function erzeuge(): array
    {
        // 0 bis 999999, mit fuehrenden Nullen aufgefuellt: die volle
        // Spannweite von sechs Ziffern wird genutzt, nicht nur 100000-999999.
        $klartext = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'klartext' => $klartext,
            'hash' => self::hash($klartext),
        ];
    }

    public static function istGueltig(?string $hash, ?string $ablauf, int $versuche, string $klartext, string $jetzt): bool
    {
        if ($hash === null || $ablauf === null) {
            return false;
        }

        if ($versuche >= self::MAX_VERSUCHE) {
            return false;
        }

        if (new \DateTimeImmutable($jetzt) >= new \DateTimeImmutable($ablauf)) {
            return false;
        }

        return hash_equals($hash, self::hash($klartext));
    }

    private static function hash(string $klartext): string
    {
        return hash('sha256', $klartext);
    }
}
