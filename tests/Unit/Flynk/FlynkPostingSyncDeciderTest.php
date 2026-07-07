<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkEvent;

class FlynkPostingSyncDeciderTest extends TestCase
{
    public function test_flynk_event_constants(): void
    {
        $this->assertSame(['publish', 'update', 'close'], FlynkEvent::all());
        $this->assertSame('publish', FlynkEvent::PUBLISH);
        $this->assertSame('update', FlynkEvent::UPDATE);
        $this->assertSame('close', FlynkEvent::CLOSE);
    }

    private function state(array $o = []): \Platform\Recruiting\Services\Flynk\FlynkPostingState
    {
        return new \Platform\Recruiting\Services\Flynk\FlynkPostingState(
            isOpen: $o['isOpen'] ?? true,
            contentHash: $o['contentHash'] ?? 'A',
            generation: $o['generation'] ?? 1,
            publishRowExists: $o['publishRowExists'] ?? false,
            publishSent: $o['publishSent'] ?? false,
            closeRowExists: $o['closeRowExists'] ?? false,
            lastDeliverableContentHash: $o['lastDeliverableContentHash'] ?? null,
        );
    }

    public function test_open_without_publish_emits_publish(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state());
        $this->assertSame('publish', $d);
    }

    public function test_open_published_same_hash_emits_nothing(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => true,
            'contentHash' => 'A', 'lastDeliverableContentHash' => 'A',
        ]));
        $this->assertNull($d);
    }

    public function test_open_published_changed_hash_emits_update(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => true,
            'contentHash' => 'B', 'lastDeliverableContentHash' => 'A',
        ]));
        $this->assertSame('update', $d);
    }

    public function test_hash_rollback_still_emits_update(): void
    {
        // A→B→C→B: contentHash=B, zuletzt geliefert=C ⇒ update (Uniqueness ist seq, nicht Hash)
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => true,
            'contentHash' => 'B', 'lastDeliverableContentHash' => 'C',
        ]));
        $this->assertSame('update', $d);
    }

    public function test_pending_publish_does_not_emit_second_publish(): void
    {
        // publishRowExists=true (pending), publishSent=false
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'publishRowExists' => true, 'publishSent' => false,
            'contentHash' => 'B', 'lastDeliverableContentHash' => 'A',
        ]));
        $this->assertNull($d); // kein zweiter publish, und kein update ohne publishSent
    }

    public function test_closed_after_publish_emits_close(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => false, 'publishRowExists' => true, 'publishSent' => true,
        ]));
        $this->assertSame('close', $d);
    }

    public function test_never_advertised_then_closed_emits_nothing(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => false, 'publishRowExists' => false, 'publishSent' => false,
        ]));
        $this->assertNull($d);
    }

    public function test_already_closed_emits_nothing(): void
    {
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => false, 'publishRowExists' => true, 'publishSent' => true,
            'closeRowExists' => true,
        ]));
        $this->assertNull($d);
    }

    public function test_reopen_new_generation_emits_publish(): void
    {
        // Gen 2: publishRowExists(Gen2)=false ⇒ erneuter publish
        $d = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::decide($this->state([
            'isOpen' => true, 'generation' => 2, 'publishRowExists' => false,
        ]));
        $this->assertSame('publish', $d);
    }

    private function row(array $o): array
    {
        return [
            'id' => $o['id'] ?? 1,
            'generation' => $o['generation'] ?? 1,
            'event_type' => $o['event_type'],
            'seq' => $o['seq'] ?? 0,
            'content_hash' => $o['content_hash'] ?? '',
            'status' => $o['status'] ?? 'sent',
        ];
    }

    public function test_generation_counts_sent_closes_plus_one(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $this->assertSame(1, $D::generation([]));
        $this->assertSame(2, $D::generation([
            $this->row(['event_type' => 'close', 'status' => 'sent', 'generation' => 1]),
        ]));
        // ein pending close zählt NICHT
        $this->assertSame(1, $D::generation([
            $this->row(['event_type' => 'close', 'status' => 'pending', 'generation' => 1]),
        ]));
    }

    public function test_publish_predicates_are_generation_scoped(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [$this->row(['event_type' => 'publish', 'status' => 'pending', 'generation' => 2, 'seq' => 0])];
        $this->assertTrue($D::publishRowExists($rows, 2));
        $this->assertFalse($D::publishRowExists($rows, 1));
        $this->assertFalse($D::publishSent($rows, 2)); // pending, nicht sent
    }

    public function test_last_deliverable_hash_excludes_failed(): void
    {
        // publish A sent, update B failed ⇒ deliverable = A (failed ausgeschlossen ⇒ Selbstheilung)
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [
            $this->row(['event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent']),
            $this->row(['event_type' => 'update', 'seq' => 1, 'content_hash' => 'B', 'status' => 'failed', 'id' => 2]),
        ];
        $this->assertSame('A', $D::lastDeliverableContentHash($rows, 1));
    }

    public function test_last_deliverable_hash_uses_highest_seq(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [
            $this->row(['event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent']),
            $this->row(['event_type' => 'update', 'seq' => 1, 'content_hash' => 'B', 'status' => 'sent', 'id' => 2]),
        ];
        $this->assertSame('B', $D::lastDeliverableContentHash($rows, 1));
    }

    public function test_build_state_then_failed_update_heals(): void
    {
        // publish A sent + update B failed, aktueller Inhalt B, offen ⇒ decide = update (heilt)
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        $rows = [
            $this->row(['event_type' => 'publish', 'seq' => 0, 'content_hash' => 'A', 'status' => 'sent']),
            $this->row(['event_type' => 'update', 'seq' => 1, 'content_hash' => 'B', 'status' => 'failed', 'id' => 2]),
        ];
        $state = $D::buildState($rows, true, 'B');
        $this->assertSame('A', $state->lastDeliverableContentHash);
        $this->assertTrue($state->publishSent);
        $this->assertSame('update', $D::decide($state));
    }

    public function test_stale_publish_when_closed(): void
    {
        // publish pending, Posting inzwischen geschlossen ⇒ stale
        $ids = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::staleRowIds(false, [
            ['id' => 7, 'event_type' => 'publish'],
        ]);
        $this->assertSame([7], $ids);
    }

    public function test_stale_close_when_reopened(): void
    {
        // close pending, Posting wieder offen ⇒ stale
        $ids = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::staleRowIds(true, [
            ['id' => 9, 'event_type' => 'close'],
        ]);
        $this->assertSame([9], $ids);
    }

    public function test_not_stale_when_consistent(): void
    {
        $D = \Platform\Recruiting\Services\Flynk\FlynkPostingSyncDecider::class;
        // publish+offen, close+geschlossen ⇒ nichts stale
        $this->assertSame([], $D::staleRowIds(true, [['id' => 1, 'event_type' => 'publish']]));
        $this->assertSame([], $D::staleRowIds(false, [['id' => 2, 'event_type' => 'close']]));
        // update+geschlossen ⇒ stale
        $this->assertSame([3], $D::staleRowIds(false, [['id' => 3, 'event_type' => 'update']]));
    }
}
