<?php

namespace Platform\Recruiting\Support\Anleitungen;

/**
 * Alle Anleitungen aus docs/anleitungen/ (Spec 2026-10-12). Liest den Ordner
 * je Prozess einmal (statischer Zwischenspeicher je Verzeichnis). README.md
 * ist Doku fuer uns, keine Anleitung. Eine kaputte Datei kippt nichts: sie
 * steht mit Grund in fehlerhaft(), alle anderen bleiben sichtbar.
 */
final class AnleitungKatalog
{
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
    private const BILDNAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(png|jpe?g|webp)$/';

    /** @var array<string, list<Anleitung>> */
    private static array $cache = [];

    public function __construct(private readonly string $verzeichnis) {}

    public static function standard(): self
    {
        return new self(dirname(__DIR__, 3) . '/docs/anleitungen');
    }

    public static function vergessen(): void
    {
        self::$cache = [];
    }

    /** @return list<Anleitung> */
    public function alle(): array
    {
        return self::$cache[$this->verzeichnis] ??= $this->lesen();
    }

    /** @return array<string, list<Anleitung>> */
    public function nachBereich(): array
    {
        $out = [];
        foreach (AnleitungParser::BEREICHE as $bereich) {
            $liste = array_values(array_filter($this->alle(), fn (Anleitung $a) => $a->fehler === null && $a->bereich === $bereich));
            if ($liste !== []) {
                $out[$bereich] = $liste;
            }
        }

        return $out;
    }

    /** @return list<Anleitung> */
    public function fehlerhaft(): array
    {
        return array_values(array_filter($this->alle(), fn (Anleitung $a) => $a->fehler !== null));
    }

    public function finde(string $slug): ?Anleitung
    {
        if (!preg_match(self::SLUG, $slug)) {
            return null;
        }
        foreach ($this->alle() as $a) {
            if ($a->slug === $slug) {
                return $a;
            }
        }

        return null;
    }

    /** @return list<Anleitung> */
    public function fuerRoute(string $routeName): array
    {
        return array_values(array_filter(
            $this->alle(),
            fn (Anleitung $a) => $a->fehler === null && in_array($routeName, $a->seiten, true)
        ));
    }

    public function bildPfad(string $slug, string $datei): ?string
    {
        if (!preg_match(self::SLUG, $slug) || !preg_match(self::BILDNAME, $datei) || str_contains($datei, '..')) {
            return null;
        }
        $ordner = realpath($this->verzeichnis . '/bilder/' . $slug);
        $pfad = realpath($this->verzeichnis . '/bilder/' . $slug . '/' . $datei);
        if ($ordner === false || $pfad === false || !is_file($pfad)) {
            return null;
        }

        return str_starts_with($pfad, $ordner . DIRECTORY_SEPARATOR) ? $pfad : null;
    }

    /** @return list<Anleitung> */
    private function lesen(): array
    {
        $gueltig = [];
        $kaputt = [];
        foreach (glob($this->verzeichnis . '/*.md') ?: [] as $datei) {
            $slug = basename($datei, '.md');
            if ($slug === 'README') {
                continue;
            }
            $a = preg_match(self::SLUG, $slug)
                ? AnleitungParser::parse($slug, (string) file_get_contents($datei))
                : Anleitung::kaputt($slug, 'Dateiname nur aus Kleinbuchstaben, Ziffern und Bindestrichen');
            if ($a->fehler === null) {
                $gueltig[] = $a;
            } else {
                $kaputt[] = $a;
            }
        }

        $rang = array_flip(AnleitungParser::BEREICHE);
        usort($gueltig, fn (Anleitung $x, Anleitung $y) => [$rang[$x->bereich], $x->titel] <=> [$rang[$y->bereich], $y->titel]);
        usort($kaputt, fn (Anleitung $x, Anleitung $y) => strcmp($x->slug, $y->slug));

        return array_merge($gueltig, $kaputt);
    }
}
