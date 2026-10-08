<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoDeclineVerdict;

/** Auslesen der Modellantwort (Spec 2026-10-08, Entscheidung 6). */
class DispoDeclineVerdictTest extends TestCase
{
    public function test_reads_json_wrapped_in_prose_and_code_fences(): void
    {
        $raw = "Hier die Einstufung:\n```json\n{\"absage\": true, \"sicherheit\": \"high\", \"einbuchungen\": [11, 12], \"grund\": \"schreibt, er sei krank\"}\n```";
        $v = DispoDeclineVerdict::parse($raw, [11, 12, 13]);

        $this->assertSame(['absage' => true, 'confidence' => 'high', 'assignment_ids' => [11, 12], 'reason' => 'schreibt, er sei krank'], $v);
    }

    public function test_ids_outside_the_candidate_list_are_dropped(): void
    {
        $v = DispoDeclineVerdict::parse('{"absage": true, "sicherheit": "medium", "einbuchungen": [11, 999, "12", "x"]}', [11, 12]);

        $this->assertSame([11, 12], $v['assignment_ids']);
    }

    public function test_unknown_confidence_counts_as_low(): void
    {
        $v = DispoDeclineVerdict::parse('{"absage": true, "sicherheit": "sehr hoch"}', [1]);

        $this->assertSame('low', $v['confidence']);
        $this->assertFalse(DispoDeclineVerdict::shouldReport($v));
    }

    /** @return list<array{string}> */
    public static function unreadable(): array
    {
        return [['keine Ahnung'], ['{"sicherheit": "high"}'], ['{"absage": "ja"}'], ['{absage: true}'], ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadable')]
    public function test_unreadable_answers_return_null(string $raw): void
    {
        $this->assertNull(DispoDeclineVerdict::parse($raw, [1]));
    }

    public function test_only_high_and_medium_declines_are_reported(): void
    {
        $base = ['assignment_ids' => [], 'reason' => ''];
        $this->assertTrue(DispoDeclineVerdict::shouldReport($base + ['absage' => true, 'confidence' => 'high']));
        $this->assertTrue(DispoDeclineVerdict::shouldReport($base + ['absage' => true, 'confidence' => 'medium']));
        $this->assertFalse(DispoDeclineVerdict::shouldReport($base + ['absage' => true, 'confidence' => 'low']));
        $this->assertFalse(DispoDeclineVerdict::shouldReport($base + ['absage' => false, 'confidence' => 'high']));
    }

    public function test_reason_is_capped(): void
    {
        $v = DispoDeclineVerdict::parse(json_encode(['absage' => false, 'grund' => str_repeat('x', 500)]), []);

        $this->assertSame(200, mb_strlen($v['reason']));
    }
}
