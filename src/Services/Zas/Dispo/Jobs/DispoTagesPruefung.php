<?php

namespace Platform\Recruiting\Services\Zas\Dispo\Jobs;

/**
 * „Ist die Person an Tag X irgendwo gebucht?“ — ueber alle Veranstaltungen und
 * Filialen, je Personengruppe (Anker). Rein rechnend.
 */
final class DispoTagesPruefung
{
    /** Gemeinsame Zeilenregel fuer Zaehler und Tagespruefung: Auftrag, nicht verschwunden/gemeldet/abgesagt. */
    public static function istEinsatz(array $a): bool
    {
        return (int) ($a['status_id'] ?? -1) === 1
            && empty($a['missing_since'])
            && empty($a['deletion_marked_at'])
            && empty($a['declined_at']);
    }

    /**
     * @param list<array{employee_id:int, datum:string, status_id:int, missing_since:?string, deletion_marked_at:?string, declined_at:?string}> $assignments
     * @param array<int,int> $anchorByEmployee employee_id => Anker (fehlt -> die id selbst)
     * @return array<int, array<string,true>> Anker => [Y-m-d => true]
     */
    public static function gebuchteTage(array $assignments, array $anchorByEmployee): array
    {
        $out = [];
        foreach ($assignments as $a) {
            if (!self::istEinsatz($a)) {
                continue;
            }
            $emp = (int) $a['employee_id'];
            $anchor = $anchorByEmployee[$emp] ?? $emp;
            $out[$anchor][substr((string) $a['datum'], 0, 10)] = true;
        }
        ksort($out);

        return $out;
    }
}
