<?php

namespace Platform\Recruiting\Support;

/**
 * Was ein Ja/Nein-Feld aus dem MA-Portal bedeutet — an EINER Stelle.
 *
 * Die Formularwerte kommen als Strings aus wire:model ('1', '0', ''), die
 * Spalten sind dreiwertig (ja / nein / unbeantwortet). Vorher entschied das
 * jede Stelle fuer sich: die Bool-Umwandlung in EmployeePortal::saveAll(),
 * der MainEmployerRequiredGuard und der FirstAiderDateGuard.
 *
 * Sie sind auseinandergelaufen, und das hatte Folgen (Befund Review
 * 25.09.2026): 'nein' galt dem Guard als "beantwortet ja" und verlangte den
 * Namen des Hauptarbeitgebers nicht — gespeichert wurde aber false, also
 * genau der Zustand, den die Pflicht verhindern soll. Umgekehrt passierte
 * 'Ja' den Guard und landete als NULL: eine gueltige Antwort still geloescht,
 * mit der Meldung "Aenderungen gespeichert.".
 *
 * Wer hier etwas ergaenzt, aendert damit Formular, Guards und Sichtbarkeit
 * gleichzeitig — das ist der Zweck.
 */
final class PortalBoolValue
{
    private const TRUTHY = ['1', 'true', 'ja'];
    private const FALSY  = ['0', 'false', 'nein'];

    /** true / false / null (= unbeantwortet oder unverstaendlich). */
    public static function parse(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $value = mb_strtolower(trim((string) ($raw ?? '')));

        if (in_array($value, self::TRUTHY, true)) {
            return true;
        }
        if (in_array($value, self::FALSY, true)) {
            return false;
        }

        return null;
    }
}
