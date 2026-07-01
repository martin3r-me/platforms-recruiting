<?php

namespace Platform\Recruiting\Support;

/**
 * Zentrale Zuordnung der Nicht-EU-Dokument-Uploads eines Bewerbers
 * (extra_field_values, by name) auf die RecEmployee-Dateispalten.
 *
 * Single Source of Truth — ersetzt die frueher inline im
 * CreateEmployeeFromApplicantService verstreute Zuordnung, die zu einer
 * "halben Migration" gefuehrt hatte (manche Felder aus der nie befuellten
 * legalStatus-Kruecke gelesen). Der zugehoerige Unit-Test schlaegt fehl,
 * falls Ziel-Spalten oder Quell-Feldnamen driften.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
class NonEuDocumentMapping
{
    /** RecEmployee-Spalte => Applicant-Extra-Field-Name (Phase Onboarding). */
    public const MAP = [
        'aufenthaltstitel_front_file_id'      => 'aufenthaltstitel_vorderseite',
        'aufenthaltstitel_back_file_id'       => 'aufenthaltstitel_ruckseite',
        'visumsblatt_file_id'                 => 'visum_foto',
        'zusatzblatt_file_id'                 => 'zusatzblatt_arbeitsgenehmigung_vorderseite',
        'zusatzblatt_back_file_id'            => 'zusatzblatt_arbeitsgenehmigung_ruckseite',
        'fiktionsbescheinigung_front_file_id' => 'fiktionsbescheinigung_vorderseite',
        'fiktionsbescheinigung_back_file_id'  => 'fiktionsbescheinigung_ruckseite',
    ];

    /**
     * @param array $extraValues  by-name Extra-Field-Werte des Bewerbers
     * @return array<string, int|null>  RecEmployee-Dateispalten → file_id|null
     */
    public static function resolve(array $extraValues): array
    {
        $out = [];
        foreach (self::MAP as $column => $fieldName) {
            $out[$column] = self::normalizeFileId($extraValues[$fieldName] ?? null);
        }
        return $out;
    }

    /**
     * File-Werte liegen als file_id (numeric) oder — defensiv — als Multi-File
     * JSON-Array vor. Gibt die (erste) file_id als int zurueck, sonst null.
     */
    public static function normalizeFileId($raw): ?int
    {
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }
        if ($raw === null || $raw === '' || $raw === '0' || $raw === 0) {
            return null;
        }
        return is_numeric($raw) ? (int) $raw : null;
    }
}
