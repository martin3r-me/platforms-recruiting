<?php

namespace Platform\Recruiting\Support;

/**
 * Die 27 EU-Mitgliedstaaten als ISO-3166-Codes in der Schreibweise des
 * Lookups `geburtsland` (klein).
 *
 * Bewusst OHNE EWR/Schweiz: Norweger, Islaender, Liechtensteiner und Schweizer
 * brauchen zwar keine Arbeitsgenehmigung, sind aber keine EU-Buerger. Wer die
 * Liste als "braucht keine Genehmigung" liest, muss sie erweitern — dafuer ist
 * sie nicht gedacht.
 *
 * Stand: 27 Staaten seit dem Brexit (31.01.2020).
 */
final class EuMemberStates
{
    public const CODES = [
        'at', 'be', 'bg', 'cy', 'cz', 'de', 'dk', 'ee', 'es', 'fi', 'fr', 'gr', 'hr', 'hu',
        'ie', 'it', 'lt', 'lu', 'lv', 'mt', 'nl', 'pl', 'pt', 'ro', 'se', 'si', 'sk',
    ];

    public static function contains(?string $code): bool
    {
        return $code !== null && in_array(mb_strtolower(trim($code)), self::CODES, true);
    }
}
