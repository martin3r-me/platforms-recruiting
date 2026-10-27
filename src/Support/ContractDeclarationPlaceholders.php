<?php

namespace Platform\Recruiting\Support;

/**
 * Platzhalter fuer die Erklaerungen zu kurzfristigen Beschaeftigungen und
 * beschaeftigungslosen Zeiten (MA-Arbeitsvertrag, Markus 2026: § 8 und § 16).
 *
 * Steht ein Platzhalter in der Vorlage, setzt RecContract::embedPreSigningData
 * die Antworten GENAU DORT ein, ohne eigene Paragraphen-Ueberschrift. Ohne
 * Platzhalter (RG-Vorlagen) bleibt es beim Einschub „§ 15/§ 16“ vor § 17.
 *
 * Wie {{resttage}} bewusst KEIN field_mapping: so ueberlebt der Platzhalter
 * personalizeContent() und wird erst beim Unterschreiben ersetzt.
 */
final class ContractDeclarationPlaceholders
{
    public const KURZFRISTIG = '{{erklaerung_kurzfristig}}';
    public const BESCHAEFTIGUNGSLOS = '{{erklaerung_beschaeftigungslos}}';

    public static function vorhanden(string $content): bool
    {
        return str_contains($content, self::KURZFRISTIG) || str_contains($content, self::BESCHAEFTIGUNGSLOS);
    }
}
