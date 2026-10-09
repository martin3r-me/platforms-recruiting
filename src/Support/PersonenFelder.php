<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die Felder, die dem MENSCHEN gehoeren und deshalb auf allen Akten (RG, MA)
 * gleich sein muessen (Spec 2026-10-09 §2.1). EINE Liste — Spiegel und
 * Pruefkommando lesen nur hier.
 */
final class PersonenFelder
{
    public const SPIEGELN = [
        'first_name', 'last_name', 'birth_name', 'birth_date', 'birth_place', 'gender',
        'email',
        'street', 'house_number', 'zip', 'city', 'country_code', 'birth_country', 'nationality', 'is_eu_citizen',
        'identity_card_number', 'identity_card_valid_until', 'drivers_license_class',
        'marital_status', 'number_of_children', 'religion', 'employment_type',
        'iban', 'bic', 'bank_institute', 'account_holder',
        'tax_class', 'steuer_id', 'sozialversicherungsnummer', 'health_insurance',
        'is_main_employer', 'other_employer',
    ];

    public const DATUM = ['birth_date', 'identity_card_valid_until'];
    public const BOOL  = ['is_main_employer', 'is_eu_citizen'];

    /** @return list<string> */
    public static function fuerTeam(?int $teamId): array
    {
        if ($teamId !== null && (bool) RecApplicantSettings::getOrCreateForTeam($teamId)->getSetting('tax_class_per_company', false)) {
            return array_values(array_diff(self::SPIEGELN, ['tax_class']));
        }

        return self::SPIEGELN;
    }

    /** Vergleichsform: leer = null, Datum ohne Uhrzeit, Bool als '1'/'0'. */
    public static function normalisiere(string $feld, mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }
        if (in_array($feld, self::BOOL, true)) {
            return ((bool) (is_string($wert) ? trim($wert) : $wert)) ? '1' : '0';
        }
        $text = trim((string) $wert);
        if ($text === '') {
            return null;
        }

        return in_array($feld, self::DATUM, true) ? substr($text, 0, 10) : $text;
    }
}
