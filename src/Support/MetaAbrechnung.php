<?php

namespace Platform\Recruiting\Support;

/**
 * Monatsabrechnung der WhatsApp-Kosten je Rufnummer (Markus, Mail 18.11.2026).
 *
 * Grundlage ist Metas pricing_analytics je WABA (Kalendermonat, Europe/Berlin),
 * NICHT die Abbuchungen: Meta bucht per Karte nach Betragsschwellen
 * (40/70/100 €) und den Monatsrest am 1. des Folgemonats — genau das machte
 * die weitergeleiteten Rechnungen unpruefbar. Abgleich Oktober 2026 Dispo:
 * Auswertung 184,81 € gegen Abbuchungen 184,79 €.
 *
 * Rundung: je Zeile Meta auf 2 Stellen, Summe = exakt × (1 + Aufschlag)
 * gerundet, Aufschlag = Summe − Meta. So geht jede Zeile optisch auf; die
 * Gesamtzeile addiert die gerundeten Zeilen (kann 1 Cent von der exakt
 * gerechneten Summe abweichen).
 *
 * Reine Rechnung ohne IO — Abfrage und Zuordnung macht das Kommando
 * recruiting:meta-abrechnung.
 */
final class MetaAbrechnung
{
    public static function ziffern(?string $nummer): string
    {
        return preg_replace('/\D+/', '', (string) $nummer) ?? '';
    }

    /**
     * Datenpunkte aus der Graph-Antwort; Meta-Fehler werden zur Exception
     * (lieber kein CSV als ein stilles halbes).
     *
     * @return list<array<string,mixed>>
     */
    public static function datenpunkte(array $json): array
    {
        if (isset($json['error'])) {
            throw new \RuntimeException('Meta: ' . (string) ($json['error']['message'] ?? json_encode($json['error'])));
        }

        $punkte = [];
        foreach ((array) ($json['pricing_analytics']['data'] ?? []) as $block) {
            foreach ((array) ($block['data_points'] ?? []) as $p) {
                $punkte[] = (array) $p;
            }
        }

        return $punkte;
    }

    /**
     * @param list<array<string,mixed>> $datenpunkte
     * @param array<string, array{kostenstelle:?int, bezeichnung:string, rufnummer:string}> $zuordnung Ziffern → Zuordnung
     * @return array{zeilen: list<array{kostenstelle:?int, bezeichnung:string, rufnummer:string, berechnet:int, frei:int, meta:float, aufschlag:float, summe:float, meta_exakt:float}>,
     *               gesamt: array{berechnet:int, frei:int, meta:float, aufschlag:float, summe:float, meta_exakt:float}}
     */
    public static function aufstellen(array $datenpunkte, array $zuordnung, float $aufschlagProzent): array
    {
        $je = [];
        foreach ($datenpunkte as $p) {
            $nr = self::ziffern((string) ($p['phone_number'] ?? ''));
            $kosten = (float) ($p['cost'] ?? 0);
            $volumen = (int) ($p['volume'] ?? 0);
            $je[$nr] ??= ['berechnet' => 0, 'frei' => 0, 'kosten' => 0.0];
            $je[$nr]['kosten'] += $kosten;
            $je[$nr][$kosten > 0 ? 'berechnet' : 'frei'] += $volumen;
        }

        $faktor = 1 + $aufschlagProzent / 100;
        $zeilen = [];
        foreach ($je as $nr => $w) {
            $z = $zuordnung[$nr] ?? ['kostenstelle' => null, 'bezeichnung' => 'nicht zugeordnet', 'rufnummer' => '+' . $nr];
            $meta = round($w['kosten'], 2);
            $summe = round($w['kosten'] * $faktor, 2);
            $zeilen[] = [
                'kostenstelle' => $z['kostenstelle'],
                'bezeichnung' => $z['bezeichnung'],
                'rufnummer' => $z['rufnummer'],
                'berechnet' => $w['berechnet'],
                'frei' => $w['frei'],
                'meta' => $meta,
                'aufschlag' => round($summe - $meta, 2),
                'summe' => $summe,
                'meta_exakt' => $w['kosten'],
            ];
        }

        // Kostenstellen aufsteigend, Zeilen ohne Kostenstelle (HR, nicht zugeordnet) danach
        usort($zeilen, fn ($a, $b) => [$a['kostenstelle'] === null, $a['kostenstelle'] ?? 0, $a['bezeichnung']]
            <=> [$b['kostenstelle'] === null, $b['kostenstelle'] ?? 0, $b['bezeichnung']]);

        $gesamt = ['berechnet' => 0, 'frei' => 0, 'meta' => 0.0, 'aufschlag' => 0.0, 'summe' => 0.0, 'meta_exakt' => 0.0];
        foreach ($zeilen as $z) {
            foreach (array_keys($gesamt) as $k) {
                $gesamt[$k] += $z[$k];
            }
        }
        foreach (['meta', 'aufschlag', 'summe'] as $k) {
            $gesamt[$k] = round($gesamt[$k], 2);
        }

        return ['zeilen' => $zeilen, 'gesamt' => $gesamt];
    }

