<?php

namespace Platform\Recruiting\Tests\Unit\Comms;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\ConversationEscalation;
use Platform\Recruiting\Services\Comms\ConversationInboxReport;

class ConversationInboxReportTest extends TestCase
{
    private const HOUR = 3600;
    private const NOW = 1_000_000;

    private function row(array $overrides = []): array
    {
        return array_merge([
            'thread_id' => 1,
            'subject_type' => 'applicant',
            'subject_id' => 10,
            'url' => '/recruiting/applicants/10',
            'contact_name' => 'Test Person',
            'preview' => 'Hallo',
            'phone' => '+49150',
            'owner_user_id' => 7,
            'is_unread' => true,
            'last_inbound_at' => self::NOW - 1 * self::HOUR,
            'last_outbound_at' => null,
        ], $overrides);
    }

    public function test_empty_rows_yield_zeroes(): void
    {
        $r = ConversationInboxReport::fromRows([], self::NOW);

        $this->assertSame(0, $r->totalCount);
        $this->assertSame(0, $r->unreadCount);
        $this->assertSame(0, $r->missedCount);
        $this->assertSame([], $r->rows);
    }

    public function test_counts_per_level_and_unread(): void
    {
        $rows = [
            $this->row(['thread_id' => 1, 'last_inbound_at' => self::NOW - 1 * self::HOUR, 'is_unread' => true]),   // green
            $this->row(['thread_id' => 2, 'last_inbound_at' => self::NOW - 14 * self::HOUR, 'is_unread' => false]), // yellow
            $this->row(['thread_id' => 3, 'last_inbound_at' => self::NOW - 22 * self::HOUR, 'is_unread' => true]),  // red
            $this->row(['thread_id' => 4, 'last_inbound_at' => self::NOW - 30 * self::HOUR, 'is_unread' => true]),  // missed
            // beantwortet → none, zählt nicht in Level-Counts:
            $this->row(['thread_id' => 5, 'last_inbound_at' => self::NOW - 5 * self::HOUR, 'last_outbound_at' => self::NOW - 4 * self::HOUR, 'is_unread' => false]),
        ];

        $r = ConversationInboxReport::fromRows($rows, self::NOW);

        $this->assertSame(5, $r->totalCount);
        $this->assertSame(3, $r->unreadCount);
        $this->assertSame(1, $r->greenCount);
        $this->assertSame(1, $r->yellowCount);
        $this->assertSame(1, $r->redCount);
        $this->assertSame(1, $r->missedCount);
        $this->assertSame(2, $r->escalationCount); // Eskalation = rot + verpasst
    }

    public function test_rows_sorted_missed_first_then_by_expiry(): void
    {
        $rows = [
            $this->row(['thread_id' => 'green', 'last_inbound_at' => self::NOW - 1 * self::HOUR]),
            $this->row(['thread_id' => 'missed_recent', 'last_inbound_at' => self::NOW - 25 * self::HOUR]),
            $this->row(['thread_id' => 'red', 'last_inbound_at' => self::NOW - 22 * self::HOUR]),
            $this->row(['thread_id' => 'missed_old', 'last_inbound_at' => self::NOW - 50 * self::HOUR]),
        ];

        $r = ConversationInboxReport::fromRows($rows, self::NOW);
        $order = array_map(fn ($row) => $row->threadId, $r->rows);

        // Verpasst zuerst (am längsten zu oben), dann offene nach Ablauf (rot vor grün).
        $this->assertSame(['missed_old', 'missed_recent', 'red', 'green'], $order);
    }

    public function test_answered_rows_sort_last(): void
    {
        $rows = [
            $this->row(['thread_id' => 'answered', 'last_inbound_at' => self::NOW - 5 * self::HOUR, 'last_outbound_at' => self::NOW - 1 * self::HOUR]),
            $this->row(['thread_id' => 'green', 'last_inbound_at' => self::NOW - 2 * self::HOUR, 'last_outbound_at' => null]),
        ];

        $r = ConversationInboxReport::fromRows($rows, self::NOW);
        $order = array_map(fn ($row) => $row->threadId, $r->rows);

        $this->assertSame(['green', 'answered'], $order);
    }

    public function test_row_carries_display_fields_and_escalation(): void
    {
        $r = ConversationInboxReport::fromRows([$this->row()], self::NOW);
        $row = $r->rows[0];

        $this->assertSame(1, $row->threadId);
        $this->assertSame('applicant', $row->subjectType);
        $this->assertSame(10, $row->subjectId);
        $this->assertSame('/recruiting/applicants/10', $row->url);
        $this->assertSame('Test Person', $row->contactName);
        $this->assertSame('Hallo', $row->preview);
        $this->assertSame(7, $row->ownerUserId);
        $this->assertTrue($row->isUnread);
        $this->assertInstanceOf(ConversationEscalation::class, $row->escalation);
        $this->assertSame(ConversationEscalation::LEVEL_GREEN, $row->escalation->level);
    }

    public function test_custom_thresholds_passed_through(): void
    {
        // 4h Restzeit, Gelb-Schwelle 6h → gelb (statt grün bei default 12h? default wäre auch gelb)
        // Daher: enge Schwellen, die default anders entscheiden würden.
        $rows = [$this->row(['last_inbound_at' => self::NOW - 20 * self::HOUR])]; // 4h Restzeit
        $r = ConversationInboxReport::fromRows($rows, self::NOW, 3.0, 1.0); // 4h > 3h gelb-Schwelle → grün

        $this->assertSame(1, $r->greenCount);
        $this->assertSame(0, $r->yellowCount);
    }
}
