<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

/**
 * Effektiver "Ansprechpartner vor Ort" einer VA (Runde-3-Nachzug, Kundenwunsch):
 * Die Teamleitung ist der STANDARD, eine manuelle Eingabe ueberschreibt ihn.
 *
 * Persistenz-Vertrag: rec_dispo_events.ansprechpartner haelt NUR manuelle
 * Ueberschreibungen. null (oder identisch mit der Standard-Teamleitung) = Standard,
 * der live aus den Einbuchungen kommt — wechselt die Teamleitung in ZAS, zieht
 * der Ansprechpartner ueberall mit, ohne Neu-Senden. Rein, kein DB-Zugriff.
 */
final class DispoContactResolver
{
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    /**
     * @param list<array{employee_id:int, name:string, phone:?string, label:string}> $leads (Reihenfolge = Standard zuerst)
     * @return array{label:?string, source:?string} source: 'auto' | 'manual' | null (nichts vorhanden)
     */
    public static function effective(?string $stored, array $leads): array
    {
        $stored = trim((string) $stored);
        $default = $leads[0]['label'] ?? null;

        if ($stored !== '' && $stored !== $default) {
            return ['label' => $stored, 'source' => self::SOURCE_MANUAL];
        }
        if ($default !== null) {
            return ['label' => $default, 'source' => self::SOURCE_AUTO];
        }

        return ['label' => null, 'source' => null];
    }

    /**
     * Was aus der Eingabe gespeichert wird: leer oder identisch mit der
     * Standard-Teamleitung -> null (Standard), sonst der getrimmte Text.
     * Eine ANDERE Teamleitung (leads[1..]) gilt bewusst als manuelle Wahl,
     * sonst spraenge die Auswahl beim naechsten Oeffnen auf leads[0] zurueck.
     */
    public static function toStore(string $input, array $leads): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }
        $default = $leads[0]['label'] ?? null;

        return $input === $default ? null : mb_substr($input, 0, 255);
    }
}
