<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Support\PersonenFelder;

/**
 * Der EINZIGE Schreiber von Personenfeldern auf Geschwister-Akten (Spec
 * 2026-10-09 §4). Geschwister werden NUR per Query-Builder beschrieben: kein
 * updated-Ereignis, keine Rueckkopplung. Marker und Lohn-Eintrag setzt der
 * Spiegel deshalb ausdruecklich, nicht als Nebenwirkung.
 */
final class PersonenSpiegel
{
    private static bool $laeuft = false;

    /** @return list<int> */
    public function geschwister(RecEmployee $quelle): array
    {
        $ids = (new PersonScopeResolver())->forEmployee($quelle)['ids'];

        return array_values(array_filter($ids, fn (int $id) => $id !== (int) $quelle->id));
    }

    /**
     * @param  array<string,mixed> $werte  Feld => Wert, wie er in der Quelle steht
     * @return list<int> beschriebene Geschwister
     */
    public function spiegele(RecEmployee $quelle, array $werte, bool $markerSetzen, bool $lohnVerfolgen): array
    {
        if (self::$laeuft) {
            return [];
        }

        $teamId = $quelle->team_id !== null ? (int) $quelle->team_id : null;
        $werte = array_intersect_key($werte, array_flip(PersonenFelder::fuerTeam($teamId)));
        if ($werte === []) {
            return [];
        }

        $geschwister = $this->geschwister($quelle);
        if ($geschwister === []) {
            return [];
        }

        self::$laeuft = true;
        try {
            return DB::transaction(fn () => $this->schreibe($geschwister, $werte, $markerSetzen, $lohnVerfolgen));
        } finally {
            self::$laeuft = false;
        }
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $werte
     * @return list<int>
     */
    private function schreibe(array $ids, array $werte, bool $markerSetzen, bool $lohnVerfolgen): array
    {
        $beschrieben = [];
        $zeilen = DB::table('rec_employees')->whereIn('id', $ids)->orderBy('id')
            ->get(array_merge(['id', 'team_id'], array_keys($werte)));

        foreach ($zeilen as $zeile) {
            $update = [];
            $aenderungen = [];
            foreach ($werte as $feld => $neu) {
                $alt = $zeile->{$feld};
                if (PersonenFelder::normalisiere($feld, $alt) === PersonenFelder::normalisiere($feld, $neu)) {
                    continue;
                }
                $update[$feld] = is_bool($neu) ? (int) $neu : $neu;
                $aenderungen[$feld] = ['old' => $alt, 'new' => $neu];
            }
            if ($update === []) {
                continue;
            }

            $update['updated_at'] = now();
            if ($markerSetzen && array_intersect(array_keys($aenderungen), RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS) !== []) {
                $update['zas_changed_at'] = now();
            }
            DB::table('rec_employees')->where('id', $zeile->id)->update($update);

            if ($lohnVerfolgen) {
                RecEmployeeExportObserver::verfolgeLohn((int) $zeile->id, $zeile->team_id !== null ? (int) $zeile->team_id : null, $aenderungen);
            }
            $beschrieben[] = (int) $zeile->id;
        }

        return $beschrieben;
    }
}
