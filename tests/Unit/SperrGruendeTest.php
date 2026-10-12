<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\SperrGruende;

final class SperrGruendeTest extends TestCase
{
    private const JETZT = '2026-10-12 10:00:00';

    public function test_jeder_grund_mit_aktion_und_text(): void
    {
        $gruende = SperrGruende::fuer([
            'dispo'          => ['seit' => '2026-10-10 16:00:00', 'grund' => 'Dispo: nicht bestätigt', 'employee_id' => 9],
            'konto_gesperrt' => true,
            'bremse'         => ['frei_ab' => '2026-10-12T10:15:00+00:00'],
            'notfall_bis'    => '2026-10-19 10:00:00',
            'mehrdeutig'     => true,
        ], self::JETZT);

        $this->assertSame(['dispo', 'konto', 'bremse', 'notfall', 'mehrdeutig'], array_column($gruende, 'code'));
        $this->assertSame('Portal gesperrt seit 10.10.2026, 16:00 — Dispo: nicht bestätigt', $gruende[0]['text']);
        $this->assertSame(9, $gruende[0]['employee_id']);
        $this->assertSame('portal_entsperren', $gruende[0]['aktion']);
        $this->assertSame('Konto gesperrt', $gruende[1]['text']);
        $this->assertSame('5 Fehlversuche, frei ab 10:15', $gruende[2]['text']);
        $this->assertSame('Notfallweg gesperrt bis 19.10.2026', $gruende[3]['text']);
        $this->assertSame('Nummer ist auch in einem anderen Team vergeben — Anmeldung nicht möglich', $gruende[4]['text']);
        $this->assertNull($gruende[4]['aktion'], 'mehrdeutig: keine Aktion (Plattform-Support)');
    }

    public function test_leere_und_abgelaufene_eingaben_ergeben_nichts(): void
    {
        $this->assertSame([], SperrGruende::fuer(['dispo' => null, 'konto_gesperrt' => false, 'bremse' => null, 'notfall_bis' => '2026-10-12 10:00:00', 'mehrdeutig' => false], self::JETZT));
        $ohneZeit = SperrGruende::fuer(['bremse' => ['frei_ab' => null]], self::JETZT);
        $this->assertSame('5 Fehlversuche, frei spätestens nach 15 Minuten', $ohneZeit[0]['text']);
        $this->assertFalse(SperrGruende::zaehltFuersAbzeichen(SperrGruende::BREMSE));
        $this->assertTrue(SperrGruende::zaehltFuersAbzeichen(SperrGruende::DISPO));
    }
}
