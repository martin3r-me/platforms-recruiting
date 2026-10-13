<?php

namespace Platform\Recruiting\Support;

use DateTimeImmutable;

/**
 * Wann geht eine Nachricht raus — und wann bewusst nicht.
 *
 * Rein: keine Datenbank, keine Uhr, keine Konfiguration. Alles wird
 * hereingereicht.
 *
 * Die Signatur ist der Kern: sie beantwortet „wurde ueber GENAU DIESE Punkte
 * schon informiert". Sie ist sortierunabhaengig, sonst meldete eine andere
 * Reihenfolge dieselben Punkte als neu. Ein leeres Feld ergibt bewusst die
 * leere Signatur — „nichts offen" ist kein Zustand, ueber den man meldet.
 *
 * Markus' Folie 27 nennt „zu viele Nachrichten" als eines der drei Dinge, die
 * wir vermeiden muessen. Diese Klasse ist die Stelle, an der das durchgesetzt
 * wird.
 *
 * NACHTRAG 13.10.2026 (Kundenzusage Markus, Go-live-Folie 8: „Eine neue
 * Nachricht kommt nur, wenn ein neuer Punkt dazukommt."): die Signatur allein
 * reicht dafuer nicht. Als Hash ueber die GANZE Menge war sie auch dann
 * "anders", wenn die Liste nur KLEINER wurde — wer einen von drei Punkten
 * erledigte, bekam nach der Pause "du hast 2 offene Punkte". Seitdem werden
 * zusaetzlich die gemeldeten CODES gespeichert (rec_persons.aufgaben_codes)
 * und darfMelden() fragt `hatNeuenPunkt()`: gemeldet wird nur, wenn
 * mindestens ein aktueller Code bei der letzten erfolgreichen Meldung NICHT
 * dabei war. Teilmenge oder gleich → still. Die Signatur bleibt bestehen:
 * sie ist der Marker "es wurde gemeldet" (Zielkreis ET-16, HR-Liste
 * „Erinnert"), und fuer den Altbestand, an dem noch keine Codes stehen,
 * gilt weiter der Hash-Vergleich — sonst ginge nach dem Deploy an jeden mit
 * altem Stand ein Schwall Nachrichten raus.
 */
final class TriggerRegeln
{
    /**
     * `array_filter` ohne Callback wirft alles Falsy raus, nicht nur leere
     * Strings — ein Code `"0"` wuerde ebenso verschwinden. Nachgemessen gegen
     * ProofTypes::all() (Stand 01.10.2026): keiner der Codes (ausweis,
     * selfie, krankenkasse, iban_nachweis, nationalpass, aufenthaltstitel,
     * visum, arbeitsgenehmigung, fiktionsbescheinigung, schulbescheinigung,
     * immatrikulation, erstbescheinigung, ersthelfer) ist "0" oder sonst
     * falsy — nur ein leerer String kommt als Luecke vor (fehlendes `code`).
     * Deshalb ist der einfache `array_filter` hier ungefaehrlich.
     *
     * Entdoppeln uebernimmt diese Methode bewusst nicht selbst: der
     * tatsaechliche Erzeuger der `['code' => ...]`-Felder ist
     * ProofChecklist::build() (aufgerufen aus ProofReader::checklist()) —
     * NICHT PersonPflichten::vereinige(), das liefert nur eine
     * list<string> von Codes, keine Felder mit diesem Schluessel.
     * ProofChecklist::build() entdoppelt selbst per `array_unique` (Zeile
     * 44, Stand 01.10.2026) — ein doppelter Code kommt hier also nicht an.
     * (Korrektur Nachbesserungsrunde 1: die vorige Fassung dieses Kommentars
     * nannte das falsche Glied der Kette.)
     *
     * `implode('|', ...)` statt `implode('', ...)`: der Trenner ist bei den
     * 13 festen Codes nicht einmal noetig — erschoepfend geprueft (alle 8191
     * nichtleeren Teilmengen der 13 Codes sortiert-konkateniert, keine
     * Kollision), aber billig genug, um ihn trotzdem zu behalten.
     *
     * @param list<array{code?:string}> $offenePunkte
     */
    public static function signatur(array $offenePunkte): string
    {
        $codes = array_filter(array_map(
            static fn (array $p) => (string) ($p['code'] ?? ''),
            $offenePunkte
        ));

        if ($codes === []) {
            return '';
        }

        sort($codes);

        return hash('sha256', implode('|', $codes));
    }

