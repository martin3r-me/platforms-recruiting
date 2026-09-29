<?php

namespace Platform\Recruiting\Support;

/**
 * Was ist ein gueltiges Konto-Passwort?
 *
 * Der Benutzername im Mitarbeiter-Portal ist die Handynummer — und die ist
 * kein Geheimnis. Sie steht im Gruppenchat und auf dem Dienstplan. Damit
 * traegt allein das Passwort die ganze Last der Anmeldung. Deshalb verlangt
 * die Spec (§2.3) eine spuerbare Mindestlaenge, aber bewusst KEINEN Zwang zu
 * Sonderzeichen, Ziffern oder Gross-/Kleinschreibung: erzwungene
 * Zeichenklassen fuehren erfahrungsgemaess zu kuerzeren, gemusterten
 * Passwoertern ("Passwort1!") statt zu staerkeren — Laenge schlaegt
 * Zeichenvielfalt.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PasswortRegeln
{
    /** Spec §2.3 — Laenge statt Zeichenklassen, weil der Benutzername oeffentlich ist. */
    public const MINDESTLAENGE = 10;

    /**
     * @return string|null null = in Ordnung, sonst die Fehlermeldung fuers Formular.
     */
    public static function pruefe(string $passwort): ?string
    {
        // mb_strlen statt strlen: ein Umlaut oder Emoji im Passwort darf nicht
        // als mehrere Zeichen zaehlen und so die Mindestlaenge verfaelschen.
        if (mb_strlen($passwort) < self::MINDESTLAENGE) {
            return sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MINDESTLAENGE);
        }

        return null;
    }
}
