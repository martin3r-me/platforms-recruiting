<?php

namespace Platform\Recruiting\Support;

/**
 * Anstellungen → Personen (Spec §3.1, Schritt 3). Wer bei RHEINGEDECK und MA
 * arbeitet, hat zwei Anstellungen mit gleichem person_key und bekommt ein
 * Dokument EINMAL: die Anstellung mit der kleineren id traegt die Zustellung.
 * Ohne person_key bleibt jede Anstellung ein Empfaenger — zwei Menschen ohne
 * Marker duerfen nicht verschmelzen (Muster SendProofReminders).
 */
final class EmpfaengerEntdoppler
{
    /**
     * @param  list<array{id:int, person_key:?string}> $anstellungen
     * @return list<int> behaltene ids, Reihenfolge der ersten Nennung
     */
    public static function aufPersonen(array $anstellungen): array
    {
        $ohneKey   = [];   // id => true
        $proPerson = [];   // key => kleinste id
        $reihe     = [];   // Schluessel in Reihenfolge der ersten Nennung

        foreach ($anstellungen as $a) {
            $id  = (int) $a['id'];
            $key = trim((string) ($a['person_key'] ?? ''));

            if ($key === '') {
                if (!isset($ohneKey[$id])) {
                    $ohneKey[$id] = true;
                    $reihe[] = ['art' => 'id', 'wert' => $id];
                }
                continue;
            }

            if (!isset($proPerson[$key])) {
                $proPerson[$key] = $id;
                $reihe[] = ['art' => 'key', 'wert' => $key];
            } elseif ($id < $proPerson[$key]) {
                $proPerson[$key] = $id;
            }
        }

        $out = [];
        foreach ($reihe as $r) {
            $out[] = $r['art'] === 'id' ? $r['wert'] : $proPerson[$r['wert']];
        }

        return $out;
    }
}
