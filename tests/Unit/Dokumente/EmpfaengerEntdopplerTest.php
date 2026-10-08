<?php

namespace Platform\Recruiting\Tests\Unit\Dokumente;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EmpfaengerEntdoppler;

final class EmpfaengerEntdopplerTest extends TestCase
{
    public function test_zwei_anstellungen_einer_person_werden_eine(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 223, 'person_key' => 'p-7'],
            ['id' => 160, 'person_key' => 'p-7'],
        ]);
        $this->assertSame([160], $ids, 'die kleinere id gewinnt, unabhaengig von der Reihenfolge');
    }

    public function test_ohne_person_key_bleibt_jede_anstellung(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 5, 'person_key' => null],
            ['id' => 6, 'person_key' => ''],
            ['id' => 7, 'person_key' => '   '],
        ]);
        $this->assertSame([5, 6, 7], $ids);
    }

    public function test_doppelte_ids_in_der_eingabe_zaehlen_einmal(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 9, 'person_key' => null],
            ['id' => 9, 'person_key' => null],
        ]);
        $this->assertSame([9], $ids);
    }

    public function test_reihenfolge_folgt_der_ersten_nennung(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 30, 'person_key' => 'b'],
            ['id' => 10, 'person_key' => 'a'],
            ['id' => 20, 'person_key' => 'b'],
        ]);
        $this->assertSame([20, 10], $ids);
    }
}
