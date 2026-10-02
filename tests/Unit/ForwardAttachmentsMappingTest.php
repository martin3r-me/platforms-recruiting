<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardAttachments;

class ForwardAttachmentsMappingTest extends TestCase
{
    public function test_mappt_url_vorschau_titel(): void
    {
        $out = ForwardAttachments::map([
            ['url' => 'https://x/a', 'thumbnail' => 'https://x/t', 'title' => 'Lohnzettel.pdf'],
        ], 'document');
        $this->assertSame([['url' => 'https://x/a', 'thumbnail' => 'https://x/t', 'title' => 'Lohnzettel.pdf', 'media_type' => 'document']], $out);
    }

    public function test_eintraege_ohne_url_fallen_weg(): void
    {
        $out = ForwardAttachments::map([['url' => null, 'title' => 'weg'], ['url' => '', 'title' => 'leer'], ['url' => 'https://x/b']], 'image');
        $this->assertCount(1, $out);
        $this->assertSame('Datei', $out[0]['title']);
        $this->assertNull($out[0]['thumbnail']);
    }
}
