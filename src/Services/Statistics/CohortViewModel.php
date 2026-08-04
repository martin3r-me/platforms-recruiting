<?php

namespace Platform\Recruiting\Services\Statistics;

/**
 * Anzeige-Logik der Statistik-Seite (Spec §3) — absichtlich frei von Livewire,
 * Laravel und DB: Gruppierung/Sortierung der Assigner-Zeilen und die Aufloesung
 * der Drill-down-Mengen. Der riskante Teil bleibt damit pure-unit-testbar
 * (Modul-Konvention), waehrend die Livewire-Komponente nur noch delegiert.
 *
 * Was hier NICHT passiert: Praezedenz-Kette und Zuordnungsregel bestimmen —
 * das ist allein Sache des CohortAssigner. Diese Klasse ordnet nur an, zaehlt
 * und loest Mengen auf; sie darf keine Zeile erzeugen, verwerfen oder umhaengen.
 */
final class CohortViewModel
{
    /**
     * Fallback-Werte des Assigners (groupFor). Es sind Befunde, keine echten
     * Orte/Taetigkeiten — sie sortieren deshalb immer ans Ende ihrer Ebene.
     */
    public const FALLBACKS = ['ohne Ort', 'ohne Ausschreibung', 'ohne Tätigkeit'];

    /**
     * Anzeige-Reihenfolge = Reihenfolge der Praezedenz-Kette (Spec §4).
     * Bewusst vollstaendig: ein hier fehlender Typ landet mit 99 am Ende und
     * bleibt sichtbar, statt still zu verschwinden.
     */
    private const TYPE_ORDER = [
        'schulung' => 0,
        'ohne_schulung' => 1,
        'geparkt' => 2,
        'abgesagt' => 3,
        'dublette' => 4,
        'unrouted' => 5,
        'import' => 6,
        'ohne_datum' => 7,
        'unbekannter_status' => 8,
    ];

    /** Interview-ID einer Schulungszeile aus dem Row-Key ("schulung:42"). */
    public static function interviewIdOf(array $row): ?int
    {
        if (($row['type'] ?? null) !== 'schulung') {
            return null;
        }
        return (int) substr((string) $row['key'], strlen('schulung:'));
    }

    /**
     * Anzeige-Baum Ort → Taetigkeit → Zeilen. Rein umsortierend: es geht keine
     * Zeile verloren und es kommt keine hinzu (siehe Test), damit die
     * Rekonziliations-Invariante die Gruppierung ueberlebt.
     *
     * @param  list<array>  $rows  Assigner-Zeilen
     * @param  array<int,string>  $startsAtByInterview  interview_id => sortierbarer Zeitstempel
     * @return array<string, array{ort:string, activities:array<string, list<array>>}>
     */
    public function groups(array $rows, array $startsAtByInterview = []): array
    {
        $byFallbackThenName = function ($a, $b) {
            $fa = in_array((string) $a, self::FALLBACKS, true) ? 1 : 0;
            $fb = in_array((string) $b, self::FALLBACKS, true) ? 1 : 0;
            return $fa !== $fb ? $fa <=> $fb : strcasecmp((string) $a, (string) $b);
        };

        $byPrecedenceChain = function ($x, $y) use ($startsAtByInterview) {
            $cmp = (self::TYPE_ORDER[$x['type']] ?? 99) <=> (self::TYPE_ORDER[$y['type']] ?? 99);
            if ($cmp !== 0) {
                return $cmp;
            }
            // Schulungen chronologisch, neueste zuerst — dieselbe Leserichtung
            // wie der Termin-Tie-Break im Assigner
            if ($x['type'] === 'schulung') {
                $sx = (string) ($startsAtByInterview[self::interviewIdOf($x)] ?? '');
                $sy = (string) ($startsAtByInterview[self::interviewIdOf($y)] ?? '');
                if ($sx !== $sy) {
                    return strcmp($sy, $sx);
                }
            }
            // ohne_schulung nach Phasen-Reihenfolge (key = "ohne_schulung:{order}|{name}")
            return strnatcmp((string) $x['key'], (string) $y['key']);
        };

        $buckets = [];
        foreach ($rows as $row) {
            $buckets[$row['group']['ort']][$row['group']['taetigkeit']][] = $row;
        }
        uksort($buckets, $byFallbackThenName);

        $groups = [];
        foreach ($buckets as $ort => $activities) {
            uksort($activities, $byFallbackThenName);
            $sorted = [];
            foreach ($activities as $act => $actRows) {
                usort($actRows, $byPrecedenceChain);
                $sorted[(string) $act] = $actRows;
            }
            $groups[(string) $ort] = ['ort' => (string) $ort, 'activities' => $sorted];
        }

        return $groups;
    }

