<?php
// src/Support/VersandBereitschaft.php
namespace Platform\Recruiting\Support;

/**
 * Darf dieser Bewerber JETZT Vertraege + Portal-Link bekommen?
 *
 *  - bereit:         Mitarbeiter existiert oder Bewerber steht in der Phase, die
 *                    beim Vertragsversand den Mitarbeiter anlegt.
 *  - unvollstaendig: Weg ist gesichert, aber die aktuelle Phase ist noch nicht die
 *                    Anlage-Phase (typisch: Onboarding offen). -> vormerken.
 *  - gesperrt:       kein gesicherter Weg (Phase fremder Stelle, Stelle ohne
 *                    Anlage-Phase, Direkteinstellung, keine Phase). -> Riegel.
 *
 * Spec: docs/superpowers/specs/2026-10-06-versand-vormerken-design.md §1
 */
final class VersandBereitschaft
{
    /** @param list<string> $fehlendeFelder */
    private function __construct(
        public readonly string $status,
        public readonly ?string $grund,
        public readonly array $fehlendeFelder,
    ) {}

    public static function bereit(): self
    {
        return new self('bereit', null, []);
    }

    /** @param list<string> $felder */
    public static function unvollstaendig(array $felder): self
    {
        $liste = $felder === [] ? 'Pflichtfelder der aktuellen Phase' : implode(', ', $felder);

        return new self('unvollstaendig', 'Onboarding unvollständig: ' . $liste, array_values($felder));
    }

    public static function gesperrt(string $grund): self
    {
        return new self('gesperrt', $grund, []);
    }

    public function istBereit(): bool
    {
        return $this->status === 'bereit';
    }

    /** Fuer Spalten und Chips: kurz, ohne Satzpunkt. */
    public function kurztext(): string
    {
        return match ($this->status) {
            'bereit' => 'Bereit',
            'unvollstaendig' => 'Daten fehlen: ' . ($this->fehlendeFelder === [] ? 'Pflichtfelder' : implode(', ', $this->fehlendeFelder)),
            default => 'Gesperrt: ' . $this->grund,
        };
    }
}
