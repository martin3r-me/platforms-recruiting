<?php

namespace Platform\Recruiting\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\NaechsterEinsatz;

/**
 * Kachel „Nächster Einsatz“ auf der Portal-Startseite (29.10.2026): kurz —
 * wann, wo, Zeit, Status; Details auf der Einsatz-Seite. Pure.
 */
class NaechsterEinsatzTest extends TestCase
{
    /** @return object{datum:Carbon, von:string, bis:string, confirmed_at:?string, reconfirm_required_at:?string, event:object} */
    private function einsatz(string $datum, string $von, ?string $bestaetigt = null, ?string $geaendert = null, string $name = 'Messe Düsseldorf'): object
    {
        return (object) [
            'datum' => Carbon::parse($datum), 'von' => $von, 'bis' => '16:00',
            'confirmed_at' => $bestaetigt, 'reconfirm_required_at' => $geaendert,
            'event' => (object) ['name' => $name, 'einsatz_ref' => 'E-1', 'ort' => 'Düsseldorf'],
        ];
    }

    public function test_nichts_geplant(): void
    {
        $this->assertNull(NaechsterEinsatz::aus([], Carbon::parse('2026-10-29 09:00')));
    }

    public function test_morgen_offen_und_weitere(): void
    {
        $k = NaechsterEinsatz::aus([
            $this->einsatz('2026-10-30', '08:00'),
            $this->einsatz('2026-11-02', '10:00', '2026-10-28 10:00:00'),
            $this->einsatz('2026-11-05', '10:00'),
        ], Carbon::parse('2026-10-29 09:00'));

        $this->assertSame('Morgen', $k['tag']);
        $this->assertSame('08:00–16:00', $k['zeit']);
        $this->assertSame('Messe Düsseldorf', $k['name']);
        $this->assertSame('offen', $k['status']);
        $this->assertSame(2, $k['weitere']);
        $this->assertSame(2, $k['unbestaetigt']);
    }

    public function test_heute_datum_und_geaendert(): void
    {
        $now = Carbon::parse('2026-10-29 07:00');
        $this->assertSame('Heute', NaechsterEinsatz::aus([$this->einsatz('2026-10-29', '08:00', '2026-10-20')], $now)['tag']);
        $this->assertSame('bestaetigt', NaechsterEinsatz::aus([$this->einsatz('2026-10-29', '08:00', '2026-10-20')], $now)['status']);
        $this->assertSame('So 01.11.', NaechsterEinsatz::aus([$this->einsatz('2026-11-01', '08:00')], $now)['tag']);
        // Zeit geaendert schlaegt bestaetigt: neu bestaetigen.
        $this->assertSame('geaendert', NaechsterEinsatz::aus([$this->einsatz('2026-11-01', '08:00', '2026-10-20', '2026-10-28')], $now)['status']);
    }
}
