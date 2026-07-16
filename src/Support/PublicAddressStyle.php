<?php

namespace Platform\Recruiting\Support;

/**
 * Normalisiert den Team-Setting-Wert `use_informal_address` zu einem
 * strikten Bool. Default ist IMMER Sie (false) — nur ein explizit
 * aktiviertes Setting duzt. Das Settings-JSON kann je nach Herkunft
 * (Checkbox-Toggle, Livewire-Hydration, Alt-Daten) bool, int oder
 * string enthalten; alles Unbekannte faellt auf Sie zurueck.
 *
 * Single Source of Truth fuer die Anrede-Aufloesung auf oeffentlichen
 * Seiten. Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
class PublicAddressStyle
{
    public static function informal(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true'], true);
        }
        return false;
    }
}
