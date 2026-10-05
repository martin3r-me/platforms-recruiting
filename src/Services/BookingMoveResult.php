<?php

namespace Platform\Recruiting\Services;

/**
 * Ergebnis eines Verschiebe-Laufs. Entweder $error (nichts wurde angefasst,
 * z. B. Zieltermin unzulaessig) oder eine Aufteilung in verschoben und
 * uebersprungen mit Grund je Buchung.
 */
final class BookingMoveResult
{
    /**
     * @param list<int>          $moved   Buchungs-IDs, die jetzt im Zieltermin liegen
     * @param array<int, string> $skipped Buchungs-ID => Grund
     */
    public function __construct(
        public readonly array $moved = [],
        public readonly array $skipped = [],
        public readonly ?string $error = null,
    ) {}

    public static function failed(string $error): self
    {
        return new self(error: $error);
    }
}
