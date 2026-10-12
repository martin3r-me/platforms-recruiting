<?php

namespace Platform\Recruiting\Support\Anleitungen;

/** Eine Kundenanleitung aus docs/anleitungen/<slug>.md. $fehler != null = Datei unlesbar. */
final class Anleitung
{
    /**
     * @param list<string> $seiten Routennamen, auf denen das „?“ erscheint
     * @param list<Folie>  $folien
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $titel,
        public readonly string $bereich,
        public readonly string $stand,
        public readonly array $seiten,
        public readonly array $folien,
        public readonly ?string $fehler = null,
    ) {}

    public static function kaputt(string $slug, string $grund): self
    {
        return new self($slug, $slug, '', '', [], [], $grund);
    }

    public function standDeutsch(): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->stand);

        return $d ? $d->format('d.m.Y') : '';
    }
}
