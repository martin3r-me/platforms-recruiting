<?php

namespace Platform\Recruiting\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Zentrale Telefonnummern-Normalisierung nach E.164 (pur, kein DB-Zugriff).
 *
 * Hintergrund (Befund 01.09.): Der ZAS-Import liefert bei Neuanlagen Nummern
 * in jedem denkbaren Format — national mit 0 ("0176..."), ohne 0 und ohne +
 * ("1766..."), mit Leerzeichen ("0 176 ..."), teils mit unsichtbaren
 * Unicode-Richtungszeichen aus Copy-Paste. Meta akzeptiert nur E.164; eine
 * nationale Nummer wird als US-Nummer gedeutet -> "Message undeliverable"
 * (131026). Diese Klasse ist der eine Trichter fuer alle Sendewege.
 *
 * Kontrakt: normalize() liefert E.164 ("+4917624533557") oder null, wenn die
 * Nummer nicht als gueltige Rufnummer parsebar ist. Der Aufrufer entscheidet,
 * ob er bei null die Rohnummer weiterreicht (Sendeweg: nicht schlechter als
 * vorher) oder den Fall listet (Bestands-Backfill).
 */
final class PhoneE164
{
    /**
     * @param string $region Default-Region fuer Nummern ohne Laendercode (ISO, z. B. 'DE')
     */
    public static function normalize(?string $raw, string $region = 'DE'): ?string
    {
        $clean = self::stripInvisible((string) $raw);
        if ($clean === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $parsed = $util->parse($clean, $region);
            if (!$util->isValidNumber($parsed)) {
                return null;
            }
        } catch (NumberParseException) {
            return null;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * Festnetznummer? (formal gueltig, aber praktisch nie WhatsApp-faehig —
     * der Backfill listet solche Nummern zur Datenpflege beim Kunden.)
     */
    public static function isFixedLine(string $e164): bool
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            $type = $util->getNumberType($util->parse($e164, null));
        } catch (NumberParseException) {
            return false;
        }

        return in_array($type, [PhoneNumberType::FIXED_LINE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)
            && $type === PhoneNumberType::FIXED_LINE;
    }

    /**
     * Entfernt unsichtbare Steuer-/Richtungszeichen (LRM/RLM, LRE..PDF,
     * Isolate, Zero-Width, NBSP) — die Ursache der "sonstig"-Formate aus
     * Copy-Paste — und trimmt.
     */
    public static function stripInvisible(string $raw): string
    {
        $clean = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00A0}]/u', '', $raw) ?? $raw;

        return trim($clean);
    }
}