    /**
     * Spaltenschluessel einer Zeile aufloesen. Neben den columns-Schluesseln auch
     * die drei Zeilen-Mengen 'ids', 'hr_desk_ids' und 'uneindeutig_ids' — die
     * beiden Marker (HR-Schreibtisch, uneindeutige Stellen-Zuordnung / Fall 2 der
     * Zuordnungsregel) werden damit genauso anklickbar wie jede Zahl.
     *
     * @return list<int>
     */
    public function idsOf(array $row, string $column): array
    {
        return match ($column) {
            'ids' => $row['ids'] ?? [],
            'hr_desk_ids' => $row['hr_desk_ids'] ?? [],
            'uneindeutig_ids' => $row['uneindeutig_ids'] ?? [],
            default => $row['columns'][$column] ?? [],
        };
    }

    /**
     * Zellenwert einer Zeilenmenge. Nutzt dieselbe Aufloesung wie das Drill-down
     * (idsOf) — angezeigte Zahl und Modal-Laenge koennen dadurch nicht
     * auseinanderlaufen.
     *
     * @param  list<array>  $rows
     */
    public function countIn(array $rows, string $column): int
    {
        return count($this->resolveIds($rows, ['scope' => 'all'], $column));
    }

    /**
     * IDs aller Zeilen, die auf $spec passen.
     *
     * $spec['scope']: 'row' (genau eine Zeile) | 'type' (Bucket in einer Gruppe)
     *                 | 'ort' (Ort-Summe) | 'all' (Gesamt)
     *
     * KEIN array_unique: die Zeilen sind per Rekonziliations-Invariante disjunkt.
     * Ein unique wuerde eine Verletzung maskieren, statt sie aufzudecken — die
     * Gesamt-Zeile der View vergleicht genau darauf.
     *
     * @param  list<array>  $rows
     * @return list<int>
     */
    public function resolveIds(array $rows, array $spec, string $column): array
    {
        $ort = isset($spec['ort']) ? (string) $spec['ort'] : null;
        $act = isset($spec['act']) ? (string) $spec['act'] : null;
        $type = isset($spec['type']) ? (string) $spec['type'] : null;
        $key = isset($spec['key']) ? (string) $spec['key'] : null;

        $matches = match ((string) ($spec['scope'] ?? 'all')) {
            'row' => fn ($row) => $row['type'] === $type && $row['key'] === $key
                && $row['group']['ort'] === $ort && $row['group']['taetigkeit'] === $act,
            'type' => fn ($row) => $row['type'] === $type
                && $row['group']['ort'] === $ort && $row['group']['taetigkeit'] === $act,
            'ort' => fn ($row) => $row['group']['ort'] === $ort,
            default => fn ($row) => true,
        };

        $ids = [];
        foreach ($rows as $row) {
            if ($matches($row)) {
                $ids = array_merge($ids, $this->idsOf($row, $column));
            }
        }

        return array_values($ids);
    }

    /**
     * Scope als wire:click-taugliches Token. Ort-, Taetigkeits- und Phasennamen
     * sind freie Nutzertexte und koennen Anfuehrungszeichen enthalten — als
     * nackte wire:click-Argumente wuerden sie den Ausdruck zerlegen. base64 ist
     * immer [A-Za-z0-9+/=] und damit attribut- wie parser-sicher.
     */
    public function encodeScope(array $spec): string
    {
        return base64_encode((string) json_encode($spec));
    }

    /** @return array<string,mixed>|null null = unbrauchbares Token (still ignorieren) */
    public function decodeScope(string $token): ?array
    {
        $decoded = base64_decode($token, true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }
        $spec = json_decode($decoded, true);

        return is_array($spec) ? $spec : null;
    }
}
