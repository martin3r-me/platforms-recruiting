<?php

namespace Platform\Recruiting\Support;

/**
 * Kategorien und Aktionen eines bereitgestellten Dokuments (Spec 2026-10-08,
 * §2.1). Die Kategorie belegt die Aktion nur VOR, HR kann sie je Dokument
 * uebersteuern. Eine Stelle fuer Kodes und Labels — Blade und Services fragen
 * hier, nie eigene Listen.
 */
final class DokumentKategorie
{
    public const CONTRACT    = 'contract';
    public const INSTRUCTION = 'instruction';
    public const FORM        = 'form';
    public const PAYSLIP     = 'payslip';
    public const CERTIFICATE = 'certificate';
    public const OTHER       = 'other';
    public const INFO        = 'info';

    public const AKTION_NONE        = 'none';
    public const AKTION_ACKNOWLEDGE = 'acknowledge';
    public const AKTION_SIGN        = 'sign';

    private const KATALOG = [
        self::CONTRACT    => ['label' => 'Vertrag / Zusatzvereinbarung', 'aktion' => self::AKTION_SIGN],
        self::INSTRUCTION => ['label' => 'Belehrung / Unterweisung',     'aktion' => self::AKTION_ACKNOWLEDGE],
        self::FORM        => ['label' => 'Formular',                     'aktion' => self::AKTION_ACKNOWLEDGE],
        self::PAYSLIP     => ['label' => 'Lohnabrechnung',               'aktion' => self::AKTION_NONE],
        self::CERTIFICATE => ['label' => 'Bescheinigung',                'aktion' => self::AKTION_NONE],
        self::OTHER       => ['label' => 'Sonstiges',                    'aktion' => self::AKTION_NONE],
        self::INFO        => ['label' => 'Info / News',                  'aktion' => self::AKTION_NONE],
    ];

    private const AKTIONEN = [
        self::AKTION_NONE        => 'Nur ablegen',
        self::AKTION_ACKNOWLEDGE => 'Zur Kenntnis bestätigen',
        self::AKTION_SIGN        => 'Unterschreiben',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::KATALOG);
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return array_map(static fn (array $k) => $k['label'], self::KATALOG);
    }

    /**
     * Kategorien fuer ein Datei-Formular (PDF hochladen): ohne Text-Kategorien
     * (istText) — die gibt es nur ueber den Umschalter PDF | Info auf der Seite
     * Dokumente. Akte, Veranstaltung und Dokumente-Seite fragen hier
     * (Schlussreview M-2).
     *
     * @return array<string,string>
     */
    public static function labelsFuerDatei(): array
    {
        return array_filter(self::labels(), static fn (string $code) => !self::istText($code), ARRAY_FILTER_USE_KEY);
    }

    public static function label(string $code): string
    {
        return self::KATALOG[$code]['label'] ?? $code;
    }

    public static function exists(string $code): bool
    {
        return isset(self::KATALOG[$code]);
    }

    public static function defaultAktion(string $code): string
    {
        if (!self::exists($code)) {
            throw new \InvalidArgumentException("Unbekannte Dokumentkategorie '{$code}'.");
        }

        return self::KATALOG[$code]['aktion'];
    }

    /**
     * Textbasierte Kategorie (Spec 2026-10-16 §2): statt einer PDF traegt das
     * Dokument content_markdown; die Aktion ist immer AKTION_NONE. Die EINE
     * Stelle fuer diese Regel — Service, Blade und Portal fragen hier.
     */
    public static function istText(string $category): bool
    {
        return $category === self::INFO;
    }

    /** @return array<string,string> Code → Label */
    public static function aktionen(): array
    {
        return self::AKTIONEN;
    }

    public static function aktionExists(string $aktion): bool
    {
        return isset(self::AKTIONEN[$aktion]);
    }

    public static function aktionLabel(string $aktion): string
    {
        return self::AKTIONEN[$aktion] ?? $aktion;
    }

    /** Verlangt diese Aktion etwas vom Menschen? Nur dann gibt es Nachricht und offenen Punkt. */
    public static function brauchtHandlung(string $aktion): bool
    {
        return $aktion === self::AKTION_ACKNOWLEDGE || $aktion === self::AKTION_SIGN;
    }

    /**
     * Bekommt der Empfaenger die Portal-WhatsApp? Alles, was eine Handlung
     * verlangt, UND jede Info (Spec 2026-10-16 §1.3, §7): eine Info ist "nur
     * ablegen" ohne offenen Punkt, aber der Mensch soll erfahren, dass sie da
     * ist. Automatisch zugestellte Info-Zeilen tragen notify_error
     * nicht_noetig und werden deshalb trotzdem nie angeschrieben.
     */
    public static function brauchtHinweis(string $category, string $aktion): bool
    {
        return self::brauchtHandlung($aktion) || self::istText($category);
    }
}
