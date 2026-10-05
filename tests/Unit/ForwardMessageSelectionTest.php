<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Comms\Forward\ForwardMessageSelection;

class ForwardMessageSelectionTest extends TestCase
{
    private const NOW = 1_759_400_000; // fester Zeitpunkt, nie time()

    private function msg(int $id, string $direction, int $daysAgo, string $kind = 'text'): array
    {
        return ['id' => $id, 'direction' => $direction, 'kind' => $kind, 'ts' => self::NOW - $daysAgo * 86400];
    }

    public function test_nur_eingehende_der_letzten_tage_neueste_zuerst(): void
    {
        $messages = [
            $this->msg(1, 'inbound', 10),
            $this->msg(2, 'inbound', 3),
            $this->msg(3, 'outbound', 2),
            $this->msg(4, 'inbound', 0),
        ];

        $this->assertSame([4, 2], ForwardMessageSelection::candidates($messages, 4, self::NOW));
    }

    public function test_angeklickte_alte_nachricht_ist_trotzdem_dabei(): void
    {
        $messages = [$this->msg(1, 'inbound', 30), $this->msg(2, 'inbound', 1)];

        $this->assertSame([2, 1], ForwardMessageSelection::candidates($messages, 1, self::NOW));
    }

    public function test_ausgehende_oder_unbekannte_angeklickte_ergibt_leer(): void
    {
        $messages = [$this->msg(1, 'outbound', 0), $this->msg(2, 'inbound', 0)];

        $this->assertSame([], ForwardMessageSelection::candidates($messages, 1, self::NOW));
        $this->assertSame([], ForwardMessageSelection::candidates($messages, 99, self::NOW));
    }

    public function test_vorlagen_sind_nie_kandidat(): void
    {
        $messages = [$this->msg(1, 'inbound', 0, 'template'), $this->msg(2, 'inbound', 0)];

        $this->assertSame([2], ForwardMessageSelection::candidates($messages, 2, self::NOW));
    }

    public function test_sanitize_wirft_fremde_ids_und_dubletten_raus(): void
    {
        $this->assertSame([4, 2], ForwardMessageSelection::sanitize([2, 99, 4, 2, '4'], [4, 2]));
        $this->assertSame([], ForwardMessageSelection::sanitize([99], [4, 2]));
    }
}
