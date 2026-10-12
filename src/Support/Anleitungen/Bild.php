<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Ein Bild einer Folie: Dateiname im Ordner bilder/<slug>/, Alt-Text, Screenshot-Rezept. */
final class Bild
{
    /** @param array<string, string|true> $rezept */
    public function __construct(
        public readonly string $datei,
        public readonly string $alt,
        public readonly array $rezept,
    ) {}
}
