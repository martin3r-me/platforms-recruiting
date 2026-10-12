<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Eine Folie einer Anleitung — die HTML-Felder sind bereits sicher gerendert. */
final class Folie
{
    public function __construct(
        public readonly int $nr,
        public readonly string $kicker,
        public readonly string $titel,
        public readonly ?string $einleitungHtml,
        public readonly ?string $schritteHtml,
        public readonly int $schritteAnzahl,
        public readonly ?Bild $bild,
        public readonly ?string $merksatzHtml,
        public readonly ?string $tabelleHtml,
    ) {}

    public function anker(): string
    {
        return 'folie-' . $this->nr;
    }
}
