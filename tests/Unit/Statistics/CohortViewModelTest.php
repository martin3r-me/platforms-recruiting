<?php

namespace Platform\Recruiting\Tests\Unit\Statistics;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Statistics\CohortViewModel;

/**
 * Anzeige-Logik der Statistik-Seite. Die Livewire-Komponente selbst ist im
 * Modul nicht testbar (kein Laravel/DB im Bootstrap) — deshalb liegt der
 * riskante Teil hier: Gruppierung/Sortierung und die Aufloesung der
 * Drill-down-Mengen.
 */
final class CohortViewModelTest extends TestCase
{
    private function vm(): CohortViewModel
    {
        return new CohortViewModel();
    }

    /**
     * @param  list<int>  $ids
     */
    private function row(
        string $type,
        string $key,
        string $ort,
        string $taetigkeit,
        array $ids = [],
        array $columns = [],
        array $hrDesk = [],
        array $uneindeutig = [],
        ?int $postingId = null,
    ): array {
        return [
            'type' => $type,
            'key' => $key,
            'group' => ['ort' => $ort, 'taetigkeit' => $taetigkeit, 'uneindeutig' => $uneindeutig !== []],
            // seit v2: jede Assigner-Zeile haengt an einer Ausschreibung
            // (null = ohne Zuordnung, Fall 3 der Zuordnungsregel)
            'posting_id' => $postingId,
            'posting_title' => $postingId === null ? '' : 'Ausschreibung ' . $postingId,
            'posting_closed' => false,
            'ids' => $ids,
            'hr_desk_ids' => $hrDesk,
            'uneindeutig_ids' => $uneindeutig,
            'tth_days' => [],
            'columns' => array_merge([
                'kontaktiert' => [], 'gebucht' => [], 'bestaetigt' => [],
                'teilgenommen' => [], 'standby' => [], 'no_show' => [],
                'vertrag_verschickt' => [], 'unterschrieben' => [],
            ], $columns),
        ];
    }

    public function test_echte_orte_stehen_alphabetisch_vor_den_fallback_gruppen(): void
    {
        $rows = [
            $this->row('ohne_datum', '-', 'ohne Ort', 'Service'),
            $this->row('ohne_datum', '-', 'Wuppertal', 'Service'),
            $this->row('ohne_datum', '-', 'ohne Ausschreibung', 'ohne Ausschreibung'),
            $this->row('ohne_datum', '-', 'Essen', 'Service'),
        ];

        $this->assertSame(
            ['Essen', 'Wuppertal', 'ohne Ausschreibung', 'ohne Ort'],
            array_keys($this->vm()->groups($rows)),
        );
    }

    public function test_taetigkeiten_sortieren_fallbacks_ebenfalls_ans_ende(): void
    {
        $rows = [
            $this->row('ohne_datum', '-', 'Essen', 'ohne Tätigkeit'),
            $this->row('ohne_datum', '-', 'Essen', 'Spülhilfe'),
            $this->row('ohne_datum', '-', 'Essen', 'Bankett'),
        ];

        $groups = $this->vm()->groups($rows);

        $this->assertSame(
            ['Bankett', 'Spülhilfe', 'ohne Tätigkeit'],
            array_keys($groups['Essen']['activities']),
        );
    }

    /**
     * Erwartet wird die ANZEIGE-Reihenfolge (Erfolgspfad zuerst), NICHT die
     * Praezedenz-Kette des Assigners — die lautet ohne_datum, dublette, unrouted,
     * import, schulung/unbekannter_status, abgesagt, geparkt, ohne_schulung.
     * Die Kette bestimmt die Zeilen-Zuordnung, diese Liste nur die Sortierung.
     */
    public function test_zeilen_folgen_der_anzeige_reihenfolge(): void
    {
        // absichtlich in verdrehter Reihenfolge eingefuettert
        $rows = [
            $this->row('unbekannter_status', '-', 'Essen', 'Service'),
            $this->row('abgesagt', '-', 'Essen', 'Service'),
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service'),
            $this->row('import', '-', 'Essen', 'Service'),
            $this->row('schulung', 'schulung:7', 'Essen', 'Service'),
            $this->row('geparkt', '-', 'Essen', 'Service'),
            $this->row('dublette', '-', 'Essen', 'Service'),
            $this->row('unrouted', '-', 'Essen', 'Service'),
            $this->row('ohne_datum', '-', 'Essen', 'Service'),
        ];

        $ordered = array_map(
            fn ($r) => $r['type'],
            $this->vm()->groups($rows)['Essen']['activities']['Service'],
        );

        $this->assertSame([
            'schulung', 'ohne_schulung', 'geparkt', 'abgesagt', 'dublette',
            'unrouted', 'import', 'ohne_datum', 'unbekannter_status',
        ], $ordered);
    }

    public function test_schulungen_stehen_chronologisch_neueste_zuerst(): void
    {
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service'),
            $this->row('schulung', 'schulung:2', 'Essen', 'Service'),
            $this->row('schulung', 'schulung:3', 'Essen', 'Service'),
        ];
        $startsAt = [
            1 => '2026-05-01 09:00:00',
            2 => '2026-08-01 09:00:00',
            3 => '2026-06-15 09:00:00',
        ];

        $keys = array_map(
            fn ($r) => $r['key'],
            $this->vm()->groups($rows, $startsAt)['Essen']['activities']['Service'],
        );

