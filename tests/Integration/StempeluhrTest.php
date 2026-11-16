<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

class StempeluhrTest extends ZeitTestCase
{
    private string $alteZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alteZone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->alteZone);
        parent::tearDown();
    }

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
        // Bezug-Ende 00:00 Berlin (MESZ) = 22:00 UTC; gespeichert wird in der Standardzone.
        $this->assertSame('2026-10-01 22:00:00', $e->soll_bis_at->format('Y-m-d H:i:s'));
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

    private function berlinBezug(): \Platform\Recruiting\Zeit\ZeitBezug
    {
        return new \Platform\Recruiting\Zeit\ZeitBezug(
            'rec_employee', 901, 'rec_dispo_assignment', 1, '2026-10-01',
            \Carbon\CarbonImmutable::parse('2026-10-01 14:00', 'Europe/Berlin'),
            \Carbon\CarbonImmutable::parse('2026-10-01 22:00', 'Europe/Berlin'),
            self::TEAM,
        );
    }

    public function test_zeitzone_speichert_in_standardzone_und_fenster_gilt_als_zeitpunkt(): void
    {
        $uhr = $this->uhr();
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:30:00', 'UTC'));
        $this->assertSame('zu_frueh', $uhr->kommen($this->berlinBezug(), $this->akteur(), Stempeluhr::ART_SELBST));

        Carbon::setTestNow(Carbon::parse('2026-10-01 12:05:00', 'UTC'));
        $this->assertSame('ok', $uhr->kommen($this->berlinBezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $roh = \Illuminate\Database\Capsule\Manager::table('rec_zeit_eintraege')->first();
        $this->assertSame('2026-10-01 12:05:00', $roh->kommen_at);
        $this->assertSame('2026-10-01 12:00:00', $roh->soll_von_at);
        $this->assertSame('2026-10-01 20:00:00', $roh->soll_bis_at);
    }

    public function test_zeitzone_korrektur_rechnet_um_und_erkennt_gleichen_zeitpunkt(): void
    {
        $uhr = $this->uhr();
        $berlin = \Carbon\CarbonImmutable::parse('2026-10-01 22:30', 'Europe/Berlin');
        $this->assertSame('ok', $uhr->korrigieren($this->berlinBezug(), $this->akteur(), ['kommen_at' => Carbon::parse('2026-10-01 12:00:00', 'UTC'), 'gehen_at' => $berlin], 'x'));
        $this->assertSame('2026-10-01 20:30:00', \Illuminate\Database\Capsule\Manager::table('rec_zeit_eintraege')->value('gehen_at'));
        $this->assertSame('2026-10-01 20:30:00', ZeitVerlauf::where('feld', 'gehen_at')->value('neu'));

        $anzahl = ZeitVerlauf::count();
        $andereZone = \Carbon\CarbonImmutable::parse('2026-10-01 20:30:00', 'UTC')->setTimezone('Asia/Tokyo');
        $this->assertSame('unveraendert', $uhr->korrigieren($this->berlinBezug(), $this->akteur(), ['gehen_at' => $andereZone], 'x'));
        $this->assertSame($anzahl, ZeitVerlauf::count());
    }

    private function roh(string $spalte): mixed
    {
        return \Illuminate\Database\Capsule\Manager::table('rec_zeit_eintraege')->value($spalte);
    }

    public function test_kommen_nach_fensterende_ist_zu_spaet(): void
    {
        $this->jetzt('23:01');
        $this->assertSame('zu_spaet', $this->uhr()->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST));
        $this->assertSame(0, ZeitEintrag::count());
    }

    public function test_stellvertretendes_gehen_ignoriert_das_fenster(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Europe/Berlin'));
        $this->assertSame('ok', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND));
    }

    public function test_gehen_vor_kommen_ist_ungueltig_gleichzeitig_erlaubt(): void
    {
        $uhr = $this->uhr();
        $uhr->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => Carbon::parse('2026-10-01 18:00:00')], 'x');
        $verlauf = ZeitVerlauf::count();
        $this->assertSame('ungueltig', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND, Carbon::parse('2026-10-01 17:00:00')));
        $this->assertNull($this->roh('gehen_at'));
        $this->assertSame($verlauf, ZeitVerlauf::count());
        $this->assertSame('ok', $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND, Carbon::parse('2026-10-01 18:00:00')));
    }

    public function test_uebergebene_zeit_wird_in_standardzone_gespeichert(): void
    {
        $this->uhr()->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_STELLVERTRETEND, Carbon::parse('2026-10-01 14:30', 'Europe/Berlin'));
        $this->assertSame('2026-10-01 12:30:00', $this->roh('kommen_at'));
    }

    public function test_sperre_steht_in_standardzone_und_entsperren_grund_im_verlauf(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        Carbon::setTestNow(Carbon::parse('2026-10-01 14:05:00', 'Europe/Berlin'));
        $uhr->sperren(ZeitEintrag::first(), $this->akteur(), 'fertig');
        $this->assertSame('2026-10-01 12:05:00', $this->roh('gesperrt_at'));
        $this->assertSame('2026-10-01 12:05:00', ZeitVerlauf::where('feld', 'gesperrt_at')->value('neu'));
        $uhr->entsperren(ZeitEintrag::first(), $this->akteur(), 'Irrtum');
        $this->assertSame('Irrtum', ZeitVerlauf::orderByDesc('id')->first()->grund);
    }

    public function test_jedes_schreiben_zieht_den_plan_nach(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $neu = new \Platform\Recruiting\Zeit\ZeitBezug('rec_employee', 901, 'rec_dispo_assignment', 1, '2026-10-02',
            \Carbon\CarbonImmutable::parse('2026-10-02 06:00', 'UTC'), \Carbon\CarbonImmutable::parse('2026-10-02 10:00', 'UTC'), self::TEAM + 1);
        $uhr->gehen($neu, $this->akteur(), Stempeluhr::ART_STELLVERTRETEND, Carbon::parse('2026-10-02 09:00:00', 'UTC'));
        $this->assertSame('2026-10-02 06:00:00', $this->roh('soll_von_at'));
        $this->assertSame('2026-10-02 10:00:00', $this->roh('soll_bis_at'));
        $this->assertSame('2026-10-02', substr((string) $this->roh('tag'), 0, 10));
        $this->assertSame(self::TEAM + 1, (int) $this->roh('team_id'));
    }

    public function test_umhaengen_haengt_die_zweite_person_um_nicht_die_erste(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(901, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $uhr->kommen($this->bezug(902, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $uhr->umhaengen('rec_dispo_assignment', 1, 2, 'rec_employee', 902);
        $this->assertSame(1, (int) ZeitEintrag::where('person_id', 901)->value('bezug_id'));
        $this->assertSame(2, (int) ZeitEintrag::where('person_id', 902)->value('bezug_id'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ungueltigeZeiten')]
    public function test_korrektur_nimmt_nur_strenge_zeiten(mixed $wert): void
    {
        $this->assertSame('ungueltig', $this->uhr()->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => $wert], 'x'));
        $this->assertSame(0, ZeitEintrag::count());
    }

    public static function ungueltigeZeiten(): array
    {
        return [['quatsch'], ['25:99'], ['14:00'], [0], [[]], ['2026-02-31 10:00'], ['']];
    }

    public function test_korrektur_nimmt_string_mit_offset(): void
    {
        $this->assertSame('ok', $this->uhr()->korrigieren($this->bezug(), $this->akteur(), ['kommen_at' => '2026-10-01T14:00:00+02:00'], 'x'));
        $this->assertSame('2026-10-01 12:00:00', $this->roh('kommen_at'));
    }

    public function test_korrektur_unbekannter_schluessel_und_pause_streng(): void
    {
        $uhr = $this->uhr();
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['komen_at' => '2026-10-01 14:00'], 'x'));
        foreach (['12.7', '1e2', -5, 12.5, true] as $p) {
            $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => $p], 'x'), var_export($p, true));
        }
        $this->assertSame(0, ZeitEintrag::count());
        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => '30'], 'x'));
        $this->assertSame(30, (int) $this->roh('pause_minuten'));
        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => ''], 'x'));
        $this->assertNull($this->roh('pause_minuten'));
    }

    public function test_unbekannte_art_ist_programmierfehler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uhr()->kommen($this->bezug(), $this->akteur(), 'Selbst');
    }

    public function test_lange_und_leere_gruende(): void
    {
        $uhr = $this->uhr();
        $lang = str_repeat('a', 256);
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['bemerkung' => 'x'], $lang));
        $uhr->korrigieren($this->bezug(), $this->akteur(), ['bemerkung' => 'x'], 'ok');
        $e = ZeitEintrag::first();
        try {
            $uhr->sperren($e, $this->akteur(), $lang);
            $this->fail('erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        try {
            $uhr->sperren($e, $this->akteur(), ' ');
            $this->fail('erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $uhr->sperren($e, $this->akteur(), 'g');
        try {
            $uhr->entsperren($e, $this->akteur(), $lang);
            $this->fail('erwartet InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }
        $uhr->entsperren($e, $this->akteur('user', 7, str_repeat('ä', 300)), 'g');
        $this->assertSame(255, mb_strlen(ZeitVerlauf::orderByDesc('id')->first()->akteur_name));
    }

    public function test_pause_bis_600_minuten_ist_erlaubt_darueber_ungueltig(): void
    {
        $uhr = $this->uhr();
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => 601], 'x'));
        $this->assertSame('ungueltig', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => '601'], 'x'));
        $this->assertSame(0, ZeitEintrag::count());

        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), ['pause_minuten' => 600], 'x'));
        $this->assertSame(600, (int) $this->roh('pause_minuten'));
    }

    public function test_person_umschreiben_zieht_den_datensatz_auf_die_andere_anstellung(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(901, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $uhr->kommen($this->bezug(901, 2), $this->akteur(), Stempeluhr::ART_SELBST);
        $kommen = \Illuminate\Database\Capsule\Manager::table('rec_zeit_eintraege')->where('bezug_id', 1)->value('kommen_at');
        $verlauf = ZeitVerlauf::count();

        $status = $uhr->personUmschreiben('rec_dispo_assignment', 1, 'rec_employee', 901, 950, $this->akteur('system', 0, 'ZAS-Import'));

        $this->assertSame('ok', $status);
        $this->assertSame(950, (int) ZeitEintrag::where('bezug_id', 1)->value('person_id'));
        $this->assertNotNull($kommen);
        $this->assertSame($kommen, \Illuminate\Database\Capsule\Manager::table('rec_zeit_eintraege')->where('bezug_id', 1)->value('kommen_at'), 'kein Zeitwert aendert sich');
        $this->assertSame(901, (int) ZeitEintrag::where('bezug_id', 2)->value('person_id'), 'anderer Bezug bleibt');
        $this->assertSame($verlauf + 1, ZeitVerlauf::count());
        $z = ZeitVerlauf::orderByDesc('id')->first();
        $this->assertSame(['person_id', '901', '950', 'umzug', 'system', 'ZAS-Import', null], [$z->feld, $z->alt, $z->neu, $z->art, $z->akteur_typ, $z->akteur_name, $z->grund]);
        $this->assertSame((int) ZeitEintrag::where('bezug_id', 1)->value('id'), (int) $z->rec_zeit_eintrag_id);
    }

    public function test_person_umschreiben_ohne_datensatz_und_mit_vorhandenem_ziel(): void
    {
        $uhr = $this->uhr();
        $system = $this->akteur('system', 0, 'ZAS-Import');
        $this->assertSame('kein_datensatz', $uhr->personUmschreiben('rec_dispo_assignment', 1, 'rec_employee', 901, 950, $system));

        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(901, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->jetzt('14:20');
        $uhr->kommen($this->bezug(950, 1), $this->akteur(), Stempeluhr::ART_SELBST);
        $vorher = ZeitEintrag::orderBy('id')->get()->map->getAttributes()->all();
        $verlauf = ZeitVerlauf::count();

        $this->assertSame('ziel_existiert', $uhr->personUmschreiben('rec_dispo_assignment', 1, 'rec_employee', 901, 950, $system));
        $this->assertSame('kein_datensatz', $uhr->personUmschreiben('rec_dispo_assignment', 1, 'employee', 901, 950, $system), 'anderer Personen-Typ');

        $this->assertSame($vorher, ZeitEintrag::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($verlauf, ZeitVerlauf::count());
    }

    public function test_person_umschreiben_nimmt_auch_einen_gesperrten_datensatz(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $uhr->sperren(ZeitEintrag::first(), $this->akteur(), 'erledigt');

        $this->assertSame('ok', $uhr->personUmschreiben('rec_dispo_assignment', 1, 'rec_employee', 901, 950, $this->akteur('system', 0, 'ZAS-Import')));
        $e = ZeitEintrag::first();
        $this->assertSame(950, (int) $e->person_id);
        $this->assertTrue($e->istGesperrt());
    }

    public function test_sperren_nur_ausgecheckt_prueft_den_frischen_stand_nicht_das_uebergebene_modell(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->jetzt('22:00');
        $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $gesehen = ZeitEintrag::first(); // ausgecheckt — so hat es der Aufrufer gelesen
        // Inzwischen leert eine Korrektur das Gehen.
        $this->assertSame('ok', $uhr->korrigieren($this->bezug(), $this->akteur(), ['gehen_at' => null], 'doch noch da'));

        $this->assertSame('nicht_ausgecheckt', $uhr->sperren($gesehen, $this->akteur(), 'erledigt', true));
        $this->assertFalse(ZeitEintrag::first()->istGesperrt());
        $this->assertSame(0, ZeitVerlauf::where('feld', 'gesperrt_at')->count());

        // Ohne den Schalter bleibt sperren() bedingungslos (wie bisher).
        $this->assertSame('ok', $uhr->sperren($gesehen, $this->akteur(), 'erledigt'));
        $this->assertSame('schon', $uhr->sperren($gesehen, $this->akteur(), 'erledigt', true));
    }

    public function test_sperren_nur_ausgecheckt_sperrt_den_ausgecheckten(): void
    {
        $uhr = $this->uhr();
        $this->jetzt('14:00');
        $uhr->kommen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->assertSame('nicht_ausgecheckt', $uhr->sperren(ZeitEintrag::first(), $this->akteur(), 'erledigt', true));
        $this->jetzt('22:00');
        $uhr->gehen($this->bezug(), $this->akteur(), Stempeluhr::ART_SELBST);
        $this->assertSame('ok', $uhr->sperren(ZeitEintrag::first(), $this->akteur(), 'erledigt', true));
        $this->assertTrue(ZeitEintrag::first()->istGesperrt());
    }
}
