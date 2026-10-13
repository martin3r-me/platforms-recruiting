<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasListe;

final class DispoJobZasListeTest extends TestCase
{
    public function testEineZeileJeZusageInReihenfolge(): void
    {
        $text = DispoJobZasListe::text([
            ['name' => 'Anna Ast', 'personnel_number' => 'RG1', 'phone' => '+4917612345678'],
            ['name' => 'Ben Birke', 'personnel_number' => '', 'phone' => ''],
        ]);

        $this->assertSame("Anna Ast · RG1 · +4917612345678\nBen Birke · – · –", $text);
        $this->assertSame('', DispoJobZasListe::text([]));
    }
}
