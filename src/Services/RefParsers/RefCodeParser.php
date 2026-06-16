<?php

namespace Platform\Recruiting\Services\RefParsers;

class RefCodeParser implements SourceRefParser
{
    /** Zeichensatz ohne verwechselbare I, O, 0, 1. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const PATTERN = '/\bRG-([A-HJ-NP-Z2-9]{4})\b/i';

    public function extract(?string $subject, ?string $body): ?string
    {
        foreach ([$subject, $body] as $haystack) {
            if ($haystack && preg_match(self::PATTERN, $haystack, $m)) {
                return 'RG-' . strtoupper($m[1]);
            }
        }

        return null;
    }

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'RG-' . $code;
    }
}
