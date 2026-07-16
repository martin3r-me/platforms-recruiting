<?php

namespace Platform\Recruiting\Support;

use Platform\Recruiting\Models\RecInterviewType;

/**
 * Bewerber-Wording eines Termins aus der Gesprächsart (Name + Genus).
 *
 * Fallback ist IMMER das komplette Paar "Termin"/maskulin — nie ein
 * Custom-Name mit Fallback-Artikel gemischt (falscher Artikel wäre
 * schlimmer als generisch). possessiv() liefert satzmittig ("deine …",
 * "Ihr …"); satzinitial beim Aufrufer mit ucfirst().
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class TerminWort
{
    private function __construct(
        private readonly string $name,
        private readonly string $genus, // 'm' | 'f' | 'n'
    ) {
    }

    public static function fromParts(?string $name, ?string $genus): self
    {
        $name = trim((string) $name);
        $genus = strtolower(trim((string) $genus));
        if ($name === '' || !in_array($genus, ['m', 'f', 'n'], true)) {
            return new self('Termin', 'm');
        }
        return new self($name, $genus);
    }

    public static function from(?RecInterviewType $type): self
    {
        return self::fromParts($type?->name, $type?->genus);
    }

    public function nominativ(): string
    {
        return $this->name;
    }

    public function akkusativMitArtikel(): string
    {
        return match ($this->genus) {
            'f' => 'die ' . $this->name,
            'n' => 'das ' . $this->name,
            default => 'den ' . $this->name,
        };
    }

    public function possessiv(bool $duzen): string
    {
        $pronomen = $this->genus === 'f'
            ? ($duzen ? 'deine' : 'Ihre')
            : ($duzen ? 'dein' : 'Ihr');
        return $pronomen . ' ' . $this->name;
    }
}
