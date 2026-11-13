<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\DispoEskalationsMerker;

/**
 * Sheran 13.11.2026: eine von Hand angepasste Eskalation soll fuer die
 * naechsten Veranstaltungen vorbelegt bleiben, bis das HCM neu geoeffnet wird.
 */
class DispoEskalationsMerkerTest extends TestCase
{
    public function test_standard_wird_nicht_gemerkt(): void
    {
        $this->assertNull(DispoEskalationsMerker::aus('vortag', '', '', '', ''));
        $this->assertNull(DispoEskalationsMerker::aus('vortag', '2026-11-20', '', '', ''), 'Datum ohne Datum-Modus zählt nicht');
    }

    public function test_angepasste_zeiten_und_modus_werden_gemerkt(): void
    {
        $this->assertSame(
            ['day' => 'vortag', 'date' => '', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'],
            DispoEskalationsMerker::aus('vortag', '', '09:00', '10:00', '11:00')
        );
        $this->assertSame(
            ['day' => 'einsatztag', 'date' => '', 't1' => '', 't2' => '', 't3' => ''],
            DispoEskalationsMerker::aus('einsatztag', '', '', '', '')
        );
        $this->assertSame(
            ['day' => 'datum', 'date' => '2026-11-13', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'],
            DispoEskalationsMerker::aus('datum', '2026-11-13', '09:00', '10:00', '11:00')
        );
    }

    public function test_unbekannter_modus_und_kaputte_werte_werden_nicht_gemerkt(): void
    {
        $this->assertNull(DispoEskalationsMerker::aus('irgendwas', '', '09:00', '10:00', '11:00'));
        $this->assertNull(DispoEskalationsMerker::aus('datum', 'kein-datum', '09:00', '10:00', '11:00'));
        $this->assertNull(DispoEskalationsMerker::aus('vortag', '', '9 Uhr', '10:00', '11:00'));
    }

    public function test_wird_nur_auf_eine_va_ohne_eigenen_plan_uebernommen(): void
    {
        $merker = ['day' => 'vortag', 'date' => '', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'];

        $this->assertSame($merker, DispoEskalationsMerker::anwendbar($merker, false, '2026-11-13'));
        $this->assertNull(DispoEskalationsMerker::anwendbar($merker, true, '2026-11-13'), 'die VA hat schon einen eigenen Plan');
        $this->assertNull(DispoEskalationsMerker::anwendbar(null, false, '2026-11-13'));
    }

    public function test_vergangenes_datum_wird_nicht_uebernommen(): void
    {
        $merker = ['day' => 'datum', 'date' => '2026-11-12', 't1' => '09:00', 't2' => '10:00', 't3' => '11:00'];

        $this->assertNull(DispoEskalationsMerker::anwendbar($merker, false, '2026-11-13'));
        $this->assertSame($merker, DispoEskalationsMerker::anwendbar($merker, false, '2026-11-12'), 'heute läuft der Tag noch');
    }

    /** Die Sitzung liefert, was immer dort liegt — auch Muell darf das Fenster nicht stoeren. */
    public function test_muell_aus_der_sitzung_wird_verworfen(): void
    {
        $this->assertNull(DispoEskalationsMerker::anwendbar(['day' => 'vortag'], false, '2026-11-13'));
        $this->assertNull(DispoEskalationsMerker::anwendbar('text', false, '2026-11-13'));
        $this->assertNull(DispoEskalationsMerker::anwendbar(['day' => 'x', 'date' => '', 't1' => '', 't2' => '', 't3' => ''], false, '2026-11-13'));
    }
}
