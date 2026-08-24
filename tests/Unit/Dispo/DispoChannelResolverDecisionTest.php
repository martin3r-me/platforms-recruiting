<?php
namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;

class DispoChannelResolverDecisionTest extends TestCase
{
    public function test_filiale_channel_wins(): void
    {
        $this->assertSame(51, DispoChannelResolver::channelIdFor(400, [400 => 51, 100 => 52], 28));
    }
    public function test_fallback_to_default_when_no_filiale_mapping(): void
    {
        $this->assertSame(28, DispoChannelResolver::channelIdFor(200, [400 => 51], 28));
        $this->assertSame(28, DispoChannelResolver::channelIdFor(null, [400 => 51], 28));
    }
    public function test_null_when_no_mapping_and_no_default(): void
    {
        $this->assertNull(DispoChannelResolver::channelIdFor(200, [], null));
    }
}
