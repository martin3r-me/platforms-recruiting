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
 * Fund F2 der Pruefung: ein Passwort mit Nullbyte kam bisher durch und liess
 * das spaetere `password_hash()` (bcrypt) mit einem `ValueError` abstuerzen
 * — eine 500er-Seite statt einer Formularmeldung. Steuerzeichen (`\x00` bis
 * `\x1F` sowie `\x7F`) werden deshalb hier abgewiesen, an der einzigen
 * Stelle, die noch eine verstaendliche Meldung zurueckgeben kann.
 *
 * Hoechstlaenge 200 Zeichen — NICHT wegen Rechenlast (gemessen: bcrypt
 * braucht fuer 16 Zeichen und fuer 10 MB dieselbe Zeit, `password_hash`
 * schneidet ohnehin nach 72 Byte ab), sondern weil kein echtes Passwort
 * laenger ist und ein unbegrenztes Feld sonst irgendwo anders anstoesst
 * (Request-Groesse, Log-/Speicherrauschen).
 *
 * Es wird NIE getrimmt, auch fuehrende/nachgestellte Leerzeichen bleiben
 * Teil des Passworts. Trimmen wuerde einen Tippfehler ("geheim123 " mit
 * Leerzeichen am Ende) verzeihen — aber nur, wenn WIRKLICH JEDE Stelle, die
 * das Passwort je entgegennimmt (Setzen UND jede spaetere Anmeldung),
 * exakt gleich trimmt. Vergisst eine einzige es, sperrt das lautlos ALLE
 * Betroffenen aus, nicht nur den einen Tippfehler-Fall. Der rohe String
 * braucht diese Absprache zwischen mehreren Stellen nicht. Fuer den
 * seltenen Einzelfall gibt es das Zuruecksetzen per Einmalcode.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PasswortRegeln
{
    /** Spec §2.3 — Laenge statt Zeichenklassen, weil der Benutzername oeffentlich ist. */
    public const MINDESTLAENGE = 10;

    /** Kein echtes Passwort ist laenger; verhindert Request-/Log-Rauschen. */
    public const HOECHSTLAENGE = 200;

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

        if (mb_strlen($passwort) > self::HOECHSTLAENGE) {
            return sprintf('Das Passwort darf hoechstens %d Zeichen lang sein.', self::HOECHSTLAENGE);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $passwort) === 1) {
            return 'Das Passwort darf keine Steuerzeichen enthalten.';
        }

        return null;
    }
}
