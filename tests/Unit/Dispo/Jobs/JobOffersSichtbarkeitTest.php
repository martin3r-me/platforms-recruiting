<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersSichtbarkeit;

class JobOffersSichtbarkeitTest extends TestCase
{
    public function testNurLaufendeOffeneFreieKuenftigeSchichten(): void
    {
        $a = fn (array $x) => $x + ['offer_shift_id' => 1, 'datum' => '2026-10-24', 'laeuft' => true, 'closed' => false, 'accepted' => false];
        $rows = [
            $a(['offer_shift_id' => 1]),
            $a(['offer_shift_id' => 2, 'laeuft' => false]),
            $a(['offer_shift_id' => 3, 'closed' => true]),
            $a(['offer_shift_id' => 4, 'datum' => '2026-10-19']),
            $a(['offer_shift_id' => 5, 'datum' => '2026-10-25']),           // gebucht
            $a(['offer_shift_id' => 6, 'accepted' => true, 'closed' => true]), // eigene Zusage bleibt sichtbar
        ];
        $sichtbar = JobOffersSichtbarkeit::filtern($rows, ['2026-10-25' => true], '2026-10-20');
        $this->assertSame([1, 6], array_column($sichtbar, 'offer_shift_id'));
    }
}
