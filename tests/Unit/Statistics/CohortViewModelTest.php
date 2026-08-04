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
    ): array {
        return [
            'type' => $type,
            'key' => $key,
            'group' => ['ort' => $ort, 'taetigkeit' => $taetigkeit, 'uneindeutig' => $uneindeutig !== []],
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

    public function test_zeilen_folgen_der_praezedenz_kette(): void
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
        ], 'ids');

        $this->assertSame([1], $ids);
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
            'type' => 'geparkt', 'key' => '-',
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
}
