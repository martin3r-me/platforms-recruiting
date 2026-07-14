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

    public function test_ref_code_appears_in_description_and_meta(): void
    {
        $t = B::build($this->posting(['ref_code' => 'RG-AB23']), 'publish', null);
        $this->assertStringContainsString('Referenz-Code: RG-AB23', $t->payload['description']);
        $this->assertSame('RG-AB23', $t->payload['meta']['ref_code']);
    }

    public function test_ref_code_line_also_on_update_but_not_on_close(): void
    {
        $u = B::build($this->posting(['ref_code' => 'RG-AB23']), 'update', null);
        $this->assertStringContainsString('Referenz-Code: RG-AB23', $u->payload['description']);

        $c = B::build($this->posting(['ref_code' => 'RG-AB23']), 'close', null);
        $this->assertStringNotContainsString('RG-AB23', $c->payload['description']);
    }

    public function test_content_hash_without_ref_code_stays_legacy_compatible(): void
    {
        // Bestands-Postings ohne Code dürfen KEINEN neuen Hash bekommen (kein Update-Sturm).
        $legacy = hash('sha256', "a\nb\nc");
        $this->assertSame($legacy, B::contentHash('a', 'b', 'c'));
        $this->assertSame($legacy, B::contentHash('a', 'b', 'c', null));
        $this->assertSame($legacy, B::contentHash('a', 'b', 'c', ''));
    }

    public function test_content_hash_changes_with_ref_code(): void
    {
        $this->assertNotSame(B::contentHash('a', 'b', 'c'), B::contentHash('a', 'b', 'c', 'RG-AB23'));
    }

    public function test_build_hash_equals_content_hash_with_ref_code(): void
    {
        // Konsistenz-Garantie für den Reconciler-Detect-Pass (beide Hash-Quellen identisch).
        $t = B::build($this->posting(['ref_code' => 'RG-AB23']), 'publish', null);
        $this->assertSame(B::contentHash('Koch', 'Tolle Stelle', 'Küche', 'RG-AB23'), $t->contentHash);
    }

    public function test_missing_ref_code_key_behaves_like_today(): void
    {
        $t = B::build($this->posting(), 'publish', null);
        $this->assertStringNotContainsString('Referenz-Code', $t->payload['description']);
        $this->assertNull($t->payload['meta']['ref_code']);
        $this->assertSame(B::contentHash('Koch', 'Tolle Stelle', 'Küche'), $t->contentHash);
    }
}
