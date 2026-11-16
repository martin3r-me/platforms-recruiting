<?php

namespace Platform\Recruiting\Tests\Integration;

use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zeit\DispoZeiten;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

/**
 * Webapp-Dienst der Zeiterfassung (Spec 2026-11-16 §4): Spaltentexte, Fenster,
 * Korrektur und stellvertretendes Stempeln ueber der Stempeluhr — mit echten
 * Datensaetzen. Einsatz 01.10.2026 14:00–22:00 Berliner Zeit (= 12:00–20:00 UTC).
 */
class DispoZeitenTest extends ZeitTestCase
{
    private object $schalter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schalter = new class extends ZeitSchalter {
            public bool $an = true;

            public function aktiv(): bool
            {
                return $this->an;
            }

            public function teamId(): int
            {
                return 1101;
            }
        };
    }

    private function dienst(): DispoZeiten
    {
        return new DispoZeiten(new Stempeluhr($this->regeln()), $this->schalter, $this->regeln());
    }

    private function einbuchung(array $attrs = []): RecDispoAssignment
    {
        return $this->assignment($this->event(), array_merge(['rec_employee_id' => 901, 'von' => '14:00', 'bis' => '22:00'], $attrs));
    }

    /** @param array<string,mixed> $felder UTC-Zeiten */
    private function eintrag(RecDispoAssignment $a, array $felder = [], int $person = 901): ZeitEintrag
    {
        return ZeitEintrag::create(array_merge([
            'team_id' => self::TEAM, 'person_typ' => 'rec_employee', 'person_id' => $person,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $a->id, 'tag' => '2026-10-01',
            'soll_von_at' => '2026-10-01 12:00:00', 'soll_bis_at' => '2026-10-01 20:00:00',
        ], $felder));
    }

    public function test_zelle_texte_fuer_alle_zustaende(): void
    {
        $a = $this->einbuchung();
        $d = $this->dienst();

        $leer = $d->zelle(null);
        $this->assertSame(['zustand' => 'offen', 'text' => 'nicht eingecheckt', 'verspaetung' => 0, 'gesperrt' => false], $leer);

        $e = $this->eintrag($a, ['kommen_at' => '2026-10-01 11:52:00']);
        $z = $d->zelle($e);
        $this->assertSame('eingecheckt', $z['zustand']);
        $this->assertSame('eingecheckt 13:52', $z['text']);
        $this->assertSame(0, $z['verspaetung'], 'zu frueh ist keine Verspaetung');

        $e->update(['gehen_at' => '2026-10-01 20:07:00']);
        $z = $d->zelle($e->fresh());
        $this->assertSame('ausgecheckt', $z['zustand']);
        $this->assertSame('ausgecheckt 13:52 – 22:07', $z['text']);

        $e->update(['gesperrt_at' => '2026-10-01 21:00:00']);
        $z = $d->zelle($e->fresh());
        $this->assertSame('erledigt', $z['zustand']);
        $this->assertSame('erledigt 13:52 – 22:07', $z['text']);
        $this->assertTrue($z['gesperrt']);
    }

    public function test_verspaetung_zwoelf_minuten(): void
    {
        $a = $this->einbuchung();
        $e = $this->eintrag($a, ['kommen_at' => '2026-10-01 12:12:00']);

        $z = $this->dienst()->zelle($e);

        $this->assertSame(12, $z['verspaetung']);
        $this->assertSame('eingecheckt 14:12', $z['text']);
    }

    public function test_geleerter_datensatz_zeigt_nicht_eingecheckt(): void
    {
        $a = $this->einbuchung();
        $e = $this->eintrag($a);

        $this->assertSame('nicht eingecheckt', $this->dienst()->zelle($e)['text']);
    }

    public function test_fuer_event_nur_passende_person_und_eine_abfrage_je_tabelle(): void
    {
        $event = $this->event();
        $a1 = $this->assignment($event, ['rec_employee_id' => 901]);
        $a2 = $this->assignment($event, ['rec_employee_id' => 902]);
        $a3 = $this->assignment($event, ['rec_employee_id' => null]);
        $andere = $this->assignment($this->event(), ['rec_employee_id' => 903]);
        $this->eintrag($a1, ['kommen_at' => '2026-10-01 12:00:00'], 901);
        // Personenwechsel-Rest: Datensatz einer anderen Person an Zeile 2 — nicht maßgeblich.
        $this->eintrag($a2, ['kommen_at' => '2026-10-01 12:00:00'], 777);
        $this->eintrag($andere, ['kommen_at' => '2026-10-01 12:00:00'], 903);

        $karte = $this->dienst()->fuerEvent((int) $event->id);

        $this->assertSame([(int) $a1->id], array_keys($karte));
        $this->assertSame(901, (int) $karte[(int) $a1->id]->person_id);
        $this->assertArrayNotHasKey((int) $a3->id, $karte);
    }

    public function test_fuer_event_bei_schalter_aus_ohne_abfrage(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->schalter->an = false;

        $db = \Illuminate\Database\Capsule\Manager::connection();
        $db->enableQueryLog();
        $karte = $this->dienst()->fuerEvent((int) $a->rec_dispo_event_id);

        $this->assertSame([], $karte);
        $this->assertSame([], $db->getQueryLog(), 'Schalter aus: keine Abfrage');
        $db->disableQueryLog();
    }

    public function test_fuer_event_laedt_in_zwei_abfragen_unabhaengig_von_der_zeilenzahl(): void
    {
        $event = $this->event();
        for ($i = 0; $i < 6; $i++) {
            $a = $this->assignment($event, ['rec_employee_id' => 950 + $i]);
            $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00'], 950 + $i);
        }
        $db = \Illuminate\Database\Capsule\Manager::connection();
        $db->enableQueryLog();

        $karte = $this->dienst()->fuerEvent((int) $event->id);

        $this->assertCount(6, $karte);
        $this->assertLessThanOrEqual(2, count($db->getQueryLog()));
        $db->disableQueryLog();
    }

    public function test_korrektur_22_00_auf_22_30_mit_verlauf(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00']);

        $status = $this->dienst()->korrigieren($a, $this->akteur('user', 7, 'Sebastian'), ['gehen_at' => '2026-10-01 20:30:00'], 'Teamleiter bestätigt');

        $this->assertSame('ok', $status);
        $e = ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', (int) $a->id);
        $this->assertSame('2026-10-01 20:30:00', $e->gehen_at->format('Y-m-d H:i:s'));
        $v = $this->dienst()->fenster($a)['verlauf'][0];
        $this->assertSame('Gehen', $v['feld']);
        $this->assertStringContainsString('22:00', (string) $v['alt']);
        $this->assertStringContainsString('22:30', (string) $v['neu']);
        $this->assertSame('Teamleiter bestätigt', $v['grund']);
        $this->assertSame('Sebastian', $v['wer']);
        $row = ZeitVerlauf::query()->latest('id')->first();
        $this->assertSame('user', $row->akteur_typ);
        $this->assertSame('korrektur', $row->art);
    }

    public function test_korrektur_legt_datensatz_an_und_verlauf_zeigt_ortszeit(): void
    {
        $a = $this->einbuchung();

        $status = $this->dienst()->korrigieren($a, $this->akteur(), ['kommen_at' => '2026-10-01 11:55:00'], 'Vergessen');

        $this->assertSame('ok', $status);
        $this->assertSame(1, ZeitEintrag::query()->count());
        $v = $this->dienst()->fenster($a)['verlauf'][0];
        $this->assertSame('Kommen', $v['feld']);
        $this->assertNull($v['alt']);
        $this->assertSame('01.10.2026 13:55', $v['neu'], 'Ortszeit, nicht UTC');
    }

    public function test_fenster_werte_in_ortszeit_und_regelpause(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:37', 'gehen_at' => '2026-10-01 20:10:00', 'bemerkung' => 'x']);

        $f = $this->dienst()->fenster($a);

        $this->assertSame('14:00–22:00', $f['soll']);
        $this->assertSame('2026-10-01T14:00', $f['kommen']);
        $this->assertSame('2026-10-01T22:10', $f['gehen']);
        $this->assertNull($f['pause']);
        $this->assertSame(30, $f['regelpause']);
        $this->assertSame('x', $f['bemerkung']);
        $this->assertSame('ausgecheckt', $f['zustand']);
        $this->assertFalse($f['gesperrt']);
    }

    public function test_fenster_ohne_datensatz_ist_leer_und_offen(): void
    {
        $f = $this->dienst()->fenster($this->einbuchung());

        $this->assertNull($f['kommen']);
        $this->assertNull($f['gehen']);
        $this->assertSame('offen', $f['zustand']);
        $this->assertSame([], $f['verlauf']);
    }

    public function test_jetzt_kommen_ohne_fensterpruefung_als_stellvertretend(): void
    {
        $a = $this->einbuchung();
        \Carbon\Carbon::setTestNow('2026-10-01 05:00:00'); // Stunden vor dem Fenster

        $status = $this->dienst()->jetztKommen($a, $this->akteur('user', 7, 'Sebastian'));

        $this->assertSame('ok', $status);
        $e = ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', (int) $a->id);
        $this->assertSame('2026-10-01 05:00:00', $e->kommen_at->format('Y-m-d H:i:s'));
        $row = ZeitVerlauf::query()->latest('id')->first();
        $this->assertSame('stellvertretend', $row->art);
        $this->assertSame('user', $row->akteur_typ);
        $this->assertSame('schon', $this->dienst()->jetztKommen($a, $this->akteur()));
    }

    public function test_jetzt_gehen_stellvertretend_auch_nach_fensterende(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        \Carbon\Carbon::setTestNow('2026-10-02 08:00:00'); // lange nach Schichtende + 60 min

        $status = $this->dienst()->jetztGehen($a, $this->akteur());

        $this->assertSame('ok', $status);
        $this->assertSame('stellvertretend', ZeitVerlauf::query()->latest('id')->first()->art);
        $this->assertSame('kein_kommen', $this->dienst()->jetztGehen($this->einbuchung(['rec_employee_id' => 905]), $this->akteur()));
    }

    public function test_leeren_kommen_und_gehen_zusammen(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00']);

        $this->assertSame('ungueltig', $this->dienst()->korrigieren($a, $this->akteur(), ['kommen_at' => null], 'falsche Schicht'));
        $this->assertSame('ok', $this->dienst()->korrigieren($a, $this->akteur(), ['kommen_at' => null, 'gehen_at' => null], 'falsche Schicht'));

        $e = ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', (int) $a->id);
        $this->assertNull($e->kommen_at);
        $this->assertNull($e->gehen_at);
        $this->assertSame('offen', $this->dienst()->fenster($a)['zustand']);
        $this->assertSame(2, count($this->dienst()->fenster($a)['verlauf']), 'Verlauf bleibt');
    }

    public function test_gesperrter_datensatz_nicht_aenderbar_und_im_fenster_gesperrt(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00', 'gesperrt_at' => '2026-10-01 21:00:00']);

        $this->assertSame('gesperrt', $this->dienst()->korrigieren($a, $this->akteur(), ['bemerkung' => 'x'], 'g'));
        $this->assertSame('gesperrt', $this->dienst()->jetztKommen($a, $this->akteur()));
        $this->assertSame('gesperrt', $this->dienst()->jetztGehen($a, $this->akteur()));
        $f = $this->dienst()->fenster($a);
        $this->assertTrue($f['gesperrt']);
        $this->assertSame('erledigt', $f['zustand']);
    }

    public function test_ohne_person_ohne_datensatz(): void
    {
        $a = $this->einbuchung(['rec_employee_id' => null]);
        $d = $this->dienst();

        $this->assertSame('ohne_person', $d->jetztKommen($a, $this->akteur()));
        $this->assertSame('ohne_person', $d->jetztGehen($a, $this->akteur()));
        $this->assertSame('ohne_person', $d->korrigieren($a, $this->akteur(), ['bemerkung' => 'x'], 'g'));
        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_schalter_aus(): void
    {
        $a = $this->einbuchung();
        $this->schalter->an = false;
        $d = $this->dienst();

        $this->assertSame('aus', $d->jetztKommen($a, $this->akteur()));
        $this->assertSame('aus', $d->jetztGehen($a, $this->akteur()));
        $this->assertSame('aus', $d->korrigieren($a, $this->akteur(), ['bemerkung' => 'x'], 'g'));
        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_verlauf_neueste_zuerst_mit_pause_und_bemerkung(): void
    {
        $a = $this->einbuchung();
        $d = $this->dienst();
        $d->korrigieren($a, $this->akteur(), ['kommen_at' => '2026-10-01 12:00:00'], 'eins');
        $d->korrigieren($a, $this->akteur(), ['pause_minuten' => 15, 'bemerkung' => 'Stau'], 'zwei');

        $verlauf = $d->fenster($a)['verlauf'];

        $this->assertSame(['zwei', 'zwei', 'eins'], array_column($verlauf, 'grund'));
        $this->assertEqualsCanonicalizing(['Pause', 'Bemerkung'], array_column(array_slice($verlauf, 0, 2), 'feld'));
    }
}
