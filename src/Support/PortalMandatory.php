<?php

namespace Platform\Recruiting\Support;

/**
 * Welche PFLICHTANGABEN fehlen diesem Menschen gerade?
 *
 * Warum es diese Klasse gibt (Schlussfix F1, 26.09.2026)
 * ------------------------------------------------------
 * Seit dem Deadlock-Fix (Aufgabe 6, C1) blockt kein Waechter mehr
 * gruppenuebergreifend. Die Kostenseite dieser Entscheidung war ausdruecklich:
 * "Einziger verbliebener Druck: Ring und Offen-Zaehler." Diesen Druck gab es
 * in der behaupteten Form nicht — der Offen-Zaehler zaehlte ausschliesslich
 * Nachweise plus die Arbeitgeber-Frage, die Staatsangehoerigkeit und die
 * Ersthelfer-Kopplung kamen darin gar nicht vor. Der Start-Bildschirm sagte
 * "Wir haben alles, was wir von dir brauchen", waehrend der Ring daneben
 * "92 % — es fehlt die Staatsangehoerigkeit" meldete. Derselbe Satz, dieselbe
 * Seite, entgegengesetzte Aussage.
 *
 * Diese Klasse ist die EINE Quelle fuer die Frage "welche Pflichtangabe fehlt
 * noch". Sie tippt nichts ab:
 *   - WELCHE Felder ueberhaupt Pflicht sind, sagen die Waechter selbst
 *     (PortalProfileGuards::WAECHTER) — sie wissen es, sie pruefen es.
 *   - OB die Pflicht heute greift, sagt derselbe Waechter, der auch beim
 *     Speichern blockt (PortalProfileGuards::blocktWaechter()). Anzeige und
 *     Speichern koennen damit nicht auseinanderlaufen.
 *   - OB ein einzelnes Feld des Waechters gerade verlangt wird, sagt die
 *     bedingte Pflicht aus dem Feldkatalog (required_if ueber
 *     PortalFieldRelevance) — dieselbe Regel, nach der sich auch der rote
 *     Rand im Blatt richtet.
 *
 * Die drei Stuecke greifen genau ineinander:
 *   nationality        kein required_if -> immer verlangt, Waechter blockt
 *                      genau dann, wenn es leer ist.
 *   is_main_employer   kein required_if -> immer verlangt, Waechter blockt
 *                      bei "unbeantwortet".
 *   other_employer     required_if is_main_employer=false -> nur dann
 *                      verlangt, und genau dann blockt auch der Waechter.
 *   first_aider_*      required_if is_first_aider=true -> nur dann verlangt,
 *                      und genau dann blockt auch der Waechter. is_first_aider
 *                      SELBST faellt heraus, weil es dann nicht leer ist —
 *                      "bei nein passiert nichts" bleibt also wahr.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PortalMandatory
{
    /**
     * Die offenen Pflichtangaben, in der Reihenfolge der Waechter-Kaskade
     * (Ersthelfer, Staatsangehoerigkeit, Hauptarbeitgeber).
     *
     * @param array<string, array<string, array<string,mixed>>> $gruppen  editableFieldGroups()
     * @param array<string,mixed> $datensatz  gecastete Attributwerte
     * @param list<string> $offeneNachweisCodes  Codes der Nachweisarten, die in
     *        der Aufgabenliste SCHON als offen stehen (ProofChecklist). Ein
     *        Feld, das zu einer dieser Arten gehoert, wird hier ausgelassen —
     *        sonst zaehlt der Offen-Zaehler denselben Ersthelfer-Schein
     *        zweimal, einmal als Nachweis und einmal als Pflichtangabe.
     * @return list<array{feld:string, gruppe:string, label:string}>
     */
    public static function offen(array $gruppen, array $datensatz, array $offeneNachweisCodes = []): array
    {
        $aus = [];

        foreach (PortalProfileGuards::WAECHTER as $name => $felder) {
            if (!PortalProfileGuards::blocktWaechter($name, $datensatz)) {
                continue;
            }

            foreach ($felder as $feld) {
                [$gruppe, $meta] = self::imKatalog($gruppen, $feld);
                if ($meta === null) {
                    // Ein Waechterfeld ohne Profilfeld gaebe es nur, wenn
                    // jemand den Katalog beschneidet. Dann kann die Anzeige
                    // nichts benennen -- der Waechter blockt trotzdem weiter.
                    continue;
                }
                if (!PortalFieldRelevance::istRelevant($meta, $datensatz)) {
                    continue;
                }
                $wert = $datensatz[$feld] ?? null;
                if (!($wert === null || $wert === '' || $wert === [])) {
                    continue;
                }
                if (self::schonAlsNachweisOffen($feld, $offeneNachweisCodes)) {
                    continue;
                }

                $aus[$feld] = [
                    'feld'   => $feld,
                    'gruppe' => $gruppe,
                    'label'  => (string) ($meta['label'] ?? $feld),
                ];
            }
        }

        return array_values($aus);
    }

    /**
     * Wuerde ein Waechter das Speichern DIESER Gruppe blockieren?
     *
     * Der rote Punkt an der Gruppenzeile unterscheidet danach (F1): "das
     * blockiert das Speichern" wiegt schwerer als "fehlt noch, folgenlos".
     *
     * Gefragt wird mit demselben Aufruf und derselben Reichweite wie beim
     * echten Speichern (PortalProfileWriter uebergibt genau die Felder der
     * Gruppe) -- NICHT ueber die Liste aus offen(). Der Unterschied ist
     * echt: offen() laesst eine Pflichtangabe weg, die schon als offener
     * NACHWEIS gezaehlt wird (sonst zaehlte der Ersthelfer-Schein zweimal).
     * Fuer den ZAEHLER ist das richtig, fuer den PUNKT waere es falsch --
     * der Waechter blockt die Gruppe trotzdem, und genau das soll der Punkt
     * sagen.
     *
     * @param array<string, array<string,mixed>> $gruppenFelder Felder DIESER Gruppe
     * @param array<string,mixed> $datensatz
     */
    public static function blocktGruppe(array $gruppenFelder, array $datensatz): bool
    {
        return PortalProfileGuards::fehler([], $datensatz, $gruppenFelder) !== null;
    }

    /**
     * Gehoert dieses Feld zu einer Nachweisart, die schon als offene Aufgabe
     * in der Liste steht? Abgeleitet aus dem Katalog (Dateispalten UND
     * Ablaufspalte), nicht aus einer zweiten Zuordnung -- und ueber die
     * OFFENEN Codes gefragt, damit die Doppeldeutigkeit von
     * school_certificate_valid_until (Schule ODER Immatrikulation) sich von
     * selbst aufloest: es zaehlt die Art, die dieser Mensch wirklich braucht.
     *
     * @param list<string> $offeneNachweisCodes
     */
    private static function schonAlsNachweisOffen(string $feld, array $offeneNachweisCodes): bool
    {
        foreach ($offeneNachweisCodes as $code) {
            if (in_array($feld, ProofTypes::legacyFileColumns($code), true)) {
                return true;
            }
            if (ProofTypes::legacyExpiryColumn($code) === $feld) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gruppe und Metadaten eines Feldes aus dem Katalog.
     *
     * @param array<string, array<string, array<string,mixed>>> $gruppen
     * @return array{0:string, 1:?array<string,mixed>}
     */
    private static function imKatalog(array $gruppen, string $feld): array
    {
        foreach ($gruppen as $gruppe => $felder) {
            if (array_key_exists($feld, $felder)) {
                return [(string) $gruppe, $felder[$feld]];
            }
        }

        return ['', null];
    }
}
