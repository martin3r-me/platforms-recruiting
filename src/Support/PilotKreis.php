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
    /**
     * Liest einen Pilot-Schalter (comms_topic_nur_pilot,
     * einsatz_pruefung_nur_pilot) fail-closed: fehlend, leer oder unlesbar
     * bleibt die Sperre AN. Nur ein ausdrueckliches false / 0 / "false" /
     * "off" / "no" oeffnet fuer alle. Eine Stelle fuer Einstellungsfenster
     * und Einsatz-Pruefung, damit beide dasselbe lesen.
     */
    public static function sperreAn(mixed $wert): bool
    {
        if (is_bool($wert)) {
            return $wert;
        }
        if ($wert === 0 || $wert === 0.0) {
            return false;
        }
        if (is_string($wert) && in_array(strtolower(trim($wert)), ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        return true;
    }

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

    /**
     * Hat dieser MENSCH (rec_persons.id) mindestens eine aktive Anstellung
     * mit neuem Portal? Fuer das Konto (Codes, Einladung): ohne neues Portal
     * fuehrt jede Anmeldung ins Leere ('ohne-ziel'), ein Code per WhatsApp
     * waere eine Nachricht ohne Ziel — und neue Features gibt es nur im
     * Pilot (Sebastian 19.10.2026). Derselbe Schnitt wie anstellungenVon()
     * in KontoAnmelden (aktiv, ueber rec_person_id).
     */
    public static function personHatNeuesPortal(int $personId): bool
    {
        return self::personenMitNeuemPortal([$personId]) !== [];
    }

    /**
     * @param  list<int>  $personIds
     * @return list<int>  die Teilmenge mit neuem Portal
     */
    public static function personenMitNeuemPortal(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        return DB::table('rec_employees')
            ->whereIn('rec_person_id', $personIds)
            ->where('is_active', true)
            ->whereNotNull('portal_v2_since')
            ->distinct()
            ->pluck('rec_person_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
