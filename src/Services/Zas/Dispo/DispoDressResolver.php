<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoDressPackage;
use Platform\Recruiting\Models\RecDispoEventDress;

/**
 * Bestimmt je Einbuchung genau ein Waeschepaket.
 *
 * Vorrang, das erste Ergebnis gewinnt:
 *   1. an der Einbuchung festgeschrieben (Versandzeitpunkt)
 *   2. Zeile der Veranstaltung mit genau dieser Taetigkeit
 *   3. Zeile der Veranstaltung mit dem VA-weiten Sentinel ('')
 *   4. keins
 *
 * (Stufe 2 des Vorhabens schiebt zwischen 3 und 4 den Gruppen-Default.)
 *
 * forAssignments() ist rein lesend — die Einsatz-Seite darf es gefahrlos
 * aufrufen. Geschrieben wird ausschliesslich in freeze().
 *
 * freeze() stempelt BEIDES: die Paket-Referenz und eine Kopie des
 * Kleidungstextes (dress_items_text). Die Referenz allein haette nicht
 * gereicht — die Pflegemaske schreibt items_text in den BESTEHENDEN
 * Paket-Datensatz, und die Einsatz-Seite las den Text live von dort. Aendert
 * jemand drei Wochen spaeter den Inhalt von "Logistik", haette sich damit
 * rueckwirkend geaendert, was ein Mitarbeiter bereits bestaetigt hat. Die
 * Einsatz-Seite bevorzugt deshalb die Kopie (EmployeeAssignments::eventGroups()).
 */
class DispoDressResolver
{
    /**
     * @param  iterable<RecDispoAssignment> $assignments
     * @return array<int, ?RecDispoDressPackage> Map assignment_id => Paket|null
     */
    public function forAssignments(iterable $assignments): array
    {
        $rows = [];
        foreach ($assignments as $assignment) {
            $rows[] = $assignment;
        }
        if ($rows === []) {
            return [];
        }

        $eventIds  = array_values(array_unique(array_map(fn ($a) => (int) $a->rec_dispo_event_id, $rows)));
        $frozenIds = array_values(array_filter(array_map(fn ($a) => $a->rec_dispo_dress_package_id !== null
            ? (int) $a->rec_dispo_dress_package_id
            : null, $rows)));

        // Zuordnungen der betroffenen VAs: [event_id][taetigkeit] => package_id
        $map = [];
        foreach (RecDispoEventDress::query()->whereIn('rec_dispo_event_id', $eventIds)->get() as $row) {
            $map[(int) $row->rec_dispo_event_id][(string) $row->taetigkeit] = (int) $row->rec_dispo_dress_package_id;
        }

        $wanted = $frozenIds;
        foreach ($map as $byTaetigkeit) {
            foreach ($byTaetigkeit as $packageId) {
                $wanted[] = $packageId;
            }
        }

        // Bewusst OHNE active()-Filter: ein ausgemustertes Paket, das an einer
        // VA haengt oder festgeschrieben wurde, muss weiter angezeigt werden.
        $packages = $wanted === []
            ? collect()
            : RecDispoDressPackage::query()->whereIn('id', array_unique($wanted))->get()->keyBy('id');

        $out = [];
        foreach ($rows as $assignment) {
            $id = (int) $assignment->id;

            if ($assignment->rec_dispo_dress_package_id !== null) {
                $out[$id] = $packages[(int) $assignment->rec_dispo_dress_package_id] ?? null;
                continue;
            }

            $byTaetigkeit = $map[(int) $assignment->rec_dispo_event_id] ?? [];
            $taetigkeit   = trim((string) $assignment->taetigkeit);
            $packageId    = $byTaetigkeit[$taetigkeit]
                ?? $byTaetigkeit[RecDispoEventDress::ALL]
                ?? null;

            $out[$id] = $packageId !== null ? ($packages[$packageId] ?? null) : null;
        }

        return $out;
    }

    /**
     * Schreibt das aufgeloeste Paket an die Einbuchungen fest (Versandzeitpunkt)
     * — Referenz plus Textkopie, siehe Kopfkommentar.
     *
     * Bereits gestempelte Einbuchungen bleiben unberuehrt: was jemand bestaetigt
     * hat, darf sich durch einen zweiten Versand nicht aendern.
     *
     * @param  list<int> $assignmentIds
     * @return int Anzahl gestempelter Zeilen
     */
    public function freeze(array $assignmentIds): int
    {
        if ($assignmentIds === []) {
            return 0;
        }

        $assignments = RecDispoAssignment::query()
            ->whereIn('id', $assignmentIds)
            ->whereNull('dress_frozen_at')
            ->get();

        if ($assignments->isEmpty()) {
            return 0;
        }

        $resolved = $this->forAssignments($assignments);

        // Nach Paket gruppieren, damit aus n Einbuchungen wenige Updates werden.
        $byPackage = [];
        $texte = [];
        foreach ($resolved as $assignmentId => $package) {
            if ($package === null) {
                continue;
            }
            $byPackage[(int) $package->id][] = $assignmentId;
            $texte[(int) $package->id] = (string) $package->items_text;
        }

        $stamped = 0;
        $now = now();
        foreach ($byPackage as $packageId => $ids) {
            $stamped += RecDispoAssignment::query()
                ->whereIn('id', $ids)
                ->update([
                    'rec_dispo_dress_package_id' => $packageId,
                    'dress_frozen_at'            => $now,
                    // Wortlaut des Versandzeitpunkts — die Einsatz-Seite zeigt
                    // ab jetzt diese Kopie, nicht mehr den lebenden Paket-Text.
                    'dress_items_text'           => (string) $texte[$packageId],
                ]);
        }

        return $stamped;
    }
}
