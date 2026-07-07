<?php

namespace Platform\Recruiting\Tests\Unit\Flynk;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Flynk\FlynkResponseMapper as M;

class FlynkResponseMapperTest extends TestCase
{
    public function test_201_is_success_with_task_id(): void
    {
        $r = M::map(201, ['id' => 'abc', 'status' => 'Open']);
        $this->assertTrue($r->ok);
        $this->assertSame('abc', $r->taskId);
        $this->assertFalse($r->permanent);
    }

    public function test_401_is_unauthorized_not_permanent(): void
    {
        $r = M::map(401, null);
        $this->assertFalse($r->ok);
        $this->assertTrue($r->unauthorized);
        $this->assertFalse($r->permanent);
    }

    public function test_422_is_permanent(): void
    {
        $r = M::map(422, ['message' => 'Validation failed']);
        $this->assertFalse($r->ok);
        $this->assertTrue($r->permanent);
        $this->assertFalse($r->unauthorized);
    }

    public function test_429_is_rate_limited_not_permanent(): void
    {
        $r = M::map(429, null);
        $this->assertTrue($r->rateLimited);
        $this->assertFalse($r->permanent);
        $this->assertFalse($r->ok);
    }

    public function test_500_is_transient(): void
    {
        $r = M::map(500, null);
        $this->assertFalse($r->ok);
        $this->assertFalse($r->permanent);
        $this->assertFalse($r->rateLimited);
        $this->assertFalse($r->unauthorized);
    }

    public function test_connection_failure_is_transient(): void
    {
        $r = M::map(null, null, connectionFailed: true);
        $this->assertFalse($r->ok);
        $this->assertFalse($r->permanent);
        $this->assertSame('connection_failed', $r->error);
    }
}
