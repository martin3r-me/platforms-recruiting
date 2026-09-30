<?php

namespace Platform\Recruiting\Support;

/**
 * Schwaerzt vollstaendige Rufnummern in einem FREMDEN Text — vor allem in
 * Ausnahmemeldungen, bevor sie ins Log gehen.
 *
 * WARUM DAS EINE KLASSE IST UND KEINE PRIVATE METHODE. Sie stand als
 * private Methode in recruiting:konto-zuruecksetzen, waehrend
 * recruiting:konto-einladen dieselbe Art Meldung ROH ins Log legte — und
 * zwar unter einem Kommentar, der das Gegenteil versprach ("aber NIE der
 * Code und nie eine volle Nummer"). Die Zusage stimmte dort nur zufaellig:
 * die heute erreichbaren Wuerfe tragen keine Nummer. Zwei Massstaebe fuer
 * dieselbe Frage sind genau die Stelle, an der der eine nachgezogen und der
 * andere vergessen wird.
 *
 * NACHGEMESSEN, nicht vermutet: PersonLinker::setzeNummer() baut seine
 * Ausnahme mit sprintf und setzt die normalisierte Nummer woertlich ein
 * ("Die Nummer %s gehoert im Team %s bereits Person %d"). Dazu kommt der
 * Fall, den dieses Projekt schon live hatte: eine QueryException traegt die
 * SQL samt eingesetzter Werte in ihrer Meldung — bei SQLSTATE 22001 (zu
 * langer Feldwert) steht die volle Rufnummer darin.
 *
 * DIE REGEL: eine Ziffernfolge von mindestens sieben Stellen, mit oder ohne
 * fuehrendes Plus, wird auf ihre letzten vier gekuerzt. Kurze Zahlen —
 * Team-Kennung, Personen-Kennung, Zeilennummern — bleiben stehen; sie
 * gehoeren ins Log und helfen beim Suchen. Sieben ist die Grenze, weil
 * darunter keine Rufnummer liegt und darueber kaum eine Kennung.
 *
 * Reine Logik, kein Framework, kein Speicher.
 */
final class NummernSchwaerzung
{
    /**
     * Die Untergrenze, ab der eine Ziffernfolge als Rufnummer gilt.
     *
     * Ausgeschrieben und nicht in der Regel versteckt, damit die Entscheidung
     * eine Entscheidung bleibt: kuerzer waere jede Personalnummer eine
     * "Nummer", laenger rutschte eine Festnetznummer ohne Vorwahl durch.
     */
    public const MINDESTSTELLEN = 7;

    public static function anwenden(string $text): string
    {
        $geschwaerzt = preg_replace_callback(
            '/\+?\d{'.self::MINDESTSTELLEN.',}/',
            static fn (array $treffer): string => '...'.substr($treffer[0], -4),
            $text,
        );

        // preg_replace_callback gibt bei einem Fehler null zurueck. Dann
        // ginge der Originaltext durch — UNGESCHWAERZT.
        //
        // DIESER ZWEIG IST HEUTE UNERREICHBAR, und zwar aus einem anderen
        // Grund, als hier zuerst stand. Nachgemessen: das Muster laeuft OHNE
        // /u, PCRE arbeitet also byteweise. Auch ungueltiges UTF-8 wird
        // klaglos ersetzt, preg_last_error() bleibt 0 — die frueher hier
        // genannte Begruendung ("etwa bei ungueltigem UTF-8") mass nicht,
        // was sie behauptete. Erst MIT /u liefert dieselbe Eingabe null.
        //
        // Der Rueckfall bleibt trotzdem stehen: er ist billiger als eine
        // Wache, und eine leere Zeichenkette saehe im Log aus wie "es gab
        // keinen Grund". WER JE EIN /u ERGAENZT, macht ihn scharf und muss
        // dann entscheiden, ob ein ungeschwaerzter Text ins Log darf.
        return $geschwaerzt ?? $text;
    }
}
