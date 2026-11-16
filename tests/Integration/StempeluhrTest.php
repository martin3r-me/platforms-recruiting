<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

class StempeluhrTest extends ZeitTestCase
{
    private function uhr(): Stempeluhr
    {
        return new Stempeluhr($this->regeln());
    }

    private function jetzt(string $zeit): void
    {
        Carbon::setTestNow(Carbon::parse("2026-10-01 {$zeit}", 'Europe/Berlin'));
    }

    public function test_kommen_und_gehen_schreiben_je_einen_verlauf(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $this->assertSame('ok', $uhr->kommen($this->bezug(), $this->akteur('employee', 901, 'Max Muster'), Stempeluhr::ART_SELBST));
        $this->jetzt('22:00');
        $this->assertSame('ok', $uhr->gehen($this->bezug(), $this->akteur('employee', 901, 'Max Muster'), Stempeluhr::ART_SELBST));

        $this->assertSame(1, ZeitEintrag::count());
        $verlauf = ZeitVerlauf::orderBy('id')->get();
        $this->assertCount(2, $verlauf);
        $this->assertSame('kommen_at', $verlauf[0]->feld);
        $this->assertSame('gehen_at', $verlauf[1]->feld);
        $this->assertSame('selbst', $verlauf[0]->art);
        $this->assertSame('Max Muster', $verlauf[0]->akteur_name);
        $this->assertNull($verlauf[0]->alt);
        $this->assertNotNull($verlauf[0]->neu);
    }

    public function test_doppeltes_kommen_ist_schon_ohne_zweiten_verlauf(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $this->assertSame('ok', $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame('schon', $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(1, ZeitEintrag::count());
        $this->assertSame(1, ZeitVerlauf::count());
    }

    public function test_doppeltes_gehen_ist_schon_ohne_zweiten_verlauf(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->jetzt('22:00');
        $this->assertSame('ok', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame('schon', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(1, ZeitEintrag::count());
        $this->assertSame(2, ZeitVerlauf::count());
    }

    public function test_fenster_gilt_nur_fuer_selbst(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('12:59');
        $this->assertSame('zu_frueh', $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(0, ZeitEintrag::count());
        $this->jetzt('13:00');
        $this->assertSame('ok', $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));

        $this->jetzt('23:01');
        $this->assertSame('zu_spaet', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));

        $this->jetzt('10:00');
        $this->assertSame('ok', $uhr->kommen($this->bezug(902), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND));
    }

    public function test_ohne_beginn_kein_selbst_stempeln(): void
    {
        $uhr = $this->uhr();
        $b = new \Platform\Recruiting\Zeit\ZeitBezug('rec_employee', 901, 'rec_dispo_assignment', 1, '2026-10-01', null, null, self::TEAM);
        $this->jetzt('14:00');
        $this->assertSame('zu_frueh', $uhr->kommen($b, $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame('ok', $uhr->kommen($b, $this->akteur(), Stempeluhr::ART_STELLVERTRETEND));
    }

    public function test_verlaengerte_schicht_gilt_beim_gehen(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->jetzt('23:30');
        $lang = $this->bezug(901, 1, '2026-10-01 14:00', '2026-10-02 00:00');
        $this->assertSame('ok', $uhr->gehen($lang, $this->akteur(), Stempeluhr::ART_SELBST));
        $e = ZeitEintrag::first();
        $this->assertSame('2026-10-02 00:00:00', $e->soll_bis_at->format('Y-m-d H:i:s'));
    }

    public function test_gehen_ohne_kommen(): void
    {
        $this->jetzt('22:00');
        $this->assertSame('kein_kommen', $this->uhr()->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(0, ZeitEintrag::count());
        $this->assertSame(0, ZeitVerlauf::count());
    }

    public function test_korrektur_legt_an_und_protokolliert_alt_neu(): void
    {
        $uhr = $this->uhr();
        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), [
            'kommen_at' => Carbon::parse('2026-10-01 14:00:00'),
            'gehen_at'  => Carbon::parse('2026-10-01 22:00:00'),
        ], 'vergessen'));
        $this->assertSame(1, ZeitEintrag::count());
        $this->assertSame(2, ZeitVerlauf::count());

        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), [
            'gehen_at' => Carbon::parse('2026-10-01 22:30:00'),
        ], 'später gegangen'));
        $v = ZeitVerlauf::orderByDesc('id')->first();
        $this->assertSame('gehen_at', $v->feld);
        $this->assertSame('2026-10-01 22:00:00', $v->alt);
        $this->assertSame('2026-10-01 22:30:00', $v->neu);
        $this->assertSame('später gegangen', $v->grund);
        $this->assertSame('korrektur', $v->art);
        $this->assertSame(3, ZeitVerlauf::count());
    }

