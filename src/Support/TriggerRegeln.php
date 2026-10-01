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

    public static function darfMelden(
        ?string $letzteSignatur,
        ?string $gemeldetAm,
        string $neueSignatur,
        string $jetzt,
        int $pauseTage,
    ): bool {
        if ($neueSignatur === '') {
            return false;
        }

        if ($letzteSignatur === $neueSignatur) {
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
