<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkPostingPayloadBuilder as B;

class FlynkPostingPayloadBuilderTest extends TestCase
{
    private function posting(array $o = []): array
    {
        return array_merge([
            'uuid' => 'u-1', 'title' => 'Koch', 'description' => 'Tolle Stelle',
            'activity' => 'Küche', 'position_title' => 'Koch Köln', 'team_id' => 5,
            'generation' => 1, 'closes_at' => null,
        ], $o);
    }

    public function test_publish_maps_to_new_section(): void
    {
        $t = B::build($this->posting(), 'publish', null);
        $this->assertSame('new_section', $t->payload['type']);
        $this->assertSame('Stellenanzeige: Koch', $t->payload['title']);
        $this->assertSame('normal', $t->payload['priority']);
        $this->assertStringContainsString('veröffentlichen', $t->payload['description']);
    }

    public function test_update_maps_to_text_change(): void
    {
        $t = B::build($this->posting(), 'update', null);
        $this->assertSame('text_change', $t->payload['type']);
        $this->assertStringContainsString('aktualisieren', $t->payload['title']);
    }

    public function test_close_maps_to_text_change_and_empty_hash(): void
    {
        $t = B::build($this->posting(), 'close', null);
        $this->assertSame('text_change', $t->payload['type']);
        $this->assertStringContainsString('entfernen', $t->payload['title']);
        $this->assertStringContainsString('beendet', $t->payload['description']);
        $this->assertSame('', $t->contentHash);
    }

    public function test_activity_appears_in_visible_description(): void
    {
        $t = B::build($this->posting(['activity' => 'Küche']), 'publish', null);
        $this->assertStringContainsString('Küche', $t->payload['description']);
    }

    public function test_content_hash_stable_and_change_sensitive(): void
    {
        $h1 = B::contentHash('a', 'b', 'c');
        $this->assertSame($h1, B::contentHash('a', 'b', 'c'));
        $this->assertNotSame($h1, B::contentHash('a', 'b', 'd'));
        $this->assertNotSame($h1, B::contentHash('x', 'b', 'c'));
    }

    public function test_target_url_only_when_careers_url_set(): void
    {
        $this->assertArrayNotHasKey('target_url', B::build($this->posting(), 'publish', null)->payload);
        $with = B::build($this->posting(), 'publish', 'https://x.de/karriere')->payload;
        $this->assertSame('https://x.de/karriere', $with['target_url']);
    }

    public function test_meta_contains_context(): void
    {
        $meta = B::build($this->posting(['generation' => 2]), 'publish', null)->payload['meta'];
        $this->assertSame('u-1', $meta['posting_uuid']);
        $this->assertSame(5, $meta['team_id']);
        $this->assertSame(2, $meta['generation']);
        $this->assertSame('publish', $meta['event']);
    }

    public function test_title_truncated_to_255(): void
    {
        $t = B::build($this->posting(['title' => str_repeat('x', 400)]), 'publish', null);
        $this->assertLessThanOrEqual(255, mb_strlen($t->payload['title']));
    }
}
