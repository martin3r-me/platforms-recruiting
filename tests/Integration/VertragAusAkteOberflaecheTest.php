<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Http\Controllers\VertragPdfController;
use Platform\Recruiting\Livewire\Employees\Show;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\VertragHinweisSender;

/** Spec Vertrag aus der Akte §2.1 und §2.7 — die Akte ohne Livewire-Laufzeit (Muster VertragAnDerAnstellungTest). */
final class VertragAusAkteOberflaecheTest extends TestCase
{
    use VertragAusAkteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weltAufbauen();
        Container::getInstance()->instance(VertragHinweisSender::class, new class extends VertragHinweisSender {
            public function sende(RecEmployee $employee): string { return 'altes_portal'; }
        });
    }

    protected function tearDown(): void
    {
        Container::getInstance()->forgetInstance(VertragHinweisSender::class);
        Container::getInstance()->forgetInstance('request');
        $this->weltAbbauen();
        parent::tearDown();
    }

    private function akte(RecEmployee $e): Show
    {
        $show = new Show();
        $show->employeeId = $e->id;

        return $show;
    }

    private function maVorlage(array $set = []): RecContractTemplate
    {
        return $this->vorlage('AV-MA-LOG', 'MA', array_merge(['name' => 'AV MA Logistik', 'taetigkeit' => 'logistiker'], $set));
    }

    public function test_fenster_mit_einsatztag_ist_vorbelegt(): void
    {
        $this->maVorlage();
        $akte = $this->akte($this->anstellung());

        $akte->openVertragModal('2026-10-20');

        $this->assertTrue($akte->vertragModalShow);
        $this->assertSame('2026-10-01', $akte->vertragBeginn);
        $this->assertSame('2026-10-31', $akte->vertragEnde);
        $this->assertSame('', $akte->vertragZuschlag);
        $this->assertNotSame('', $akte->vertragVorlageId, 'genau eine Vorlage: vorgewaehlt');

        $leer = $this->akte($this->anstellung(['personnel_number' => 'MA2']));
        $leer->openVertragModal('kaputt');
        $this->assertSame('', $leer->vertragBeginn);
    }

    public function test_vertragsarten_nur_aktive_av_der_gesellschaft(): void
    {
        $passend = $this->maVorlage();
        $this->vorlage('AV-default', 'RG');
        $this->vorlage('AV-MA-ALT', 'MA', ['is_active' => false]);
        $this->vorlage('IFSG', 'MA');

        $this->assertSame([['id' => $passend->id, 'label' => 'AV MA Logistik (Logistiker)']], $this->akte($this->anstellung())->vertragsVorlagen());
    }

    /** Review-Focus 4. */
    public function test_erstellen_mit_komma_zuschlag_landet_in_der_liste(): void
    {
        $vorlage = $this->maVorlage();
        $ma = $this->anstellung();
        $akte = $this->akte($ma);
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragZuschlag = '0,60';

        $akte->vertragErstellen();

        $this->assertNull($akte->flashError);
        $vertrag = RecContract::query()->where('rec_employee_id', $ma->id)->firstOrFail();
        $this->assertStringContainsString('Arbeitsvertrag #' . $vertrag->id . ' erstellt', (string) $akte->flash);
        $this->assertStringContainsString('noch nicht auf das neue Portal umgestellt', (string) $akte->flash);
        $this->assertFalse($akte->vertragModalShow);

        $zeile = $this->akte($ma)->openContracts()[0];
        $this->assertSame($vertrag->id, $zeile['id']);
        $this->assertSame('01.10.2026', $zeile['beginn']);
        $this->assertSame('31.10.2026', $zeile['ende']);
        $this->assertSame('0,60', $zeile['zuschlag']);
        $this->assertNotNull($zeile['sign_url']);
        $this->assertTrue($zeile['can_cancel']);
        $this->assertFalse($zeile['can_reissue'], 'ohne Bewerbung kein Neu-Ausstellen (ReissueContractService braucht sie)');
    }

    public function test_eingabefehler_und_inaktive_akte(): void
    {
        $vorlage = $this->maVorlage();
        $akte = $this->akte($this->anstellung());
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;

        $akte->vertragZuschlag = 'abc';
        $akte->vertragErstellen();
        $this->assertSame('Zuschlag muss eine Zahl sein (z. B. 0,60).', $akte->flashError);

        $akte->vertragZuschlag = '0,60';
        $akte->vertragVorlageId = '';
        $akte->vertragErstellen();
        $this->assertSame('Bitte eine Vertragsart wählen.', $akte->flashError);

        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragBeginn = '';
        $akte->vertragErstellen();
        $this->assertSame('Bitte einen Vertragsbeginn eintragen.', $akte->flashError);
        $this->assertSame(0, RecContract::count());

        $inaktiv = $this->akte($this->anstellung(['personnel_number' => 'MA3', 'is_active' => false]));
        $inaktiv->openVertragModal();
        $this->assertFalse($inaktiv->vertragModalShow);
        $this->assertSame('Die Akte ist deaktiviert — es entsteht kein neuer Vertrag.', $inaktiv->flashError);
    }

    public function test_doppelabdeckung_kommt_als_meldung(): void
    {
        $vorlage = $this->maVorlage();
        $ma = $this->anstellung();
        $this->vertragAn($ma, $vorlage, [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);
        $akte = $this->akte($ma);
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragZuschlag = '0,60';

        $akte->vertragErstellen();

        $this->assertStringContainsString('bereits einen Arbeitsvertrag', (string) $akte->flashError);
        $this->assertTrue($akte->vertragModalShow, 'Fenster bleibt offen');
    }

    public function test_unterschriebene_ohne_bewerbung_mit_hr_pdf_und_altcode_zuschlag(): void
    {
        $ma = $this->anstellung();
        $neu = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00'],
            ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31', 'zuschlag' => '0,60']);
        $alt = $this->vertragAn($ma, $this->vorlage('AV-060', 'MA'), ['status' => 'completed', 'signed_at' => '2025-01-02 12:00:00', 'completed_at' => '2025-01-02 12:00:00']);

        $zeilen = collect($this->akte($ma)->signedContracts())->keyBy('id');

        $this->assertSame('/recruiting.employees.vertrag-pdf/' . http_build_query(['contractId' => $neu->id]), $zeilen[$neu->id]['pdf_url']);
        $this->assertSame('0,60', $zeilen[$neu->id]['zuschlag']);
        $this->assertSame('0,60', $zeilen[$alt->id]['zuschlag'], 'Alt-AV ohne Feld: aus dem Code');
        $this->assertNull($zeilen[$alt->id]['beginn']);
    }

    public function test_offenen_vertrag_stornieren_unterschriebenen_nicht(): void
    {
        $ma = $this->anstellung();
        $offen = $this->vertragAn($ma, $this->maVorlage());
        $fertig = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00']);
        $akte = $this->akte($ma);

        $akte->vertragStornieren($offen->id);
        $this->assertSame('cancelled', $offen->fresh()->status);
        $this->assertStringContainsString('storniert', (string) $akte->flash);

        $akte->vertragStornieren($fertig->id);
        $this->assertSame('completed', $fertig->fresh()->status);
        $this->assertSame('Hier lassen sich nur offene Arbeitsverträge dieser Akte stornieren.', $akte->flashError);
    }

    /**
     * Ledger T7-1 / Schlussreview M4: Stornieren in der Akte nur fuer
     * Arbeitsvertraege (Spec §1: die Akte kennt nur AV). IFSG/AT aus dem
     * Bewerbungsweg (auch pending) bleiben unberuehrt — Knopf UND Server.
     * Probe: can_cancel wieder true bzw. istAv-Waechter in vertragStornieren() entfernen → rot.
     */
    public function test_stornieren_nur_fuer_arbeitsvertraege(): void
    {
        $ma = $this->anstellung();
        $av = $this->vertragAn($ma, $this->maVorlage());
        $ifsg = $this->vertragAn($ma, $this->vorlage('IFSG'), ['status' => 'pending', 'sent_at' => null]);
        $at = $this->vertragAn($ma, $this->vorlage('AT-140'));
        $akte = $this->akte($ma);

        $knopf = array_column($akte->openContracts(), 'can_cancel', 'id');
        $this->assertSame([$av->id => true, $ifsg->id => false, $at->id => false], $knopf);

        $akte->vertragStornieren($ifsg->id);
        $this->assertSame('pending', $ifsg->fresh()->status);
        $this->assertSame('Hier lassen sich nur offene Arbeitsverträge dieser Akte stornieren.', $akte->flashError);

        $akte->vertragStornieren($at->id);
        $this->assertSame('sent', $at->fresh()->status);
    }

    public function test_neu_ausstellen_ohne_bewerbung_meldet_statt_abzustuerzen(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->maVorlage());
        $akte = $this->akte($ma);

        $akte->openReissueModal($v->id);

        $this->assertFalse($akte->reissueModalShow);
        $this->assertSame('Neu ausstellen geht nur bei Verträgen aus einer Bewerbung — diesen Vertrag stornieren und neu erstellen.', $akte->flashError);
    }

    public function test_hr_pdf_nur_im_eigenen_team(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->maVorlage(), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00']);
        $offen = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'));

        $this->assertSame($v->id, VertragPdfController::hrVertrag($this->team, $v->id)?->id);
        $this->assertNull(VertragPdfController::hrVertrag($this->team + 1, $v->id));
        $this->assertNull(VertragPdfController::hrVertrag($this->team, $offen->id));
    }

    /** Review Fix 3: der Aufruf vom HR-Schreibtisch (Task 8) oeffnet das Fenster ueber mount(). */
    public function test_mount_mit_vertrag_neu_oeffnet_vorbelegt(): void
    {
        $this->maVorlage();
        $ma = $this->anstellung();

        Container::getInstance()->instance('request', Request::create('/x', 'GET', ['vertrag' => 'neu', 'einsatz' => '2026-11-14']));
        $akte = new Show();
        $akte->mount($ma->id);
        $this->assertTrue($akte->vertragModalShow);
        $this->assertSame('2026-11-01', $akte->vertragBeginn);
        $this->assertSame('2026-11-30', $akte->vertragEnde);

        Container::getInstance()->instance('request', Request::create('/x', 'GET', ['vertrag' => 'neu', 'einsatz' => '14.11.2026']));
        $kaputt = new Show();
        $kaputt->mount($ma->id);
        $this->assertTrue($kaputt->vertragModalShow, 'ungueltiger Einsatztag: Fenster offen, nur ohne Vorbelegung');
        $this->assertSame('', $kaputt->vertragBeginn);
        $this->assertSame('', $kaputt->vertragEnde);

        Container::getInstance()->instance('request', Request::create('/x', 'GET'));
        $ohne = new Show();
        $ohne->mount($ma->id);
        $this->assertFalse($ohne->vertragModalShow);
    }

    /** Review Fix 1: die Meldung steht IM Fenster — die Kopfzeile liegt hinter dem Overlay. */
    public function test_fehlermeldung_steht_im_gerenderten_fenster(): void
    {
        $vorlage = $this->maVorlage();
        $ma = $this->anstellung();
        $this->vertragAn($ma, $vorlage, [], ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2026-10-31']);
        $akte = $this->akte($ma);
        $akte->openVertragModal('2026-10-20');
        $akte->vertragVorlageId = (string) $vorlage->id;
        $akte->vertragZuschlag = '0,60';
        $akte->vertragErstellen();
        $this->assertNotNull($akte->flashError);

        $html = $this->renderPartial('_vertrag-erstellen', [
            'vertragVorlagen' => $akte->vertragsVorlagen(),
            'vertragFirma'    => 'MA',
            'fehler'          => $akte->flashError,
        ]);

        $this->assertStringContainsString(e($akte->flashError), $html);
        $this->assertStringContainsString('bereits einen Arbeitsvertrag', $html);
        $this->assertStringContainsString('wire:model="vertragZuschlag"', $html, 'Formular bleibt sichtbar');

        $ohneFehler = $this->renderPartial('_vertrag-erstellen', ['vertragVorlagen' => [], 'vertragFirma' => 'MA', 'fehler' => null]);
        $this->assertStringNotContainsString('bg-red-50', $ohneFehler);
        $this->assertStringContainsString('keine Arbeitsvertrags-Vorlage', $ohneFehler);
    }

    /** Beide Fenster binden die Fehlerzeile ein, das Vertragsfenster seinen Rumpf (Quelle, nicht Laufzeit). */
    public function test_beide_fenster_binden_die_fehlerzeile_ein(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/show.blade.php');
        $reissue = substr($quelle, strpos($quelle, 'model="reissueModalShow"'));
        $reissue = substr($reissue, 0, strpos($reissue, '</x-ui-modal>'));
        $vertrag = substr($quelle, strpos($quelle, 'model="vertragModalShow"'));
        $vertrag = substr($vertrag, 0, strpos($vertrag, '</x-ui-modal>'));

        $this->assertStringContainsString("employees._modal-fehler", $reissue);
        $this->assertStringContainsString("employees._vertrag-erstellen", $vertrag);
    }

    public function test_unterschriebene_zeile_traegt_den_code(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-060', 'MA'), ['status' => 'completed', 'signed_at' => '2025-01-02 12:00:00', 'completed_at' => '2025-01-02 12:00:00']);

        $zeile = $this->akte($ma)->signedContracts()[0];
        $this->assertSame($v->id, $zeile['id']);
        $this->assertSame('Arbeitsvertrag', $zeile['display_name']);
        $this->assertSame('AV-060', $zeile['code']);
    }

    private function renderPartial(string $name, array $daten): string
    {
        $cache = sys_get_temp_dir() . '/vertrag-akte-render-' . getmypid() . '-' . uniqid();
        @mkdir($cache, 0777, true);
        $viewsRoot = dirname(__DIR__, 2) . '/resources/views';
        $files = new Filesystem();
        $compiler = new BladeCompiler($files, $cache);
        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($compiler, $files));
        $finder = new FileViewFinder($files, [$viewsRoot]);
        $finder->addNamespace('recruiting', $viewsRoot);
        $factory = new ViewFactory($resolver, $finder, new Dispatcher(new Container()));

        try {
            return $factory->make('recruiting::livewire.employees.' . $name, $daten)->render();
        } finally {
            foreach (glob($cache . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($cache);
        }
    }
}
