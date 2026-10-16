<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Jobs\DokumentHinweiseVersenden;
use Platform\Recruiting\Livewire\Employees\Documents;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\InfoText;

/**
 * Seite Dokumente, Umschalter PDF | Info (Spec 2026-10-16 §3.2): eine Info
 * ist Text statt PDF, braucht keine Datei, darf ohne Empfaenger raus, wenn
 * der Haken "automatisch an neue Mitarbeiter" sitzt, und die Vorschau nutzt
 * denselben Renderer wie das Portal. Der PDF-Weg bleibt unveraendert.
 */
final class DokumentInfoFormularTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private string $pdfPfad;

    /** Bus-Attrappe: merkt sich die eingestellten Jobs statt sie zu queuen. */
    private object $bus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $container = Container::getInstance();
        $container->singleton(\Livewire\EventBus::class);
        // dispatch() legt Browser-Events im DataStore ab — ohne Provider ist der kein Singleton.
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
        $container->instance(DokumentService::class, new DokumentService($this->speicher));
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
        $c->forgetInstance(AuthFactory::class);
        $c->forgetInstance(DokumentService::class);
        $c->forgetInstance(BusDispatcher::class);
        $c->forgetInstance(\Livewire\EventBus::class);
        $c->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function seite(): Documents
    {
        $s = new Documents();
        ComputedWiring::wire($s);

        return $s;
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

    private function blade(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/documents.blade.php');
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

    /** @return list<string> Namen der Browser-Events, die die Komponente ausgeloest hat */
    private function events(Documents $s): array
    {
        return array_map(fn ($e) => $e->serialize()['name'], \Livewire\store($s)->get('dispatched', []));
    }

    public function test_startet_im_pdf_weg(): void
    {
        $s = $this->seite();
        $this->assertSame('pdf', $s->art);
        $this->assertSame('', $s->infoMarkdown);
        $this->assertFalse($s->autoNeue);
        $this->assertFalse($s->vorschauOffen);
    }

    public function test_info_ohne_datei_wird_bereitgestellt_und_alles_geleert(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'Willkommen';
        $s->infoMarkdown = "# Hallo\n\nSchoen, dass du da bist.";
        $s->autoNeue = true;
        $s->toggle((int) $ma->id);

        $s->bereitstellenPruefen();
        $this->assertNull($s->flashError);
        $this->assertTrue($s->bestaetigungOffen);
        $s->bereitstellen();

        $this->assertNull($s->flashError);
        $d = RecDocument::query()->where('category', 'info')->first();
        $this->assertNotNull($d);
        $this->assertTrue((bool) $d->auto_neue_mitarbeiter);
        $this->assertSame('none', (string) $d->action);
        $this->assertSame("# Hallo\n\nSchoen, dass du da bist.", (string) $d->content_markdown);
        $this->assertSame(1, RecDocumentRecipient::query()->where('rec_document_id', $d->id)->count());

        $this->assertSame([], $s->gewaehlteIds());
        $this->assertSame('', $s->infoMarkdown);
        $this->assertSame('', $s->titel);
        $this->assertFalse($s->autoNeue);
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('info', $s->art, 'der Umschalter bleibt stehen — HR schreibt vielleicht gleich die naechste');
        $this->assertSame('Info „Willkommen“ bereitgestellt: WhatsApp geht an 1 Empfänger; zusätzlich automatisch an neue Mitarbeiter.', $s->flash);
        $this->assertSame([(int) $d->id], $this->hinweisJobs(), 'Info mit Empfaengern: Portal-WhatsApp ueber den Job (Spec §7)');
        $this->assertContains('info-bereitgestellt', $this->events($s), 'der Editor im Browser wird geleert');
    }

    public function test_info_nur_mit_haken_ohne_empfaenger(): void
    {
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'Neu bei uns';
        $s->infoMarkdown = 'Text';

        $s->bereitstellenPruefen();
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('Bitte mindestens einen Empfänger wählen oder „automatisch an neue Mitarbeiter“ anhaken.', $s->flashError);

        $s->autoNeue = true;
        $s->bereitstellenPruefen();
        $this->assertNull($s->flashError);
        $this->assertTrue($s->bestaetigungOffen);
        $s->bereitstellen();

        $d = RecDocument::query()->where('category', 'info')->first();
        $this->assertNotNull($d);
        $this->assertSame(0, RecDocumentRecipient::query()->where('rec_document_id', $d->id)->count());
        $this->assertSame('Info „Neu bei uns“ wird automatisch an neue Mitarbeiter zugestellt (beim ersten Öffnen des Portals).', $s->flash);
        $this->assertSame([], $this->hinweisJobs(), 'nur Automatik: kein Versand-Job');
    }

    public function test_info_ohne_text_wird_vor_der_rueckfrage_abgefangen(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'X';
        $s->infoMarkdown = "  \n ";
        $s->toggle((int) $ma->id);

        $s->bereitstellenPruefen();
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('Bitte einen Text schreiben.', $s->flashError);
    }

    public function test_info_ohne_titel_wird_abgefangen_und_braucht_keine_datei(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->art = 'info';
        $s->infoMarkdown = 'Text';
        $s->toggle((int) $ma->id);

        $s->bereitstellenPruefen();
        $this->assertFalse($s->bestaetigungOffen);
        $this->assertSame('Bitte einen Titel angeben.', $s->flashError, 'nicht "Bitte eine PDF auswählen."');
    }

    public function test_info_ignoriert_eine_liegengebliebene_pdf(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->datei = $this->datei();
        $s->kategorie = 'contract';
        $s->aktion = 'sign';
        $s->art = 'info';
        $s->titel = 'Info';
        $s->infoMarkdown = 'Text';
        $s->toggle((int) $ma->id);

        $s->bereitstellenPruefen();
        $s->bereitstellen();

        $d = RecDocument::query()->first();
        $this->assertSame('info', (string) $d->category);
        $this->assertSame('none', (string) $d->action);
        $this->assertSame(hash('sha256', 'Text'), (string) $d->file_sha256, 'Pruefsumme ueber den Text, nicht ueber die PDF');
        $this->assertNotNull($s->datei, 'die Datei des PDF-Wegs bleibt unberuehrt');
    }

    public function test_bereitstellen_ohne_rueckfrage_tut_auch_bei_info_nichts(): void
    {
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'X';
        $s->infoMarkdown = 'Text';
        $s->autoNeue = true;

        $s->bereitstellen();

        $this->assertSame(0, RecDocument::query()->count());
        $this->assertSame('Text', $s->infoMarkdown);
    }

    public function test_umschalten_schliesst_rueckfrage_und_fehler(): void
    {
        $s = $this->seite();
        $s->bestaetigungOffen = true;
        $s->flashError = 'alt';
        $s->art = 'info';
        $s->updatedArt();

        $this->assertFalse($s->bestaetigungOffen);
        $this->assertNull($s->flashError);
    }

    public function test_unbekannte_art_faellt_auf_pdf(): void
    {
        $s = $this->seite();
        $s->art = 'quatsch';
        $s->updatedArt();
        $this->assertSame('pdf', $s->art);
    }

    public function test_pdf_weg_unveraendert(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->toggle((int) $ma->id);
        $s->titel = 'Hausordnung';

        $s->bereitstellenPruefen();
        $this->assertSame('Bitte eine PDF auswählen.', $s->flashError);

        $s->datei = $this->datei();
        $s->infoMarkdown = 'bleibt liegen';
        $s->bereitstellenPruefen();
        $this->assertTrue($s->bestaetigungOffen);
        $s->bereitstellen();

        $d = RecDocument::query()->first();
        $this->assertSame('other', (string) $d->category);
        $this->assertNull($d->content_markdown);
        $this->assertSame('An 1 Personen abgelegt.', $s->flash);
        $this->assertNotContains('info-bereitgestellt', $this->events($s));
    }

    public function test_pdf_weg_bietet_die_kategorie_info_nicht_an(): void
    {
        $blade = $this->blade();
        // Filter liegt jetzt an EINER Stelle (Schlussreview M-2), belegt in DokumentKategorieTest.
        $this->assertStringContainsString('$kategorien = \\Platform\\Recruiting\\Support\\DokumentKategorie::labelsFuerDatei();', $blade);
        $this->assertArrayNotHasKey('info', \Platform\Recruiting\Support\DokumentKategorie::labelsFuerDatei());
    }

    public function test_vorschau_rendert_mit_demselben_renderer(): void
    {
        $s = $this->seite();
        $s->infoMarkdown = "# T\n\nHallo <script>alert(1)</script> [Link](https://example.org)";

        $this->assertSame(InfoText::html($s->infoMarkdown), $s->vorschauHtml());
        $this->assertStringNotContainsString('<script', $s->vorschauHtml());
        $this->assertStringContainsString('<h2>T</h2>', $s->vorschauHtml());
    }

    public function test_blade_editor_haken_vorschau_und_rueckfrage(): void
    {
        $blade = $this->blade();
        $this->assertStringContainsString('wire:model.live="art"', $blade);
        $this->assertStringContainsString('wire:ignore', $blade);
        $this->assertStringContainsString('window.ToastUIEditor', $blade);
        $this->assertStringContainsString("toastui:ready", $blade);
        $this->assertStringContainsString("hideModeSwitch: true", $blade);
        $this->assertStringContainsString("[['heading', 'bold', 'italic'], ['ul', 'ol'], ['link']]", $blade);
        $this->assertStringContainsString('wire:model.blur="infoMarkdown"', $blade, 'Rueckfall-Textfeld ohne Editor');
        $this->assertStringContainsString('x-on:info-bereitgestellt.window', $blade);
        $this->assertStringContainsString('wire:model.live="autoNeue"', $blade);
        $this->assertStringContainsString('Automatisch an neue Mitarbeiter (beim ersten Öffnen des Portals)', $blade);
        $this->assertStringContainsString('<x-ui-modal wire:model="vorschauOffen"', $blade);
        $this->assertStringContainsString('{!! $this->vorschauHtml !!}', $blade);
        $this->assertStringContainsString('automatisch an alle Neuen ab heute', $blade);
    }

    public function test_info_nennt_empfaenger_im_alten_portal(): void
    {
        $neu = $this->anstellung();
        $alt = $this->anstellung(['first_name' => 'Alt', 'portal_v2_since' => null]);
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'News';
        $s->infoMarkdown = 'Text';
        $s->toggle((int) $neu->id);
        $s->toggle((int) $alt->id);

        $s->bereitstellenPruefen();
        $s->bereitstellen();

        $this->assertSame('Info „News“ an 2 Empfänger bereitgestellt: WhatsApp geht an 1 Empfänger; 1 im alten Portal: keine Nachricht, sichtbar nach der Umstellung.', $s->flash);
        $this->assertCount(1, $this->hinweisJobs());
    }

    public function test_ueberblick_zeigt_bei_info_keinen_pdf_knopf_und_rechnet_den_versand(): void
    {
        $ma = $this->anstellung();
        $s = $this->seite();
        $s->art = 'info';
        $s->titel = 'News';
        $s->infoMarkdown = 'Text';
        $s->toggle((int) $ma->id);
        $s->bereitstellenPruefen();
        $s->bereitstellen();
        $s->zeige = 'alle';

        $dok = $s->dokumente()[0];
        $this->assertTrue($dok['is_info']);
        $this->assertTrue($dok['braucht_hinweis']);
        $this->assertSame(1, $dok['ausstehend'], 'unversuchte Info-Zeile zaehlt als ausstehend');
        $this->assertStringContainsString("@if (!\$dok['is_info'])", $this->blade());
    }

    public function test_rueckfrage_bei_info_sagt_whatsapp(): void
    {
        $blade = $this->blade();
        $this->assertStringContainsString('WhatsApp geht an {{ $mitWhatsApp }} Empfänger.', $blade);
        $this->assertStringContainsString('{{ $ohnePortal }} im alten Portal: keine Nachricht, sichtbar nach der Umstellung.', $blade);
        $this->assertStringNotContainsString('davon {{ $ohnePortal }} im alten Portal ohne Nachricht', $blade, 'Fund M2: kein "geht an 2, davon 1 ohne"');
    }
}
