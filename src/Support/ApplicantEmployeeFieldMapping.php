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

    /**
     * Spaltenbreiten der rec_employees-Textspalten (Stand der Migrationen).
     * resolve() kappt jeden Wert darauf, statt ihn 1:1 durchzureichen.
     *
     * Warum: ein einziger zu langer Wert liess bisher den kompletten INSERT
     * mit SQLSTATE[22001] sterben — und mit ihm die Mitarbeiter-Anlage, an
     * der im Versand-Pfad die Portal-WhatsApp haengt. Der Bewerber bekam
     * dann gar keine Nachricht, obwohl die Vertraege als "versendet"
     * markiert waren (Bewerber #2381, 25.08.2026). Ein gekappter Wert ist
     * verkraftbar, ein verlorener Mitarbeiter nicht.
     *
     * Gleiche Schutzlogik wie ZasInboundRowMapper::MAX_LENGTHS auf dem
     * Inbound-Pfad — der hatte sie von Anfang an, der Create-Flow nicht.
     * Nur skalare Textspalten: birth_date/DATE_MAP sind DATE, ARRAY_MAP
     * ist JSON, FILE_MAP ist int.
     */
    public const MAX_LENGTHS = [
        'first_name'                    => 120,
        'last_name'                     => 120,
        'birth_name'                    => 120,
        'birth_place'                   => 120,
        'birth_country'                 => 64,
        'identity_card_number'          => 64,
        'email'                         => 255,
        'street'                        => 255,
        'house_number'                  => 16,
        'zip'                           => 16,
        'city'                          => 120,
        'country_code'                  => 64,
        'phone'                         => 64,
        'employment_type'               => 64,
        'umfang_der_tatigkeit'          => 64,
        'iban'                          => 64,
        'bic'                           => 32,
        'bank_institute'                => 120,
        'steuer_id'                     => 32,
        'sozialversicherungsnummer'     => 32,
        'gender'                        => 32,
        'marital_status'                => 32,
        'health_insurance'              => 64,
        'drivers_license_class'         => 32,
        'recruited_by_personnel_number' => 64,
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

        return self::clampToColumnWidths($out);
    }

    /**
     * Kappt alle Textspalten auf ihre Spaltenbreite (siehe MAX_LENGTHS).
     * mb_substr, nicht substr: ein mitten im Byte-Paar gekappter Umlaut
     * waere kein gueltiges UTF-8 und wuerde von MySQL erst recht abgelehnt.
     *
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    private static function clampToColumnWidths(array $columns): array
    {
        foreach (self::MAX_LENGTHS as $column => $maxLength) {
            if (!isset($columns[$column]) || !is_string($columns[$column])) {
                continue;
            }
            if (mb_strlen($columns[$column]) > $maxLength) {
                $columns[$column] = mb_substr($columns[$column], 0, $maxLength);
            }
        }

        return $columns;
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
