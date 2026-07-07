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
}
