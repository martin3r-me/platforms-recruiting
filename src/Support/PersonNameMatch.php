<?php

namespace Platform\Recruiting\Support;

/**
 * Plausibilitaets-Guard fuer die Zuordnung MA -> CRM-Kontakt: gehoert der
 * gefundene Kontakt ueberhaupt zu diesem Menschen?
 *
 * WARUM ES DEN GUARD BRAUCHT (Befund Dry-Run 2026-08-27, 634 MA):
 * Die Match-Kaskade des ZasEmployeeContactLinker arbeitete fehlerfrei — bei
 * allen drei Fehltreffern stimmte die E-Mail exakt und war am Kontakt primaer
 * UND aktiv. Falsch war nicht der Vergleich, sondern die Datenlage: der
 * MA-Stammsatz aus ZAS trug die Adresse eines ANDEREN Menschen.
 *   - MA #126 Osselmann, Nina  ->  daniel.roesberg@rheingedeck.de (Kollege)
 *   - MA #404 Momoh Warri      ->  Teamvario@vianobis.de (Sammeladresse)
 *   - Kontakt #1091 "Mohammed Ali" traegt die Gmail von MA #294 Ahmed, Muneeb
 * Ein E-Mail-Vergleich kann das prinzipiell nicht bemerken. Sammeladressen
 * (Vermittler, Familien, Firmenpostfach) sind bei Aushilfen strukturell
 * haeufig — das kommt wieder, ist also kein Fall fuer einmalige Datenpflege.
 *
 * WARUM SO LOCKER: Die Gegenprobe sind die 126 korrekten Verlinkungen
 * desselben Laufs. Dort weichen die Namen staendig voneinander ab, ohne dass
 * ein anderer Mensch gemeint waere: "Bao Duy Ngoc Nguyen" gegen "Bao Nguyen",
 * "Mohammad Ghanam Aleissa" gegen "Mo Aleissa", "Lars Zend Abdull" gegen
 * "Lars Zend Abdulll", "Maxima Wamser" gegen "Unbekannt Maximawamser". Ein
 * strenger Vergleich haette mehr kaputt gemacht als repariert. Deshalb reicht
 * EIN gemeinsamer Namensbestandteil — das trennt "Osselmann/Nina" von
 * "Daniel/Roesberg" zuverlaessig und laesst die echten Treffer durch.
 *
 * Der Guard entscheidet NICHT ueber richtig/falsch, sondern ueber
 * automatisch/manuell: was hier durchfaellt, wird uebersprungen und
 * begruendet — nie stillschweigend verlinkt.
 */
final class PersonNameMatch
{
    /**
     * Kuerzer als das matcht nie. Ohne Untergrenze verbinden Partikel wie
     * "de", "al", "van" oder "bin" beliebige Menschen miteinander.
     */
    private const MIN_TOKEN = 3;

    /**
     * Ab dieser Laenge gilt ein Token auch als Treffer, wenn es in einem
     * anderen ENTHALTEN ist ("wamser" in "maximawamser", "himmit" in
     * "himmithamza429"). Bewusst hoeher als MIN_TOKEN: bei drei Zeichen
     * waere ein Teilstring-Treffer reiner Zufall.
     */
    private const MIN_CONTAINS = 5;

    /**
     * Kontaktnamen, die keine Person bezeichnen. Das CRM fuellt fehlende
     * Namen mit einem Platzhalter (siehe ApplicantContactName::UNKNOWN) —
     * der darf keinen Treffer stiften.
     */
    private const PLACEHOLDERS = ['unbekannt', 'unknown', 'nn'];

    /**
     * Koennen MA und Kontakt derselbe Mensch sein?
     *
     * @param  string  $firstName    Vorname des Mitarbeiters
     * @param  string  $lastName     Nachname des Mitarbeiters
     * @param  string  $contactName  Anzeigename des CRM-Kontakts ("Vorname Nachname")
     */
    public static function plausible(string $firstName, string $lastName, string $contactName): bool
    {
        $employee = self::tokens($firstName . ' ' . $lastName);
        $contact  = self::tokens($contactName);

        if ($employee === [] || $contact === []) {
            return false;
        }

        foreach ($employee as $e) {
            foreach ($contact as $c) {
                if ($e === $c) {
                    return true;
                }

                // Zusammengeschriebene oder angehaengte Formen: "maximawamser"
                // enthaelt "wamser", "abdulll" enthaelt "abdull".
                if (mb_strlen($e) >= self::MIN_CONTAINS && str_contains($c, $e)) {
                    return true;
                }
                if (mb_strlen($c) >= self::MIN_CONTAINS && str_contains($e, $c)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Zerlegt einen Namen in vergleichbare Bestandteile: kleingeschrieben,
     * ohne Diakritika, ohne Ziffern und Satzzeichen, ohne Platzhalter und
     * ohne zu kurze Partikel.
     *
     * @return string[]
     */
    private static function tokens(string $name): array
    {
        $normalized = self::fold($name);
        $parts = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($parts as $part) {
            // Ziffern taugen nicht als Namensbeleg: Kontakt #1203 heisst
            // "Unbekannt +4915208331495", da ist die Nummer der ganze Name.
            $letters = preg_replace('/[^a-z]/', '', $part) ?? '';
            if (mb_strlen($letters) < self::MIN_TOKEN) {
                continue;
            }
            if (in_array($letters, self::PLACEHOLDERS, true)) {
                continue;
            }
            $tokens[] = $letters;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Diakritika-Faltung. Bewusst als feste Tabelle statt per iconv
     * TRANSLIT: dessen Ergebnis haengt an der Locale des Prozesses und
     * liefert je nach Umgebung "?" oder "'e" statt "e" — auf dem Server
     * waere der Vergleich dann ein anderer als im Test.
     */
    private static function fold(string $value): string
    {
        $map = [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c',
            'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
            'ś' => 's', 'š' => 's', 'ş' => 's',
            'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
            'ý' => 'y', 'ÿ' => 'y',
            'ğ' => 'g', 'ł' => 'l', 'đ' => 'd', 'ř' => 'r', 'ť' => 't', 'ď' => 'd',
            'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th', 'ð' => 'd',
        ];

        return strtr(mb_strtolower(trim($value)), $map);
    }
}
