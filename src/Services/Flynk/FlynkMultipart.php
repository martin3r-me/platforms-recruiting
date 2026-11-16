<?php

namespace Platform\Recruiting\Services\Flynk;

/**
 * JSON-Payload → flache Formularfelder fuer `multipart/form-data`.
 *
 * FLYNK erwartet verschachtelte Werte als `meta[ref_code]`, `meta[posting_uuid]`
 * (Mail 11/2026). Laravels Http-Client flacht Arrays im Multipart-Fall NICHT
 * selbst ab — ein verschachteltes `meta` kaeme dort als kaputter Part an.
 *
 * null-Werte entfallen (ein leeres Formularfeld waere ein leerer String, kein
 * null). Booleans werden 1/0.
 */
final class FlynkMultipart
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    public static function flatten(array $payload, string $prefix = ''): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                $out += self::flatten($value, $name);
                continue;
            }
            if (is_bool($value)) {
                $out[$name] = $value ? '1' : '0';
                continue;
            }
            $out[$name] = (string) $value;
        }

        return $out;
    }
}
