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
}
