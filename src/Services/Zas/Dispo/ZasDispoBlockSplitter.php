<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Zerlegt den ZAS-Webexport in seine {Block}-Abschnitte.
 *
 * Die Dateien haben KEINE Header-Zeilen — die Spaltendefinitionen stammen
 * aus Herrn Michels Mail vom 10.08. und sind hier die einzige Quelle.
 * Bekannte Bloecke (Dispo, Dispo2) werden zu Assoc-Zeilen gemappt, alle
 * anderen roh durchgereicht (Sichtung). Erwartet UTF-8 (vorher
 * CsvEncodingNormalizer::toUtf8()). Wirft nie — kaputte Zeilen werden
 * uebersprungen und gezaehlt.
 */
class ZasDispoBlockSplitter
{
    public const COLUMNS = [
        'Dispo' => [
            'datum', 'einsatzfirma_kurz', 'pnr', 'einsatz_id', 'ze', 'tlp_nr',
            'ds_id', 'von', 'bis', 'status_id', 'essengeld', 'taetigkeit',
            'tlp_nr2', 'verrechnungssatz',
        ],
        'Dispo2' => [
            'datum', 'text', 'einsatz_id', 'taetigkeit_von_bis', 'anzahl',
            'dispoposten_id', 'projektbezeichnung', 'filiale', 'filial_nr',
            'taetigk_id', 'von', 'bis', 'ort', 'einsatzfirma', 'mitarbeiter_info',
            'status_id', 'taetigkeit', 'interne_bem', 'id_firma',
        ],
    ];

    /** Zeilen mit weniger Zellen gelten in bekannten Bloecken als Muell. */
    private const MIN_CELLS = 3;

    /**
     * @return array{known: array<string, list<array<string, string>>>, unknown: array<string, list<list<string>>>, skipped: array<string, int>}
     */
    public function split(string $utf8Content): array
    {
        $known = [];
        $unknown = [];
        $skipped = [];

        $currentBlock = null;
        $lines = preg_split('/\r\n|\r|\n/', $utf8Content) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^\{([^}]+)\}$/', $trimmed, $m)) {
                $currentBlock = $m[1];
                continue;
            }

            if ($currentBlock === null) {
                continue; // Zeilen vor dem ersten Marker ignorieren
            }

            $cells = array_map('trim', str_getcsv($trimmed, ';', '"', ''));

            if (isset(self::COLUMNS[$currentBlock])) {
                if (count($cells) < self::MIN_CELLS) {
                    $skipped[$currentBlock] = ($skipped[$currentBlock] ?? 0) + 1;
                    continue;
                }
                $known[$currentBlock][] = $this->zip(self::COLUMNS[$currentBlock], $cells);
            } else {
                $unknown[$currentBlock][] = $cells;
            }
        }

        return ['known' => $known, 'unknown' => $unknown, 'skipped' => $skipped];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $cells
     * @return array<string, string>
     */
    private function zip(array $columns, array $cells): array
    {
        $out = [];
        $count = max(count($columns), count($cells));
        for ($i = 0; $i < $count; $i++) {
            $key = $columns[$i] ?? ('col_' . $i);
            $out[$key] = $cells[$i] ?? '';
        }

        return $out;
    }
}
