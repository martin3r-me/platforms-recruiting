<?php

namespace Platform\Recruiting\Support;

/**
 * Serverseitige Pruefung einer bereitzustellenden Datei (Spec §3.1, Schritt 1).
 * Prueft den INHALT, nicht nur den Namen: ein umbenanntes JPG heisst .pdf und
 * ist trotzdem keins. Reine Funktion, keine Abhaengigkeiten.
 */
final class DokumentUploadRegeln
{
    public const MAX_BYTES = 12 * 1024 * 1024;

    /** @return ?string null = in Ordnung, sonst Fehlertext fuer HR */
    public static function pruefe(string $inhalt, string $originalName): ?string
    {
        $endung = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($endung !== 'pdf') {
            return 'Nur PDF-Dateien sind erlaubt.';
        }
        if ($inhalt === '') {
            return 'Die Datei ist leer.';
        }
        if (strlen($inhalt) > self::MAX_BYTES) {
            return 'Die Datei ist größer als 12 MB.';
        }
        if (!str_starts_with($inhalt, '%PDF-')) {
            return 'Die Datei ist keine PDF.';
        }

        return null;
    }
}
