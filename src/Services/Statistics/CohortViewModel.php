<?php

namespace Platform\Recruiting\Services\Statistics;

use Platform\Recruiting\Support\YmdDate;

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
     * Der Vertrag ist FLACH: eine Spalte ist eine ID-Menge. `phase_reached` ist
     * dagegen nach Phasen-`order` verschachtelt (`[order => ids]`) und passt hier
     * nicht hinein — der order-qualifizierte Zugriff kommt in Task 8. Wer es
     * trotzdem versucht, bekommt eine Exception statt einer stillen Zahl (siehe
     * flatColumn).
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
            default => $this->flatColumn($row, $column),
        };
    }

    /**
     * Spaltenwert als flache ID-Menge — mit lautem Abbruch bei verschachtelten
     * Spalten.
     *
     * Warum eine Exception und nicht einfach `[]` oder das Verschachtelte selbst:
     * `count()` auf `phase_reached` zaehlt PHASEN, nicht Bewerbungen (gemessen im
     * Review: 44 statt 25). Das ist eine plausibel aussehende falsche Zahl in
     * einer Zelle, und genau solche Zahlen soll diese Seite abschaffen — der
     * Kunde konnte die alten nicht nachvollziehen. Ein verschachtelter
     * Spaltenwert ist ein Programmierfehler des Aufrufers (z.B. `phase_reached`
     * in die `$colDefs` der View aufgenommen, ohne den Zugriff je Phase zu
     * bauen), kein Datenfall — also fail-loud statt fail-quiet.
     *
     * Geprueft wird die FORM, nicht der Spaltenname: kommt in Task 8 eine zweite
     * verschachtelte Spalte dazu, greift die Sperre ohne Pflege einer Namensliste.
     *
     * Heute unerreichbar: `$colDefs` (index.blade.php) listet die Spalten
     * einzeln auf und enthaelt `phase_reached` nicht; `tiles()` summiert
     * namentlich. Ein gecrafteter `drill(token, 'phase_reached')`-Aufruf lief
     * schon vorher in einen Fehler (verschachtelte Arrays in `whereIn`) — jetzt
     * eben in einen sprechenden.
     *
     * @return list<int>
     */
    private function flatColumn(array $row, string $column): array
    {
        $value = $row['columns'][$column] ?? [];
        if ($value !== [] && is_array(reset($value))) {
            throw new \InvalidArgumentException(
                "Spalte '{$column}' ist verschachtelt (z.B. phase_reached: [order => ids]) und "
                . 'hat in idsOf()/countIn() keine flache ID-Menge. Zugriff je Phase '
                . 'order-qualifiziert bauen (Task 8), nicht ueber den flachen Spalten-Vertrag.'
            );
        }

        return $value;
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
     * Scope 'row' braucht seit v2 zusaetzlich $spec['posting'] (?int, die
     * posting_id der Zeile; null = ohne Ausschreibung) — ohne diesen Schluessel
     * trifft er absichtlich nichts.
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

        // Ab v2 haengt JEDE Zeile an einer Ausschreibung (CohortAssigner bildet den
        // Zeilen-Schluessel mit der posting_id als fuehrendem Bestandteil). Der
        // Row-Key im Token ist aber der SCHMALE key des Assigners ("schulung:42",
        // "ohne_schulung:2|Onboarding") — der ist seitdem nicht mehr eindeutig:
        // zwei Ausschreibungen derselben Gruppe mit gleichem Typ und gleichem key
        // sind zwei Zeilen, und ein row-Token passte auf beide. Das Modal zeigte
        // dann die IDs beider Ausschreibungen unter dem Label einer einzigen.
        // Deshalb ist die Ausschreibung ein eigenes Vergleichskriterium.
        //
        // isset() reicht hier NICHT: posting_id ist bei Bewerbungen ohne Zuordnung
        // legitim null (Fall 3 der Zuordnungsregel), und isset(null) ist false.
        // Unterschieden wird deshalb „Schluessel vorhanden" (auch mit Wert null)
        // von „Schluessel fehlt" — und das Fehlen ist fail-closed (siehe unten).
        $postingGiven = array_key_exists('posting', $spec);
        $posting = ($postingGiven && $spec['posting'] !== null) ? (int) $spec['posting'] : null;

        $matches = match ((string) ($spec['scope'] ?? 'all')) {
            // fail-closed wie beim unbekannten Scope: ein row-Token OHNE
            // Ausschreibungs-Angabe (alter/gecrafteter Token) liefert NICHTS,
            // statt auf alle Ausschreibungen der Gruppe zu passen. Ein leeres
            // Modal ist der harmlose Ausgang; die stille Vermischung zweier
            // Ausschreibungen unter einem Label ist genau der Fehler, den diese
            // Seite nicht machen darf.
            'row' => fn ($row) => $postingGiven
                && $row['type'] === $type && $row['key'] === $key
                && $row['group']['ort'] === $ort && $row['group']['taetigkeit'] === $act
                && ($row['posting_id'] ?? null) === $posting,
            'type' => fn ($row) => $row['type'] === $type
                && $row['group']['ort'] === $ort && $row['group']['taetigkeit'] === $act,
            'ort' => fn ($row) => $row['group']['ort'] === $ort,
            // Ein Zeilentyp ueber ALLE Gruppen hinweg — Grundlage der Kachel
            // „Ohne Termin", die nicht an einem Ort haengt.
            'type_all' => fn ($row) => $row['type'] === $type,
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
     * CLIENT-GRENZE: wie resolveIds, aber fail-closed statt laut — fuer alles, was
     * aus einem Request kommt (drill() in der Livewire-Komponente).
     *
     * Die Trennlinie, und sie ist beabsichtigt:
     *  - INNEN laut. Ein verschachtelter Spaltenwert in idsOf()/flatColumn() ist
     *    ein Programmierfehler des Aufrufers (phase_reached ohne den
     *    order-qualifizierten Zugriff aus Task 8) und muss knallen, sonst zeigt
     *    eine Zelle eine plausible falsche Zahl.
     *  - AN DER GRENZE still. Was von draussen kommt, ist EINGABE, nicht Code:
     *    $column stammt bei drill() aus dem Request und ist damit manipulierbar.
     *    Eine unbrauchbare Angabe liefert deshalb eine leere Menge — dieselbe
     *    Regel, die hier schon fuer einen unbrauchbaren Token (decodeScope →
     *    null) und einen unbekannten Scope (default → nichts) gilt. Ein
     *    gecrafteter Request darf die Seite nicht sprengen.
     *
     * Fangt bewusst NUR InvalidArgumentException (die Form-Sperre aus
     * flatColumn) — alles andere waere ein echter Defekt und soll sichtbar
     * bleiben, kein pauschales catch \Throwable.
     *
     * @param  list<array>  $rows
     * @return list<int>
     */
    public function resolveIdsFromClient(array $rows, array $spec, string $column): array
    {
        try {
            return $this->resolveIds($rows, $spec, $column);
        } catch (\InvalidArgumentException) {
            return [];
        }
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

        $age = YmdDate::daysBetween($rowMaxAppliedAt, $todayYmd);
        if ($age === null) {
            return true;
        }

        return $age < $tthMedian;
    }

    /**
     * Right-Censoring fuer AGGREGATE (Summen-Zeile, Gesamt-Zeile, KPI-Kachel):
     * grau nur, wenn JEDE enthaltene Zeile fuer sich zu jung ist.
     *
     * Warum nicht dieselbe Regel wie fuer Einzelzeilen: der Alters-Anker einer
     * Zeilenmenge ist die juengste enthaltene Bewerbung (maxAppliedAt) — in einer
     * Gesamtsicht ist praktisch immer eine von heute dabei, die Kachel waere also
     * dauerhaft grau. Ein Zustand, der nie wechselt, traegt keine Information und
     * liest sich als Anzeigefehler (Live-Befund 2026-08-05: Kachel grau, einzelne
     * Zeilen daneben farbig).
     *
     * Fachlich traegt die Unterscheidung: der Verzerrungsanteil ist |jung| / |alle|
     * — ueber viele reife Zeilen vernachlaessigbar, in einer einzelnen frischen
     * Schulung dominant. Sind ALLE Zeilen jung, greift das Argument nicht mehr und
     * auch das Aggregat wird ausgegraut. Die exakte Variante (Reife pro Bewerbung
     * statt pro Zeile) ist fuer Teil 2 vorgemerkt.
     *
     * @param  list<array>  $rows
     */
    public function isCensoredAggregate(array $rows, string $todayYmd, ?int $tthMedian): bool
    {
        if ($rows === []) {
            return true; // keine Zeilen → keine belegbare Reife
        }

        foreach ($rows as $row) {
            if (!$this->isCensored($row['max_applied_at'] ?? null, $todayYmd, $tthMedian)) {
                return false; // mindestens eine reife Zeile → Aggregat zaehlt
            }
        }

        return true;
    }

    /**
     * EINZIGER Einstieg fuer die Anzeige: waehlt die Censoring-Regel anhand der
     * Zeilenmenge selbst, statt sie sich vom Aufrufer sagen zu lassen.
     *
     * Kriterium ist „mehr als eine Zeile" — und das ist nicht Bequemlichkeit,
     * sondern genau die Voraussetzung des Verduennungs-Arguments: nur wenn eine
     * Menge mehrere Zeilen enthaelt, koennen reife die jungen ueberstimmen. Eine
     * Sammelzeile mit genau einer Phase ist eben KEIN Aggregat und wird wie eine
     * Einzelzeile behandelt.
     *
     * Vorher entschied ein durchgereichtes Flag ($isTotal) darueber. Das war an
     * drei von vier Stellen richtig gesetzt und an der vierten (Sammelzeile
     * „Ohne Schulung") falsch — dieselbe Fehlerklasse, die das Paket eigentlich
     * beheben sollte. Mit der Ableitung aus den Daten kann kein Aufrufer sie
     * mehr falsch setzen.
     *
     * @param  list<array>  $rows
     */
    public function isCensoredForRows(array $rows, string $todayYmd, ?int $tthMedian): bool
    {
        return count($rows) > 1
            ? $this->isCensoredAggregate($rows, $todayYmd, $tthMedian)
            : $this->isCensored($this->maxAppliedAt($rows), $todayYmd, $tthMedian);
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
