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
     * Reine ANZEIGE-Reihenfolge: Erfolgspfad zuerst (Schulung, dann noch offene
     * Bewerbungen), Befunde und Sonderfaelle danach.
     *
     * Das ist bewusst NICHT die Praezedenz-Kette der Spec §4 und darf auch nicht
     * mit ihr verwechselt werden: die Kette entscheidet im CohortAssigner, WELCHE
     * Zeile eine Person bekommt (Stufe 1 = is_test bis Stufe 8 = Phase). Hier wird
     * nur sortiert — eine Aenderung an dieser Liste verschiebt Zeilen in der
     * Tabelle, sie ordnet niemanden um.
     *
     * Vollstaendig gehalten, damit die Sortierung erwartbar bleibt; ein hier
     * fehlender Typ landet mit 99 am Ende und bleibt sichtbar, statt still zu
     * verschwinden (siehe Test).
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
     * die vier Zeilen-Mengen 'ids', 'hr_desk_ids', 'uneindeutig_ids' und
     * 'offen_ids' — die Marker (HR-Schreibtisch, uneindeutige Stellen-Zuordnung /
     * Fall 2 der Zuordnungsregel) und die offen-Menge werden damit genauso
     * anklickbar wie jede Zahl.
     *
     * @return list<int>
     */
    public function idsOf(array $row, string $column): array
    {
        return match ($column) {
            'ids' => $row['ids'] ?? [],
            'hr_desk_ids' => $row['hr_desk_ids'] ?? [],
            'uneindeutig_ids' => $row['uneindeutig_ids'] ?? [],
            'offen_ids' => $row['offen_ids'] ?? [],
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
            'all' => fn ($row) => true,
            // fail-closed: ein unbekannter Scope liefert NICHTS. Als Default auf
            // „alles" waere ein Tippfehler im Token ein Datenleck-artiger Unfall —
            // das Modal zeigte die gesamte Kohorte unter einem falschen Label.
            default => fn ($row) => false,
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
     * Conversion einer Zeilenmenge in Prozent (unterschrieben / Bewerbungen).
     *
     * null bedeutet „keine Quote", NICHT 0 %: ohne Bewerbungen ist nichts
     * gescheitert, und die Tabelle zeigt dafuer „–". 0 % heisst dagegen sehr wohl
     * „Bewerbungen da, aber keine Unterschrift".
     *
     * @param  list<array>  $rows
     */
    public function conversionOf(array $rows): ?int
    {
        $total = $this->countIn($rows, 'ids');
        if ($total === 0) {
            return null;
        }

        return (int) round($this->countIn($rows, 'unterschrieben') / $total * 100);
    }

    /**
     * Juengstes `max_applied_at` einer Zeilenmenge (Y-m-d) — der Alters-Anker, auch
     * fuer Summen-Zeilen. Zeilen ohne Datum (ohne_datum) zaehlen nicht mit; bestehen
     * ALLE Zeilen daraus, gibt es kein Alter → null.
     *
     * Maximum, nicht Minimum: die juengste Bewerbung liefert das kleinste Alter und
     * laesst die Conversion damit haeufiger ausgrauen. Bei einer Summen-Zeile
     * bestimmt also die frischeste enthaltene Zeile, ob die Quote schon zaehlt.
     *
     * @param  list<array>  $rows
     */
    public function maxAppliedAt(array $rows): ?string
    {
        $max = null;
        foreach ($rows as $row) {
            $value = $row['max_applied_at'] ?? null;
            if ($value === null) {
                continue;
            }
            // Y-m-d ist lexikographisch = chronologisch
            if ($max === null || $value > $max) {
                $max = (string) $value;
            }
        }

        return $max;
    }

    /**
     * Enthaelt die Zeilenmenge mindestens eine LAUFENDE Kohorte? Nur dann ist
     * „noch offen" eine Aussage — auf rein ausgeschlossenen Buckets zeigt die
     * Tabelle „–" statt einer Null, die wie ein Messwert aussieht.
     *
     * Die Typ-Liste kommt aus dem CohortAssigner, der die Typen vergibt — eine
     * eigene Kopie hier waere eine zweite Wahrheit.
     *
     * @param  list<array>  $rows
     */
    public function hasRunningRow(array $rows): bool
    {
        foreach ($rows as $row) {
            if (in_array($row['type'] ?? null, CohortAssigner::RUNNING_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Right-Censoring (Spec §6): Ist die Conversion dieser Zeile noch nicht
     * aussagekraeftig, weil die Kohorte juenger ist als der Median-Durchlauf?
     *
     * `$todayYmd` wird bewusst HEREINGEREICHT und nicht hier erzeugt — eine pure
     * Klasse darf keine Uhr haben, sonst waere die Regel nicht testbar.
     *
     * Grau (true) in vier Faellen, alle konservativ „Reife nicht belegbar":
     *  - kein Median (keine Unterschriften) → es gibt keine Referenz;
     *  - Zeile ohne Datum (ohne_datum) → kein Alter bestimmbar;
     *  - unlesbares/rollover-Datum → lieber grau als falsch zuversichtlich;
     *  - Alter < Median. Die Grenze ist STRIKT: Alter == Median gilt als reif.
     */
    public function isCensored(?string $rowMaxAppliedAt, string $todayYmd, ?int $tthMedian): bool
    {
        if ($tthMedian === null || $rowMaxAppliedAt === null) {
            return true;
        }

        $age = self::ageInDays($rowMaxAppliedAt, $todayYmd);
        if ($age === null) {
            return true;
        }

        return $age < $tthMedian;
    }

    /** Ganze Tage zwischen zwei Y-m-d-Strings; negativ moeglich, null = unlesbar. */
    private static function ageInDays(string $fromYmd, string $toYmd): ?int
    {
        $from = self::parseYmd($fromYmd);
        $to = self::parseYmd($toYmd);
        if ($from === null || $to === null) {
            return null;
        }

        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    private static function parseYmd(string $value): ?\DateTimeImmutable
    {
        // '!' nullt alle Zeitfelder, fixe UTC-Zone → die Differenz ist exakt in
        // Tagen, ohne Sommerzeit-Effekte.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        // Round-Trip-Pruefung: createFromFormat rollt '2026-02-30' still auf
        // '2026-03-02' weiter. Ein solches Datum ist kaputt, kein Datum.
        return $date->format('Y-m-d') === $value ? $date : null;
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
