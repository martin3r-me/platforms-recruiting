<?php

namespace Platform\Recruiting\Support;

/**
 * Anstellungen → Personen (Spec §3.1, Schritt 3). Wer bei RHEINGEDECK und MA
 * arbeitet, hat zwei Anstellungen und bekommt ein Dokument EINMAL.
 *
 * Die Gruppen folgen dem Portal (PersonScopeResolver), sonst traegt eine
 * Anstellung die Zustellung, die der Mensch im Portal gar nicht sieht
 * (Review 29.10.2026):
 *  - gleiche rec_person_id = derselbe Mensch, auch mit verschiedenen alten
 *    person_key (nach PersonLinker::fuehreZusammen);
 *  - verschiedene rec_person_id verschmelzen NIE, auch nicht ueber einen
 *    gemeinsamen Schluessel;
 *  - eine ungebundene Anstellung haengt sich ueber ihren person_key an die
 *    Person, wenn der Schluessel zu genau EINER Person fuehrt; sonst bilden
 *    die Ungebundenen mit gleichem Schluessel eine eigene Gruppe;
 *  - ohne beide Merkmale bleibt jede Anstellung allein — zwei Menschen ohne
 *    Marker duerfen nicht verschmelzen (Muster SendProofReminders).
 * Getragen wird die Zustellung von der kleinsten GEBUNDENEN id der Gruppe,
 * nur ohne gebundene von der kleinsten id.
 */
final class EmpfaengerEntdoppler
{
    /**
     * @param  list<array{id:int, person_key:?string, rec_person_id?:?int}> $anstellungen
     * @return list<int> Traeger-ids, Reihenfolge der ersten Nennung
     */
    public static function aufPersonen(array $anstellungen): array
    {
        $gruppe = self::gruppen($anstellungen);
        $traeger = self::traeger($anstellungen, $gruppe);

        $out = [];
        foreach ($anstellungen as $a) {
            $g = $gruppe[(int) $a['id']];
            if (!isset($out[$g])) {
                $out[$g] = $traeger[$g];
            }
        }

        return array_values($out);
    }

    /**
     * Gruppe je Anstellung: 'p:<rec_person_id>', 'k:<person_key>' oder 'a:<id>'.
     *
     * @param  list<array{id:int, person_key:?string, rec_person_id?:?int}> $anstellungen
     * @return array<int, string> id => Gruppe
     */
    public static function gruppen(array $anstellungen): array
    {
        $personenJeSchluessel = [];   // Schluessel => [rec_person_id => true]
        foreach ($anstellungen as $a) {
            $pid = (int) ($a['rec_person_id'] ?? 0);
            $key = trim((string) ($a['person_key'] ?? ''));
            if ($pid > 0 && $key !== '') {
                $personenJeSchluessel[$key][$pid] = true;
            }
        }

        $out = [];
        foreach ($anstellungen as $a) {
            $id  = (int) $a['id'];
            $pid = (int) ($a['rec_person_id'] ?? 0);
            $key = trim((string) ($a['person_key'] ?? ''));
            if ($pid > 0) {
                $out[$id] = 'p:' . $pid;
            } elseif ($key !== '' && count($personenJeSchluessel[$key] ?? []) === 1) {
                $out[$id] = 'p:' . array_key_first($personenJeSchluessel[$key]);
            } elseif ($key !== '') {
                $out[$id] = 'k:' . $key;
            } else {
                $out[$id] = 'a:' . $id;
            }
        }

        return $out;
    }

    /**
     * Traeger je Gruppe: kleinste gebundene id, sonst kleinste id.
     *
     * @param  list<array{id:int, person_key:?string, rec_person_id?:?int}> $anstellungen
     * @param  array<int, string> $gruppe
     * @return array<string, int>
     */
    public static function traeger(array $anstellungen, array $gruppe): array
    {
        $gebunden = [];
        $alle = [];
        foreach ($anstellungen as $a) {
            $id = (int) $a['id'];
            $g = $gruppe[$id];
            $alle[$g] = min($alle[$g] ?? $id, $id);
            if ((int) ($a['rec_person_id'] ?? 0) > 0) {
                $gebunden[$g] = min($gebunden[$g] ?? $id, $id);
            }
        }

        return array_map(fn (string $g) => $gebunden[$g] ?? $alle[$g], array_combine(array_keys($alle), array_keys($alle)));
    }
}
