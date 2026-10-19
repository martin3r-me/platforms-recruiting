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
        $this->akte(['company' => 'MA']);                                                                                                           // nur MA

        $zeilen = $this->listen()->lohn($this->team);

        $this->assertCount(1, $zeilen);
        $this->assertSame((int) $rgU->id, $zeilen[0]['rg_employee_id']);
        $this->assertSame((int) $maU->id, $zeilen[0]['ma_employee_id']);
        $this->assertStringContainsString('unbekannt', $zeilen[0]['begruendung']);
        $this->assertSame([], $this->listen()->lohn($this->team, 'Zzz'));
        $this->assertCount(1, $this->listen()->lohn($this->team, 'unbek'));
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
}
