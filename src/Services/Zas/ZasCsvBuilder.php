<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Baut die ZAS-CSV im Legacy-Format aus dem WordPress/Quform-Setup.
 *
 * Format-Konventionen (alle bewusst beibehalten, weil ZAS-Importer
 * positional liest und Hr. Michel sich am Bestandsformat orientiert):
 *
 *   - Encoding: UTF-8 mit BOM (\xEF\xBB\xBF)
 *   - Trenner: `;` (Semikolon)
 *   - Zeilenende: `|;\n` (Pipe + Semikolon + LF)
 *   - Leere Werte: leerer String zwischen den Trennern
 *   - Werte werden roh ausgegeben — keine Quoting-Logik. Deshalb
 *     muessen Werte vorher von Semikolons / Newlines / |-Zeichen
 *     bereinigt werden, sonst zerschiesst es das Schema.
 *
 * Header und Daten kommen vom ZasFieldResolver — Reihenfolge dort
 * verbindlich definiert (ZasFieldResolver::COLUMNS).
 */
class ZasCsvBuilder
{
    public const BOM = "\xEF\xBB\xBF";
    public const SEPARATOR = ';';
    public const LINE_END = "|;\n";

    /**
     * Baut das vollstaendige CSV-Dokument inklusive BOM + Header-Zeile.
     *
     * @param  array<int, array<string, string>>  $rows  Liste von Bewerber-Arrays
     *                                                   (Spalte → Wert), Reihenfolge
     *                                                   muss zu COLUMNS passen.
     */
    public function build(array $rows): string
    {
        $output = self::BOM;
        $output .= $this->buildHeaderLine();

        foreach ($rows as $row) {
            $output .= $this->buildRow($row);
        }

        return $output;
    }

    /**
     * Header-Zeile aus den Spaltennamen.
     */
    public function buildHeaderLine(): string
    {
        return implode(self::SEPARATOR, ZasFieldResolver::COLUMNS) . self::LINE_END;
    }

    /**
     * Eine Datensatz-Zeile. Spalten-Reihenfolge muss exakt der
     * Header-Reihenfolge entsprechen.
     */
    public function buildRow(array $row): string
    {
        $cells = [];
        foreach (ZasFieldResolver::COLUMNS as $column) {
            $cells[] = $this->sanitize((string) ($row[$column] ?? ''));
        }
        return implode(self::SEPARATOR, $cells) . self::LINE_END;
    }

    /**
     * Bereinigt einen Zellen-Wert von Zeichen die das ZAS-CSV-Format
     * brechen wuerden. Wir machen kein RFC-4180-Quoting (das verstuende
     * der ZAS-Importer nicht), deshalb stripen wir die Zeichen statt sie
     * zu escapen.
     *
     *   - Semikolon → Komma (sonst falsche Spalten-Splits)
     *   - Pipe-Semikolon (|;) → entfernt (sonst frueher Zeilenende)
     *   - CR/LF → Leerzeichen (sonst Zeilenumbruch in Zelle)
     */
    protected function sanitize(string $value): string
    {
        // Pipe-Semikolon-Kombi vorrangig entfernen, bevor wir den
        // Semikolon einzeln behandeln.
        $value = str_replace('|;', '', $value);
        $value = str_replace(';', ',', $value);
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        return $value;
    }
}
