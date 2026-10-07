<?php

namespace Platform\Recruiting\Support;

/**
 * Beschriftung der Vorlagen-Merkmale fuer die MA-Akte (Spec §3.4) — nur
 * Anzeige, keine Logik. Firma: Label aus recruiting.zas.company_labels,
 * sonst der Code selbst (die MA-GmbH hat im Repo keine Namensquelle; der
 * ZAS-Export fuehrt nur den Code). Leer bleibt leer.
 */
final class VorlagenMerkmale
{
    /** @param array<string,string>|null $labels null = aus der Config lesen */
    public static function zeile(?string $company, ?string $taetigkeit, ?array $labels = null): string
    {
        return implode(' · ', array_filter([self::firma($company, $labels), self::taetigkeit($taetigkeit)], fn ($s) => $s !== ''));
    }

    public static function firma(?string $company, ?array $labels = null): string
    {
        $code = trim((string) $company);
        if ($code === '') {
            return '';
        }
        $labels ??= (array) config('recruiting.zas.company_labels', []);

        return (string) ($labels[$code] ?? $code);
    }

    public static function taetigkeit(?string $taetigkeit): string
    {
        $t = trim((string) $taetigkeit);

        return $t === '' ? '' : mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
    }
}
