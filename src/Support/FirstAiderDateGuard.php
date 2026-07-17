<?php

namespace Platform\Recruiting\Support;

/**
 * Datumspflicht-Kopplung Ersthelfer: der Endzustand "Ersthelfer=Ja ohne
 * Bis-Datum" darf die HR-Maske nicht verlassen (Spec 2026-07-17).
 * Pure Funktion auf den rohen Formularwerten (Strings aus wire:model,
 * Bool-Select liefert ''/'1'/'0') — kein Laravel, unit-testbar.
 *
 * Endzustands-Pruefung, bewusst: blockt auch Saves, bei denen nur ein
 * anderes Feld geaendert wurde — so wird ein per lenientem ZAS-Import
 * entstandener "Ja ohne Datum"-MA beim naechsten Edit repariert.
 */
class FirstAiderDateGuard
{
    /** Fehlertext oder null wenn der Zustand konsistent ist. */
    public static function error(mixed $isFirstAider, mixed $validUntil): ?string
    {
        $flag = mb_strtolower(trim((string) ($isFirstAider ?? '')));
        $isSet = in_array($flag, ['1', 'true', 'ja'], true);
        $date = trim((string) ($validUntil ?? ''));

        if (!$isSet || $date !== '') {
            return null;
        }

        return 'Ersthelfer-Datum fehlt: "Ersthelfer-Schein gueltig bis" ist Pflicht, sobald Ersthelfer auf Ja steht. Es wurde nichts gespeichert.';
    }
}
