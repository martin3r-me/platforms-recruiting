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
     * Praefix des order-qualifizierten Zugriffs auf die verschachtelte Spalte
     * `phase_reached` (siehe phaseColumnKey/phaseIds). Bewusst mit ':' statt
     * '_': der Trenner darf in keinem echten Spaltenschluessel vorkommen.
     */
    public const PHASE_COLUMN_PREFIX = 'phase_reached:';

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
     * nicht hinein. Erreichbar ist sie nur ORDER-QUALIFIZIERT, also je Phase
     * ("phase_reached:3", siehe phaseColumnKey). Die flache Lesart bleibt
     * verboten und liefert eine Exception statt einer stillen Zahl (flatColumn).
     *
     * @return list<int>
     */
    public function idsOf(array $row, string $column): array
    {
        return match (true) {
            $column === 'ids' => $row['ids'] ?? [],
            $column === 'hr_desk_ids' => $row['hr_desk_ids'] ?? [],
            $column === 'uneindeutig_ids' => $row['uneindeutig_ids'] ?? [],
            $column === 'offen_ids' => $row['offen_ids'] ?? [],
            str_starts_with($column, self::PHASE_COLUMN_PREFIX) => $this->phaseIds($row, $column),
            default => $this->flatColumn($row, $column),
        };
    }

    /**
     * Spaltenschluessel einer einzelnen Phase des Trichters. EINE Quelle fuer
     * View (Spaltendefinition), Zaehlung und Drill-down — ein zweites
     * Zusammenbauen des Strings an der Klickstelle waere die Sorte Kopie, die
     * beim naechsten Umbenennen auseinanderlaeuft.
     */
    public static function phaseColumnKey(int $order): string
    {
        return self::PHASE_COLUMN_PREFIX . $order;
    }

    /**
     * IDs EINER Phase aus der verschachtelten Spalte `phase_reached`.
     *
     * Eine leere Menge ist ein normaler Datenfall (die Filiale hat mehr Phasen,
     * als diese Zeile erreicht hat, oder die Zeile ist ein ausgeschlossener
     * Bucket und fuellt den Trichter gar nicht) — deshalb `[]` und kein Fehler.
     *
     * Eine unbrauchbare order dagegen ist ein PROGRAMMIERFEHLER des Aufrufers
     * und bricht laut ab, genau wie die flache Lesart in flatColumn(): eine
     * still auf 0 zusammenfallende Phasen-Spalte saehe wie ein Messwert aus
     * ("niemand hat diese Phase erreicht"), und solche Zahlen soll diese Seite
     * abschaffen. An der Client-Grenze wird derselbe Fall wieder still —
     * resolveIdsFromClient fangt InvalidArgumentException ab, weil $column bei
     * drill() aus dem Request kommt (Merkregel: innen laut, an der Grenze still).
     *
     * @return list<int>
     */
    private function phaseIds(array $row, string $column): array
    {
        $order = substr($column, strlen(self::PHASE_COLUMN_PREFIX));
        // ctype_digit statt is_numeric: '-1', '1.5', '1e2' und '' sind KEINE
        // Phasen-order. is_numeric haette sie alle durchgelassen und (int) haette
        // daraus stumme Treffer gemacht.
        if ($order === '' || !ctype_digit($order)) {
            throw new \InvalidArgumentException(
                "Spalte '{$column}' hat keine brauchbare Phasen-order. Erwartet wird "
                . self::PHASE_COLUMN_PREFIX . '{ganze Zahl} (siehe phaseColumnKey).'
            );
        }

        return $row['columns']['phase_reached'][(int) $order] ?? [];
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
     * in die `$colDefs` der View aufgenommen, statt den order-qualifizierten
     * Zugriff phaseColumnKey() zu benutzen), kein Datenfall — also fail-loud
     * statt fail-quiet.
     *
     * Geprueft wird die FORM, nicht der Spaltenname: kommt eine zweite
     * verschachtelte Spalte dazu, greift die Sperre ohne Pflege einer Namensliste.
     *
     * Die Sperre bleibt auch mit dem Zugriff je Phase (phaseIds) noetig: der ist
     * eine zusaetzliche Tuer fuer "phase_reached:3", keine Aufweichung der
     * flachen Lesart. `$colDefs` (index.blade.php) listet die Spalten einzeln
     * auf und enthaelt `phase_reached` nicht; `tiles()` summiert namentlich. Ein
     * gecrafteter `drill(token, 'phase_reached')`-Aufruf lief schon vorher in
     * einen Fehler (verschachtelte Arrays in `whereIn`) — jetzt eben in einen
     * sprechenden.
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
     *                 | 'ort' (Ort-Summe) | 'posting' (alle Zeilen EINER
     *                 Ausschreibung) | 'all' (Gesamt)
     *
     * Die Scopes 'row' und 'posting' brauchen zusaetzlich $spec['posting'] (?int,
     * die posting_id; null = ohne Ausschreibung) — ohne diesen Schluessel treffen
     * sie absichtlich nichts.
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
            // ALLE Zeilen einer Ausschreibung — die Zeilen-Einheit der
            // Ausschreibungs-Tabelle (Tabelle 1), die ueber die Zeilentypen
            // hinweg summiert. 'type'/'ort' schneiden anders und koennten sie
            // nicht ersetzen. Dieselbe fail-closed-Regel wie bei 'row': ohne
            // Angabe der Ausschreibung trifft der Scope nichts, statt auf alles
            // zu passen (ein leeres Modal ist der harmlose Ausgang).
            'posting' => fn ($row) => $postingGiven && ($row['posting_id'] ?? null) === $posting,
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
     * Prozentwert einer Summen-Zeile: Summe der Zaehler geteilt durch Summe der
     * Nenner — NIEMALS der Mittelwert der Zeilen-Prozente. Bei 1/1 und 1/99
     * ergaebe der Mittelwert 50 %, richtig sind 2 %.
     *
     * null = kein Nenner gepflegt, also keine Quote (nicht 0 %).
     *
     * @param  list<array>  $rows
     */
    public function sumPercent(array $rows, string $numeratorColumn, string $bedarfKey): ?int
    {
        $numerator = 0;
        $denominator = 0;
        foreach ($rows as $row) {
            $bedarf = $row[$bedarfKey] ?? null;
            if ($bedarf === null || $bedarf <= 0) {
                continue;
            }
            $denominator += (int) $bedarf;
            $numerator += count($row['columns'][$numeratorColumn] ?? []);
        }

        return $denominator > 0 ? (int) round($numerator / $denominator * 100) : null;
    }

    /**
     * Summe der gepflegten Bedarfe — der NENNER der Erfuellungs-Quote und damit
     * genau dieselbe Auswahl wie in sumPercent(): eine Ausschreibung ohne
     * gepflegten Bedarf (null oder <= 0) zaehlt in keinem von beiden mit.
     * Stuende in der Bedarf-Zelle eine andere Summe als die, gegen die die Quote
     * daneben rechnet, waere die Zeile nicht nachrechenbar — und Nachrechenbarkeit
     * ist der Grund fuer diese Seite.
     *
     * null = kein einziger Bedarf gepflegt (nicht 0).
     *
     * @param  list<array>  $rows
     */
    public function sumBedarf(array $rows, string $bedarfKey = 'bedarf'): ?int
    {
        $sum = null;
        foreach ($rows as $row) {
            $bedarf = $row[$bedarfKey] ?? null;
            if ($bedarf === null || $bedarf <= 0) {
                continue;
            }
            $sum = ($sum ?? 0) + (int) $bedarf;
        }

        return $sum;
    }

    /**
     * Bezugsgroessen der Erfuellungs-Quote in der Gesamt-Zeile — die Zahlen, aus
     * denen sumPercent() seinen Prozentwert bildet, PLUS das, was nicht darin
     * steckt.
     *
     * Warum das eigene Zahlen braucht und der Prozentwert allein nicht reicht:
     * die Quote zaehlt nur Ausschreibungen mit gepflegtem Bedarf, die
     * Spalte „Unterschrieben" daneben zaehlt ALLE. Bei „Unterschrieben 9" und
     * „Bedarf 10" liest jeder 90 %, angezeigt werden aber 50 % (5 von 10, weil
     * vier Unterschriften an Ausschreibungen ohne Bedarf haengen). Der Kunde hat
     * genau diese Sorte Zahl reklamiert („woher kommt die 96?"). Die Quote bleibt
     * richtig — sie darf nicht von ungepflegten Ausschreibungen verwaessert
     * werden —, aber ihr Bezug muss sichtbar sein: `signed`/`bedarf` sind der
     * Bruch, die `excluded_*`/`without_posting_*`-Werte benennen die Differenz
     * zur Spalte daneben.
     *
     * Die beiden Gruende, aus der Quote zu fallen, werden GETRENNT gezaehlt und
     * duerfen nicht vermischt werden:
     *  - `excluded_postings`: echte Ausschreibungen, an denen kein Bedarf
     *    gepflegt ist. Das ist ein Pflege-Hinweis („trag den Bedarf nach").
     *  - `without_posting_*`: die Zeile „ohne Ausschreibung" (Fall 3 der
     *    Zuordnungsregel). Dort gibt es keine Ausschreibung, an der man etwas
     *    pflegen KOENNTE — sie als „Ausschreibung ohne gepflegten Bedarf" zu
     *    zaehlen machte die genannte Zahl um eins zu gross und passte nicht zu
     *    dem, was die Tabelle als Zeilen zeigt.
     * Die Summen sind von der Trennung unberuehrt; sie betrifft die Beschriftung,
     * und eine Differenz falsch zu benennen ist schlimmer als sie nicht zu
     * erklaeren.
     *
     * @param  list<array>  $rows
     * @return array{signed:int, bedarf:?int, pct:?int, excluded_postings:int,
     *               excluded_signed:int, without_posting_groups:int, without_posting_signed:int}
     */
    public function fulfilmentTotals(array $rows, string $numeratorColumn = 'unterschrieben', string $bedarfKey = 'bedarf'): array
    {
        $signed = 0;
        $excludedPostings = 0;
        $excludedSigned = 0;
        $withoutPostingGroups = 0;
        $withoutPostingSigned = 0;
        foreach ($rows as $row) {
            $bedarf = $row[$bedarfKey] ?? null;
            $count = count($row['columns'][$numeratorColumn] ?? []);
            // Dieselbe Bedingung wie in sumPercent() und sumBedarf() — die drei
            // MUESSEN dieselben Ausschreibungen zaehlen, sonst zeigt die Zelle
            // einen Bruch, der nicht zu ihrem Prozentwert passt. Ein Test haelt
            // die drei gegeneinander fest.
            if ($bedarf === null || $bedarf <= 0) {
                if (($row['posting_id'] ?? null) === null) {
                    $withoutPostingGroups++;
                    $withoutPostingSigned += $count;
                } else {
                    $excludedPostings++;
                    $excludedSigned += $count;
                }
                continue;
            }
            $signed += $count;
        }

        return [
            'signed' => $signed,
            'bedarf' => $this->sumBedarf($rows, $bedarfKey),
            // Der Prozentwert kommt aus der EINEN benannten Quelle, nicht aus
            // einer zweiten Division hier.
            'pct' => $this->sumPercent($rows, $numeratorColumn, $bedarfKey),
            'excluded_postings' => $excludedPostings,
            'excluded_signed' => $excludedSigned,
            'without_posting_groups' => $withoutPostingGroups,
            'without_posting_signed' => $withoutPostingSigned,
        ];
    }

    /**
     * Bezugsgroessen der Pipeline-Ampel in der Gesamt-Zeile: Σ Bewerbungen gegen
     * Σ (Bedarf x Faktor).
     *
     * Warum das Ziel schon hier fertig gerechnet wird und nicht als Faktor
     * weiterreist: ein Faktor laesst sich nicht addieren (8,0 und 12,0 ergeben
     * nicht 20,0). Aufgerundet wird JE AUSSCHREIBUNG, genau wie in
     * TargetLight::pipeline — die Summe der Einzelziele ist das Ziel, nicht das
     * Ziel der Summe (3 x 7,5 = 23, nicht 22,5).
     *
     * Nur Ausschreibungen mit Bedarf UND Faktor zaehlen, und zwar auf BEIDEN
     * Seiten des Bruchs: eine ungepflegte Ausschreibung wuerde sonst Bewerbungen
     * in den Zaehler geben, ohne einen Nenner beizusteuern — die Gesamt-Ampel
     * stuende dann auf Gruen, weil jemand ein Feld nicht gefuellt hat.
     *
     * target = null heisst "keine einzige Ausschreibung gepflegt" (nicht 0) und
     * fuehrt in TargetLight zur grauen Ampel.
     *
     * @param  list<array>  $rows  Zeilen mit 'bedarf', 'bewerbungs_faktor', 'ids'
     * @return array{bewerbungen:int, target:?int}
     */
    public function pipelineTotals(array $rows): array
    {
        $bewerbungen = 0;
        $target = null;
        foreach ($rows as $row) {
            $bedarf = $row['bedarf'] ?? null;
            $faktor = $row['bewerbungs_faktor'] ?? null;
            if ($bedarf === null || $faktor === null || $bedarf <= 0 || $faktor <= 0) {
                continue;
            }
            $target = ($target ?? 0) + (int) ceil($bedarf * $faktor);
            $bewerbungen += count($row['ids'] ?? []);
        }

        return ['bewerbungen' => $bewerbungen, 'target' => $target];
    }

    /**
     * Eine Zeile je AUSSCHREIBUNG (Tabelle 1 der Statistik-Seite): die
     * Assigner-Zeilen einer Ausschreibung werden zu einer Anzeige-Zeile
     * zusammengefasst — der Kunde fragt "laeuft diese Ausschreibung auf Ziel",
     * nicht "wie verteilt sich sie ueber die Zeilentypen".
     *
     * Rein umsortierend wie groups(): keine Zeile geht verloren, keine kommt
     * hinzu (siehe Test), damit die Rekonziliations-Invariante die Gruppierung
     * ueberlebt.
     *
     * Die Stammdaten (bedarf, bewerbungs_faktor, published_ymd, closes_ymd)
     * haengt der AUFRUFER an die Zeilen (Livewire-Komponente, aus rec_postings) —
     * diese Klasse hat keine DB. Sie sind je Ausschreibung identisch, deshalb
     * genuegt die erste Zeile als Quelle. Fehlt ein Wert, bleibt er null: "leer
     * heisst nicht gepflegt", und eine nicht gepflegte Ausschreibung bekommt eine
     * graue Ampel statt einer erfundenen.
     *
     * Die Gruppe traegt zusaetzlich die VEREINIGTEN Mengen ('ids', 'columns'),
     * damit sie sich fuer sumPercent()/pipelineTotals() wie eine Zeile verhaelt.
     * Das ist keine zweite Wahrheit: die Vereinigung ist eine reine
     * Konkatenation disjunkter Zeilenmengen (Rekonziliations-Invariante) und
     * zaehlt damit identisch zu countIn() ueber 'rows'. Die Zellen der Tabelle
     * rechnen weiter ueber 'rows', weil dort auch das Drill-down haengt.
     *
     * @param  list<array>  $rows  Assigner-Zeilen, um die Stammdaten ergaenzt
     * @return list<array{posting_id:?int, posting_title:string, posting_closed:bool,
     *                    taetigkeiten:list<string>, bedarf:?int, bewerbungs_faktor:?float,
     *                    published_ymd:?string, closes_ymd:?string,
     *                    ids:list<int>, columns:array<string,mixed>, rows:list<array>}>
     */
    public function postingGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $postingId = $row['posting_id'] ?? null;
            // '-' als Buckt-Schluessel fuer "ohne Ausschreibung": ein leerer
            // Array-Schluessel wuerde mit der Ausschreibung 0 kollidieren.
            $bucket = $postingId === null ? '-' : (string) $postingId;

            if (!isset($groups[$bucket])) {
                $groups[$bucket] = [
                    'posting_id' => $postingId,
                    'posting_title' => (string) ($row['posting_title'] ?? ''),
                    'posting_closed' => (bool) ($row['posting_closed'] ?? false),
                    'taetigkeiten' => [],
                    'bedarf' => $row['bedarf'] ?? null,
                    'bewerbungs_faktor' => $row['bewerbungs_faktor'] ?? null,
                    'published_ymd' => $row['published_ymd'] ?? null,
                    'closes_ymd' => $row['closes_ymd'] ?? null,
                    'ids' => [],
                    'columns' => [],
                    'rows' => [],
                ];
            }

            $group = &$groups[$bucket];
            $group['rows'][] = $row;
            $group['ids'] = array_merge($group['ids'], $row['ids'] ?? []);
            $taetigkeit = (string) ($row['group']['taetigkeit'] ?? '');
            if ($taetigkeit !== '' && !in_array($taetigkeit, $group['taetigkeiten'], true)) {
                $group['taetigkeiten'][] = $taetigkeit;
            }
            foreach (($row['columns'] ?? []) as $column => $value) {
                $group['columns'][$column] = $this->mergeColumn($group['columns'][$column] ?? null, $value);
            }
            unset($group);
        }

        // Anzeige-Reihenfolge: echte Ausschreibungen alphabetisch (Titel ist das,
        // was der Kunde liest), "ohne Ausschreibung" als Befund ans Ende — dieselbe
        // Regel wie fuer die Fallback-Gruppen in groups().
        $groups = array_values($groups);
        usort($groups, function ($a, $b) {
            $fa = $a['posting_id'] === null ? 1 : 0;
            $fb = $b['posting_id'] === null ? 1 : 0;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }
            $cmp = strnatcasecmp($a['posting_title'], $b['posting_title']);

            // Tie-Break ueber die ID: zwei Ausschreibungen duerfen gleich heissen,
            // die Reihenfolge muss trotzdem stabil sein (sonst springen Zeilen
            // zwischen zwei Aufrufen).
            return $cmp !== 0 ? $cmp : ((int) $a['posting_id'] <=> (int) $b['posting_id']);
        });

        return $groups;
    }

    /**
     * Zwei Spaltenwerte vereinigen — flach (list<int>) wie verschachtelt
     * (phase_reached: [order => ids]). Die Form entscheidet, nicht der Name,
     * genau wie in flatColumn(): eine neue verschachtelte Spalte funktioniert
     * ohne Pflege einer Namensliste.
     */
    private function mergeColumn(mixed $carry, mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $carry = is_array($carry) ? $carry : [];

        // Verschachtelt: je order vereinigen. Ein array_merge auf der obersten
        // Ebene wuerde die order-Schluessel neu durchnummerieren und die Phasen
        // gegeneinander verschieben.
        if (($value !== [] && is_array(reset($value))) || ($carry !== [] && is_array(reset($carry)))) {
            foreach ($value as $order => $ids) {
                $carry[$order] = array_merge($carry[$order] ?? [], is_array($ids) ? $ids : []);
            }

            return $carry;
        }

        return array_merge($carry, $value);
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
