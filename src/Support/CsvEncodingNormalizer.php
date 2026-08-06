<?php

namespace Platform\Recruiting\Support;

/**
 * Normalisiert CSV-/Text-Rohbytes fuer Parsing und Anzeige: erkennt das
 * Encoding (UTF-8 / Windows-1252 / ISO-8859-1 / ASCII, Fallback Windows-1252),
 * konvertiert nach UTF-8 und strippt eine fuehrende UTF-8-BOM.
 *
 * Extrahiert aus ImportApplicantsCsvService::readCsv() — identisches
 * Verhalten. Die Rohdatei auf dem Disk bleibt unveraendert; normalisiert
 * wird nur die In-Memory-Kopie (Windows-1252-Bytes wuerden sonst
 * json_encode in Livewire-Komponenten scheitern lassen → 500).
 */
class CsvEncodingNormalizer
{
    public static function toUtf8(string $raw): string
    {
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ASCII'], true) ?: 'Windows-1252';
        if ($encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }

        return (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);
    }
}
