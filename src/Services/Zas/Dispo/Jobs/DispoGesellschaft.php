<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * Gesellschaft einer Anstellung: RG oder MA (Feld `company`, Praefix der
 * Personalnummer). NICHT verwechseln mit dem ZAS-Status GO/MA (ZasStatus).
 *
 * Kunde 27.10.2026: „je Filiale je Gesellschaft ausweisen. So gefiltert
 * schreiben wir dann auch an.“ Rein rechnend.
 */
final class DispoGesellschaft
{
    public const RG = 'RG';
    public const MA = 'MA';

    public const ALLE = [self::RG => 'RG', self::MA => 'MA'];

    /**
     * Nur bekannte Werte, feste Reihenfolge RG, MA.
     *
     * @param array<int|string,mixed> $werte
     * @return list<string>
     */
    public static function normalize(array $werte): array
    {
        $gewaehlt = [];
        foreach ($werte as $w) {
            if (is_scalar($w)) {
                $gewaehlt[strtoupper(trim((string) $w))] = true;
            }
        }

        return array_values(array_filter(array_keys(self::ALLE), fn ($g) => isset($gewaehlt[$g])));
    }

    /** Gesellschaft einer Anstellung aus `company`; alles ausser MA ist RG (Export-Default). */
    public static function ausCompany(?string $company): string
    {
        return strtoupper(trim((string) $company)) === self::MA ? self::MA : self::RG;
    }

    /**
     * Gesellschaft einer Veranstaltung aus dem ZAS-Text `einsatzfirma`, wenn
     * eindeutig; sonst null (= Vorbelegung beide). „MA“ zaehlt nur als eigenes
     * Wort, damit „Maritim“ nicht zur Gesellschaft MA wird.
     */
    public static function ausEinsatzfirma(?string $text): ?string
    {
        $t = trim((string) $text);
        if ($t === '') {
            return null;
        }
        if (preg_match('/(^|[^\p{L}])MA([^\p{L}]|$)/u', $t) === 1) {
            return self::MA;
        }
        if (preg_match('/(^|[^\p{L}])RG([^\p{L}]|$)/u', $t) === 1 || stripos($t, 'rhein') !== false) {
            return self::RG;
        }

        return null;
    }

    /** Beschriftung einer Welle: null oder beide = kein Zusatz. */
    public static function label(?array $firmen): string
    {
        if ($firmen === null) {
            return '';
        }
        $f = self::normalize($firmen);

        return match (count($f)) {
            0 => 'keine Gesellschaft',
            1 => $f[0],
            default => '',
        };
    }
}
