<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Livewire\Dispo\Events\Show as EventShow;
use Platform\Recruiting\Livewire\Employees\Documents;
use Platform\Recruiting\Livewire\Employees\Show as AkteShow;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\DokumentAkteZeilen;

/**
 * Haken "WhatsApp-Hinweis senden" (Sebastian 16.10.2026: "beim Bereitstellen
 * unterscheiden koennen, ob es dazu eine WhatsApp gibt oder nicht"):
 * notify_mode = none heisst, weder Job noch hinweiseVersenden() noch
 * "Erneut senden" schreiben an. "WhatsApp jetzt senden" dreht bewusst um.
 */
final class DokumentWhatsAppHakenTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private string $pdfPfad;
    private object $bus;
    private object $sender;

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
        $this->sender = new class extends DokumentHinweisSender {
            /** @var list<int> */
            public array $angeschrieben = [];

            public function sende(RecEmployee $employee): string
            {
                $this->angeschrieben[] = (int) $employee->id;

                return self::STATUS_SENT;
            }
        };
        $container->instance(DokumentService::class, new DokumentService($this->speicher, $this->sender));
        $this->bus = new class {
            /** @var list<object> */
            public array $jobs = [];

            public function dispatch($job)
            {
                $this->jobs[] = $job;
            }
        };
        $container->instance(BusDispatcher::class, $this->bus);
        $this->pdfPfad = $this->root . '/upload.pdf';
        file_put_contents($this->pdfPfad, self::PDF);
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

    /** @return list<int> document_ids der eingestellten Hinweis-Jobs */
    private function hinweisJobs(): array
    {
        $ids = [];
        foreach ($this->bus->jobs as $job) {
            if ($job instanceof DokumentHinweiseVersenden) {
                $ids[] = (int) (new \ReflectionProperty($job, 'documentId'))->getValue($job);
            }
        }

        return $ids;
    }

    private function datei(): object
    {
        return new class($this->pdfPfad) {
            public function __construct(private string $pfad)
            {
            }

            public function getRealPath(): string
            {
                return $this->pfad;
            }

            public function getClientOriginalName(): string
            {
                return 'Hausordnung.pdf';
            }
        };
    }

    private function seite(): Documents
    {
        $s = new Documents();
        ComputedWiring::wire($s);

        return $s;
    }

    private function blade(string $pfad): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/' . $pfad);
    }

    private function notifiedCount(int $docId): int
    {
        return RecDocumentRecipient::query()->where('rec_document_id', $docId)->whereNotNull('notified_at')->count();
    }

    // ---- Service ----------------------------------------------------------

    public function test_info_ohne_whatsapp_schreibt_niemanden_an(): void
    {
        $ma = $this->anstellung(['phone' => '+491701111111']);
        $r = $this->service()->bereitstellenText(3, ['title' => 'News', 'notify_mode' => 'none'], 'Text', [(int) $ma->id], 7);

        $this->assertSame('none', (string) $r['dokument']->notify_mode);
        $this->assertFalse($r['versand_noetig']);
        $e = $this->service()->hinweiseVersenden($r['dokument']);
        $this->assertSame(0, $e['versucht']);
        $this->assertSame(0, $this->notifiedCount((int) $r['dokument']->id));
        $z = RecDocumentRecipient::query()->where('rec_document_id', $r['dokument']->id)->sole();
        $this->assertSame('ohne_whatsapp', $this->service()->erneutSenden($z));
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_pdf_ohne_whatsapp_schreibt_niemanden_an(): void
    {
        $ma = $this->anstellung(['phone' => '+491701111111']);
        $r = $this->service()->bereitstellen(3, ['title' => 'Belehrung', 'category' => 'instruction', 'action' => 'acknowledge', 'notify_mode' => 'none'], self::PDF, 'b.pdf', [(int) $ma->id], null, 7);

        $this->assertFalse($r['versand_noetig']);
        $this->assertSame(0, $this->service()->hinweiseVersenden($r['dokument'])['versucht']);
        $z = RecDocumentRecipient::query()->where('rec_document_id', $r['dokument']->id)->sole();
        $this->assertSame('ohne_whatsapp', $this->service()->erneutSenden($z));
        $this->assertSame([], $this->sender->angeschrieben);
        $this->assertSame(0, $this->notifiedCount((int) $r['dokument']->id));
    }

    public function test_default_bleibt_whatsapp(): void
    {
        $ma = $this->anstellung(['phone' => '+491701111111']);
        $pdf = $this->service()->bereitstellen(3, ['title' => 'B', 'category' => 'instruction', 'action' => 'acknowledge'], self::PDF, 'b.pdf', [(int) $ma->id], null, 7);
        $info = $this->service()->bereitstellenText(3, ['title' => 'N'], 'Text', [(int) $ma->id], 7);

        $this->assertSame('whatsapp', (string) $pdf['dokument']->notify_mode);
        $this->assertSame('whatsapp', (string) $info['dokument']->notify_mode);
        $this->assertTrue($pdf['versand_noetig']);
        $this->assertTrue($info['versand_noetig']);
        $this->assertSame(1, $this->service()->hinweiseVersenden($info['dokument'])['benachrichtigt']);
    }

    public function test_bestand_ohne_spaltenwert_gilt_als_whatsapp(): void
    {
        $ma = $this->anstellung();
        $d = RecDocument::create(['team_id' => 3, 'title' => 'Alt', 'category' => 'info', 'action' => 'none', 'content_markdown' => 'x', 'file_sha256' => hash('sha256', 'x')]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $ma->id]);

        $this->assertSame('whatsapp', (string) $d->fresh()->notify_mode, 'Spalten-Default: Bestand bleibt whatsapp');
        $this->assertSame(1, $this->service()->hinweiseVersenden($d->fresh())['versucht']);
    }

    public function test_ungueltiger_wert_wird_abgelehnt(): void
    {
        $ma = $this->anstellung();
        foreach ([
            fn () => $this->service()->bereitstellenText(3, ['title' => 'N', 'notify_mode' => 'sms'], 'Text', [(int) $ma->id], 7),
            fn () => $this->service()->bereitstellen(3, ['title' => 'B', 'category' => 'other', 'action' => 'none', 'notify_mode' => 'ja'], self::PDF, 'b.pdf', [(int) $ma->id], null, 7),
        ] as $aufruf) {
            try {
                $aufruf();
                $this->fail('ungueltiger notify_mode muss abgelehnt werden');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(0, RecDocument::query()->count(), 'nichts angelegt');
    }

    // ---- Job --------------------------------------------------------------

    public function test_job_stellt_sich_ohne_whatsapp_nicht_ein(): void
    {
        $ma = $this->anstellung();
        $ohne = $this->service()->bereitstellenText(3, ['title' => 'A', 'notify_mode' => 'none'], 'Text', [(int) $ma->id], 7);
        $mit = $this->service()->bereitstellenText(3, ['title' => 'B'], 'Text', [(int) $ma->id], 7);

        DokumentHinweiseVersenden::starten($ohne['dokument']);
        DokumentHinweiseVersenden::starten($mit['dokument']);

        $this->assertSame([(int) $mit['dokument']->id], $this->hinweisJobs());
    }

    // ---- "WhatsApp jetzt senden" -------------------------------------------

    public function test_whatsapp_jetzt_dreht_um_und_versendet(): void
    {
        $ma = $this->anstellung(['phone' => '+491701111111']);
        $r = $this->service()->bereitstellenText(3, ['title' => 'News', 'notify_mode' => 'none'], 'Text', [(int) $ma->id], 7);

        $this->assertTrue($this->service()->whatsappJetzt($r['dokument'], 7));
        $d = RecDocument::find($r['dokument']->id);
        $this->assertSame('whatsapp', (string) $d->notify_mode);
        $this->assertSame(1, $this->service()->hinweiseVersenden($d)['benachrichtigt']);
        $this->assertSame([(int) $ma->id], $this->sender->angeschrieben);
        $this->assertFalse($this->service()->whatsappJetzt($d, 7), 'zweiter Klick: schon umgedreht');
    }

    public function test_whatsapp_jetzt_aus_dem_ueberblick_startet_den_job(): void
    {
        $ma = $this->anstellung();
        $r = $this->service()->bereitstellenText(3, ['title' => 'News', 'notify_mode' => 'none'], 'Text', [(int) $ma->id], 7);
        $s = $this->seite();

        $s->whatsappJetztSenden((int) $r['dokument']->id);

        $this->assertSame('whatsapp', (string) RecDocument::find($r['dokument']->id)->notify_mode);
        $this->assertSame([(int) $r['dokument']->id], $this->hinweisJobs());
        $this->assertSame('WhatsApp für „News“ wird jetzt verschickt, läuft im Hintergrund.', $s->flash);
    }

    public function test_whatsapp_jetzt_greift_nicht_auf_fremdes_team(): void
    {
        $d = RecDocument::create(['team_id' => 9, 'title' => 'Fremd', 'category' => 'info', 'action' => 'none', 'content_markdown' => 'x', 'file_sha256' => 'x', 'notify_mode' => 'none']);
        $this->seite()->whatsappJetztSenden((int) $d->id);

        $this->assertSame('none', (string) $d->fresh()->notify_mode);
        $this->assertSame([], $this->hinweisJobs());
    }

    // ---- Formular Seite Dokumente -----------------------------------------

    public function test_haken_ist_vorausgewaehlt(): void
    {
        $this->assertTrue($this->seite()->whatsappHinweis);
        $this->assertTrue((new AkteShow())->dokumentWhatsApp);
        $this->assertTrue((new EventShow())->dokWhatsApp);
    }

    public function test_pdf_ohne_haken_legt_none_an_und_startet_keinen_job(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->datei = $this->datei();
        $s->titel = 'Belehrung';
        $s->kategorie = 'instruction';
        $s->aktion = 'acknowledge';
        $s->whatsappHinweis = false;
        $s->toggle((int) $ma->id);
        $s->bereitstellenPruefen();
        $s->bereitstellen();

        $d = RecDocument::query()->sole();
        $this->assertSame('none', (string) $d->notify_mode);
        $this->assertSame([], $this->hinweisJobs());
        $this->assertSame('An 1 Personen bereitgestellt, ohne WhatsApp.', $s->flash);
        $this->assertTrue($s->whatsappHinweis, 'nach dem Bereitstellen wieder vorausgewaehlt');
    }

    public function test_info_ohne_haken_legt_none_an_und_startet_keinen_job(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'News';
        $s->infoMarkdown = 'Text';
        $s->whatsappHinweis = false;
        $s->toggle((int) $ma->id);
        $s->bereitstellenPruefen();
        $s->bereitstellen();

        $d = RecDocument::query()->sole();
        $this->assertSame('none', (string) $d->notify_mode);
        $this->assertSame([], $this->hinweisJobs());
        $this->assertSame('Info „News“ an 1 Empfänger bereitgestellt, ohne WhatsApp.', $s->flash);
    }

    public function test_pdf_mit_haken_startet_den_job_wie_bisher(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->datei = $this->datei();
        $s->titel = 'Belehrung';
        $s->kategorie = 'instruction';
        $s->aktion = 'acknowledge';
        $s->toggle((int) $ma->id);
        $s->bereitstellenPruefen();
        $s->bereitstellen();

        $d = RecDocument::query()->sole();
        $this->assertSame('whatsapp', (string) $d->notify_mode);
        $this->assertSame([(int) $d->id], $this->hinweisJobs());
    }

    public function test_formular_blade_hat_haken_und_ohne_whatsapp_rueckfrage(): void
    {
        $b = $this->blade('employees/documents.blade.php');
        $this->assertStringContainsString('wire:model.live="whatsappHinweis"', $b);
        $this->assertStringContainsString('WhatsApp-Hinweis senden', $b);
        $this->assertGreaterThanOrEqual(2, substr_count($b, 'ohne WhatsApp'), 'Rueckfrage (PDF + Info) sagt "ohne WhatsApp"');
        $this->assertStringContainsString('WhatsApp jetzt senden', $b);
        $this->assertStringContainsString('whatsappJetztSenden(', $b);
    }

    // ---- Ueberblick + Akte-Zeilen -----------------------------------------

    public function test_ueberblick_zeigt_ohne_whatsapp_statt_benachrichtigt(): void
    {
        $ma = $this->anstellung();
        $r = $this->service()->bereitstellen(3, ['title' => 'B', 'category' => 'instruction', 'action' => 'acknowledge', 'notify_mode' => 'none'], self::PDF, 'b.pdf', [(int) $ma->id], null, 7);
        $s = $this->seite();
        $s->zeige = 'alle';
        $s->aufgeklappt = (int) $r['dokument']->id;

        $dok = $s->dokumente()[0];
        $this->assertTrue($dok['ohne_whatsapp']);
        $this->assertFalse($dok['braucht_hinweis'], 'Fortschritt "benachrichtigt" entfaellt');
        $this->assertSame(0, $dok['ausstehend'], 'kein "Versand erneut anstossen"');
        $this->assertFalse($dok['versand_laeuft']);
        $this->assertTrue($dok['kann_whatsapp_jetzt']);
        $this->assertSame('ohne WhatsApp', $dok['empfaenger'][0]['versand_text']);
        $this->assertFalse($dok['empfaenger'][0]['kann_erneut_senden']);
    }

    public function test_nur_ablegen_bietet_kein_whatsapp_jetzt(): void
    {
        $ma = $this->anstellung();
        $this->service()->bereitstellen(3, ['title' => 'Lohn', 'category' => 'payslip', 'action' => 'none', 'notify_mode' => 'none'], self::PDF, 'l.pdf', [(int) $ma->id], null, 7);
        $s = $this->seite();
        $s->zeige = 'alle';

        $this->assertFalse($s->dokumente()[0]['kann_whatsapp_jetzt'], 'nur ablegen: es gibt nichts zu senden');
    }

    public function test_akte_zeile_ohne_whatsapp(): void
    {
        $ma = $this->anstellung();
        $r = $this->service()->bereitstellenText(3, ['title' => 'N', 'notify_mode' => 'none'], 'Text', [(int) $ma->id], 7);
        $z = RecDocumentRecipient::query()->where('rec_document_id', $r['dokument']->id)->sole();
        $zeile = DokumentAkteZeilen::fuer([$z->setRelation('document', RecDocument::find($r['dokument']->id))])[0];

        $this->assertSame('ohne WhatsApp', $zeile['versand_text']);
        $this->assertFalse($zeile['kann_erneut_senden']);
        $this->assertFalse($zeile['versand_laeuft']);
    }

    // ---- Akte und Veranstaltung --------------------------------------------

    public function test_akte_ohne_haken_legt_none_an_und_startet_keinen_job(): void
    {
        $ma = $this->anstellung();
        $s = new AkteShow();
        ComputedWiring::wire($s);
        $s->employeeId = (int) $ma->id;
        $s->dokumentDatei = $this->datei();
        $s->dokumentTitel = 'Belehrung';
        $s->dokumentKategorie = 'instruction';
        $s->dokumentAktion = 'acknowledge';
        $s->dokumentWhatsApp = false;

        $s->dokumentBereitstellen();

        $this->assertNull($s->flashError);
        $d = RecDocument::query()->sole();
        $this->assertSame('none', (string) $d->notify_mode);
        $this->assertSame([], $this->hinweisJobs());
        $this->assertSame('Dokument bereitgestellt, ohne WhatsApp.', $s->flash);
        $this->assertTrue($s->dokumentWhatsApp);
    }

    public function test_veranstaltung_ohne_haken_legt_none_an_und_startet_keinen_job(): void
    {
        $ma = $this->anstellung();
        DB::table('rec_dispo_events')->insert(['id' => 55, 'uuid' => 'ev-55', 'einsatz_ref' => 'E55', 'name' => 'Messe', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rec_dispo_assignments')->insert(['uuid' => 'as-1', 'ds_ref' => '1', 'pnr_raw' => 'RG1', 'datum' => '2026-12-31', 'rec_dispo_event_id' => 55, 'rec_employee_id' => $ma->id, 'status_id' => \Platform\Recruiting\Models\RecDispoAssignment::STATUS_AUFTRAG, 'created_at' => now(), 'updated_at' => now()]);
        $s = new EventShow();
        ComputedWiring::wire($s);
        $s->eventId = 55;
        $s->dokDatei = $this->datei();
        $s->dokTitel = 'Belehrung';
        $s->dokWhatsApp = false;

        $s->dokumentBereitstellen();

        $d = RecDocument::query()->sole();
        $this->assertSame('none', (string) $d->notify_mode);
        $this->assertSame([], $this->hinweisJobs());
        $this->assertSame('An 1 Eingebuchte bereitgestellt, ohne WhatsApp — Stand auf der Dokumente-Seite.', $s->dokumentFlash);
    }

    public function test_akte_und_veranstaltung_haben_den_haken(): void
    {
        $akte = $this->blade('employees/show.blade.php');
        $this->assertStringContainsString('wire:model="dokumentWhatsApp"', $akte);
        $this->assertStringContainsString('WhatsApp-Hinweis senden', $akte);
        $ev = $this->blade('dispo/events/show.blade.php');
        $this->assertStringContainsString('wire:model="dokWhatsApp"', $ev);
        $this->assertStringContainsString('WhatsApp-Hinweis senden', $ev);
    }
}