    /**
     * Die Codes der offenen Punkte — gefiltert, entdoppelt, sortiert. Dieselbe
     * Filterregel wie signatur(), damit beide ueber dieselbe Menge sprechen.
     *
     * @param  list<array{code?:string}> $offenePunkte
     * @return list<string>
     */
    public static function codes(array $offenePunkte): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn (array $p) => (string) ($p['code'] ?? ''),
            $offenePunkte
        ))));
        sort($codes);

        return $codes;
    }

    /**
     * Fuer die Spalte rec_persons.aufgaben_codes: JSON-Liste. Leere Menge →
     * null (ueber "nichts offen" wird nie gemeldet, also auch nichts
     * gespeichert).
     *
     * @param list<array{code?:string}> $offenePunkte
     */
    public static function codesFuerSpalte(array $offenePunkte): ?string
    {
        $codes = self::codes($offenePunkte);

        return $codes === [] ? null : json_encode($codes, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Die Spalte zurueck in eine Code-Liste. Alles, was keine JSON-Liste aus
     * Strings ist (null, leer, Schrott), ergibt null = "keine Codes lesbar"
     * — dann gilt in darfMelden() der alte Hash-Vergleich (Altbestand).
     *
     * @return list<string>|null
     */
    public static function codesAusSpalte(mixed $wert): ?array
    {
        if (!is_string($wert) || $wert === '') {
            return null;
        }

        $liste = json_decode($wert, true);
        if (!is_array($liste) || !array_is_list($liste)) {
            return null;
        }

        foreach ($liste as $code) {
            if (!is_string($code)) {
                return null;
            }
        }

        return $liste;
    }

    /**
     * Kundenzusage Markus (Folie 8): neu ist nur, was bei der letzten
     * erfolgreichen Meldung NICHT dabei war. Ein Punkt, der erledigt und
     * spaeter wieder offen wurde, war in der letzten Meldung enthalten und
     * gilt deshalb als bekannt — er loest nichts aus. (Wird die Liste ganz
     * leer, raeumt ET-16 Signatur UND Codes; dann ist beim naechsten Mal
     * alles wieder neu.)
     *
     * @param list<string> $letzteCodes
     * @param list<string> $neueCodes
     */
    public static function hatNeuenPunkt(array $letzteCodes, array $neueCodes): bool
    {
        return array_diff($neueCodes, $letzteCodes) !== [];
    }

    /**
     * Die beiden Code-Parameter (Nachtrag 13.10.2026) sind optional, damit
     * der Altbestand ohne gespeicherte Codes beim Hash-Vergleich bleibt:
     * fehlt einer der beiden (null), entscheidet wie bisher die Signatur.
     * Die Codes zaehlen NUR, wenn auch eine letzte Signatur steht — ohne sie
     * gab es keine (gueltige) letzte Meldung, etwa nach ET-23 (nachtraeglich
     * gescheitert, Signatur geraeumt).
     *
     * @param list<string>|null $letzteCodes
     * @param list<string>|null $neueCodes
     */
    public static function darfMelden(
        ?string $letzteSignatur,
        ?string $gemeldetAm,
        string $neueSignatur,
        string $jetzt,
        int $pauseTage,
        ?array $letzteCodes = null,
        ?array $neueCodes = null,
    ): bool {
        if ($neueSignatur === '') {
            return false;
        }

        if ($letzteSignatur !== null && $letzteCodes !== null && $neueCodes !== null) {
            // Kundenzusage: nur ein NEUER Punkt meldet. Teilmenge → still.
            if (!self::hatNeuenPunkt($letzteCodes, $neueCodes)) {
                return false;
            }
        } elseif ($letzteSignatur === $neueSignatur) {
            // Altbestand (nur Hash, keine Codes lesbar): Verhalten wie bis 13.10.2026.
            return false;
        }

        if ($gemeldetAm === null || $gemeldetAm === '') {
            return true;
        }

        try {
            $zuletzt = new DateTimeImmutable($gemeldetAm);
            $heute   = new DateTimeImmutable($jetzt);
        } catch (\Throwable) {
            // ET-14-Entscheidung (Nachbesserungsrunde 1, 01.10.2026): ein
            // unlesbarer Zeitstempel gilt als "Pause ist um", es wird also
            // gemeldet — NICHT geschwiegen. Der vorige Kommentar hier
            // ("die sichere Richtung ist das Schweigen") war falsch:
            // gemeldetAm wird ausschliesslich bei einem ERFOLGREICHEN
            // Versand ueberschrieben (Aufgabe 10). Ein Schrottwert waere mit
            // "return false" ein DAUERHAFTES Schweigen ohne Ausweg gewesen,
            // weil kein spaeterer Lauf den Wert je repariert. Nach oben ist
            // das Risiko durch den Signatur-Waechter begrenzt: hoechstens
            // eine Nachricht je geaenderter Punktmenge.
            return true;
        }

        return $zuletzt->modify('+' . $pauseTage . ' days') <= $heute;
    }
}
