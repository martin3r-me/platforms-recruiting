<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Livewire\Employees\Documents;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\DokumentAutoZustellung;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\DokumentVerlauf;

/**
 * Verlauf je Dokument als Nachweis (Sebastian 16.10.2026: "sicherstellen,
 * dass alles geloggt wird — wer was wie gesendet hat, von welchem User").
 * Jede HR-Handlung und jede Automatik schreibt genau eine Zeile in
 * rec_document_events; ein Schreibfehler kippt die Handlung nie.
 */
final class DokumentVerlaufTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private object $bus;
    private object $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $container = Container::getInstance();
        $container->singleton(\Livewire\EventBus::class);
        $container->singleton(\Livewire\Mechanisms\DataStore::class);
        $container->instance(AuthFactory::class, new class implements AuthFactory {
            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function user(): object
            {
                return (object) ['id' => 7, 'currentTeam' => (object) ['id' => 3]];
            }

            public function id(): int
            {
                return 7;
            }
        });
        $sender = new class extends DokumentHinweisSender {
            public function sende(RecEmployee $employee): string
            {
                return $employee->first_name === 'Fehl' ? 'no_phone' : self::STATUS_SENT;
            }
        };
        $container->instance(DokumentService::class, new DokumentService($this->speicher, $sender));
        $this->bus = new class {
            /** @var list<object> */
            public array $jobs = [];

            public function dispatch($job)
            {
                $this->jobs[] = $job;
            }
        };
        $container->instance(BusDispatcher::class, $this->bus);
        // Log-Attrappe, die mitschreibt (Facade cacht — clearResolvedInstance noetig).
        $this->logs = new class {
            /** @var list<array{0:string,1:string}> */
            public array $zeilen = [];

            public function __call($m, $a)
            {
                $this->zeilen[] = [$m, (string) ($a[0] ?? '')];
            }
        };
        $container->instance('log', $this->logs);
        Facade::clearResolvedInstance('log');
        DB::table('users')->insert(['id' => 7, 'name' => 'Sebastian Haustein']);
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([AuthFactory::class, DokumentService::class, BusDispatcher::class, \Livewire\EventBus::class, \Livewire\Mechanisms\DataStore::class] as $n) {
            $c->forgetInstance($n);
        }
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function service(): DokumentService
    {
        return app(DokumentService::class);
    }

    /** @return list<object> */
    private function zeilen(?string $aktion = null): array
    {
        $q = DB::table('rec_document_events')->orderBy('id');
        if ($aktion !== null) {
            $q->where('aktion', $aktion);
        }

        return $q->get()->all();
    }

    private function details(object $zeile): array
    {
        return json_decode((string) $zeile->details, true) ?? [];
    }

    private function seite(): Documents
    {
        $s = new Documents();
        ComputedWiring::wire($s);

        return $s;
    }

    private function pdfBereitstellen(array $ids, array $daten = []): RecDocument
    {
        return $this->service()->bereitstellen(3, $daten + ['title' => 'Belehrung', 'category' => 'instruction', 'action' => 'acknowledge'], self::PDF, 'b.pdf', $ids, null, 7)['dokument'];
    }

    // ---- Schreiben ----------------------------------------------------------

    public function test_bereitstellen_pdf_schreibt_eine_zeile_mit_user_und_details(): void
    {
        $a = $this->anstellung();
        $b = $this->anstellung(['first_name' => 'Ben']);
        $d = $this->pdfBereitstellen([(int) $a->id, (int) $b->id], ['einstieg' => 'veranstaltung']);

        $z = $this->zeilen();
        $this->assertCount(1, $z);
        $this->assertSame(DokumentVerlauf::BEREITGESTELLT, $z[0]->aktion);
        $this->assertSame(7, (int) $z[0]->user_id);
        $this->assertSame(3, (int) $z[0]->team_id);
        $this->assertSame((int) $d->id, (int) $z[0]->rec_document_id);
        $this->assertNull($z[0]->rec_document_recipient_id);
        $this->assertSame(['empfaenger' => 2, 'notify_mode' => 'whatsapp', 'auto_neue_mitarbeiter' => false, 'art' => 'pdf', 'einstieg' => 'veranstaltung'], $this->details($z[0]));
    }

    public function test_bereitstellen_info_schreibt_art_info_und_einstieg_default_dokumente(): void
    {
        $a = $this->anstellung();
        $d = $this->service()->bereitstellenText(3, ['title' => 'News', 'notify_mode' => 'none', 'auto_neue_mitarbeiter' => true], 'Text', [(int) $a->id], 7)['dokument'];

        $z = $this->zeilen();
        $this->assertCount(1, $z);
        $this->assertSame((int) $d->id, (int) $z[0]->rec_document_id);
        $this->assertSame(['empfaenger' => 1, 'notify_mode' => 'none', 'auto_neue_mitarbeiter' => true, 'art' => 'info', 'einstieg' => 'dokumente'], $this->details($z[0]));
    }

    public function test_job_schreibt_versand_gestartet_ohne_user(): void
    {
        $a = $this->anstellung();
        $f = $this->anstellung(['first_name' => 'Fehl']);
        $d = $this->pdfBereitstellen([(int) $a->id, (int) $f->id]);

        (new DokumentHinweiseVersenden((int) $d->id))->handle();

        $z = $this->zeilen(DokumentVerlauf::VERSAND_GESTARTET);
        $this->assertCount(1, $z);
        $this->assertNull($z[0]->user_id);
        $this->assertSame(['versucht' => 2, 'benachrichtigt' => 1, 'fehler' => ['no_phone' => 1]], $this->details($z[0]));
    }

    public function test_whatsapp_jetzt_schreibt_mit_user(): void
    {
        $a = $this->anstellung();
        $d = $this->pdfBereitstellen([(int) $a->id], ['notify_mode' => 'none']);
        $this->seite()->whatsappJetztSenden((int) $d->id);

        $z = $this->zeilen(DokumentVerlauf::WHATSAPP_JETZT);
        $this->assertCount(1, $z);
        $this->assertSame(7, (int) $z[0]->user_id);
        $this->service()->whatsappJetzt($d, 7);
        $this->assertCount(1, $this->zeilen(DokumentVerlauf::WHATSAPP_JETZT), 'nichts umgedreht = nichts geloggt');
    }

    public function test_versand_anstossen_schreibt_mit_user_vor_dem_job(): void
    {
        $a = $this->anstellung();
        $d = $this->pdfBereitstellen([(int) $a->id]);

        $this->seite()->versandAnstossen((int) $d->id);

        $z = $this->zeilen(DokumentVerlauf::VERSAND_ANGESTOSSEN);
        $this->assertCount(1, $z);
        $this->assertSame(7, (int) $z[0]->user_id);
        $this->assertNull($z[0]->rec_document_recipient_id);
        $this->assertSame(['quelle' => 'dokumente'], $this->details($z[0]));
        $this->assertSame('Versand angestoßen', DokumentVerlauf::label(DokumentVerlauf::VERSAND_ANGESTOSSEN));
        $this->assertCount(1, $this->bus->jobs, 'Job laeuft wie bisher');
    }

    public function test_erneut_senden_schreibt_je_empfaenger_mit_status(): void
    {
        $a = $this->anstellung();
        $d = $this->pdfBereitstellen([(int) $a->id]);
        $r = RecDocumentRecipient::query()->where('rec_document_id', $d->id)->sole();

        $this->seite()->erneutSenden((int) $r->id);

        $z = $this->zeilen(DokumentVerlauf::ERNEUT_GESENDET);
        $this->assertCount(1, $z);
        $this->assertSame((int) $r->id, (int) $z[0]->rec_document_recipient_id);
        $this->assertSame(7, (int) $z[0]->user_id);
        $this->assertSame(['status' => 'sent'], $this->details($z[0]));
    }

    public function test_zurueckziehen_einzeln_und_ganz(): void
    {
        $a = $this->anstellung();
        $b = $this->anstellung(['first_name' => 'Ben']);
        $d = $this->pdfBereitstellen([(int) $a->id, (int) $b->id]);
        $r = RecDocumentRecipient::query()->where('rec_document_id', $d->id)->orderBy('id')->first();
        $s = $this->seite();

        $s->zurueckziehen((int) $r->id);
        $einzeln = $this->zeilen(DokumentVerlauf::ZURUECKGEZOGEN);
        $this->assertCount(1, $einzeln);
        $this->assertSame((int) $r->id, (int) $einzeln[0]->rec_document_recipient_id);
        $this->assertSame(7, (int) $einzeln[0]->user_id);

        $s->dokumentZurueckziehen((int) $d->id);
        $alle = $this->zeilen(DokumentVerlauf::ZURUECKGEZOGEN);
        $this->assertCount(2, $alle);
        $this->assertNull($alle[1]->rec_document_recipient_id);
        $this->assertSame(['anzahl' => 1, 'geloescht' => true], $this->details($alle[1]));
        $this->assertSame([], $this->zeilen(DokumentVerlauf::AUTOMATIK_BEENDET), 'PDF ohne Haken: kein Automatik-Eintrag');
    }

    public function test_automatik_beenden_schreibt_mit_user(): void
    {
        $d = $this->service()->bereitstellenText(3, ['title' => 'W', 'auto_neue_mitarbeiter' => true], 'Text', [], 7)['dokument'];
        $this->seite()->automatikBeenden((int) $d->id);

        $z = $this->zeilen(DokumentVerlauf::AUTOMATIK_BEENDET);
        $this->assertCount(1, $z);
        $this->assertSame(7, (int) $z[0]->user_id);
    }

    public function test_auto_zustellung_schreibt_ohne_user_mit_empfaenger(): void
    {
        $d = $this->service()->bereitstellenText(3, ['title' => 'W', 'auto_neue_mitarbeiter' => true], 'Text', [], 7)['dokument'];
        DB::table('rec_documents')->where('id', $d->id)->update(['published_at' => '2026-10-01 00:00:00']);
        $neu = $this->anstellung(['rec_applicant_id' => 501]);
        DB::table('rec_employees')->where('id', $neu->id)->update(['created_at' => '2026-10-02 00:00:00']);

        (new DokumentAutoZustellung())->fuerErstesOeffnen($neu->fresh());

        $z = $this->zeilen(DokumentVerlauf::AUTO_ZUGESTELLT);
        $this->assertCount(1, $z);
        $this->assertNull($z[0]->user_id);
        $r = RecDocumentRecipient::query()->where('rec_document_id', $d->id)->sole();
        $this->assertSame((int) $r->id, (int) $z[0]->rec_document_recipient_id);
        $this->assertSame(['employee_id' => (int) $neu->id], $this->details($z[0]));
    }

    public function test_schreibfehler_kippt_die_aktion_nicht(): void
    {
        $a = $this->anstellung();
        $d = $this->pdfBereitstellen([(int) $a->id]);
        Schema::drop('rec_document_events');

        $e = $this->service()->dokumentZurueckziehen($d);

        $this->assertSame(1, $e['zurueckgezogen']);
        $this->assertNotNull(RecDocumentRecipient::query()->where('rec_document_id', $d->id)->value('withdrawn_at'));
        $warnungen = array_filter($this->logs->zeilen, fn ($z) => $z[0] === 'warning' && $z[1] === 'recruiting.dokumente.verlauf_fehlgeschlagen');
        $this->assertCount(1, $warnungen);

        $b = $this->anstellung(['first_name' => 'Ben']);
        $neu = $this->pdfBereitstellen([(int) $b->id]);
        $this->assertNotNull($neu->id, 'auch Bereitstellen laeuft ohne Verlaufstabelle durch');
    }

    // ---- Lesen + Anzeige ----------------------------------------------------

    public function test_fuer_liefert_labels_namen_und_neueste_oben(): void
    {
        $a = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'Test']);
        $d = $this->pdfBereitstellen([(int) $a->id]);
        (new DokumentHinweiseVersenden((int) $d->id))->handle();
        DB::table('rec_document_events')->where('aktion', DokumentVerlauf::BEREITGESTELLT)->update(['created_at' => '2026-10-16 09:12:00']);
        DB::table('rec_document_events')->where('aktion', DokumentVerlauf::VERSAND_GESTARTET)->update(['created_at' => '2026-10-16 09:13:00']);

        $v = DokumentVerlauf::fuer((int) $d->id);

        $this->assertCount(2, $v);
        $this->assertSame(DokumentVerlauf::VERSAND_GESTARTET, $v[0]['aktion'], 'neueste oben');
        $this->assertSame('System', $v[0]['user']);
        $this->assertSame('16.10.2026 09:12', $v[1]['zeit']);
        $this->assertSame('Sebastian Haustein', $v[1]['user']);
        $this->assertSame('bereitgestellt', $v[1]['aktion_label']);
        $this->assertSame('bereitgestellt an 1 Empfänger (PDF, WhatsApp ja, von: Dokumente)', $v[1]['text']);
        $this->assertNull($v[1]['recipient_id']);
        $this->assertSame(1, $v[1]['details']['empfaenger']);
    }

    public function test_fuer_nennt_den_empfaenger_bei_einzelaktionen(): void
    {
        $a = $this->anstellung(['first_name' => 'Anna', 'last_name' => 'Test']);
        $d = $this->pdfBereitstellen([(int) $a->id], ['notify_mode' => 'none']);
        $r = RecDocumentRecipient::query()->where('rec_document_id', $d->id)->sole();
        $this->seite()->zurueckziehen((int) $r->id);

        $v = DokumentVerlauf::fuer((int) $d->id);
        $this->assertSame('zurückgezogen für Anna Test', $v[0]['text']);
        $this->assertSame((int) $r->id, $v[0]['recipient_id']);
        $this->assertSame('bereitgestellt an 1 Empfänger (PDF, WhatsApp nein, von: Dokumente)', $v[1]['text']);
    }

    public function test_unbekannter_benutzer_heisst_system(): void
    {
        $d = RecDocument::create(['team_id' => 3, 'title' => 'X', 'category' => 'info', 'action' => 'none', 'content_markdown' => 'x', 'file_sha256' => 'x']);
        DokumentVerlauf::schreibe(3, (int) $d->id, null, DokumentVerlauf::AUTOMATIK_BEENDET, 999);

        $this->assertSame('System', DokumentVerlauf::fuer((int) $d->id)[0]['user']);
    }

    public function test_ueberblick_zeigt_den_verlauf_des_aufgeklappten_dokuments(): void
    {
        $a = $this->anstellung();
        $d = $this->pdfBereitstellen([(int) $a->id]);
        $s = $this->seite();
        $s->zeige = 'alle';

        $this->assertSame([], $s->dokumente()[0]['verlauf'], 'zugeklappt: kein Verlauf geladen');
        $s->aufgeklappt = (int) $d->id;
        unset($s->dokumente);
        $verlauf = $s->dokumente()[0]['verlauf'];
        $this->assertCount(1, $verlauf);
        $this->assertSame('Sebastian Haustein', $verlauf[0]['user']);

        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/documents.blade.php');
        $this->assertStringContainsString('>Verlauf<', $blade);
        $this->assertStringContainsString("\$dok['verlauf']", $blade);
    }
}
