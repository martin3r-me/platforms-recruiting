<?php

namespace Platform\Recruiting\Support;

/**
 * Pflicht-Kopplung Ersthelfer: der Endzustand "Ersthelfer=Ja ohne die
 * zugehoerigen Nachweise" darf die Maske nicht verlassen (Spec 2026-07-17,
 * erweitert 2026-09-01). Pure Funktion auf den rohen Formularwerten
 * (Strings aus wire:model, Bool-Select liefert ''/'1'/'0') — kein Laravel,
 * unit-testbar.
 *
 * Endzustands-Pruefung, bewusst: blockt auch Saves, bei denen nur ein
 * anderes Feld geaendert wurde — so wird ein per lenientem ZAS-Import
 * entstandener "Ja ohne Datum"-MA beim naechsten Edit repariert.
 *
 * Zwei Ausbaustufen, eine Regel:
 *  - HR (Employees/Show) ruft OHNE $requireCertificate auf. Dort ist nur
 *    das Bis-Datum Pflicht — HR soll nicht an einer Datei haengenbleiben,
 *    die HR selbst gar nicht hat (sonst waeren die Bestands-Ersthelfer in
 *    der Akte komplett unspeicherbar).
 *  - MA-Portal ruft MIT $requireCertificate auf: wer "Ja" angibt, muss
 *    Datum UND Schein liefern (Kundenwunsch 2026-09-01).
 */
class FirstAiderDateGuard
{
    /**
     * Fehlertext oder null wenn der Zustand konsistent ist.
     *
     * @param mixed $certificateFileId File-Id des hochgeladenen Scheins.
     *                                 null/''/0 gelten als "nicht vorhanden".
     * @param bool  $requireCertificate Dokumentpflicht einschalten (Portal).
     */
    public static function error(
        mixed $isFirstAider,
        mixed $validUntil,
        mixed $certificateFileId = null,
        bool $requireCertificate = false,
    ): ?string {
        $flag = mb_strtolower(trim((string) ($isFirstAider ?? '')));
        // Truthy-Set muss identisch bleiben mit der Bool-Konvertierung in Employees/Show.php::saveAll()
        $isSet = in_array($flag, ['1', 'true', 'ja'], true);
        if (!$isSet) {
            return null;
        }

        $dateMissing = trim((string) ($validUntil ?? '')) === '';
        // "0" ist keine gueltige File-Id — der Cast auf int faengt sowohl
        // den Leerstring als auch eine 0 aus einem manipulierten POST.
        $certMissing = $requireCertificate && (int) trim((string) ($certificateFileId ?? '')) <= 0;

        if ($dateMissing && $certMissing) {
            return 'Ersthelfer-Angaben unvollstaendig: "Ersthelfer-Schein gueltig bis" eintragen UND den Schein hochladen — beides ist Pflicht, sobald Ersthelfer auf Ja steht. Es wurde nichts gespeichert.';
        }

        if ($dateMissing) {
            return 'Ersthelfer-Datum fehlt: "Ersthelfer-Schein gueltig bis" ist Pflicht, sobald Ersthelfer auf Ja steht. Es wurde nichts gespeichert.';
        }

        if ($certMissing) {
            return 'Ersthelfer-Nachweis fehlt: bitte den Ersthelfer-Schein hochladen — er ist Pflicht, sobald Ersthelfer auf Ja steht. Es wurde nichts gespeichert.';
        }

        return null;
    }
}
