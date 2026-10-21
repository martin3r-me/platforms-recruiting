<?php

namespace Platform\Recruiting\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\CreateTestEmployee;

/** Option-Parsing des Kommandos recruiting:test-ma (reine Logik, kein Container). */
final class CreateTestEmployeeOptionsTest extends TestCase
{
    public function testParsePairs(): void
    {
        $this->assertSame(['a' => 'b', 'c' => 'd=e'], CreateTestEmployee::parsePairs(['a=b', 'c=d=e']));
        $this->assertSame([], CreateTestEmployee::parsePairs([]));
    }

    public function testParsePairsOhneGleichheitszeichenWirft(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CreateTestEmployee::parsePairs(['a=b', 'kaputt']);
    }

    public function testParseListTrimmtUndIgnoriertLeere(): void
    {
        $this->assertSame(['a', 'b'], CreateTestEmployee::parseList(' a, ,b ,'));
        $this->assertSame([], CreateTestEmployee::parseList(null));
        $this->assertSame([], CreateTestEmployee::parseList(''));
    }
}
