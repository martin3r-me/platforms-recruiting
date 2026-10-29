<?php

namespace Platform\Recruiting\Support;

/**
 * Anstellungen → Personen (Spec §3.1, Schritt 3). Wer bei RHEINGEDECK und MA
 * arbeitet, hat zwei Anstellungen und bekommt ein Dokument EINMAL: die
 * Anstellung mit der kleineren id traegt die Zustellung.
 *
 * Derselbe Mensch ist, wer dieselbe rec_person_id ODER denselben person_key
 * traegt (transitiv, 29.10.2026). Nur person_key reichte nicht: nach dem
 * Zusammenfuehren zweier Personen (PersonLinker::fuehreZusammen) teilen die
 * Anstellungen die rec_person_id, behalten aber ihre alten Schluessel — das
 * Portal (PersonScopeResolver) sieht EINEN Menschen, die Zustellung sah zwei.
 * Ohne beide Merkmale bleibt jede Anstellung ein Empfaenger — zwei Menschen
 * ohne Marker duerfen nicht verschmelzen (Muster SendProofReminders).
 */
final class EmpfaengerEntdoppler
{
    /**
     * @param  list<array{id:int, person_key:?string, rec_person_id?:?int}> $anstellungen
     * @return list<int> behaltene ids, Reihenfolge der ersten Nennung
     */
    public static function aufPersonen(array $anstellungen): array
    {
        $gruppe = self::gruppen($anstellungen);

        $kleinste = [];   // Gruppe => kleinste id
        $reihe    = [];   // Gruppen in Reihenfolge der ersten Nennung
        foreach ($anstellungen as $a) {
            $id = (int) $a['id'];
            $g  = $gruppe[$id];
            if (!isset($kleinste[$g])) {
                $kleinste[$g] = $id;
                $reihe[] = $g;
            } elseif ($id < $kleinste[$g]) {
                $kleinste[$g] = $id;
            }
        }

        return array_map(fn (int $g) => $kleinste[$g], $reihe);
    }

    /**
     * Gruppe je Anstellung: die kleinste id ihres Menschen.
     *
     * @param  list<array{id:int, person_key:?string, rec_person_id?:?int}> $anstellungen
     * @return array<int, int> id => Gruppen-id
     */
    public static function gruppen(array $anstellungen): array
    {
        $eltern = [];
        $finde = function (int $id) use (&$eltern, &$finde): int {
            if ($eltern[$id] !== $id) {
                $eltern[$id] = $finde($eltern[$id]);
            }

            return $eltern[$id];
        };

        $erster = [];   // Merkmal => erste id damit
        foreach ($anstellungen as $a) {
            $id = (int) $a['id'];
            $eltern[$id] ??= $id;

            $merkmale = [];
            $key = trim((string) ($a['person_key'] ?? ''));
            if ($key !== '') {
                $merkmale[] = 'k:' . $key;
            }
            $personId = (int) ($a['rec_person_id'] ?? 0);
            if ($personId > 0) {
                $merkmale[] = 'p:' . $personId;
            }
            foreach ($merkmale as $m) {
                if (!isset($erster[$m])) {
                    $erster[$m] = $id;
                    continue;
                }
                [$ra, $rb] = [$finde($erster[$m]), $finde($id)];
                if ($ra !== $rb) {
                    $eltern[max($ra, $rb)] = min($ra, $rb);
                }
            }
        }

        $out = [];
        foreach (array_keys($eltern) as $id) {
            $out[$id] = $finde($id);
        }

        return $out;
    }
}
