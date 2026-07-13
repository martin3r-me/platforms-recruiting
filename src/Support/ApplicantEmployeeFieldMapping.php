<?php

namespace Platform\Recruiting\Support;

/**
 * Zentrale Zuordnung Bewerber-Extra-Fields (by name) → RecEmployee-Spalten.
 *
 * Single Source of Truth fuer den Create-Flow (CreateEmployeeFromApplicantService)
 * UND den Backfill (recruiting:backfill-employee-fields). Erweitert das Muster
 * von NonEuDocumentMapping auf das gesamte Field-Set, damit Mapping-Drift
 * zwischen Anlage und Nachziehen unmoeglich ist.
 *
 * resolve() liefert NUR befuellte Spalten (kein null-Ausschreiben):
 *  - der Create-Flow kann Contact-Fallbacks via array_merge unterlegen
 *  - der Backfill kann vorhandene MA-Werte nie mit null ueberschreiben
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
class ApplicantEmployeeFieldMapping
{
    /** RecEmployee-Spalte => Extra-Field-Name, Wert 1:1 durchgereicht. */
    public const TEXT_MAP = [
        'first_name'                    => 'vorname',
        'last_name'                     => 'nachname',
        'birth_name'                    => 'geburtsname',
        'birth_date'                    => 'geburtsdatum',
        'birth_place'                   => 'geburtsort',
        'birth_country'                 => 'geburtsland',
        'identity_card_number'          => 'ausweisnummer',
        'email'                         => 'email',
        'street'                        => 'strasse',
        'house_number'                  => 'hausnummer',
        'zip'                           => 'plz',
        'country_code'                  => 'land',
        'employment_type'               => 'ich_bin',
        'umfang_der_tatigkeit'          => 'umfang_der_tatigkeit',
        'iban'                          => 'iban',
        'bic'                           => 'bic',
        'bank_institute'                => 'geldinstitut',
        'steuer_id'                     => 'steuer_id',
        'sozialversicherungsnummer'     => 'sozialversicherungsnummer',
        'gender'                        => 'geschlecht',
        'marital_status'                => 'familienstand',
        'health_insurance'              => 'krankenkasse',
        'drivers_license_class'         => 'fuhrerschein_klasse',
        'recruited_by_personnel_number' => 'geworben_von',
    ];

    /** city hat eine Fallback-Kette ueber zwei historische Feldnamen. */
    public const CITY_SOURCES = ['stadt', 'ort'];

    /** RecEmployee-Spalte => Extra-Field-Name, auf Y-m-d normalisiert. */
    public const DATE_MAP = [
        'identity_card_valid_until'    => 'ausweis_gultig_bis',
        'residence_permit_valid_until' => 'aufenthaltserlaubnis_bis',
        'work_permit_valid_until'      => 'arbeitsgenehmigung_bis',
    ];

    /**
     * RecEmployee-Spalte => Extra-Field-Name fuer Datei-Uploads (file_id).
     * Die Non-EU-Dokumente kommen zusaetzlich aus NonEuDocumentMapping::MAP.
     */
    public const FILE_MAP = [
        'identity_card_front_file_id'   => 'ausweis_reisepass_foto_vorderseite',
        'identity_card_back_file_id'    => 'ausweis_reisepass_foto_ruckseite',
        'selfie_file_id'                => 'selfie_upload',
        'health_insurance_card_file_id' => 'foto_versichertenkarte',
    ];

    /**
     * Legacy-Feldnamen aelterer Formular-Generationen (Definition-IDs 652ff.,
     * betrifft Bewerber vor ~Mai 2026). Greifen nur, wenn der aktuelle
     * Feldname (FILE_MAP/NonEuDocumentMapping) keinen Wert geliefert hat.
     * nationalpass/immatrikulation existieren NUR in der alten Generation.
     */
    public const FILE_ALIASES = [
        'identity_card_front_file_id'   => 'foto_ausweis_vorderseite',
        'identity_card_back_file_id'    => 'foto_ausweis_ruckseite',
        'health_insurance_card_file_id' => 'foto_versicherungskarte',
        'zusatzblatt_file_id'           => 'zusatzblatt',
        'visumsblatt_file_id'           => 'visumsblatt',
        'nationalpass_file_id'          => 'nationalpass',
        'immatrikulation_file_id'       => 'immatrikulationsbescheinigung_schulbescheinigung',
    ];

    /** Multi-Lookup-Felder, als Array (JSON-dekodiert) gespeichert. */
    public const ARRAY_MAP = [
        'beschaftigungsort' => 'beschaftigungsort',
        'art_der_tatigkeit' => 'art_der_tatigkeit',
    ];

    public const BOOL_MAP = [
        'has_car' => 'pkw_vorhanden',
    ];

    /**
     * @param array $extraValues  by-name Extra-Field-Werte des Bewerbers
     * @return array<string, mixed>  ausschliesslich befuellte RecEmployee-Spalten
     */
    public static function resolve(array $extraValues): array
    {
        $out = [];

        foreach (self::TEXT_MAP as $column => $field) {
            $value = $extraValues[$field] ?? null;
            if ($value !== null && $value !== '') {
                $out[$column] = $value;
            }
        }

        foreach (self::CITY_SOURCES as $field) {
            $value = $extraValues[$field] ?? null;
            if ($value !== null && $value !== '') {
                $out['city'] = $value;
                break;
            }
        }

        $phone = self::normalizePhoneValue($extraValues['telefonnummer'] ?? null);
        if ($phone !== null && $phone !== '') {
            $out['phone'] = $phone;
        }

        foreach (self::DATE_MAP as $column => $field) {
            $value = self::normalizeDateValue($extraValues[$field] ?? null);
            if ($value !== null) {
                $out[$column] = $value;
            }
        }

        foreach (self::ARRAY_MAP as $column => $field) {
            $value = self::normalizeArrayValue($extraValues[$field] ?? null);
            if ($value !== null && $value !== []) {
                $out[$column] = $value;
            }
        }

        foreach (self::BOOL_MAP as $column => $field) {
            $value = self::normalizeBoolValue($extraValues[$field] ?? null);
            if ($value !== null) {
                $out[$column] = $value;
            }
        }

        $fileMap = self::FILE_MAP + NonEuDocumentMapping::MAP;
        foreach ($fileMap as $column => $field) {
            $value = NonEuDocumentMapping::normalizeFileId($extraValues[$field] ?? null);
            if ($value !== null) {
                $out[$column] = $value;
            }
        }

        foreach (self::FILE_ALIASES as $column => $field) {
            if (isset($out[$column])) {
                continue;
            }
            $value = NonEuDocumentMapping::normalizeFileId($extraValues[$field] ?? null);
            if ($value !== null) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    /**
     * Alle Quell-Feldnamen (aktuell + legacy), die das Mapping kennt.
     * Basis fuer den Unmapped-Report des Backfill-Commands.
     */
    public static function knownSourceFields(): array
    {
        return array_values(array_unique(array_merge(
            array_values(self::TEXT_MAP),
            self::CITY_SOURCES,
            ['telefonnummer'],
            array_values(self::DATE_MAP),
            array_values(self::ARRAY_MAP),
            array_values(self::BOOL_MAP),
            array_values(self::FILE_MAP),
            array_values(NonEuDocumentMapping::MAP),
            array_values(self::FILE_ALIASES),
        )));
    }

    /**
     * phone-Extra-Field kommt vom Core-Form als Array (raw/country/e164/
     * international) oder JSON-String davon; e164 bevorzugt. Legacy-Strings
     * werden durchgereicht.
     */
    public static function normalizePhoneValue($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw['e164'] ?? $raw['raw'] ?? $raw['international'] ?? null;
        }
        if (is_string($raw) && str_starts_with(trim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded['e164'] ?? $decoded['raw'] ?? $decoded['international'] ?? null;
            }
        }
        return (string) $raw;
    }

    /** Multi-Lookup: JSON-Array-String dekodieren, Skalar zu Single-Array. */
    public static function normalizeArrayValue($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && str_starts_with(trim($raw), '[')) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return [$raw];
    }

    /** Datums-Wert auf Y-m-d normalisieren ("2026-05-21", "21.05.2026"). */
    public static function normalizeDateValue($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function normalizeBoolValue($raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower((string) $raw);
        if (in_array($s, ['1', 'true', 'ja', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'nein', 'no'], true)) {
            return false;
        }
        return null;
    }
}