    public function test_korrektur_ungueltig(): void
    {
        $uhr = $this->uhr();
        $k = Carbon::parse('2026-10-01 14:00:00');
        $g = Carbon::parse('2026-10-01 22:00:00');

        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => $k], ''));
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => $g, 'gehen_at' => $k], 'x'));
        $this->assertSame(0, ZeitEintrag::count());

        $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => $k, 'gehen_at' => $g], 'x');
        $verlauf = ZeitVerlauf::count();
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => null], 'x'));
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => 601], 'x'));
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => -1], 'x'));
        $this->assertSame($verlauf, ZeitVerlauf::count());
        $e = ZeitEintrag::first();
        $this->assertNotNull($e->kommen_at);
        $this->assertNull($e->pause_minuten);
    }

    public function test_unveraendert_schreibt_nichts(): void
    {
        $uhr = $this->uhr();
        $this->assertSame('unveraendert', $uhr->korrigieren($this->bezug(), $this->akteur(), ['bemerkung' => null], 'x'));
        $this->assertSame(0, ZeitEintrag::count());

        $k = Carbon::parse('2026-10-01 14:00:00');
        $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => $k], 'x');
        $this->assertSame('unveraendert', $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => $k], 'x'));
        $this->assertSame(1, ZeitVerlauf::count());
    }

    public function test_leeren_und_neu_stempeln(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->jetzt('22:00');
        $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);

        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => null, 'gehen_at' => null], 'falsche Schicht'));
        $e = ZeitEintrag::first();
        $this->assertNull($e->kommen_at);
        $this->assertNull($e->gehen_at);
        $this->assertSame(4, ZeitVerlauf::count());

        $this->jetzt('15:00');
        $this->assertSame('ok', $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(1, ZeitEintrag::count());
    }

    public function test_sperre_blockt_alles(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $e = ZeitEintrag::first();

        $this->assertSame('ok', $uhr->sperren($e, $this->akteur(), 'erledigt'));
        $this->assertSame('schon', $uhr->sperren($e->fresh(), $this->akteur(), 'erledigt'));
        $this->assertSame('gesperrt', $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND));
        $this->assertSame('gesperrt', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND));
        $this->assertSame('gesperrt', $uhr->korrigieren($this->bezug(), $this->akteur(), ['bemerkung' => 'x'], 'x'));

        $this->assertSame('ungueltig', $uhr->entsperren($e->fresh(), $this->akteur(), ''));
        $this->assertSame('ok', $uhr->entsperren($e->fresh(), $this->akteur(), 'Irrtum'));
        $this->assertSame('nicht_gesperrt', $uhr->entsperren($e->fresh(), $this->akteur(), 'Irrtum'));

        $zeilen = ZeitVerlauf::where('feld', 'gesperrt_at')->orderBy('id')->get();
        $this->assertCount(2, $zeilen);
        $this->assertSame('sperre', $zeilen[0]->art);
        $this->assertSame('sperre', $zeilen[1]->art);
        $this->assertNull($zeilen[0]->alt);
        $this->assertNull($zeilen[1]->neu);
    }

    public function test_umhaengen_zieht_nur_die_person_um(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(901, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $uhr->kommen($this->bezug(902, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $verlauf = ZeitVerlauf::count();

        $uhr->umhaengen('rec_dispo_assignment', 1, 2, 'rec_employee', 901);

        $this->assertSame(2, (int) ZeitEintrag::where('person_id', 901)->value('bezug_id'));
        $this->assertSame(1, (int) ZeitEintrag::where('person_id', 902)->value('bezug_id'));
        $this->assertSame($verlauf, ZeitVerlauf::count());

        // Ziel hat schon einen Datensatz -> nichts tun
        $uhr->kommen($this->bezug(902, 2), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND);
        $uhr->umhaengen('rec_dispo_assignment', 1, 2, 'rec_employee', 902);
        $this->assertSame(1, (int) ZeitEintrag::where('person_id', 902)->where('bezug_id', 1)->count());
    }
}
