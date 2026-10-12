<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoTagesPruefung;

final class DispoTagesPruefungTest extends TestCase
{
    public function testGebuchteTageJePersonengruppe(): void
    {
        $rows = [
            ['employee_id' => 1, 'datum' => '2026-10-24', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
            ['employee_id' => 2, 'datum' => '2026-10-25 00:00:00', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
            ['employee_id' => 1, 'datum' => '2026-10-26', 'status_id' => 0, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
            ['employee_id' => 1, 'datum' => '2026-10-27', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => '2026-10-01'],
            ['employee_id' => 9, 'datum' => '2026-10-24', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null],
        ];
        $this->assertSame(
            [1 => ['2026-10-24' => true, '2026-10-25' => true], 9 => ['2026-10-24' => true]],
            DispoTagesPruefung::gebuchteTage($rows, [1 => 1, 2 => 1, 9 => 9])
        );
    }

    public function testUnbekannterMitarbeiterIstSeinEigenerAnker(): void
    {
        $rows = [['employee_id' => 5, 'datum' => '2026-10-24', 'status_id' => 1, 'missing_since' => null, 'deletion_marked_at' => null, 'declined_at' => null]];
        $this->assertSame([5 => ['2026-10-24' => true]], DispoTagesPruefung::gebuchteTage($rows, []));
    }
}