    /**
     * Ziffern → Kostenstelle/Bezeichnung/Anzeige-Nummer.
     * Dispo-Nummern: Filiale aus den Dispo-Einstellungen (Filiale → Kanal),
     * Kostenstelle = Filialnummer, Bezeichnung = Filialen::code. Nummern ohne
     * Filiale (HR) aus der Konfig, ohne Kostenstelle. Eine Filial-Zuordnung
     * schlaegt den Konfig-Eintrag; zwei Filialen auf einer Nummer → erste
     * (kleinste Filialnummer) gewinnt, Warnung.
     *
     * @param array<string,string> $ohneFiliale Nummer → Bezeichnung
     * @param list<?string> $kontoNummern Anzeige-Nummern der WA-Konten (nur fuer die Schreibweise)
     * @param list<array{filial_nr:int, rufnummer:string}> $filialKanaele
     * @param callable(string):void $warn
     * @return array<string, array{kostenstelle:?int, bezeichnung:string, rufnummer:string}>
     */
    public static function zuordnung(array $ohneFiliale, array $kontoNummern, array $filialKanaele, callable $warn): array
    {
        $out = [];
        foreach ($ohneFiliale as $nr => $bezeichnung) {
            $z = self::ziffern((string) $nr);
            $out[$z] = ['kostenstelle' => null, 'bezeichnung' => (string) $bezeichnung, 'rufnummer' => '+' . $z];
        }
        foreach ($kontoNummern as $anzeige) {
            $z = self::ziffern($anzeige);
            if ($z !== '' && isset($out[$z])) {
                $out[$z]['rufnummer'] = (string) $anzeige;
            }
        }

        usort($filialKanaele, fn ($a, $b) => $a['filial_nr'] <=> $b['filial_nr']);
        foreach ($filialKanaele as $k) {
            $z = self::ziffern($k['rufnummer']);
            if ($z === '') {
                continue;
            }
            if (isset($out[$z]) && $out[$z]['kostenstelle'] !== null) {
                if ($out[$z]['kostenstelle'] !== $k['filial_nr']) {
                    $warn("Rufnummer {$k['rufnummer']} ist mehreren Filialen zugeordnet ({$out[$z]['kostenstelle']}, {$k['filial_nr']}) — {$out[$z]['kostenstelle']} gewinnt.");
                }
                continue;
            }
            $out[$z] = [
                'kostenstelle' => $k['filial_nr'],
                'bezeichnung' => Filialen::code($k['filial_nr']) ?? ('Filiale ' . $k['filial_nr']),
                'rufnummer' => $k['rufnummer'],
            ];
        }

        return $out;
    }

    /** @return list<list<string>> Kopf, Zeilen, Gesamt — fuer fputcsv mit ';' (deutsches Excel) */
    public static function csvZeilen(array $aufstellung, string $zeitraum, string $waehrung, float $aufschlagProzent): array
    {
        $prozent = rtrim(rtrim(number_format($aufschlagProzent, 2, ',', ''), '0'), ',');
        $out = [[
            'Kostenstelle', 'Filiale/Bezeichnung', 'Rufnummer', 'Zeitraum', 'Nachrichten berechnet', 'Nachrichten frei',
            "Meta ({$waehrung})", "Aufschlag {$prozent} % ({$waehrung})", "Summe ({$waehrung})",
        ]];
        foreach ($aufstellung['zeilen'] as $z) {
            $out[] = [
                $z['kostenstelle'] === null ? '' : (string) $z['kostenstelle'], $z['bezeichnung'], $z['rufnummer'], $zeitraum,
                (string) $z['berechnet'], (string) $z['frei'], self::betrag($z['meta']), self::betrag($z['aufschlag']), self::betrag($z['summe']),
            ];
        }
        $g = $aufstellung['gesamt'];
        $out[] = ['', 'Gesamt', '', $zeitraum, (string) $g['berechnet'], (string) $g['frei'], self::betrag($g['meta']), self::betrag($g['aufschlag']), self::betrag($g['summe'])];

        return $out;
    }

    public static function betrag(float $wert): string
    {
        return number_format($wert, 2, ',', '.');
    }
}
