<?php

namespace Platform\Recruiting\Support;

/**
 * Steuer-ID und Sozialversicherungsnummer ohne Leerraum (Clara/RHEINGEDECK,
 * Protokoll 28.08.2026): "Eingabe der Steuer ID und SV-Nummer bitte nur ohne
 * Leerzeichen moeglich machen sonst landet spaeter nicht die vollstaendige
 * Nummer in Agenda."
 *
 * Entfernt wird ausschliesslich Leerraum — auch Tabulator, Zeilenumbruch und
 * das geschuetzte Leerzeichen, das beim Kopieren aus Word und PDFs mitkommt
 * und im Eingabefeld unsichtbar ist.
 *
 * BEWUSST KEIN Filter auf Ziffern: Die SV-Nummer enthaelt einen Buchstaben,
 * und was HR sonst eintraegt, soll inhaltlich stehen bleiben. Ein stiller
 * Zeichenfilter wuerde falsche Nummern erzeugen, und die fallen niemandem auf.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class TaxAndSvNumber
{
    /** Leerraum-freier Wert, oder null wenn nichts uebrig bleibt. */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // \s deckt Leerzeichen, Tabulator und Umbruch ab; \x{00A0} das
        // geschuetzte Leerzeichen, \x{202F} das schmale.
        $clean = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $value);

        return ($clean === null || $clean === '') ? null : $clean;
    }
}
