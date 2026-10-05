<?php

namespace Platform\Recruiting\Services\Comms\Forward;

/**
 * Sortiert Weiterleitungs-Karten in eine Nachrichtenliste ein, ohne die
 * Bestandszeilen umzusortieren (Spec Runde 2: die Reihenfolge des Chats
 * bleibt, wie sie ist). Pure, Zeit als Unix-Timestamp.
 */
final class ForwardTimeline
{
    /**
     * @param list<array<string, mixed>> $rows  Bestandszeilen mit 'ts'
     * @param list<array<string, mixed>> $cards Karten mit 'ts'
     * @return list<array<string, mixed>>
     */
    public static function insert(array $rows, array $cards): array
    {
        if ($cards === []) {
            return $rows;
        }
        $order = array_keys($cards);
        usort($order, static fn (int $a, int $b) => [$cards[$a]['ts'], $a] <=> [$cards[$b]['ts'], $b]);

        $out = [];
        $i = 0;
        $n = count($rows);
        foreach ($order as $k) {
            while ($i < $n && (int) $rows[$i]['ts'] <= (int) $cards[$k]['ts']) {
                $out[] = $rows[$i++];
            }
            $out[] = $cards[$k];
        }
        while ($i < $n) {
            $out[] = $rows[$i++];
        }

        return $out;
    }
}
