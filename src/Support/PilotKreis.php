<?php

namespace Platform\Recruiting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Wer gehoert zur Pilotgruppe? (Sebastian 19.10.2026: „alle neuen Features
 * nur auf die Pilot-Kandidaten, alles Alte laeuft sauber weiter".)
 *
 * Pilot = der MENSCH hat mindestens eine aktive Anstellung mit
 * `portal_v2_since` (gesetzt per recruiting:portal-umstellen). Derselbe
 * Personen-Schnitt wie in Bote: das Datum steht an der ANSTELLUNG, nicht am
 * Menschen, und bei einem Doppelbeschaeftigten RG+MA ist "nur eine Zeile
 * umgestellt" der Normalfall der Pilotumstellung. Schreibt er ueber die
 * nicht umgestellte Zeile, ist er trotzdem im Pilot.
 *
 * Geprueft wird die Anstellung selbst (ohne is_active-Filter — der Aufrufer
 * hat sie bereits als die richtige ausgewaehlt) oder eine AKTIVE Schwester
 * derselben rec_person_id. Ohne rec_person_id zaehlt nur die Anstellung
 * selbst: der Telefon-Zweig des PersonScopeResolver ist eine Uebergangs-
 * vermutung, und eine Vermutung soll niemanden in den Pilot ziehen.
 *
 * Genutzt von der Themen-Auswahl (Eingang ueber EINE Nummer). Die
 * Einsatz-Pruefung entscheidet dagegen ueber Bote::fuer() auf dem ganzen
 * Personen-Umfang — dort muss die Pilot-Sperre der HR-Faelle genau dem
 * Schnitt der Nachricht folgen (Review Fix-Runde 1).
 *
 * Query Builder, zwei kleine indizierte Abfragen — laeuft je Eingang bzw.
 * je Mensch im Lauf, nie als Massenleser.
 */
final class PilotKreis
{
    public static function istImPilot(int $employeeId): bool
    {
        $zeile = DB::table('rec_employees')
            ->where('id', $employeeId)
            ->first(['id', 'rec_person_id', 'portal_v2_since']);

        if ($zeile === null) {
            return false;
        }
        if ($zeile->portal_v2_since !== null) {
            return true;
        }
        if ($zeile->rec_person_id === null) {
            return false;
        }

        return DB::table('rec_employees')
            ->where('rec_person_id', (int) $zeile->rec_person_id)
            ->where('id', '!=', $employeeId)
            ->where('is_active', true)
            ->whereNotNull('portal_v2_since')
            ->exists();
    }
}
