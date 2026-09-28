<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SharedPhonePlanner;

/**
 * Vor der ersten Portal-Welle (Handynummer = Benutzername, Canvas 68)
 * muss HR wissen, wo eine Nummer an mehreren Datensaetzen haengt und ob
 * das der normale RG/MA-Fall ist oder zwei verschiedene Menschen.
 */
final class SharedPhonePlannerTest extends TestCase
{
    private function ma(int $id, ?string $phone, ?string $birthDate, ?string $personKey): array
    {
        return ['id' => $id, 'phone' => $phone, 'birth_date' => $birthDate, 'person_key' => $personKey];
    }

    public function test_gleiche_nummer_gleicher_person_key_ist_dieselbe_person(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', null, 'p-1'),
            $this->ma(2, '+4915112345678', null, 'p-1'),
        ]);

        $this->assertSame([1, 2], $plan['gleiche_person']);
        $this->assertSame([], $plan['andere_person']);
    }

    public function test_gleiche_nummer_gleiches_geburtsdatum_ohne_marker_ist_dieselbe_person(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', '1990-05-01', null),
            $this->ma(2, '+4915112345678', '1990-05-01', null),
        ]);

        $this->assertSame([1, 2], $plan['gleiche_person']);
        $this->assertSame([], $plan['andere_person']);
    }

    public function test_gleiche_nummer_verschiedene_geburtsdaten_ist_andere_person(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', '1990-05-01', null),
            $this->ma(2, '+4915112345678', '1985-11-20', null),
        ]);

        $this->assertSame([], $plan['gleiche_person']);
        $this->assertSame([1, 2], $plan['andere_person']);
    }

    public function test_gleiche_nummer_beide_ohne_geburtsdatum_und_ohne_marker_ist_konservativ_andere_person(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', null, null),
            $this->ma(2, '+4915112345678', null, null),
        ]);

        $this->assertSame([], $plan['gleiche_person'], 'zwei leere Geburtsdaten bestaetigen sich nicht gegenseitig');
        $this->assertSame([1, 2], $plan['andere_person']);
    }

    public function test_verschiedene_schreibweisen_derselben_nummer_landen_in_derselben_gruppe(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+49 152 12345678', null, 'p-1'),
            $this->ma(2, '0152 12345678', null, 'p-1'),
            $this->ma(3, '0049152 12345678', null, 'p-1'),
        ]);

        $this->assertSame([1, 2, 3], $plan['gleiche_person']);
        $this->assertSame([], $plan['andere_person']);
    }

    public function test_zu_kurze_nummer_faellt_komplett_raus(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '1234', null, 'p-1'),
            $this->ma(2, '1234', null, 'p-1'),
        ]);

        $this->assertSame([], $plan['gleiche_person']);
        $this->assertSame([], $plan['andere_person']);
    }

    public function test_einzelgaenger_kommt_in_keine_menge(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', '1990-05-01', 'p-1'),
        ]);

        $this->assertSame([], $plan['gleiche_person']);
        $this->assertSame([], $plan['andere_person']);
    }

    public function test_datum_mit_und_ohne_uhrzeit_gilt_als_gleiches_datum(): void
    {
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', '1990-05-01', null),
            $this->ma(2, '+4915112345678', '1990-05-01 00:00:00', null),
        ]);

        $this->assertSame([1, 2], $plan['gleiche_person']);
        $this->assertSame([], $plan['andere_person']);
    }

    public function test_drei_datensaetze_an_einer_nummer_zwei_davon_dieselbe_person_geht_komplett_in_andere_person(): void
    {
        // HR muss die ganze Gruppe sehen, um zu entscheiden — nicht nur den
        // ungeklaerten Rest. Wuerden wir hier nur den dritten Datensatz nach
        // andere_person schicken, saehe HR ihn ohne den Kontext, mit wem er
        // sich die Nummer eigentlich teilt.
        $plan = SharedPhonePlanner::plan([
            $this->ma(1, '+4915112345678', null, 'p-1'),
            $this->ma(2, '+4915112345678', null, 'p-1'),
            $this->ma(3, '+4915112345678', '1990-05-01', null),
        ]);

        $this->assertSame([], $plan['gleiche_person']);
        $this->assertSame([1, 2, 3], $plan['andere_person']);
    }
}
