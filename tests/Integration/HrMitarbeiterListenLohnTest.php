<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\HrDesk\Mitarbeiter;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;

/** Unterliste "Lohn" des HR-Schreibtischs (Spec Lohn MA §10). */
final class HrMitarbeiterListenLohnTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        Container::getInstance()->instance(HrMitarbeiterListen::class, new HrMitarbeiterListen($this->portalAuth()));
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(HrMitarbeiterListen::class);
        Container::getInstance()->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function listen(): HrMitarbeiterListen
    {
        return new HrMitarbeiterListen($this->portalAuth());
    }

    public function test_nur_fall_unbekannt_in_der_liste(): void
    {
        $this->person([], $rgU = $this->akte(['company' => 'RG', 'rec_applicant_id' => null, 'first_name' => 'Uwe', 'last_name' => 'Unbekannt']), $maU = $this->akte(['company' => 'MA']));
        $this->person([], $this->akte(['company' => 'RG', 'rec_applicant_id' => null, 'zuschlag_manuell' => 1.10]), $this->akte(['company' => 'MA']));   // manuell
        $this->person([], $this->akte(['company' => 'RG', 'rec_applicant_id' => 500]), $this->akte(['company' => 'MA']));                            // angleich
        $this->person([], $this->akte(['company' => 'MA']));                                                                                        // nur MA (Person ohne RG, R4)

        $zeilen = $this->listen()->lohn($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertSame((int) $rgU->id, $zeilen[0]['rg_employee_id']);
        $this->assertSame((int) $maU->id, $zeilen[0]['ma_employee_id']);
        $this->assertStringContainsString('unbekannt', $zeilen[0]['begruendung']);
        $this->assertSame([], $this->listen()->lohn($this->team, 'Zzz'));
        $this->assertCount(1, $this->listen()->lohn($this->team, 'unbek'));
    }

    /** Ruling R4 Fall A: gleiche Kennung, andere Person — in der Liste, mit der RG-Zeile als Anker. */
    public function test_gleiche_kennung_andere_person_steht_mit_rg_id_in_der_liste(): void
    {
        $this->person([], $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => 500]));
        $this->person([], $ma = $this->akte(['company' => 'MA']));
        // person_key setzt sonst nur die Paarung — direkt wie in Produktion.
        DB::table('rec_employees')->whereIn('id', [$rg->id, $ma->id])->update(['person_key' => 'k-a']);

        $zeilen = $this->listen()->lohn($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertSame((int) $rg->id, $zeilen[0]['rg_employee_id']);
        $this->assertSame((int) $ma->id, $zeilen[0]['ma_employee_id']);
        $this->assertStringContainsString('RG-Zuordnung unklar (gleiche Kennung, andere Person)', $zeilen[0]['begruendung']);
        $this->assertTrue($zeilen[0]['zuordnung_unklar']);

        // Kein Eingabefeld (Blade-Zweig) und Speichern an der Oberflaeche vorbei schreibt nichts.
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/hr-desk/mitarbeiter.blade.php');
        $this->assertMatchesRegularExpression('/@elseif \(\$unklar\)\s*<div[^>]*>Zuordnung unklar: gleiche Kennung, andere Person — Personen in der Akte zusammenführen oder klären<\/div>\s*@else/u', $blade);
        $this->authAls($this->team);
        $c = new Mitarbeiter();
        $c->zuschlagEingabe[$rg->id] = '1,10';
        $c->speichereZuschlag($rg->id);
        $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
        $this->assertStringContainsString('Zuordnung unklar', $c->meldung);
        $this->assertNull(DB::table('rec_employees')->where('id', $rg->id)->value('zuschlag_manuell'));
        $this->assertCount(1, $this->listen()->lohn($this->team));
    }

    /** Gegenprobe: normale Zeile ist nicht "unklar" und bleibt bearbeitbar. */
    public function test_normale_zeile_ist_bearbeitbar(): void
    {
        $this->person([], $this->akte(['company' => 'RG', 'rec_applicant_id' => null]), $this->akte(['company' => 'MA']));

        $zeilen = $this->listen()->lohn($this->team);
        $this->assertFalse($zeilen[0]['zuordnung_unklar']);
    }

    /** Ruling R4 Fall B: weder Person noch Kennung — in der Liste, ohne RG-Id und ohne Eingabefeld. */
    public function test_ohne_personen_zuordnung_steht_ohne_rg_id_in_der_liste(): void
    {
        $ma = $this->akte(['company' => 'MA', 'first_name' => 'Ohne', 'last_name' => 'Zuordnung']);

        $zeilen = $this->listen()->lohn($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertNull($zeilen[0]['rg_employee_id']);
        $this->assertSame((int) $ma->id, $zeilen[0]['ma_employee_id']);
        $this->assertStringContainsString('keine Personen-Zuordnung', $zeilen[0]['begruendung']);

        // Die Liste zeigt dann Hinweis statt Eingabefeld (Blade-Zweig ueber rg_employee_id === null).
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/hr-desk/mitarbeiter.blade.php');
        $this->assertMatchesRegularExpression('/@if \(\$rgId === null\)\s*<div[^>]*>keine Personen-Zuordnung — RG-Anstellung in der Akte prüfen<\/div>\s*@else/u', $blade);
    }

    public function test_zas_wert_ohne_bewerbung_gilt_als_bekannt(): void
    {
        $this->person([], $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]), $this->akte(['company' => 'MA']));
        DB::table('rec_employee_hr_data')->insert([
            'uuid' => 'hr-' . bin2hex(random_bytes(4)), 'team_id' => $this->team, 'rec_employee_id' => $rg->id, 'zas_grundlohn' => 13.90, 'zas_zuschlag' => 1.10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->listen()->lohn($this->team));
    }

    public function test_fremdes_team_bleibt_draussen(): void
    {
        $this->person([], $this->akte(['company' => 'RG', 'rec_applicant_id' => null]), $this->akte(['company' => 'MA']));

        $this->assertSame([], $this->listen()->lohn($this->team + 1));
    }

    public function test_speichern_nimmt_die_zeile_aus_der_liste(): void
    {
        $this->authAls($this->team);
        $this->person([], $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]), $this->akte(['company' => 'MA']));
        $c = new Mitarbeiter();

        $c->zuschlagEingabe[$rg->id] = 'abc';
        $c->speichereZuschlag($rg->id);
        $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
        $this->assertNull(DB::table('rec_employees')->where('id', $rg->id)->value('zuschlag_manuell'));

        $c->zuschlagEingabe[$rg->id] = '1,10';
        $c->speichereZuschlag($rg->id);
        $this->assertSame(HrAktionErgebnis::OK, $c->meldungStufe);
        $this->assertEqualsWithDelta(1.10, (float) DB::table('rec_employees')->where('id', $rg->id)->value('zuschlag_manuell'), 0.001);
        $this->assertSame([], $this->listen()->lohn($this->team));
    }

    public function test_speichern_fremdes_team_nicht_gefunden(): void
    {
        $this->authAls($this->team + 1);
        $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]);
        $c = new Mitarbeiter();
        $c->zuschlagEingabe[$rg->id] = '1,10';
        $c->speichereZuschlag($rg->id);

        $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
        $this->assertNull(DB::table('rec_employees')->where('id', $rg->id)->value('zuschlag_manuell'));
    }

    public function test_array_als_eingabe_ist_ein_fehler_und_schreibt_nichts(): void
    {
        $this->authAls($this->team);
        $this->person([], $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null]), $this->akte(['company' => 'MA']));
        $c = new Mitarbeiter();
        $c->zuschlagEingabe[$rg->id] = ['a' => 1];
        $c->speichereZuschlag($rg->id);

        $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
        $this->assertNull(DB::table('rec_employees')->where('id', $rg->id)->value('zuschlag_manuell'));
    }

    public function test_ma_id_und_muell_id_sind_nicht_gefunden(): void
    {
        $this->authAls($this->team);
        $ma = $this->akte(['company' => 'MA']);
        $c = new Mitarbeiter();
        foreach ([$ma->id, 'abc', null, [1]] as $roh) {
            $c->meldungStufe = '';
            $c->zuschlagEingabe[(int) $ma->id] = '1,10';
            $c->speichereZuschlag($roh);
            $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
        }
        $this->assertNull(DB::table('rec_employees')->where('id', $ma->id)->value('zuschlag_manuell'));
    }

    public function test_liste_im_cache_und_nach_speichern_sofort_aktuell(): void
    {
        $this->authAls($this->team);
        $this->person([], $rg = $this->akte(['company' => 'RG', 'rec_applicant_id' => null, 'last_name' => 'Cache']), $this->akte(['company' => 'MA']));
        $c = new Mitarbeiter();

        $this->assertSame(1, $c->grundZahlen()['lohn']);
        $this->assertCount(1, $c->lohn());
        $c->suche = 'zzz';
        unset($c->lohn);
        $this->assertSame([], $c->lohn());
        $c->suche = '';
        unset($c->lohn);

        $c->zuschlagEingabe[$rg->id] = '1,10';
        $c->speichereZuschlag($rg->id);

        $this->assertSame(0, $c->grundZahlen()['lohn']);
        $this->assertSame([], $c->lohn());
    }
}
