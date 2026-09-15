<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Uebernimmt die Spalte `DispoTaetigkeiten` aus dem ZAS-MA-Export (Kunde 15.09.):
 * komma-getrennte Liste der dem MA fuer die Dispo zugewiesenen Taetigkeiten.
 *
 * Zwei Dinge passieren:
 *   1. Unbekannte Werte werden in der Auswahlliste `dispo_taetigkeit`
 *      NACHGELEGT — ZAS erweitert seinen Katalog mehrmals pro Woche, niemand
 *      soll hier pflegen muessen.
 *   2. Der Stand des MA wird ERSETZT (ZAS ist fuehrend). Werte, die ZAS nicht
 *      mehr liefert, verschwinden also auch bei uns.
 *
 * Schreibt bewusst per DB-Update statt Eloquent-save: das Feld darf weder den
 * ZAS-Export-Marker ausloesen (wir wuerden ZAS seine eigenen Daten
 * zurueckschicken) noch updated_at verbrauchen — dieselbe Lehre wie beim
 * Telefon-Normalisierungslauf (Vorfall 02.09.).
 */
class ZasDispoTaetigkeitSync
{
    public const LOOKUP = 'dispo_taetigkeit';

    /**
     * @return array{values: list<string>, created_values: int} gespeicherte Werte + neu angelegte Listen-Eintraege
     */
    public function sync(RecEmployee $employee, ?string $rawList): array
    {
        $labels = self::parse($rawList);
        $created = 0;

        if ($labels !== []) {
            $created = $this->ensureLookupValues((int) $employee->team_id, $labels);
        }

        $hr = $employee->ensureHrData();
        DB::table('rec_employee_hr_data')
            ->where('id', $hr->id)
            ->update([
                'dispo_taetigkeiten'           => json_encode($labels, JSON_UNESCAPED_UNICODE),
                'dispo_taetigkeiten_synced_at' => now(),
            ]);

        return ['values' => $labels, 'created_values' => $created];
    }

    /**
     * Komma-Liste -> saubere Werte. Leerzeichen weg, Leeres raus, Dubletten
     * (auch durch Schreibweise) zusammengefasst — erste Schreibweise gewinnt.
     *
     * @return list<string>
     */
    public static function parse(?string $rawList): array
    {
        $out = [];
        foreach (explode(',', (string) $rawList) as $part) {
            $label = trim(preg_replace('/\s+/u', ' ', $part) ?? '');
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            $out[$key] ??= $label;
        }

        return array_values($out);
    }

    /** Legt fehlende Listen-Eintraege an und liefert deren Anzahl. */
    private function ensureLookupValues(int $teamId, array $labels): int
    {
        $lookupId = DB::table('core_lookups')->where('team_id', $teamId)->where('name', self::LOOKUP)->value('id');
        if ($lookupId === null) {
            $lookupId = DB::table('core_lookups')->insertGetId([
                'team_id'    => $teamId,
                'name'       => self::LOOKUP,
                'label'      => 'Dispo-Taetigkeiten (aus ZAS)',
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existing = DB::table('core_lookup_values')->where('lookup_id', $lookupId)->pluck('value')->all();
        $known = [];
        foreach ($existing as $value) {
            $known[mb_strtolower((string) $value)] = true;
        }

        $created = 0;
        foreach ($labels as $label) {
            if (isset($known[mb_strtolower($label)])) {
                continue;
            }
            DB::table('core_lookup_values')->insert([
                'lookup_id'  => $lookupId,
                'value'      => $label,
                'label'      => $label,
                'order'      => 0,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $known[mb_strtolower($label)] = true;
            $created++;
        }

        return $created;
    }
}
