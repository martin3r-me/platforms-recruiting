<?php

namespace Platform\Recruiting\Support;

/**
 * Ruling GD-1: Obergrenze fuer Einmalcode-Anforderungen — Konto-Anmeldung,
 * Passwort-Reset und Nummernwechsel teilen sich diese Bremse (der Zweck
 * steckt nicht hier, siehe Einmalcode-Docblock).
 *
 * Ohne diese Drossel liesse sich der Versuchszaehler aus Einmalcode
 * beliebig oft zurueckgesetzt bekommen: jeder neu angeforderte Code bringt
 * wieder MAX_VERSUCHE frische Rateversuche. Erst die Obergrenze hier macht
 * daraus wieder eine endliche Zahl an Versuchen pro Stunde/Tag.
 *
 * Reine Logik (kein Framework/DB), damit die Regel ohne Datenbank pruefbar
 * ist — der Aufrufer liefert die bisherigen Anforderungszeitpunkte selbst
 * (z.B. aus einem Log), diese Klasse zaehlt nur.
 */
final class CodeDrossel
{
    /** Hoechstens drei Anforderungen innerhalb der letzten 60 Minuten. */
    public const MAX_JE_STUNDE = 3;

    /** Hoechstens fuenf Anforderungen am Kalendertag von `$jetzt`. */
    public const MAX_JE_TAG = 5;

    /**
     * @param  list<string>  $bisherigeAnforderungen  Zeitstempel Y-m-d H:i:s, Reihenfolge egal.
     */
    public static function darfSenden(array $bisherigeAnforderungen, string $jetzt): bool
    {
        $jetztZeit = new \DateTimeImmutable($jetzt);
        $heute = $jetztZeit->format('Y-m-d');
        $vorEinerStunde = $jetztZeit->modify('-1 hour');

        $jeStunde = 0;
        $jeTag = 0;

        foreach ($bisherigeAnforderungen as $zeitpunkt) {
            try {
                $zeit = new \DateTimeImmutable($zeitpunkt);
            } catch (\Throwable) {
                // Ein unlesbarer Zeitstempel ist ein Datenfehler, kein Beleg
                // fuer eine Anforderung — im Zweifel bremst er nicht mit,
                // sonst legt ein kaputter Log-Eintrag die Anmeldung lahm.
                continue;
            }

            if ($zeit->format('Y-m-d') === $heute) {
                $jeTag++;
            }

            if ($zeit > $vorEinerStunde && $zeit <= $jetztZeit) {
                $jeStunde++;
            }
        }

        return $jeStunde < self::MAX_JE_STUNDE && $jeTag < self::MAX_JE_TAG;
    }
}
