<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\DokumentUnterschrift;

/**
 * Das Portal als Huelle um DokumentLeser/DokumentUnterschrift (Spec §5.3):
 * jede Aktion prueft die Empfaenger-ID gegen den Scope des angemeldeten
 * Menschen, nie gegen eine ID aus dem Zustand. Muster PortalShellDokumenteTest:
 * `new PortalShell()` + ReflectionMethod, Router fuer route().
 */
final class PortalShellMitarbeiterDokumenteTest extends TestCase
{
    use DokumenteHarness;

    private const PDF = "%PDF-1.4\nHallo";
    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $container = Container::getInstance();
        $container->instance(DokumentUnterschrift::class, new DokumentUnterschrift($this->speicher));
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $container->instance('url', new UrlGenerator($router->getRoutes(), Request::create('https://mitarbeiter.rheingedeck.de')));
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        foreach ([DokumentUnterschrift::class, 'router', 'url'] as $n) {
            $c->forgetInstance($n);
        }
        $this->harnessAbbauen();
        parent::tearDown();
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';

        return $shell;
    }

    private function zustellung(RecEmployee $ma, string $aktion = 'sign'): RecDocumentRecipient
    {
        $ablage = $this->speicher->ablegen(3, 'p-' . uniqid(), self::PDF);
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => $aktion,
            'disk' => $ablage['disk'], 'stored_path' => $ablage['stored_path'], 'original_filename' => 'h.pdf',
            'file_sha256' => $ablage['sha256'], 'file_size' => $ablage['size'],
        ]);

        return RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $ma->id]);
    }

    private function privat(object $o, string $m, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($o, $m);
        $ref->setAccessible(true);

        return $ref->invoke($o, ...$args);
    }

    public function test_oeffnen_setzt_gesehen_und_zeigt_das_blatt_mit_download_url(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma);
        $shell = $this->shell($ma);

        $shell->oeffneDokument($z->id);

        $this->assertSame($z->id, $shell->dokumentId);
        $this->assertNotNull($z->fresh()->first_viewed_at);
        $blatt = $this->privat($shell, 'dokumentBlatt', [$ma]);
        $this->assertSame('Hausordnung', $blatt['title']);
        $this->assertSame('sign', $blatt['action']);
        $this->assertTrue($blatt['gesehen']);
        $this->assertFalse($blatt['erledigt']);
        $this->assertSame('https://mitarbeiter.rheingedeck.de/recruiting/mitarbeiter/dokument/' . $z->uuid, $blatt['download_url']);
    }

    public function test_fremde_zustellung_oeffnet_nichts(): void
    {
        $ma = $this->anstellung();
        $fremd = $this->anstellung();
        $z = $this->zustellung($fremd);
        $shell = $this->shell($ma);

        $shell->oeffneDokument($z->id);

        $this->assertNull($shell->dokumentId);
        $this->assertNull($z->fresh()->first_viewed_at);
    }

    public function test_unterschreiben_ueber_die_huelle(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma);
        $shell = $this->shell($ma);
        $shell->oeffneDokument($z->id);

        $shell->dokumentGelesen = false;
        $shell->dokumentUnterschrift = self::SIG;
        $shell->unterschreibeDokument();
        $this->assertSame('Bitte bestätige, dass du das Dokument gelesen hast.', $shell->dokumentFehler);
        $this->assertNull($z->fresh()->signed_at);

        $shell->dokumentGelesen = true;
        $shell->unterschreibeDokument();
        $this->assertSame('', $shell->dokumentFehler);
        $this->assertNotNull($z->fresh()->signed_at);
        $this->assertNull($shell->dokumentId, 'Blatt schliesst sich nach Erfolg');
        $this->assertNotSame('', $shell->dokumentMeldung);
    }

    public function test_bestaetigen_ueber_die_huelle(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma, 'acknowledge');
        $shell = $this->shell($ma);
        $shell->oeffneDokument($z->id);
        $shell->dokumentGelesen = true;

        $shell->bestaetigeDokument();

        $this->assertNotNull($z->fresh()->acknowledged_at);
        $this->assertNull($shell->dokumentId);
    }

    public function test_aktion_mit_manipulierter_id_schreibt_nichts(): void
    {
        $ma = $this->anstellung();
        $fremd = $this->anstellung();
        $eigene = $this->zustellung($ma);
        $fremde = $this->zustellung($fremd);
        $shell = $this->shell($ma);
        $shell->oeffneDokument($eigene->id);
        $shell->dokumentId = $fremde->id;          // $wire.set von aussen
        $shell->dokumentGelesen = true;
        $shell->dokumentUnterschrift = self::SIG;

        $shell->unterschreibeDokument();

        $this->assertNull($fremde->fresh()->signed_at);
        $this->assertNull($eigene->fresh()->signed_at);
        $this->assertNull($shell->dokumentId);
    }

    public function test_zurueckgezogen_waehrend_das_blatt_offen_ist_meldet_es(): void
    {
        $ma = $this->anstellung();
        $z = $this->zustellung($ma, 'acknowledge');
        $shell = $this->shell($ma);
        $shell->oeffneDokument($z->id);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['withdrawn_at' => '2026-10-09 10:00:00']);   // HR zieht zurueck
        $shell->dokumentGelesen = true;

        $shell->bestaetigeDokument();

        $this->assertNull($z->fresh()->acknowledged_at);
        $this->assertNull($shell->dokumentId, 'Blatt geschlossen');
        $this->assertSame(PortalShell::MELDUNG_ZURUECKGEZOGEN, $shell->dokumentMeldung);
        $this->assertSame('Dieses Dokument wurde zurückgezogen.', $shell->dokumentMeldung);
    }

    public function test_ohne_offenes_blatt_gibt_es_keine_zurueckgezogen_meldung(): void
    {
        $ma = $this->anstellung();
        $shell = $this->shell($ma);

        $shell->unterschreibeDokument();

        $this->assertSame('', $shell->dokumentMeldung);
    }

    public function test_die_dokument_meldung_steht_auch_auf_dem_start_reiter(): void
    {
        // Das Blatt oeffnet sich auch vom Start-Reiter (offene Punkte) -- die Meldung muss dort sichtbar sein.
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
        $start = strpos($blade, "<div class=\"pane\" :class=\"tab === 'start' && 'on'\">");
        $jobs = strpos($blade, "<div class=\"pane\" :class=\"tab === 'jobs' && 'on'\">");
        $docs = strpos($blade, "<div class=\"pane\" :class=\"tab === 'docs' && 'on'\">");
        $me = strpos($blade, "<div class=\"pane\" :class=\"tab === 'me' && 'on'\">");
        $this->assertNotFalse($start);
        $this->assertNotFalse($jobs);
        $this->assertStringContainsString('{{ $dokumentMeldung }}', substr($blade, $start, $jobs - $start), 'Start-Reiter');
        $this->assertStringContainsString('{{ $dokumentMeldung }}', substr($blade, $docs, $me - $docs), 'Dokumente-Reiter');
    }

    public function test_mitarbeiter_dokumente_liste_und_offen_zaehler(): void
    {
        $ma = $this->anstellung();
        $this->zustellung($ma, 'sign');
        $this->zustellung($ma, 'none');
        $shell = $this->shell($ma);

        $liste = $this->privat($shell, 'mitarbeiterDokumente', [$ma]);

        $this->assertCount(2, $liste);
        $this->assertSame(1, count(array_filter($liste, fn ($d) => $d['offen'])));
    }
}
