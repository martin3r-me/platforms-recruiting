<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\ContractSigning;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\EmployerDeclaration;
use ReflectionMethod;

/**
 * Spec Vertrag aus der Akte §2.6, Test 7. Probe: RecContract::anstellung()
 * auf `return $this->applicant?->employee;` zurueckdrehen → hier rot.
 */
final class VertragUnterschriftOhneBewerbungTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
    }

    protected function tearDown(): void
    {
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function unterschreiben(RecContract $v, string $am = '2026-10-02 12:00:00'): void
    {
        $v->update(['status' => 'completed', 'signed_at' => $am, 'completed_at' => $am]);
    }

    public function test_anstellung_kommt_vom_vertrag_dann_vom_bewerber(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $this->assertSame($ma->id, $v->anstellung()?->id);

        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG1']), $b);
        $bewerbungsVertrag = RecContract::create([
            'rec_applicant_id' => $b->id, 'rec_contract_template_id' => $this->vorlage('AV-default', 'RG')->id,
            'team_id' => $this->team, 'status' => 'sent', 'personalized_content' => '',
        ]);
        $this->assertSame($rg->id, $bewerbungsVertrag->anstellung()?->id, 'ohne Anker: wie bisher ueber den Bewerber');
    }

    public function test_unterschrift_ohne_bewerbung_setzt_signed_at_und_befristung(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->unterschreiben($v);

        $hr = $ma->fresh()->hrData;
        $this->assertNotNull($hr, 'HR-Daten angelegt');
        $this->assertSame('2026-10-02', $hr->contract_signed_at?->toDateString());
        $this->assertSame('2026-10-31', $hr->contract_end_date?->toDateString());
    }

    public function test_befristung_wird_nur_verlaengert_nie_verkuerzt(): void
    {
        $ma = $this->anstellung();
        $ma->ensureHrData()->update(['contract_end_date' => '2026-12-31']);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);

        $this->unterschreiben($v);

        $this->assertSame('2026-12-31', $ma->fresh()->hrData->contract_end_date?->toDateString());
    }

    /** Probe: Zaehlung im Hook zurueck auf $applicant->contracts() → rot (der offene RG-AV blockiert). */
    public function test_alle_av_unterschrieben_zaehlt_je_anstellung(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG9']), $b);
        $ma = $this->verknuepfen($this->anstellung(['personnel_number' => 'MA9']), $b);
        $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'));          // offen, an RG
        $maVertrag = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));

        $this->unterschreiben($maVertrag);

        $this->assertSame('2026-10-02', $ma->fresh()->hrData?->contract_signed_at?->toDateString(), 'MA ist vollstaendig unterschrieben');
        $this->assertNull($rg->fresh()->hrData?->contract_signed_at, 'RG nicht');
    }

    public function test_zwei_offene_av_derselben_anstellung_warten_aufeinander(): void
    {
        $ma = $this->anstellung();
        $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $zweiter = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'));

        $this->unterschreiben($zweiter);

        $this->assertNull($ma->fresh()->hrData?->contract_signed_at);
    }

    public function test_bewerbungsvertrag_schreibt_die_befristung_nicht_dort_bleibt_der_alte_weg(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster');
        $rg = $this->verknuepfen($this->anstellung(['company' => 'RG', 'personnel_number' => 'RG2']), $b);
        $v = $this->vertragAn($rg, $this->vorlage('AV-default', 'RG'), [], ['vertragsende' => '2026-10-31']);

        $this->unterschreiben($v);

        $this->assertSame('2026-10-02', $rg->fresh()->hrData?->contract_signed_at?->toDateString());
        $this->assertNull($rg->fresh()->hrData?->contract_end_date, 'Bewerbungs-Vertraege: Erstquelle bleibt avContractEndDate()');
    }

    public function test_arbeitgeber_erklaerung_und_tagekonto_ohne_bewerbung(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $daten = ['par15_has_previous' => false, 'par15_entries' => [], EmployerDeclaration::KEY_ROLE => EmployerDeclaration::ROLE_MAIN, EmployerDeclaration::KEY_OTHER => null];

        $signing = new ContractSigning();
        $this->privat($signing, 'applyEmployerDeclaration')->invoke($signing, $v, $daten);
        $this->privat($signing, 'applyDayBudget')->invoke($signing, $v, $daten);

        $ma = $ma->fresh();
        $this->assertTrue($ma->is_main_employer);
        $this->assertSame(70, (int) $ma->hrData?->short_term_days_allowed, 'Default-Grenze 70, §15 "nein"');
    }

    public function test_mount_ohne_bewerbung_duzt_nach_team_und_belegt_vor(): void
    {
        DB::table('rec_applicant_settings')->insert(['team_id' => $this->team, 'settings' => json_encode(['use_informal_address' => true])]);
        $ma = $this->anstellung(['is_main_employer' => true]);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $token = $v->getOrCreatePublicFormLink()->token;

        $signing = new ContractSigning();
        $signing->mount($token);

        $this->assertSame('form', $signing->state);
        $this->assertTrue($signing->duzen);
        $this->assertSame(EmployerDeclaration::ROLE_MAIN, $signing->employerRole);
    }

    public function test_portal_link_fuehrt_ins_neue_portal_wenn_umgestellt(): void
    {
        $ma = $this->anstellung(['portal_v2_since' => '2026-10-01 00:00:00']);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $signing = new ContractSigning();

        $url = $this->privat($signing, 'buildPortalUrl')->invoke($signing, $v);

        $this->assertSame('/recruiting.public.portal-shell/' . http_build_query(['token' => $ma->fresh()->portal_token]), $url);

        $alt = $this->anstellung(['personnel_number' => 'MA4799']);
        $this->assertNull($this->privat($signing, 'buildPortalUrl')->invoke($signing, $this->vertragAn($alt, $this->vorlage('AV-MA-ZAP'))),
            'ohne Bewerbung und ohne neues Portal gibt es keinen Rueckweg');
    }

    private function privat(object $o, string $name): ReflectionMethod
    {
        $m = new ReflectionMethod($o, $name);
        $m->setAccessible(true);

        return $m;
    }
}
