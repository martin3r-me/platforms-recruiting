<?php

namespace Platform\Recruiting\Support;

/**
 * Ergebnis der Zuordnungsregel Vorlage → Anstellung (Spec §3.3 d / §3.5).
 * Reines PHP, damit die Befunde ohne Datenbank pruefbar sind.
 */
final class AnstellungsZuordnung
{
    public const ZUGEORDNET = 'zugeordnet';
    public const MEHRDEUTIG = 'mehrdeutig';
    public const FIRMA_FEHLT = 'firma_fehlt';
    public const OHNE_ANSTELLUNG = 'ohne_anstellung';

    /** @param list<int> $kandidatenIds aufsteigend */
    private function __construct(
        public readonly string $befund,
        public readonly array $kandidatenIds,
    ) {}

    /** @param list<int> $passendeIds Anstellungen mit der Firma der Vorlage */
    public static function aus(array $passendeIds, bool $hatAndereAnstellungen): self
    {
        $ids = array_values(array_map('intval', $passendeIds));
        sort($ids);

        return match (true) {
            count($ids) === 1       => new self(self::ZUGEORDNET, $ids),
            count($ids) > 1         => new self(self::MEHRDEUTIG, $ids),
            $hatAndereAnstellungen  => new self(self::FIRMA_FEHLT, []),
            default                 => new self(self::OHNE_ANSTELLUNG, []),
        };
    }

    /** Nur bei eindeutiger Zuordnung. */
    public function anstellungId(): ?int
    {
        return $this->befund === self::ZUGEORDNET ? $this->kandidatenIds[0] : null;
    }

    /** Kleinste Kennung — auch bei mehrdeutig (Spec §3.3 d: Live-Pfad nimmt sie und loggt). */
    public function ersterKandidatId(): ?int
    {
        return $this->kandidatenIds[0] ?? null;
    }
}
