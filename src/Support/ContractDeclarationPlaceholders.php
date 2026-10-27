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

    /** Leerzeichen innen sind erlaubt: „{{ erklaerung_kurzfristig }}“ (Review 27.10.). */
    public const MUSTER_KURZFRISTIG = '/\{\{\s*erklaerung_kurzfristig\s*\}\}/u';
    public const MUSTER_BESCHAEFTIGUNGSLOS = '/\{\{\s*erklaerung_beschaeftigungslos\s*\}\}/u';

    public static function vorhanden(string $content): bool
    {
        return self::hatKurzfristig($content) || self::hatBeschaeftigungslos($content);
    }

    public static function hatKurzfristig(string $content): bool
    {
        return preg_match(self::MUSTER_KURZFRISTIG, $content) === 1;
    }

    public static function hatBeschaeftigungslos(string $content): bool
    {
        return preg_match(self::MUSTER_BESCHAEFTIGUNGSLOS, $content) === 1;
    }

    /** Ersetzt per Callback — ein „$1“ in den Antworten bleibt Text. */
    public static function ersetze(string $content, string $muster, string $html): string
    {
        return (string) preg_replace_callback($muster, static fn () => $html, $content);
    }

    /**
     * Harter Stopp vor dem Unterschreiben (Review 27.10.): steht nach dem
     * Einsetzen noch ein Rest im Text — auch zerstueckelt durch Formatierung
     * („{{<span>erklaerung_</span>kurzfristig}}“) oder in einer Vorlage, deren
     * Code keinen Vorschalt-Schritt ausloest —, darf nicht unterschrieben werden.
     */
    public static function offen(string $content): bool
    {
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_match('/erklaerung_(kurzfristig|beschaeftigungslos)/u', $text) === 1;
    }
}
