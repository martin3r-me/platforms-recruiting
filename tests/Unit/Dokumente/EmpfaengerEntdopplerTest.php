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

    /** Zusammengefuehrte Personen (29.10.): gleiche rec_person_id, verschiedene person_key — EIN Mensch. */
    public function test_gleiche_person_mit_verschiedenen_schluesseln_wird_eine(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 40, 'person_key' => 'alt-a', 'rec_person_id' => 7],
            ['id' => 41, 'person_key' => 'alt-b', 'rec_person_id' => 7],
        ]);
        $this->assertSame([40], $ids);
    }

    public function test_verbundene_person_ohne_schluessel_wird_eine(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 51, 'person_key' => null, 'rec_person_id' => 8],
            ['id' => 50, 'person_key' => 'k', 'rec_person_id' => 8],
        ]);
        $this->assertSame([50], $ids);
    }

    /** Eine Anstellung haengt noch nicht an der Person, traegt aber deren Schluessel: dieselbe Person. */
    public function test_schluessel_verbindet_auch_ohne_rec_person_id(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 60, 'person_key' => 'k', 'rec_person_id' => 9],
            ['id' => 61, 'person_key' => 'k', 'rec_person_id' => null],
            ['id' => 62, 'person_key' => null, 'rec_person_id' => 9],
        ]);
        $this->assertSame([60], $ids);
    }

    public function test_verschiedene_personen_bleiben_getrennt(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 70, 'person_key' => null, 'rec_person_id' => 1],
            ['id' => 71, 'person_key' => null, 'rec_person_id' => 2],
            ['id' => 72, 'person_key' => null, 'rec_person_id' => null],
        ]);
        $this->assertSame([70, 71, 72], $ids);
    }

    /** Review 29.10.: die ungebundene Anstellung hat die kleinste id — getragen wird trotzdem von der gebundenen (Portal-Umfang). */
    public function test_traeger_ist_immer_eine_gebundene_anstellung(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 10, 'person_key' => 'K1', 'rec_person_id' => 1],
            ['id' => 20, 'person_key' => 'K2', 'rec_person_id' => 1],
            ['id' => 5, 'person_key' => 'K2', 'rec_person_id' => null],
        ]);
        $this->assertSame([10], $ids);
    }

    /** Zwei verschiedene Personen verschmelzen nie ueber einen gemeinsamen Schluessel. */
    public function test_verschiedene_personen_mit_gleichem_schluessel_bleiben_getrennt(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 30, 'person_key' => 'K', 'rec_person_id' => 1],
            ['id' => 31, 'person_key' => 'K', 'rec_person_id' => 2],
        ]);
        $this->assertSame([30, 31], $ids);
    }

    /** Ungebundene mit einem Schluessel, der zu zwei Personen fuehrt: nicht raten, eigene Gruppe. */
    public function test_mehrdeutiger_schluessel_haengt_nichts_an(): void
    {
        $ids = EmpfaengerEntdoppler::aufPersonen([
            ['id' => 40, 'person_key' => 'K', 'rec_person_id' => 1],
            ['id' => 41, 'person_key' => 'K', 'rec_person_id' => 2],
            ['id' => 42, 'person_key' => 'K', 'rec_person_id' => null],
        ]);
        $this->assertSame([40, 41, 42], $ids);
    }
}
