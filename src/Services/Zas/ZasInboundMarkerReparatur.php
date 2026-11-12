<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Vorfall 06.11.–12.11.2026: ZAS lieferte die Personal-Datei mit intakter
 * Kopfzeile (`…;ArbeitstageRest;|;`), aber OHNE den Zeilenende-Marker in den
 * Datenzeilen (`…;2;70;`). Der Importer weist solche Zeilen zu Recht ab
 * (detectRowStructureIssue) — eine Woche lang kam keine Zeile an.
 *
 * Diese Klasse ergaenzt den Marker fuer einen bewussten Nachlauf
 * (recruiting:zas-inbound-reprocess --marker-fehlt), und nur im Speicher: die
 * Rohdatei bleibt, wie ZAS sie geschickt hat, und der Importer bleibt streng.
 * Ergaenzt wird ausschliesslich, wenn die Zeile nachweislich NUR den Marker
 * verloren hat: genau ein Feld weniger als der Kopf, endet mit dem
 * Trennzeichen, und der Marker steht nirgends in der Zeile. Passt eine einzige
 * Zeile weder dazu noch zur intakten Form, wird die ganze Datei abgelehnt —
 * dann ist mehr verrutscht als der Marker. Rein.
 */
final class ZasInboundMarkerReparatur
{
    public const ERGAENZT = 'ergaenzt';
    public const INTAKT = 'intakt';
    public const ABGELEHNT = 'abgelehnt';

    private const TRENNER = ';';
    private const MARKER = '|';

    /**
     * @return array{stand: string, content: string, ergaenzt: int, zeilen: int, grund: string}
     *         content = bei ERGAENZT der Inhalt mit Marker, sonst unveraendert
     */
    public static function pruefen(string $content): array
    {
        $clean = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        // Zeilen wie ZasInboundCsvParser::parse — dieselbe Sicht auf die Datei.
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $clean) ?: [], fn ($l) => trim($l) !== ''));
        $ablehnen = fn (string $grund) => ['stand' => self::ABGELEHNT, 'content' => $content, 'ergaenzt' => 0, 'zeilen' => max(0, count($lines) - 1), 'grund' => $grund];

        if ($lines === []) {
            return $ablehnen('Datei ist leer');
        }
        $kopf = self::felder($lines[0]);
        $n = count($kopf);
        if ($n < 3 || $kopf[$n - 2] !== self::MARKER || $kopf[$n - 1] !== '') {
            return $ablehnen('Kopfzeile endet nicht mit „;|;“');
        }

        $ergaenzt = 0;
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $werte = self::felder($line);
            $anzahl = count($werte);
            if ($anzahl === $n && $werte[$n - 2] === self::MARKER && $werte[$n - 1] === '') {
                continue;
            }
            $nurMarkerFehlt = $anzahl === $n - 1
                && $werte[$anzahl - 1] === ''
                && str_ends_with(rtrim($line), self::TRENNER)
                && !in_array(self::MARKER, $werte, true);
            if (!$nurMarkerFehlt) {
                return $ablehnen(sprintf('Zeile %d: %d Felder statt %d, oder der Marker steht an falscher Stelle — mehr als der fehlende Marker', $i, $anzahl, $n));
            }
            $lines[$i] = rtrim($line) . self::MARKER . self::TRENNER;
            $ergaenzt++;
        }

        if ($ergaenzt === 0) {
            return ['stand' => self::INTAKT, 'content' => $content, 'ergaenzt' => 0, 'zeilen' => count($lines) - 1, 'grund' => ''];
        }

        return ['stand' => self::ERGAENZT, 'content' => implode("\n", $lines) . "\n", 'ergaenzt' => $ergaenzt, 'zeilen' => count($lines) - 1, 'grund' => ''];
    }

    /** Feldzerlegung exakt wie der Parser (Anfuehrungszeichen schuetzen Semikolons im Wert). */
    private static function felder(string $line): array
    {
        return array_map('trim', str_getcsv($line, self::TRENNER, '"', ''));
    }
}
