<?php

namespace Platform\Recruiting\Support;

/**
 * R27 — bedingte Sichtbarkeit (visible_if) als reine, geteilte Regel, dazu
 * die Gruppierungs-/Filterlogik fuers Render (leere Gruppen fallen raus).
 *
 * Vorher stand die Auswertung nur in EmployeePortal::fieldIsVisible() und
 * hing an $this->fieldValues (Livewire-Property). Das neue Portal (Task 4,
 * 6, 7) und der Vollstaendigkeitsring (Task 5) brauchen dieselbe Regel ohne
 * Komponenteninstanz — deshalb geloest in reine Logik.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class PortalFieldAccess
{
    /**
     * R27 — dreiwertig: sichtbar, solange die Bedingung nicht ausdruecklich
     * widerlegt ist. Gemessen gegen den Formularwert, ersatzweise gegen den
     * Datensatz (E16) — ein leerer Formularwert ist keine Aussage.
     *
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $datensatz gecastete Attributwerte
     * @param array<string,mixed> $formwerte rohe Formularwerte (Strings aus wire:model)
     */
    public static function istSichtbar(array $meta, array $datensatz, array $formwerte): bool
    {
        foreach (($meta['visible_if'] ?? []) as $feld => $erwartet) {
            $roh = array_key_exists($feld, $formwerte) && trim((string) $formwerte[$feld]) !== ''
                ? $formwerte[$feld]
                : ($datensatz[$feld] ?? null);

            $ist = is_bool($erwartet) ? PortalBoolValue::parse($roh) : $roh;

            if ($ist !== null && $ist !== $erwartet) {
                return false;
            }
        }

        return true;
    }

    /**
     * Die Gruppen, die dieser Mensch sieht — leere fallen heraus (Gegenteil
     * vom alten Blade, das eine leere Gruppe als leere Karte mit
     * Ueberschrift rendert).
     *
     * @param array<string, array<string, array<string,mixed>>> $gruppen
     * @param array<string,mixed> $datensatz
     * @param array<string,mixed> $formwerte
     * @return array<string, array<string, array<string,mixed>>>
     */
    public static function sichtbareGruppen(array $gruppen, array $datensatz, array $formwerte): array
    {
        $aus = [];
        foreach ($gruppen as $gruppe => $felder) {
            $sichtbar = [];
            foreach ($felder as $schluessel => $meta) {
                if (self::istSichtbar($meta, $datensatz, $formwerte)) {
                    $sichtbar[$schluessel] = $meta;
                }
            }
            if ($sichtbar !== []) {
                $aus[$gruppe] = $sichtbar;
            }
        }

        return $aus;
    }

    /**
     * Flache Feldliste ueber alle sichtbaren Gruppen hinweg — Metadaten
     * bleiben je Feld erhalten.
     *
     * @param array<string, array<string, array<string,mixed>>> $gruppen
     * @param array<string,mixed> $datensatz
     * @param array<string,mixed> $formwerte
     * @return array<string, array<string,mixed>>
     */
    public static function sichtbareFelderFlach(array $gruppen, array $datensatz, array $formwerte): array
    {
        $flach = [];
        foreach (self::sichtbareGruppen($gruppen, $datensatz, $formwerte) as $felder) {
            foreach ($felder as $schluessel => $meta) {
                $flach[$schluessel] = $meta;
            }
        }

        return $flach;
    }
}