        $this->assertSame(['schulung:2', 'schulung:3', 'schulung:1'], $keys);
    }

    public function test_phasen_zeilen_sortieren_natuerlich_nach_phasen_reihenfolge(): void
    {
        // Naive String-Sortierung wuerde "10" vor "2" stellen
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:10|Abschluss', 'Essen', 'Service'),
            $this->row('ohne_schulung', 'ohne_schulung:2|Screening', 'Essen', 'Service'),
        ];

        $keys = array_map(
            fn ($r) => $r['key'],
            $this->vm()->groups($rows)['Essen']['activities']['Service'],
        );

        $this->assertSame(['ohne_schulung:2|Screening', 'ohne_schulung:10|Abschluss'], $keys);
    }

    public function test_gruppierung_verliert_und_erfindet_keine_zeile(): void
    {
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [1, 2]),
            $this->row('geparkt', '-', 'Essen', 'Bankett', [3]),
            $this->row('dublette', '-', 'ohne Ort', 'ohne Tätigkeit', [4]),
            $this->row('import', '-', 'Wuppertal', 'Service', [5, 6]),
        ];

        $flat = [];
        foreach ($this->vm()->groups($rows) as $group) {
            foreach ($group['activities'] as $actRows) {
                $flat = array_merge($flat, $actRows);
            }
        }

        $this->assertCount(count($rows), $flat, 'Gruppierung ist rein umsortierend');
        $ids = [];
        foreach ($flat as $row) {
            $ids = array_merge($ids, $row['ids']);
        }
        sort($ids);
        $this->assertSame([1, 2, 3, 4, 5, 6], $ids);
    }

    public function test_ids_of_loest_zeilen_mengen_und_spalten_auf(): void
    {
        $row = $this->row(
            'schulung', 'schulung:1', 'Essen', 'Service',
            ids: [1, 2, 3],
            columns: ['gebucht' => [1, 2]],
            hrDesk: [3],
            uneindeutig: [2],
        );
        $vm = $this->vm();

        $this->assertSame([1, 2, 3], $vm->idsOf($row, 'ids'));
        $this->assertSame([3], $vm->idsOf($row, 'hr_desk_ids'));
        $this->assertSame([2], $vm->idsOf($row, 'uneindeutig_ids'));
        $this->assertSame([1, 2], $vm->idsOf($row, 'gebucht'));
        $this->assertSame([], $vm->idsOf($row, 'gibt_es_nicht'));
    }

    public function test_verschachtelte_spalte_wird_laut_abgelehnt_statt_falsch_gezaehlt(): void
    {
        // Review-Befund M4: phase_reached ist [order => ids] und passt nicht in
        // den flachen Spalten-Vertrag. count() darauf zaehlt PHASEN statt
        // Bewerbungen — eine plausibel aussehende falsche Zahl in einer Zelle,
        // also genau das, was diese Seite abschaffen soll. Der order-qualifizierte
        // Zugriff kommt in Task 8; bis dahin (und danach) ist die Fehlnutzung ein
        // Programmierfehler und muss knallen, nicht schweigen.
        $row = $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2, 3], columns: [
            'phase_reached' => [1 => [1, 2, 3], 2 => [2, 3]],
        ]);
        $vm = $this->vm();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/phase_reached/');
        $vm->idsOf($row, 'phase_reached');
    }

    public function test_countIn_reicht_die_ablehnung_der_verschachtelten_spalte_durch(): void
    {
        // countIn/resolveIds laufen ueber idsOf — die Sperre darf nicht auf dem
        // Weg durch den Aggregations-Pfad verloren gehen (das ist der Pfad, den
        // die View benutzt).
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2, 3], columns: [
                'phase_reached' => [1 => [1, 2, 3], 2 => [2, 3]],
            ]),
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->vm()->countIn($rows, 'phase_reached');
    }

    public function test_client_grenze_liefert_bei_verschachtelter_spalte_leer_statt_zu_werfen(): void
    {
        // Genau der Weg, den drill() nimmt: $column kommt aus dem Request und ist
        // manipulierbar (drill() ist eine oeffentliche Livewire-Methode). Ein
        // gecrafteter drill(token, 'phase_reached') darf KEINE Fehlerseite
        // erzeugen, sondern eine leere Auswahl — dieselbe fail-closed-Regel wie
        // fuer einen unbrauchbaren Token und einen unbekannten Scope.
        // Die Sperre selbst bleibt laut: siehe die flatColumn-Tests oben.
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2, 3], columns: [
                'phase_reached' => [1 => [1, 2, 3], 2 => [2, 3]],
            ], postingId: 48),
        ];
        $vm = $this->vm();

        // kein expectException: dieser Aufruf darf nicht werfen
        $this->assertSame([], $vm->resolveIdsFromClient($rows, ['scope' => 'all'], 'phase_reached'));
        $this->assertSame([], $vm->resolveIdsFromClient($rows, [
            'scope' => 'row', 'ort' => 'Essen', 'act' => 'Service',
            'type' => 'ohne_schulung', 'key' => 'ohne_schulung:1|Neu', 'posting' => 48,
        ], 'phase_reached'));

        // ... und der Weg bleibt fuer alles Brauchbare voll funktionsfaehig —
        // das Abfangen darf nicht zum pauschalen "immer leer" verkommen
        $this->assertSame([1, 2, 3], $vm->resolveIdsFromClient($rows, ['scope' => 'all'], 'ids'));
        $this->assertSame([1, 2, 3], $vm->resolveIdsFromClient($rows, [
            'scope' => 'row', 'ort' => 'Essen', 'act' => 'Service',
            'type' => 'ohne_schulung', 'key' => 'ohne_schulung:1|Neu', 'posting' => 48,
        ], 'ids'));
        // unbekannter Spaltenname verhaelt sich unveraendert (leer, kein Wurf)
        $this->assertSame([], $vm->resolveIdsFromClient($rows, ['scope' => 'all'], 'gibt_es_nicht'));
    }

    public function test_client_grenze_verschluckt_nur_die_form_sperre(): void
    {
        // Gefangen wird bewusst NUR InvalidArgumentException (die Form-Sperre aus
        // flatColumn). Ein pauschales catch \Throwable haette jeden echten Defekt
        // in der Aufloesung zu einem stillen "keine Treffer" gemacht — genau die
        // Fehlerklasse, die diese Seite abschaffen soll.
        // Beleg mit einem echten Defekt: eine malformte Zeile (kein Array) laeuft
        // in den TypeError von idsOf() und bleibt sichtbar.
        $this->expectException(\TypeError::class);
        $this->vm()->resolveIdsFromClient(['keine Zeile, sondern ein String'], ['scope' => 'all'], 'ids');
    }

    public function test_leere_verschachtelte_spalte_bleibt_eine_leere_menge(): void
    {
        // Die Sperre prueft die FORM des Inhalts, nicht den Spaltennamen: auf
        // einer ausgeschiedenen Zeile ist phase_reached leer und damit von einer
        // flachen leeren Spalte nicht zu unterscheiden — das darf nicht knallen,
        // sonst waere jede geparkt-Zeile ein Absturzkandidat.
        $row = $this->row('geparkt', '-', 'Essen', 'Service', [1], columns: ['phase_reached' => []]);

        $this->assertSame([], $this->vm()->idsOf($row, 'phase_reached'));
        $this->assertSame(0, $this->vm()->countIn([$row], 'phase_reached'));
    }

    public function test_scope_row_trifft_genau_eine_zeile(): void
    {
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [1]),
            $this->row('schulung', 'schulung:1', 'Essen', 'Bankett', [2]),   // andere Taetigkeit
            $this->row('schulung', 'schulung:1', 'Wuppertal', 'Service', [3]), // anderer Ort
            $this->row('schulung', 'schulung:2', 'Essen', 'Service', [4]),   // anderer Termin
        ];

        $ids = $this->vm()->resolveIds($rows, [
            'scope' => 'row', 'ort' => 'Essen', 'act' => 'Service',
            'type' => 'schulung', 'key' => 'schulung:1',
            // alle vier Zeilen haben posting_id null — die Ausschreibungs-Dimension
            // ist hier absichtlich konstant, damit der Test allein Ort/Taetigkeit/
            // Typ/Key prueft. Die Ausschreibung hat ihren eigenen Test unten.
            'posting' => null,
        ], 'ids');

        $this->assertSame([1], $ids);
    }

    public function test_scope_row_trennt_zwei_ausschreibungen_derselben_gruppe(): void
    {
        // Regression: seit v2 bildet der CohortAssigner je Ausschreibung eine
        // Zeile, aber $row['key'] ist weiterhin der schmale Schluessel
        // ("ohne_schulung:1|Neu"). Ort, Taetigkeit, Typ UND Key sind hier bei
        // beiden Zeilen identisch — ohne die posting_id im Token passte das
        // row-Token auf beide und das Modal zeigte 1, 2 UND 3 unter dem Label
        // einer einzigen Ausschreibung.
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2], postingId: 48),
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [3], postingId: 46),
        ];
        $vm = $this->vm();

        $spec = fn (?int $posting) => [
            'scope' => 'row', 'ort' => 'Essen', 'act' => 'Service',
            'type' => 'ohne_schulung', 'key' => 'ohne_schulung:1|Neu', 'posting' => $posting,
        ];

        $this->assertSame([1, 2], $vm->resolveIds($rows, $spec(48), 'ids'));
        $this->assertSame([3], $vm->resolveIds($rows, $spec(46), 'ids'));
        // eine Ausschreibung, die in dieser Gruppe nicht vorkommt, trifft nichts
        $this->assertSame([], $vm->resolveIds($rows, $spec(99), 'ids'));
        // und die Summe der Einzel-Aufloesungen deckt die Gruppe genau ab
        $this->assertSame(
            $vm->resolveIds($rows, ['scope' => 'type', 'ort' => 'Essen', 'act' => 'Service', 'type' => 'ohne_schulung'], 'ids'),
            array_merge($vm->resolveIds($rows, $spec(48), 'ids'), $vm->resolveIds($rows, $spec(46), 'ids')),
        );
    }

    public function test_scope_row_ohne_ausschreibung_im_token_trifft_fail_closed_nichts(): void
    {
        // Ein alter oder gecrafteter Token ohne 'posting' darf NICHT auf alle
        // Ausschreibungen der Gruppe passen — sonst ist genau die Vermischung
        // zurueck, die der Zusatz-Vergleich verhindert. Leeres Modal ist der
        // harmlose Ausgang (gleiche Haltung wie beim unbekannten Scope).
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1], postingId: 48),
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [2], postingId: 46),
        ];

        $this->assertSame([], $this->vm()->resolveIds($rows, [
            'scope' => 'row', 'ort' => 'Essen', 'act' => 'Service',
            'type' => 'ohne_schulung', 'key' => 'ohne_schulung:1|Neu',
        ], 'ids'));
    }

    public function test_scope_row_trifft_die_zeile_ohne_ausschreibung_nur_mit_explizitem_null(): void
    {
        // Fall 3 der Zuordnungsregel (keine Ausschreibung) ist eine echte Zeile
        // und muss anklickbar bleiben. 'posting' => null ist dafuer VORHANDEN und
        // null — nicht dasselbe wie ein fehlender Schluessel (isset() haette
        // beides verwechselt, daher array_key_exists im ViewModel).
        $rows = [
            $this->row('geparkt', '-', 'ohne Ausschreibung', 'ohne Ausschreibung', [7], postingId: null),
            $this->row('geparkt', '-', 'ohne Ausschreibung', 'ohne Ausschreibung', [8], postingId: 48),
        ];

        $this->assertSame([7], $this->vm()->resolveIds($rows, [
            'scope' => 'row', 'ort' => 'ohne Ausschreibung', 'act' => 'ohne Ausschreibung',
            'type' => 'geparkt', 'key' => '-', 'posting' => null,
        ], 'ids'));
    }

    public function test_scope_type_summiert_den_bucket_einer_gruppe(): void
    {
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2]),
            $this->row('ohne_schulung', 'ohne_schulung:2|Screening', 'Essen', 'Service', [3]),
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Bankett', [4]),
            $this->row('geparkt', '-', 'Essen', 'Service', [5]),
        ];

        $ids = $this->vm()->resolveIds($rows, [
            'scope' => 'type', 'ort' => 'Essen', 'act' => 'Service', 'type' => 'ohne_schulung',
        ], 'ids');

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_scope_ort_summiert_alle_taetigkeiten_des_orts(): void
    {
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [1]),
            $this->row('geparkt', '-', 'Essen', 'Bankett', [2]),
            $this->row('geparkt', '-', 'Wuppertal', 'Service', [3]),
        ];

        $ids = $this->vm()->resolveIds($rows, ['scope' => 'ort', 'ort' => 'Essen'], 'ids');

        $this->assertSame([1, 2], $ids);
    }

    public function test_scope_all_summiert_alles_und_deckt_sich_mit_count_in(): void
    {
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [1], ['gebucht' => [1]]),
            $this->row('geparkt', '-', 'Wuppertal', 'Bankett', [2, 3]),
        ];
        $vm = $this->vm();

        $this->assertSame([1, 2, 3], $vm->resolveIds($rows, ['scope' => 'all'], 'ids'));
        $this->assertSame(3, $vm->countIn($rows, 'ids'));
        $this->assertSame(1, $vm->countIn($rows, 'gebucht'));
        $this->assertSame(0, $vm->countIn($rows, 'unterschrieben'));
    }

    public function test_summe_dedupliziert_nicht_damit_eine_invariantverletzung_sichtbar_bleibt(): void
    {
        // Zwei Zeilen mit derselben ID waeren ein Bruch der Rekonziliations-
        // Invariante. array_unique wuerde das kaschieren — die Gesamt-Zeile der
        // View vergleicht genau auf diese Differenz.
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [7]),
            $this->row('geparkt', '-', 'Essen', 'Service', [7]),
        ];

        $this->assertSame([7, 7], $this->vm()->resolveIds($rows, ['scope' => 'all'], 'ids'));
        $this->assertSame(2, $this->vm()->countIn($rows, 'ids'));
    }

    public function test_token_uebersteht_anfuehrungszeichen_und_umlaute(): void
    {
        $vm = $this->vm();
        $spec = [
            'scope' => 'row',
            'prefix' => "O'Briens Bar — \"Spätdienst\"",
            'ort' => "Köln O'Brien",
            'act' => 'Spülhilfe "Nacht"',
            'type' => 'ohne_schulung',
            'key' => 'ohne_schulung:1|Neu',
            'posting' => 48,
        ];

        $token = $vm->encodeScope($spec);

        // attribut- und parser-sicher: keine Quotes, kein Backslash im Token
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $token);
        $this->assertSame($spec, $vm->decodeScope($token));
    }

    public function test_token_findet_die_zeile_auch_bei_sonderzeichen_im_ort(): void
    {
        $vm = $this->vm();
        $rows = [
            $this->row('geparkt', '-', "Köln O'Brien", 'Spülhilfe "Nacht"', [42]),
            $this->row('geparkt', '-', 'Essen', 'Service', [43]),
        ];
        $token = $vm->encodeScope([
            'scope' => 'row', 'prefix' => 'Geparkt',
            'ort' => "Köln O'Brien", 'act' => 'Spülhilfe "Nacht"',
            'type' => 'geparkt', 'key' => '-', 'posting' => null,
        ]);

        $spec = $vm->decodeScope($token);
        $this->assertNotNull($spec);
        $this->assertSame([42], $vm->resolveIds($rows, $spec, 'ids'));
    }

    public function test_unbrauchbares_token_wird_still_verworfen(): void
    {
        $vm = $this->vm();

        $this->assertNull($vm->decodeScope(''));
        $this->assertNull($vm->decodeScope('!!!kein-base64!!!'));
        $this->assertNull($vm->decodeScope(base64_encode('kein json')));
        $this->assertNull($vm->decodeScope(base64_encode('"nur ein string"')));
    }

    public function test_interview_id_nur_fuer_schulungszeilen(): void
    {
        $this->assertSame(42, CohortViewModel::interviewIdOf(
            $this->row('schulung', 'schulung:42', 'Essen', 'Service')
        ));
        $this->assertNull(CohortViewModel::interviewIdOf(
            $this->row('geparkt', '-', 'Essen', 'Service')
        ));
    }

    public function test_unbekannter_zeilentyp_landet_am_ende_statt_zu_verschwinden(): void
    {
        $rows = [
            $this->row('brandneuer_typ', '-', 'Essen', 'Service', [1]),
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [2]),
        ];

        $ordered = array_map(
            fn ($r) => $r['type'],
            $this->vm()->groups($rows)['Essen']['activities']['Service'],
        );

        $this->assertSame(['schulung', 'brandneuer_typ'], $ordered);
    }

    // ------------------------------------------------- //
    // Right-Censoring: Grau-Entscheidung (Spec §6)      //
    // ------------------------------------------------- //

    public function test_kohorte_juenger_als_der_median_ist_zensiert(): void
    {
        // Bewerbung vor 10 Tagen, Median-Durchlauf 30 Tage -> noch nicht aussagekraeftig
        $this->assertTrue($this->vm()->isCensored('2026-07-25', '2026-08-04', 30));
    }

    public function test_kohorte_aelter_als_der_median_ist_nicht_zensiert(): void
    {
        // 40 Tage alt bei Median 30
        $this->assertFalse($this->vm()->isCensored('2026-06-25', '2026-08-04', 30));
    }

    public function test_alter_genau_gleich_dem_median_ist_nicht_zensiert(): void
    {
        // Grenzfall: "juenger als" ist strikt — 30 Tage bei Median 30 zaehlt als reif
        $this->assertFalse($this->vm()->isCensored('2026-07-05', '2026-08-04', 30));
    }

    public function test_ein_tag_unter_dem_median_ist_noch_zensiert(): void
    {
        $this->assertTrue($this->vm()->isCensored('2026-07-06', '2026-08-04', 30));
    }

    public function test_ohne_median_bleibt_conversion_grau(): void
    {
        // Kein Median = keine Unterschriften = keine Referenz fuer Reife (Spec §6)
        $this->assertTrue($this->vm()->isCensored('2020-01-01', '2026-08-04', null));
    }

    public function test_median_null_tage_macht_jede_kohorte_reif(): void
    {
        // Median 0 (alle am Eingangstag unterschrieben): kein Alter kann darunter
        // liegen, also nie zensiert.
        $this->assertFalse($this->vm()->isCensored('2026-08-04', '2026-08-04', 0));
    }

    public function test_zeile_ohne_datum_ist_zensiert(): void
    {
        // ohne_datum-Zeilen haben kein Alter — Reife ist nicht belegbar, also grau
        $this->assertTrue($this->vm()->isCensored(null, '2026-08-04', 30));
    }

    public function test_bewerbung_in_der_zukunft_ist_zensiert(): void
    {
        // negatives Alter -> erst recht juenger als der Median
        $this->assertTrue($this->vm()->isCensored('2026-09-01', '2026-08-04', 30));
    }

    public function test_unlesbares_datum_ist_konservativ_zensiert(): void
    {
        $vm = $this->vm();
        $this->assertTrue($vm->isCensored('kein-datum', '2026-08-04', 30));
        $this->assertTrue($vm->isCensored('2026-08-04', 'kaputt', 30));
        $this->assertTrue($vm->isCensored('2026-02-30', '2026-08-04', 30), 'Rollover-Datum wird nicht stillschweigend akzeptiert');
    }

    public function test_max_applied_at_nimmt_das_juengste_datum_und_ignoriert_null(): void
    {
        $vm = $this->vm();
        $rows = [
            ['max_applied_at' => '2026-07-01'],
            ['max_applied_at' => null],
            ['max_applied_at' => '2026-05-20'],
            ['max_applied_at' => '2026-09-09'],
        ];

        $this->assertSame('2026-09-09', $vm->maxAppliedAt($rows));
        $this->assertNull($vm->maxAppliedAt([['max_applied_at' => null]]));
        $this->assertNull($vm->maxAppliedAt([]), 'leere Zeilenmenge hat kein Alter');
        $this->assertNull($vm->maxAppliedAt([['ids' => [1]]]), 'fehlender Schluessel zaehlt wie null');
    }

    public function test_eine_alte_bewerbung_macht_eine_frische_kohorte_nicht_reif(): void
    {
        // Regression fuer den Anker-Dreh: mit min(applied_at) galt diese Zeile als
        // reif (alte Bewerbung = 90 Tage alt > Median 30) und wurde voll farbig
        // ausgegeben, obwohl 20 von 21 Bewerbungen erst zwei Tage im Funnel sind.
        // Mit max(applied_at) ankert das Alter an der juengsten Bewerbung -> grau.
        $vm = $this->vm();
        $row = ['max_applied_at' => '2026-08-02'];  // Assigner liefert das Maximum
        $today = '2026-08-04';

        $this->assertSame('2026-08-02', $vm->maxAppliedAt([$row]));
        $this->assertTrue($vm->isCensored($vm->maxAppliedAt([$row]), $today, 30));
    }

    public function test_aggregat_ankert_an_der_juengsten_zeile(): void
    {
        // Summen-Zeile ueber eine reife und eine frische Zeile -> die frische
        // bestimmt den Anker, die Summe ist zensiert.
        $vm = $this->vm();
        $rows = [
            ['max_applied_at' => '2026-01-10'],
            ['max_applied_at' => '2026-08-03'],
        ];

        $this->assertSame('2026-08-03', $vm->maxAppliedAt($rows));
        $this->assertTrue($vm->isCensored($vm->maxAppliedAt($rows), '2026-08-04', 30));
    }

    public function test_offen_menge_ist_ueber_ids_of_erreichbar(): void
    {
        // damit die "noch offen"-Spalte dieselbe Klick-Mechanik nutzt wie alles andere
        $row = $this->row('schulung', 'schulung:1', 'Essen', 'Service', ids: [1, 2, 3]);
        $row['offen_ids'] = [1, 3];
        $vm = $this->vm();

        $this->assertSame([1, 3], $vm->idsOf($row, 'offen_ids'));
        $this->assertSame(2, $vm->countIn([$row], 'offen_ids'));
        $this->assertSame([1, 3], $vm->resolveIds([$row], ['scope' => 'all'], 'offen_ids'));
    }

    // ------------------------------------------------- //
    // Conversion pro Zeilenmenge                        //
    // ------------------------------------------------- //

    public function test_conversion_ist_null_ohne_bewerbungen_nicht_null_prozent(): void
    {
        // Wichtig: "keine Bewerbungen" ist KEINE Quote von 0 % — die Tabelle
        // zeigt dafuer "–", sonst liest man ein Scheitern, wo nichts passiert ist.
        $this->assertNull($this->vm()->conversionOf([]));
        $this->assertNull($this->vm()->conversionOf([
            $this->row('geparkt', '-', 'Essen', 'Service', ids: []),
        ]));
    }

    public function test_conversion_ist_null_prozent_bei_bewerbungen_ohne_unterschrift(): void
    {
        $rows = [$this->row('geparkt', '-', 'Essen', 'Service', ids: [1, 2])];

        $this->assertSame(0, $this->vm()->conversionOf($rows));
    }

    public function test_conversion_rechnet_ueber_die_ganze_zeilenmenge(): void
    {
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service',
                ids: [1, 2], columns: ['unterschrieben' => [1]]),
            $this->row('geparkt', '-', 'Essen', 'Service', ids: [3, 4]),
        ];

        // 1 von 4
        $this->assertSame(25, $this->vm()->conversionOf($rows));
    }

    public function test_conversion_wird_kaufmaennisch_gerundet(): void
    {
        $vm = $this->vm();

        // 1/3 = 33,33 -> 33
        $this->assertSame(33, $vm->conversionOf([
            $this->row('schulung', 'schulung:1', 'E', 'S', ids: [1, 2, 3], columns: ['unterschrieben' => [1]]),
        ]));
        // 2/3 = 66,67 -> 67
        $this->assertSame(67, $vm->conversionOf([
            $this->row('schulung', 'schulung:1', 'E', 'S', ids: [1, 2, 3], columns: ['unterschrieben' => [1, 2]]),
        ]));
        // 1/8 = 12,5 -> 13 (nicht 12)
        $this->assertSame(13, $vm->conversionOf([
            $this->row('schulung', 'schulung:1', 'E', 'S', ids: [1, 2, 3, 4, 5, 6, 7, 8], columns: ['unterschrieben' => [1]]),
        ]));
        // 8/8 -> 100
        $this->assertSame(100, $vm->conversionOf([
            $this->row('schulung', 'schulung:1', 'E', 'S', ids: [1, 2], columns: ['unterschrieben' => [1, 2]]),
        ]));
    }

    public function test_unbekannter_scope_liefert_nichts(): void
    {
        // fail-closed: ein unbekannter Scope darf NICHT auf "alles" zurueckfallen,
        // sonst zeigt ein Tippfehler im Token die gesamte Kohorte.
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', ids: [1, 2]),
            $this->row('geparkt', '-', 'Essen', 'Service', ids: [3]),
        ];
        $vm = $this->vm();

        $this->assertSame([], $vm->resolveIds($rows, ['scope' => 'gibt_es_nicht'], 'ids'));
        $this->assertSame([], $vm->resolveIds($rows, ['scope' => ''], 'ids'));
        // 'all' und ein fehlender Scope bleiben "alles" (Default des Tokens)
        $this->assertSame([1, 2, 3], $vm->resolveIds($rows, ['scope' => 'all'], 'ids'));
        $this->assertSame([1, 2, 3], $vm->resolveIds($rows, [], 'ids'));
    }

    public function test_hat_laufende_zeile_erkennt_die_beiden_laufenden_typen(): void
    {
        $vm = $this->vm();

        $this->assertTrue($vm->hasRunningRow([$this->row('schulung', 'schulung:1', 'E', 'S')]));
        $this->assertTrue($vm->hasRunningRow([$this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'E', 'S')]));
        $this->assertFalse($vm->hasRunningRow([
            $this->row('geparkt', '-', 'E', 'S'),
            $this->row('abgesagt', '-', 'E', 'S'),
            $this->row('dublette', '-', 'E', 'S'),
            $this->row('unrouted', '-', 'E', 'S'),
            $this->row('import', '-', 'E', 'S'),
            $this->row('ohne_datum', '-', 'E', 'S'),
            $this->row('unbekannter_status', '-', 'E', 'S'),
        ]));
        $this->assertFalse($vm->hasRunningRow([]));
        // gemischte Menge (Summen-Zeile) -> laufend, die offen-Zahl ist dort echt
        $this->assertTrue($vm->hasRunningRow([
            $this->row('geparkt', '-', 'E', 'S'),
            $this->row('schulung', 'schulung:1', 'E', 'S'),
        ]));
    }

    public function test_scope_type_all_sammelt_einen_typ_ueber_alle_gruppen(): void
    {
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Bonn', 'Service', [1, 2]),
            $this->row('ohne_schulung', 'ohne_schulung:2|Termin', 'Düsseldorf', 'Küche', [3]),
            $this->row('schulung', 'schulung:9', 'Bonn', 'Service', [4]),
            $this->row('geparkt', '-', 'Bonn', 'Service', [5]),
        ];

        // Grundlage der Kachel „Ohne Termin": haengt an keinem Ort und keiner Taetigkeit
        $this->assertSame(
            [1, 2, 3],
            $this->vm()->resolveIds($rows, ['scope' => 'type_all', 'type' => 'ohne_schulung'], 'ids'),
        );
        // ort/act im Spec sind fuer diesen Scope irrelevant und duerfen nichts einschraenken
        $this->assertSame(
            [1, 2, 3],
            $this->vm()->resolveIds(
                $rows,
                ['scope' => 'type_all', 'type' => 'ohne_schulung', 'ort' => 'Bonn', 'act' => 'Service'],
                'ids',
            ),
        );
        $this->assertSame(
            [],
            $this->vm()->resolveIds($rows, ['scope' => 'type_all', 'type' => 'gibt_es_nicht'], 'ids'),
        );
    }

    public function test_aggregat_censoring_nur_wenn_jede_zeile_zu_jung_ist(): void
    {
        $vm = $this->vm();
        $today = '2026-08-05';
        $median = 7;

        $reif = ['max_applied_at' => '2026-06-01'];   // 65 Tage alt
        $jung = ['max_applied_at' => '2026-08-04'];   // 1 Tag alt
        $ohneDatum = ['max_applied_at' => null];

        // Kernbefund: eine einzige frische Zeile darf die Kachel NICHT ausgrauen —
        // sonst ist sie dauerhaft grau und traegt keine Information mehr.
        $this->assertFalse($vm->isCensoredAggregate([$reif, $jung], $today, $median));
        $this->assertFalse($vm->isCensoredAggregate([$jung, $reif, $ohneDatum], $today, $median));

        // Sind ALLE Zeilen jung, greift das Verduennungs-Argument nicht mehr
        $this->assertTrue($vm->isCensoredAggregate([$jung, $jung], $today, $median));
        $this->assertTrue($vm->isCensoredAggregate([$ohneDatum], $today, $median));
        $this->assertTrue($vm->isCensoredAggregate([], $today, $median), 'keine Zeilen = keine belegbare Reife');
        // ohne Median gibt es keine Referenz -> grau, auch bei alten Zeilen
        $this->assertTrue($vm->isCensoredAggregate([$reif], $today, null));

        // Einzelzeilen-Regel bleibt unangetastet: dieselbe Menge, andere Aussage
        $this->assertTrue(
            $vm->isCensored($vm->maxAppliedAt([$reif, $jung]), $today, $median),
            'Einzelzeile: der Anker ist die juengste Bewerbung, also grau',
        );
    }

    public function test_regelwahl_haengt_an_der_zeilenzahl_nicht_an_einem_flag(): void
    {
        $vm = $this->vm();
        $today = '2026-08-05';
        $median = 7;

        $reif = ['max_applied_at' => '2026-06-01'];
        $jung = ['max_applied_at' => '2026-08-04'];

        // Mehrere Zeilen -> Aggregat-Regel: eine reife Zeile genuegt.
        // Deckt die Sammelzeile „Ohne Schulung" ab, die vorher als Einzelzeile
        // lief und deshalb dauerhaft grau war, sobald EINE Phase frisch war.
        $this->assertFalse($vm->isCensoredForRows([$reif, $jung], $today, $median));
        $this->assertTrue($vm->isCensoredForRows([$jung, $jung], $today, $median));

        // Genau eine Zeile -> Einzelzeilen-Regel. Eine Sammelzeile mit nur einer
        // Phase ist kein Aggregat; das Verduennungs-Argument braucht Mehrzahl.
        $this->assertTrue($vm->isCensoredForRows([$jung], $today, $median));
        $this->assertFalse($vm->isCensoredForRows([$reif], $today, $median));

        // Leere Menge: keine belegbare Reife
        $this->assertTrue($vm->isCensoredForRows([], $today, $median));
        // Ohne Median gibt es keine Referenz — unabhaengig von der Zeilenzahl
        $this->assertTrue($vm->isCensoredForRows([$reif], $today, null));
        $this->assertTrue($vm->isCensoredForRows([$reif, $reif], $today, null));
    }

    // -----------------------------------------------------------------
    // Task 8: Summen-Arithmetik, Ausschreibungs-Zeilen, Phasen-Spalten
    // -----------------------------------------------------------------

    public function test_summen_prozent_wird_neu_gerechnet_nicht_gemittelt(): void
    {
        // Der Klassiker, der Prozentzahlen kaputtmacht: Mittelwert der
        // Zeilen-Prozente statt Summe/Summe. Hier weichen beide deutlich ab.
        // Zeile A: 1 von 1 = 100 %, Zeile B: 1 von 99 = 1 %
        // Mittelwert waere 50,5 %, korrekt sind 2 von 100 = 2 %.
        $rows = [
            ['bedarf' => 1,  'columns' => ['unterschrieben' => [1]]],
            ['bedarf' => 99, 'columns' => ['unterschrieben' => [2]]],
        ];

        $this->assertSame(2, $this->vm()->sumPercent($rows, 'unterschrieben', 'bedarf'));
    }

    public function test_summen_prozent_ohne_bedarf_ist_null(): void
    {
        $this->assertSame(
            null,
            $this->vm()->sumPercent([['bedarf' => null, 'columns' => ['unterschrieben' => [1]]]], 'unterschrieben', 'bedarf'),
        );
        $this->assertSame(null, $this->vm()->sumPercent([], 'unterschrieben', 'bedarf'));
    }

    public function test_summen_prozent_ueberspringt_zaehler_ohne_nenner(): void
    {
        // Eine Ausschreibung ohne gepflegten Bedarf darf die Quote NICHT
        // aufblasen: sie liefert weder Nenner noch Zaehler. Sonst waere die
        // Gesamt-Quote von der Pflege-Disziplin abhaengig statt vom Ergebnis.
        $rows = [
            ['bedarf' => 10,   'columns' => ['unterschrieben' => [1, 2, 3, 4, 5]]],
            ['bedarf' => null, 'columns' => ['unterschrieben' => [6, 7, 8, 9]]],
            // 0 ist kein Nenner (Division), zaehlt also wie "nicht gepflegt"
            ['bedarf' => 0,    'columns' => ['unterschrieben' => [10]]],
        ];

        $this->assertSame(50, $this->vm()->sumPercent($rows, 'unterschrieben', 'bedarf'));
    }

    public function test_phasen_spalte_ist_order_qualifiziert_erreichbar(): void
    {
        // Der Zugriff, den flatColumn() bis hierher verweigert hat: nicht die
        // ganze verschachtelte Spalte, sondern EINE Phase daraus.
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2, 3], columns: [
                'phase_reached' => [1 => [1, 2, 3], 2 => [2, 3], 3 => [3]],
            ], postingId: 48),
        ];
        $vm = $this->vm();

        $this->assertSame([1, 2, 3], $vm->idsOf($rows[0], CohortViewModel::phaseColumnKey(1)));
        $this->assertSame([2, 3], $vm->idsOf($rows[0], CohortViewModel::phaseColumnKey(2)));
        $this->assertSame([3], $vm->idsOf($rows[0], CohortViewModel::phaseColumnKey(3)));
        // Phase ohne Eintrag ist eine leere MENGE, kein Fehler: eine Filiale kann
        // mehr Phasen haben, als in dieser Zeile erreicht wurden.
        $this->assertSame([], $vm->idsOf($rows[0], CohortViewModel::phaseColumnKey(4)));

        // countIn/resolveIds laufen ueber denselben Weg — angezeigte Zahl und
        // Modal-Laenge koennen dadurch nicht auseinanderlaufen.
        $this->assertSame(3, $vm->countIn($rows, CohortViewModel::phaseColumnKey(1)));
        $this->assertSame(2, $vm->countIn($rows, CohortViewModel::phaseColumnKey(2)));
        $this->assertSame(0, $vm->countIn($rows, CohortViewModel::phaseColumnKey(9)));
    }

    public function test_phasen_spalte_ohne_order_bleibt_gesperrt(): void
    {
        // Der order-qualifizierte Zugriff ist eine ZUSAETZLICHE Tuer, keine
        // Aufweichung: die flache Lesart von phase_reached bleibt verboten
        // (count() darauf zaehlt Phasen statt Bewerbungen).
        $row = $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [1, 2], columns: [
            'phase_reached' => [1 => [1, 2], 2 => [2]],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->vm()->idsOf($row, 'phase_reached');
    }

    public function test_unbrauchbare_phasen_order_wirft_innen_und_ist_an_der_grenze_still(): void
    {
        // Merkregel des Branches: innen laut (Programmierfehler), an der
        // Client-Grenze still (Eingabe). Der Spaltenname reist bei drill() aus
        // dem Request herein, ist also manipulierbar.
        $rows = [
            $this->row('ohne_schulung', 'ohne_schulung:1|Neu', 'Essen', 'Service', [7], columns: [
                'phase_reached' => [1 => [7]],
            ]),
        ];
        $vm = $this->vm();

        foreach (['phase_reached:', 'phase_reached:zwei', 'phase_reached:-1', 'phase_reached:1.5'] as $kaputt) {
            try {
                $vm->idsOf($rows[0], $kaputt);
                $this->fail("'{$kaputt}' haette laut abbrechen muessen");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('phase_reached', $e->getMessage());
            }

            // ... derselbe Aufruf an der Client-Grenze: leere Auswahl statt Fehlerseite
            $this->assertSame([], $vm->resolveIdsFromClient($rows, ['scope' => 'all'], $kaputt));
        }

        // Der brauchbare Weg bleibt offen — das Abfangen darf nicht zum
        // pauschalen "immer leer" verkommen.
        $this->assertSame([7], $vm->resolveIdsFromClient($rows, ['scope' => 'all'], CohortViewModel::phaseColumnKey(1)));
    }

    public function test_scope_posting_summiert_alle_zeilen_einer_ausschreibung(): void
    {
        // Die Ausschreibungs-Tabelle hat EINE Zeile je Ausschreibung, die ueber
        // alle Zeilentypen dieser Ausschreibung summiert. Das braucht einen
        // eigenen Scope; 'type'/'ort' schneiden anders.
        $rows = [
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [1], postingId: 7),
            $this->row('geparkt', '-', 'Essen', 'Service', [2], postingId: 7),
            $this->row('schulung', 'schulung:1', 'Essen', 'Bankett', [3], postingId: 7),
            $this->row('schulung', 'schulung:1', 'Essen', 'Service', [4], postingId: 8),
            $this->row('geparkt', '-', 'ohne Ausschreibung', 'ohne Ausschreibung', [5]),
        ];
        $vm = $this->vm();

        $this->assertSame([1, 2, 3], $vm->resolveIds($rows, ['scope' => 'posting', 'posting' => 7], 'ids'));
        $this->assertSame([4], $vm->resolveIds($rows, ['scope' => 'posting', 'posting' => 8], 'ids'));
        // Die Zeilen ohne Zuordnung (Fall 3) sind nur mit explizitem null erreichbar
        $this->assertSame([5], $vm->resolveIds($rows, ['scope' => 'posting', 'posting' => null], 'ids'));

        // fail-closed wie bei scope 'row': ohne Angabe der Ausschreibung trifft
        // das Token NICHTS, statt auf alle Zeilen zu passen.
        $this->assertSame([], $vm->resolveIds($rows, ['scope' => 'posting'], 'ids'));
    }

    public function test_posting_gruppen_bilden_eine_zeile_je_ausschreibung(): void
    {
        $rows = [
            $this->targetRow(7, ['bedarf' => 10, 'bewerbungs_faktor' => 8.0], 'Kellner', 'Service',
                ids: [1, 2], columns: ['unterschrieben' => [1], 'phase_reached' => [1 => [1, 2], 2 => [1]]]),
            $this->targetRow(7, ['bedarf' => 10, 'bewerbungs_faktor' => 8.0], 'Kellner', 'Service',
                ids: [3], columns: ['unterschrieben' => [3], 'phase_reached' => [1 => [3]]], type: 'geparkt'),
            $this->targetRow(4, ['bedarf' => null, 'bewerbungs_faktor' => null], 'Aushilfe', 'Bankett',
                ids: [4], columns: []),
            $this->targetRow(null, [], '', 'ohne Ausschreibung', ids: [5], columns: []),
        ];

        $groups = $this->vm()->postingGroups($rows);

        // Sortierung: echte Ausschreibungen alphabetisch, "ohne Ausschreibung" ans Ende
        $this->assertSame([4, 7, null], array_column($groups, 'posting_id'));
        $this->assertSame(['Aushilfe', 'Kellner', ''], array_column($groups, 'posting_title'));

        // Zwei Zeilen derselben Ausschreibung werden EINE Tabellenzeile
        $kellner = $groups[1];
        $this->assertCount(2, $kellner['rows']);
        $this->assertSame([1, 2, 3], $kellner['ids']);
        $this->assertSame([1, 3], $kellner['columns']['unterschrieben']);
        // verschachtelte Spalte wird je order vereinigt, nicht platt gemacht
        $this->assertSame([1, 2, 3], $kellner['columns']['phase_reached'][1]);
        $this->assertSame([1], $kellner['columns']['phase_reached'][2]);
        // Stammdaten der Ausschreibung reisen mit (Aufrufer haengt sie an die Zeilen)
        $this->assertSame(10, $kellner['bedarf']);
        $this->assertSame(8.0, $kellner['bewerbungs_faktor']);
        $this->assertSame('2026-01-01', $kellner['published_ymd']);
        $this->assertSame('2026-03-01', $kellner['closes_ymd']);
        $this->assertSame(['Service'], $kellner['taetigkeiten']);
        $this->assertFalse($kellner['posting_closed']);

        // Nicht gepflegt bleibt null — kein Default, kein Raten
        $this->assertNull($groups[0]['bedarf']);
        $this->assertNull($groups[0]['bewerbungs_faktor']);
        $this->assertNull($groups[2]['bedarf']);
        $this->assertNull($groups[2]['published_ymd']);

        // Keine Zeile geht verloren und es kommt keine hinzu (vier Assigner-Zeilen
        // mit fuenf Bewerbungen werden drei Anzeige-Zeilen)
        $this->assertSame(
            count($rows),
            array_sum(array_map(fn ($g) => count($g['rows']), $groups)),
        );
        $this->assertSame(
            $this->vm()->countIn($rows, 'ids'),
            array_sum(array_map(fn ($g) => count($g['ids']), $groups)),
        );
    }

    public function test_posting_gruppen_zeigen_geschlossen_wenn_eine_zeile_es_sagt(): void
    {
        // posting_closed ist eine Eigenschaft der AUSSCHREIBUNG, nicht der Zeile —
        // alle Zeilen einer Ausschreibung tragen denselben Wert. Die Gruppe darf
        // ihn nicht verlieren, egal aus welcher Zeile sie ihn liest.
        $rows = [
            array_merge(
                $this->targetRow(7, ['bedarf' => 3], 'Kellner', 'Service', ids: [1]),
                ['posting_closed' => true],
            ),
            array_merge(
                $this->targetRow(7, ['bedarf' => 3], 'Kellner', 'Service', ids: [2], type: 'geparkt'),
                ['posting_closed' => true],
            ),
        ];

        $groups = $this->vm()->postingGroups($rows);

        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]['posting_closed']);
    }

    public function test_pipeline_summen_zaehlen_nur_gepflegte_ausschreibungen(): void
    {
        // Gesamt-Zeile: Σ Bewerbungen gegen Σ (Bedarf x Faktor). Der Faktor selbst
        // laesst sich NICHT addieren, deshalb reist nur das fertige Ziel weiter.
        // Ausschreibungen ohne Bedarf/Faktor liefern weder Ziel noch Bewerbungen —
        // sonst stuende ein Zaehler ohne Nenner in der Quote.
        $groups = [
            ['bedarf' => 10, 'bewerbungs_faktor' => 8.0, 'ids' => range(1, 40)],
            // 3 x 7,5 = 22,5 -> 23: je Ausschreibung aufgerundet wie in TargetLight,
            // nicht erst die Summe
            ['bedarf' => 3, 'bewerbungs_faktor' => 7.5, 'ids' => [41, 42]],
            ['bedarf' => null, 'bewerbungs_faktor' => 8.0, 'ids' => range(50, 99)],
            ['bedarf' => 5, 'bewerbungs_faktor' => null, 'ids' => [100]],
        ];

        $this->assertSame(
            ['bewerbungen' => 42, 'target' => 103],
            $this->vm()->pipelineTotals($groups),
        );

        // Keine einzige gepflegte Ausschreibung -> kein Ziel (null, nicht 0)
        $this->assertSame(
            ['bewerbungen' => 0, 'target' => null],
            $this->vm()->pipelineTotals([['bedarf' => null, 'bewerbungs_faktor' => null, 'ids' => [1, 2]]]),
        );
        $this->assertSame(['bewerbungen' => 0, 'target' => null], $this->vm()->pipelineTotals([]));
    }

    public function test_bedarf_summe_folgt_derselben_regel_wie_die_quote(): void
    {
        // Der Bedarf in der Gesamt-Zeile ist der Nenner der Erfuellungs-Quote —
        // beide muessen dieselben Ausschreibungen zaehlen, sonst passt die
        // angezeigte Quote nicht zur angezeigten Summe.
        $groups = [
            ['bedarf' => 10, 'columns' => ['unterschrieben' => [1, 2]]],
            ['bedarf' => null, 'columns' => ['unterschrieben' => [3]]],
            ['bedarf' => 0, 'columns' => ['unterschrieben' => [4]]],
            ['bedarf' => 30, 'columns' => ['unterschrieben' => [5, 6]]],
        ];

        $this->assertSame(40, $this->vm()->sumBedarf($groups));
        $this->assertSame(10, $this->vm()->sumPercent($groups, 'unterschrieben', 'bedarf'));
        $this->assertNull($this->vm()->sumBedarf([]));
        $this->assertNull($this->vm()->sumBedarf([['bedarf' => null]]));
    }

    /**
     * Zeile MIT Ausschreibungs-Stammdaten, wie sie Index.php nach dem Assign
     * anhaengt (Bedarf/Faktor/Laufzeit kommen aus rec_postings, nicht aus dem
     * Assigner — der kennt keine Stammdaten).
     */
    private function targetRow(
        ?int $postingId,
        array $target,
        string $title = '',
        string $taetigkeit = 'Service',
        array $ids = [],
        array $columns = [],
        string $type = 'ohne_schulung',
    ): array {
        return array_merge(
            $this->row($type, $type . ':1|Neu', 'Essen', $taetigkeit, $ids, $columns, postingId: $postingId),
            [
                'posting_title' => $title,
                'bedarf' => null,
                'bewerbungs_faktor' => null,
                'published_ymd' => $postingId === null ? null : '2026-01-01',
                'closes_ymd' => $postingId === null ? null : '2026-03-01',
            ],
            $target,
        );
    }
}
