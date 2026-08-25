<?php

namespace Platform\Recruiting\Services\Zas;

/**
 * Paketgroessen-Waechter fuer POST /recruiting/zas/inbound.
 *
 * Die Verarbeitung laeuft synchron im Request. Gemessen am Massenimport
 * 2026-08-25 braucht ein 100er-Paket 2-3 Sekunden; eine vierstellige Lieferung
 * laeuft in den nginx/PHP-Timeout, und der Abschlussbericht sprengt zusaetzlich
 * die notes-Spalte. Die Absprache mit ZAS ("Pakete a ~100 Zeilen") war bisher
 * nur eine Absprache — hier wird sie pruefbar.
 *
 * Bewusst rein (kein Config-Zugriff, keine Facade): der Aufrufer holt die
 * Grenze und entscheidet, was mit der Rohdatei passiert. Die wird naemlich
 * trotzdem gespeichert, damit eine zu grosse Lieferung nicht verloren ist,
 * sondern per recruiting:zas-inbound-reprocess portionsweise laufen kann.
 */
final class ZasInboundSizeGuard
{
    /** Standardgrenze, wenn nichts konfiguriert ist. */
    public const DEFAULT_MAX_ROWS = 300;

    /**
     * @param  int $rowCount Datenzeilen der Lieferung (ohne Kopfzeile)
     * @param  int $maxRows  Obergrenze; 0 oder kleiner schaltet den Waechter ab
     * @return string|null   Ablehnungsgrund fuer ZAS, oder null wenn erlaubt
     */
    public static function rejectionReason(int $rowCount, int $maxRows): ?string
    {
        if ($maxRows <= 0 || $rowCount <= $maxRows) {
            return null;
        }

        return "Lieferung mit {$rowCount} Datenzeilen abgewiesen — pro Anfrage sind maximal"
            . " {$maxRows} Zeilen erlaubt. Bitte in kleinere Pakete aufteilen"
            . ' (Absprache: rund 100 Zeilen). Die Datei wurde gespeichert, aber nicht verarbeitet.';
    }
}
