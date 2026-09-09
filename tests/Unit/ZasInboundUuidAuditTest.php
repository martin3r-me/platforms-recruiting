<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundUuidAudit;

/**
 * Das UUID-Audit: hat ZAS diesen Datensatz je zurueckgespiegelt?
 *
 * Der Bericht existiert, weil zwei voellig verschiedene Lagen bei uns
 * identisch aussehen — „in ZAS nie angelegt" und „angelegt, aber nie
 * zurueckgeliefert". Unterscheiden kann sie nur, wer in den Rohdateien
 * nachsieht: taucht unsere mitgeschickte UUID dort auf, existiert der
 * Datensatz beim Abnehmer.
 */
class ZasInboundUuidAuditTest extends TestCase
{
    /** @return list<array{id:int,rows:list<array<string,string>>}> */
    private function deliveries(): array
    {
        return [
            ['id' => 10, 'rows' => [
                ['UUID' => 'aaa', 'ZasPersonalNr' => 'RG1'],
                ['UUID' => '',    'ZasPersonalNr' => 'RG2'],
            ]],
            ['id' => 20, 'rows' => [
                ['UUID' => 'AAA', 'ZasPersonalNr' => 'RG1'],
                ['UUID' => ' bbb ', 'ZasPersonalNr' => 'RG3'],
                ['ZasPersonalNr' => 'RG4'],
            ]],
        ];
    }

    public function test_counts_rows_and_uuids_case_and_space_insensitive(): void
    {
        $result = ZasInboundUuidAudit::collect($this->deliveries());

        $this->assertSame(5, $result['rows']);
        $this->assertSame(3, $result['with_uuid']);
        // 'aaa' und 'AAA' sind derselbe Datensatz, ' bbb ' wird getrimmt.
        $this->assertSame(['aaa', 'bbb'], array_keys($result['seen']));
        $this->assertSame(2, $result['seen']['aaa']['count']);
        $this->assertSame(10, $result['seen']['aaa']['first_file']);
        $this->assertSame(20, $result['seen']['aaa']['last_file']);
    }

    /** Die Reihenfolge der Lieferungen darf das Ergebnis nicht verfaelschen. */
    public function test_file_bounds_hold_regardless_of_input_order(): void
    {
        $result = ZasInboundUuidAudit::collect(array_reverse($this->deliveries()));

        $this->assertSame(10, $result['seen']['aaa']['first_file']);
        $this->assertSame(20, $result['seen']['aaa']['last_file']);
    }

    public function test_classify_splits_employees_applicants_and_unknown(): void
    {
        $seen = ZasInboundUuidAudit::collect($this->deliveries())['seen'];
        $seen['ccc'] = ['count' => 1, 'first_file' => 30, 'last_file' => 30];

        $result = ZasInboundUuidAudit::classify($seen, ['aaa' => 7], ['bbb' => 99]);

        $this->assertSame(1, $result['employees']);
        $this->assertSame(1, $result['applicants']);
        $this->assertSame(1, $result['unknown']);
        $this->assertSame(['ccc'], $result['unknown_samples']);
    }

    /**
     * Die Gegenrichtung ist der eigentliche Zweck: pro Datensatz die Aussage
     * „schon mal zurueckgekommen" — daran haengt die Frage, ob die 17
     * Mitarbeiter ohne Personalnummer in ZAS existieren.
     */
    public function test_trace_marks_seen_and_unseen_records(): void
    {
        $seen = ZasInboundUuidAudit::collect($this->deliveries())['seen'];

        $traced = ZasInboundUuidAudit::trace([
            ['id' => 1, 'uuid' => 'AAA'],   // gesehen, Schreibweise egal
            ['id' => 2, 'uuid' => 'zzz'],   // nie geliefert
            ['id' => 3, 'uuid' => null],    // ohne UUID gar nicht pruefbar
        ], $seen);

        $this->assertSame(
            [
                ['id' => 1, 'seen' => true,  'count' => 2, 'last_file' => 20],
                ['id' => 2, 'seen' => false, 'count' => 0, 'last_file' => null],
                ['id' => 3, 'seen' => false, 'count' => 0, 'last_file' => null],
            ],
            $traced
        );
    }

    public function test_empty_input_is_not_an_error(): void
    {
        $this->assertSame(
            ['rows' => 0, 'with_uuid' => 0, 'seen' => []],
            ZasInboundUuidAudit::collect([])
        );
        $this->assertSame([], ZasInboundUuidAudit::trace([], []));
    }
}
