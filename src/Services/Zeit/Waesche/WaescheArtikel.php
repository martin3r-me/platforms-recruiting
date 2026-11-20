<?php

namespace Platform\Recruiting\Services\Zeit\Waesche;

/**
 * Feste Wäsche-Artikel nach Markus (Folie 16, Kommentar 18.11.2026; Antworten 20.11.2026):
 *
 *   Hemd    – 25 € – Reinigung 4 €        zurück: 4 € in den Lohn, nicht zurück: 25 €
 *   Schürze – 15 € – Reinigung 4 €        zurück: 4 €,              nicht zurück: 15 €
 *   Jacke / Weste – 30 €                  Leihgebühr an den Kunden je ausgegebenem Teil
 *                                         (auch wenn nicht zurück), nicht zurück: 30 € in den Lohn
 *   Sonstiges – freier Artikel mit Preis  nicht zurück: der eingetragene Preis
 *
 * Beträge in Cent. `reinigungLohn` geht bei Rückgabe in den Lohn, `preis` bei
 * Nicht-Rückgabe; `leiheKunde` zählt jedes ausgegebene Teil für die Zusatzleistung.
 */
final class WaescheArtikel
{
    public const SONSTIGES = 'sonstiges';

    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $preisCent,
        public readonly int $reinigungLohnCent,
        public readonly bool $leiheKunde,
    ) {}

    /** @return array<string, self> */
    public static function fest(): array
    {
        return [
            'hemd'     => new self('hemd', 'Hemd', 2500, 400, false),
            'schuerze' => new self('schuerze', 'Schürze', 1500, 400, false),
            'jacke'    => new self('jacke', 'Jacke', 3000, 0, true),
            'weste'    => new self('weste', 'Weste', 3000, 0, true),
        ];
    }

    public static function sonstiges(string $name, int $preisCent): self
    {
        return new self(self::SONSTIGES, $name, max(0, $preisCent), 0, false);
    }
}
