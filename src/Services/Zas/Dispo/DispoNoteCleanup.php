<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Hinweise einer Veranstaltung aufraeumen (Kunde 25.09., Fall VA 1352):
 * "Info an Crew" haengt einen Sammeltext an, und wer sich dabei vertut, hat ihn
 * anschliessend bei hundert Leuten stehen — einzeln korrigierbar, aber nicht
 * in einem Zug.
 *
 * Gruppiert wird nach dem EXAKTEN Wortlaut, nicht nach Personen: nur so trifft
 * eine Sammelaktion garantiert niemanden, der etwas Eigenes stehen hat. Ein
 * individueller Hinweis ist automatisch seine eigene Fassung (count = 1).
 *
 * Hier wird NIE etwas versendet — die Crew sieht die Aenderung auf ihrer
 * Einsatz-Seite.
 */
final class DispoNoteCleanup
{
    /**
     * Fassungen der Hinweise dieser Veranstaltung — groesste Gruppe zuerst,
     * Einzelfaelle unten.
     *
     * @param iterable<RecDispoAssignment> $assignments Einbuchungen der VA (ALLE Tage, auch vergangene)
     * @param array<int,int> $canonByEmployee rec_employee_id => kanonische id (Personen-Paarung RG/MA)
     * @return list<array{key:string, text:string, assignment_ids:list<int>, persons:list<string>, count:int, days:list<string>, shared_persons:int, updated_at:?string}>
     */
    public static function variants(iterable $assignments, array $canonByEmployee = []): array
    {
        $groups = [];
        foreach ($assignments as $a) {
            $text = trim((string) $a->individual_note);
            if ($text === '') {
                continue;
            }
            $groups[$text]['assignment_ids'][] = (int) $a->id;

            // Je Person einmal zaehlen: wer an mehreren Tagen eingebucht ist,
            // steht sonst mehrfach in derselben Fassung.
            $employeeId = $a->rec_employee_id !== null ? (int) $a->rec_employee_id : null;
            $key = $employeeId !== null ? ($canonByEmployee[$employeeId] ?? $employeeId) : 'pnr:' . $a->pnr_raw;
            $groups[$text]['persons'][$key] = self::personName($a);

            // Tage der Fassung: wer an einem Tag etwas anderes stehen hat als am
            // Tag davor, taucht in ZWEI Fassungen auf — dann muss sichtbar sein,
            // welcher Tag zu welchem Text gehoert.
            if ($a->datum !== null) {
                $groups[$text]['days'][$a->datum->format('Y-m-d')] = $a->datum->format('d.m.');
            }

            $at = $a->individual_note_updated_at;
            if ($at !== null) {
                $current = $groups[$text]['updated_at'] ?? null;
                $groups[$text]['updated_at'] = ($current === null || $at > $current) ? $at : $current;
            }
        }

        // Personen, die in MEHR ALS EINER Fassung stecken (unterschiedliche
        // Hinweise an unterschiedlichen Tagen).
        $seen = [];
        foreach ($groups as $group) {
            foreach (array_keys($group['persons']) as $key) {
                $seen[$key] = ($seen[$key] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($groups as $text => $group) {
            $persons = array_values($group['persons']);
            $shared = count(array_filter(array_keys($group['persons']), fn ($key) => ($seen[$key] ?? 0) > 1));
            $days = $group['days'] ?? [];
            ksort($days);
            sort($persons, SORT_NATURAL | SORT_FLAG_CASE);
            $out[] = [
                // Stabiler Schluessel statt Listenindex: zwischen Anzeigen und
                // Klick kann sich die Liste geaendert haben (zweiter Nutzer,
                // Auto-Aktualisierung) — ein Index wuerde dann die falsche
                // Fassung treffen.
                'key'            => md5((string) $text),
                'text'           => (string) $text,
                'assignment_ids' => $group['assignment_ids'],
                'persons'        => $persons,
                'count'          => count($persons),
                'days'           => array_values($days),
                'shared_persons' => $shared,
                'updated_at'     => isset($group['updated_at']) ? $group['updated_at']->format('d.m.Y H:i') : null,
            ];
        }

        // Groesste Gruppe zuerst: das Aufraeumen der Masse liegt oben, die
        // empfindlichen Einzelfaelle weit weg vom ersten Klick. Bei gleicher
        // Personenzahl entscheidet die Zahl der Einbuchungen (Mehrtaeger), erst
        // dann der Text — sonst huepft die Reihenfolge rein alphabetisch.
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']
            ?: count($b['assignment_ids']) <=> count($a['assignment_ids'])
            ?: strcmp($a['text'], $b['text']));

        return $out;
    }

    /**
     * Personen mit Hinweis — ueber alle Fassungen hinweg EINMAL gezaehlt. Die
     * Summe der Fassungs-Zaehler waere zu hoch, sobald jemand an verschiedenen
     * Tagen verschiedene Hinweise hat.
     *
     * @param list<array{persons:list<string>}> $variants
     */
    public static function personTotal(iterable $assignments, array $canonByEmployee = []): int
    {
        $keys = [];
        foreach ($assignments as $a) {
            if (trim((string) $a->individual_note) === '') {
                continue;
            }
            $employeeId = $a->rec_employee_id !== null ? (int) $a->rec_employee_id : null;
            $keys[$employeeId !== null ? ($canonByEmployee[$employeeId] ?? $employeeId) : 'pnr:' . $a->pnr_raw] = true;
        }

        return count($keys);
    }

    /**
     * Schreibt eine Fassung neu oder entfernt sie — ausschliesslich auf den
     * uebergebenen Einbuchungen dieser Veranstaltung.
     *
     * @param list<int> $assignmentIds
     * @param ?string   $newNote   null oder leer = Hinweis entfernen
     * @param bool      $markAsNew Zeitstempel erneuern (Fahne "neuer Hinweis" auf
     *                             der Einsatz-Seite); false = stille Korrektur.
     *                             Beim Entfernen wird der Zeitstempel IMMER
     *                             geleert, sonst leuchtet "neu" an leerer Stelle.
     * @return int geaenderte Einbuchungen
     */
    public function apply(int $eventId, array $assignmentIds, ?string $newNote, bool $markAsNew): int
    {
        $ids = array_values(array_unique(array_map('intval', $assignmentIds)));
        if ($ids === []) {
            return 0;
        }

        $value = trim((string) $newNote);
        $data = ['individual_note' => $value !== '' ? $value : null];
        if ($value === '') {
            $data['individual_note_updated_at'] = null;
        } elseif ($markAsNew) {
            $data['individual_note_updated_at'] = now();
        }

        return RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->whereKey($ids)
            ->update($data);
    }

    /**
     * Nur die BEREITS GELADENE Beziehung lesen — sonst feuert die Anzeige je
     * Zeile eine eigene Abfrage (und im Test faellt sie ohne MA-Tabelle um).
     * Der Aufrufer laedt employee mit; ohne Namen bleibt die PNr.
     */
    private static function personName(RecDispoAssignment $a): string
    {
        $employee = $a->relationLoaded('employee') ? $a->getRelation('employee') : null;
        $name = $employee !== null
            ? trim(((string) $employee->first_name) . ' ' . ((string) $employee->last_name))
            : '';

        return $name !== '' ? $name : ('PNr ' . (string) $a->pnr_raw);
    }
}
