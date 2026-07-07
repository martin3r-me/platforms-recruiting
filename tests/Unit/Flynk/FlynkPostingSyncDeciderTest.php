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
}
