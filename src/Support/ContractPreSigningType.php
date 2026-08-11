<?php

namespace Platform\Recruiting\Support;

/**
 * Entscheidet, welcher Vorschalt-Schritt vor der Unterschrift kommt.
 *
 * Arbeitsvertraege (AV-*) fragen Angaben nach §15/§16 ab. Die Erklaerung
 * zur 140-Tage-Taetigkeit (AT-140) fragt das Rest-Kontingent ab. Alles
 * andere — insbesondere IFSG — geht direkt zu Ansicht und Unterschrift.
 *
 * Eine weitere Vorlage mit Resttage-Frage ist ein Eintrag in
 * RESTTAGE_CODES — mehr nicht.
 */
final class ContractPreSigningType
{
    public const PAR_15_16 = 'par1516';
    public const RESTTAGE  = 'resttage';

    /** Vertragscodes, die vor der Unterschrift nach dem Rest-Kontingent fragen. */
    private const RESTTAGE_CODES = ['AT-140'];

    public static function forCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (in_array($code, self::RESTTAGE_CODES, true)) {
            return self::RESTTAGE;
        }

        if (str_starts_with($code, 'AV-')) {
            return self::PAR_15_16;
        }

        return null;
    }
}
