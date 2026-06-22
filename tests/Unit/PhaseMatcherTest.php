<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\PhaseMatcher;

class PhaseMatcherTest extends TestCase
{
    // Köln-Phasen: order 1..4 → IDs 30..33.
    private const KOELN = [1 => 30, 2 => 31, 3 => 32, 4 => 33];

    public function test_same_order_wins(): void
    {
        // Düsseldorf "Schulung buchen" (order 2) → Kölns order-2-Phase = 31.
        $this->assertSame(31, PhaseMatcher::sameOrderOrFirst(2, self::KOELN));
        $this->assertSame(30, PhaseMatcher::sameOrderOrFirst(1, self::KOELN));
        $this->assertSame(33, PhaseMatcher::sameOrderOrFirst(4, self::KOELN));
    }

    public function test_unknown_order_falls_back_to_first(): void
    {
        $this->assertSame(30, PhaseMatcher::sameOrderOrFirst(7, self::KOELN));
    }

    public function test_null_order_uses_first(): void
    {
        // Bewerber ohne Phase → erste Phase der Zielstelle.
        $this->assertSame(30, PhaseMatcher::sameOrderOrFirst(null, self::KOELN));
    }

    public function test_first_is_order_smallest_regardless_of_array_order(): void
    {
        // Reihenfolge im Array darf egal sein — kleinster order gewinnt als Fallback.
        $this->assertSame(30, PhaseMatcher::sameOrderOrFirst(99, [4 => 33, 2 => 31, 1 => 30, 3 => 32]));
    }

    public function test_empty_returns_null(): void
    {
        $this->assertNull(PhaseMatcher::sameOrderOrFirst(2, []));
        $this->assertNull(PhaseMatcher::sameOrderOrFirst(null, []));
    }
}
