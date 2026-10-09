<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeExportObserver;
use Platform\Recruiting\Services\Zas\ZasInboundEmployeeImporter;
use Platform\Recruiting\Support\PersonenFelder;

/**
 * Der EINZIGE Schreiber von Personenfeldern auf Geschwister-Akten (Spec
 * 2026-10-09 §4). Geschwister werden NUR per Query-Builder beschrieben: kein
 * updated-Ereignis, keine Rueckkopplung. Marker und Lohn-Eintrag setzt der
 * Spiegel deshalb ausdruecklich, nicht als Nebenwirkung.
 */
class PersonenSpiegel
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
     * @param  list<string> $nurInLeere  Felder, die nur in LEERE Geschwisterfelder
     *         geschrieben werden: Erstbefuellung der Quelle (Spec §5.1) und
     *         ZAS-Rohwerte ohne Lookup-Treffer (Spec §5.2). Eine Regel fuer alle Aufrufer.
     * @param  bool $offeneMarkerAuslassen  Geschwister mit offenem zas_changed_at
     *         auslassen: sie tragen eine eigene Aenderung, die ZAS noch nicht
     *         kennt (ZAS-Pfad, Spec §5.2).
     * @return list<int> beschriebene Geschwister
     */
    public function spiegele(
        RecEmployee $quelle,
        array $werte,
        bool $markerSetzen,
        bool $lohnVerfolgen,
        array $nurInLeere = [],
        bool $offeneMarkerAuslassen = false,
    ): array {
        if (self::$laeuft) {
            return [];
        }

        // Erst gegen die Personenfelder schneiden, dann (nur falls noetig) die
        // Team-Einstellung lesen — ein Speichern ohne Personenfeld kostet nichts.
        $werte = array_intersect_key($werte, array_flip(PersonenFelder::SPIEGELN));
        if ($werte === []) {
            return [];
        }
        $teamId = $quelle->team_id !== null ? (int) $quelle->team_id : null;
        $werte = array_intersect_key($werte, array_flip(PersonenFelder::fuerTeam($teamId, array_keys($werte))));
        if ($werte === []) {
            return [];
        }

        $geschwister = $this->geschwister($quelle);
        if ($geschwister === []) {
            return [];
        }

        self::$laeuft = true;
        try {
            return DB::transaction(fn () => $this->schreibe($geschwister, $werte, $markerSetzen, $lohnVerfolgen, $nurInLeere, $offeneMarkerAuslassen));
        } finally {
            self::$laeuft = false;
        }
    }

    /**
     * ZAS hat fuer einen bekannten Menschen eine neue Akte angelegt (Spec §5.2,
     * Paarung). Datenhoheit liegt bei uns: nicht-leere Werte der bestehenden
     * Akte gehen in die neue; ist bei uns ein Feld leer und ZAS hat einen Wert,
     * wird er bei uns nachgetragen. Nie wird ein nicht-leerer Wert der
     * bestehenden Akte ueberschrieben. Marker auf beiden, wenn geschrieben;
     * kein Lohn-Eintrag. Felder, die der Import nie von ZAS uebernimmt
     * (OVERWRITE_PROTECTED: Ausweisnummer als Login-Faktor, das vom Mapper
     * erfundene Default-Land 'de'), werden bei uns auch nicht nachgetragen.
     */
    public function uebernimmBeiPaarung(int $bestehendeId, int $neueId): void
    {
        $teamId = DB::table('rec_employees')->where('id', $bestehendeId)->value('team_id');
        $felder = PersonenFelder::fuerTeam($teamId !== null ? (int) $teamId : null);
        $alt = DB::table('rec_employees')->where('id', $bestehendeId)->first($felder);
        $neu = DB::table('rec_employees')->where('id', $neueId)->first($felder);
        if ($alt === null || $neu === null) {
            return;
        }

        $fuerNeu = [];
        $fuerAlt = [];
        foreach ($felder as $feld) {
            $a = PersonenFelder::normalisiere($feld, $alt->{$feld});
            $n = PersonenFelder::normalisiere($feld, $neu->{$feld});
            if ($a !== null && $a !== $n) {
                $fuerNeu[$feld] = $alt->{$feld};
            } elseif ($a === null && $n !== null && !in_array($feld, ZasInboundEmployeeImporter::OVERWRITE_PROTECTED, true)) {
                $fuerAlt[$feld] = $neu->{$feld};
            }
        }

        DB::transaction(function () use ($bestehendeId, $neueId, $fuerNeu, $fuerAlt) {
            foreach ([[$neueId, $fuerNeu], [$bestehendeId, $fuerAlt]] as [$id, $update]) {
                if ($update === []) {
                    continue;
                }
                $update['updated_at'] = now();
                if (array_intersect(array_keys($update), RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS) !== []) {
                    $update['zas_changed_at'] = now();
                }
                DB::table('rec_employees')->where('id', $id)->update($update);
            }
        });
    }

    /**
     * @param list<int> $ids
     * @param array<string,mixed> $werte
     * @param list<string> $nurInLeere
     * @return list<int>
     */
    private function schreibe(array $ids, array $werte, bool $markerSetzen, bool $lohnVerfolgen, array $nurInLeere, bool $offeneMarkerAuslassen): array
    {
        $beschrieben = [];
        $zeilen = DB::table('rec_employees')->whereIn('id', $ids)->orderBy('id')
            ->get(array_merge(['id', 'team_id', 'zas_changed_at'], array_keys($werte)));

        foreach ($zeilen as $zeile) {
            if ($offeneMarkerAuslassen && $zeile->zas_changed_at !== null) {
                continue;
            }
            $update = [];
            $aenderungen = [];
            foreach ($werte as $feld => $neu) {
                $alt = $zeile->{$feld};
                $altNorm = PersonenFelder::normalisiere($feld, $alt);
                if ($altNorm === PersonenFelder::normalisiere($feld, $neu)) {
                    continue;
                }
                if ($altNorm !== null && in_array($feld, $nurInLeere, true)) {
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
