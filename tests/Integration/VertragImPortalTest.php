<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Http\Controllers\VertragPdfController;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\OffenePunkte;
use Platform\Recruiting\Services\PortalAuth;
use Platform\Recruiting\Services\VertragLeser;
use Platform\Recruiting\Support\TriggerRegeln;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Spec Vertrag aus der Akte §2.5, Tests 5 und 6; Review-Focus 5. */
final class VertragImPortalTest extends TestCase
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

    private function dokumente(RecEmployee $e): array
    {
        $shell = new PortalShell();
        $m = new ReflectionMethod($shell, 'dokumente');
        $m->setAccessible(true);

        return $m->invoke($shell, $e);
    }

    private function unterschrieben(RecEmployee $a, string $code = 'AV-MA-LOG'): RecContract
    {
        return $this->vertragAn($a, $this->vorlage($code), ['status' => 'completed', 'signed_at' => '2026-10-02 12:00:00', 'completed_at' => '2026-10-02 12:00:00']);
    }

    public function test_vertrag_ohne_bewerbung_erscheint_pending_ohne_signierlink(): void
    {
        $ma = $this->anstellung();
        $offen = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $pending = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'), ['status' => 'pending', 'sent_at' => null]);
        $this->vertragAn($ma, $this->vorlage('AV-MA-ALT'), ['status' => 'cancelled']);

        $zeilen = $this->dokumente($ma);

        $this->assertSame([$offen->id, $pending->id], array_column($zeilen, 'id'), 'storniert bleibt weg');
        $this->assertStringContainsString('recruiting.public.contract-signing', (string) $zeilen[0]['sign_url']);
        $this->assertNull($zeilen[1]['sign_url'], 'pending: der Link liefe ins Leere');
        $this->assertSame(0, CorePublicFormLink::query()->where('linkable_type', RecContract::class)->where('linkable_id', $pending->id)->count(),
            'Anzeigen legt fuer pending keinen Link an');
        $this->assertSame('Arbeitsvertrag', $zeilen[0]['display_name'], 'eine Anstellung: ohne Gesellschaft');
    }

    public function test_pdf_link_fuer_unterschriebene_ueber_den_vertragstoken(): void
    {
        $ma = $this->anstellung();
        $v = $this->unterschrieben($ma);

        $zeile = $this->dokumente($ma)[0];

        $this->assertNull($zeile['sign_url']);
        $this->assertSame('/recruiting.public.contract-pdf-anstellung/' . http_build_query(['token' => $v->publicFormLink->token]), $zeile['pdf_url']);
    }

    /** Review-Focus 5. Probe: dokumente() zurueck auf $employee->contracts() → Schwester-Richtung rot. */
    public function test_schwester_anstellung_sieht_den_vertrag_mit_gesellschaft_fremde_person_nie(): void
    {
        $ma = $this->anstellung();
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4711']);
        $this->personVerbinden($ma, $rg);
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $fremd = $this->anstellung(['personnel_number' => 'MA9999', 'first_name' => 'Fremd']);

        $ausRg = $this->dokumente($rg->fresh());
        $this->assertSame([$v->id], array_column($ausRg, 'id'));
        $this->assertSame('Arbeitsvertrag · MA', $ausRg[0]['display_name']);

        $this->assertSame([], $this->dokumente($fremd));
        $this->assertNull(app(VertragLeser::class)->signierLink($fremd, $v->id), 'fremde ID aus der Anfrage: nichts');
        $this->assertStringContainsString('recruiting.public.contract-signing', (string) app(VertragLeser::class)->signierLink($rg->fresh(), $v->id));
    }

    /** Spec-Test 5 — PDF-Route 403/404. Probe: sitzungDeckt() durch true ersetzen → rot. */
    public function test_pdf_zugriff_nur_mit_sitzung_der_person(): void
    {
        $ma = $this->anstellung();
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4711']);
        $this->personVerbinden($ma, $rg);
        $fremd = $this->anstellung(['personnel_number' => 'MA9999']);
        $v = $this->unterschrieben($ma);
        $token = $v->getOrCreatePublicFormLink()->token;
        $sitzung = fn (int $id) => fn (string $key) => $key === PortalAuth::sessionKey($id);

        $vertrag = VertragPdfController::vertragZumToken($token);
        $this->assertSame($v->id, $vertrag?->id);
        $this->assertSame(200, VertragPdfController::zugriff($vertrag, $sitzung($ma->id)));
        $this->assertSame(200, VertragPdfController::zugriff($vertrag, $sitzung($rg->id)), 'Schwester-Sitzung genuegt');
        $this->assertSame(403, VertragPdfController::zugriff($vertrag, $sitzung($fremd->id)));
        $this->assertSame(403, VertragPdfController::zugriff($vertrag, fn () => false));
        $this->assertSame(404, VertragPdfController::zugriff(VertragPdfController::vertragZumToken('gibt-es-nicht'), $sitzung($ma->id)));

        $offen = $this->vertragAn($ma, $this->vorlage('AV-MA-ZAP'));
        $this->assertSame(404, VertragPdfController::zugriff($offen, $sitzung($ma->id)), 'nicht unterschrieben: kein PDF');

        DB::table('rec_employees')->where('id', $ma->id)->update(['portal_locked_at' => '2026-10-08 10:00:00']);
        $this->assertSame(403, VertragPdfController::zugriff($vertrag->fresh(), $sitzung($ma->id)), 'Portalsperre');
    }

    /** Spec-Test 6. Probe: Pause in VertragLeser::offenePunkte() entfernen → zweite Zusicherung rot. */
    public function test_offener_punkt_in_fuer_und_trigger_mit_pause_ab_sent_at(): void
    {
        $ma = $this->anstellung();
        $v = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-10-05 10:00:00'], ['zuschlag' => '0,60']);
        $punkt = ['code' => 'vertrag:' . $v->id, 'label' => 'Arbeitsvertrag · MA', 'status' => 'offen', 'ko' => false, 'punkt' => 'crit', 'text' => 'Lesen und unterschreiben'];

        $this->assertContains($punkt, (new OffenePunkte())->fuer($ma, '2026-10-09')['punkte']);
        $this->assertNotContains($punkt, (new OffenePunkte())->fuerTrigger($ma, '2026-10-09')['punkte'], 'vor 4 Tagen versandt: Trigger schweigt');
        $this->assertContains($punkt, (new OffenePunkte())->fuerTrigger($ma, '2026-10-13')['punkte'], 'nach der Pause wieder dabei');
    }

    /**
     * Schlussreview I3: alte offene Vertraege aus dem Bewerbungsweg, die
     * vertraege-an-anstellung an die Anstellung gehaengt hat (IFSG, AV ohne
     * Zuschlagsfeld, versandt vor Monaten), werden KEIN offener Punkt — sonst
     * stiege der Portal-Zaehler und die Trigger-Signatur aenderte sich fuer
     * alle diese Menschen (naechste Welle schriebe sie an). In der Liste mit
     * Unterschreiben-Link bleiben sie wie bisher.
     * Probe: VertragsHerkunft-Filter in VertragLeser::offenePunkte() entfernen → rot.
     */
    public function test_alter_bewerbungsvertrag_ist_kein_offener_punkt_und_aendert_den_trigger_nicht(): void
    {
        $b = $this->bewerberMitKontakt('Max', 'Muster', 0.3);
        $ma = $this->verknuepfen($this->anstellung(), $b);
        $vorher = TriggerRegeln::signatur((new OffenePunkte())->fuerTrigger($ma, '2026-10-09')['punkte']);
        $vorherZahl = count((new OffenePunkte())->fuer($ma, '2026-10-09')['punkte']);

        // Wie nach recruiting:vertraege-an-anstellung: Bewerbung + Anker, kein Zuschlagsfeld.
        $ifsg = $this->vertragAn($ma, $this->vorlage('IFSG'), ['sent_at' => '2026-05-02 10:00:00']);
        $av = $this->vertragAn($ma, $this->vorlage('AV-default'), ['sent_at' => '2026-05-02 10:00:00'], ['vertragsbeginn' => '2026-05-01']);
        $this->assertSame($b->id, (int) $av->rec_applicant_id, 'Vorflug: Bewerbungsvertrag');

        $this->assertSame([], app(VertragLeser::class)->offenePunkte($ma->fresh()));
        $this->assertSame($vorherZahl, count((new OffenePunkte())->fuer($ma->fresh(), '2026-10-09')['punkte']), 'Portal-Zaehler unveraendert');
        $this->assertSame($vorher, TriggerRegeln::signatur((new OffenePunkte())->fuerTrigger($ma->fresh(), '2026-10-09')['punkte']), 'Trigger-Signatur unveraendert');

        $zeilen = $this->dokumente($ma->fresh());
        $this->assertSame([$ifsg->id, $av->id], array_column($zeilen, 'id'), 'in der Liste bleiben sie');
        $this->assertStringContainsString('recruiting.public.contract-signing', (string) $zeilen[1]['sign_url']);

        // Derselbe Mensch bekommt einen Vertrag aus der Akte: der IST ein Punkt.
        $akte = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'), ['sent_at' => '2026-09-20 10:00:00'], ['zuschlag' => '0,60']);
        $this->assertSame(['vertrag:' . $akte->id], array_column(app(VertragLeser::class)->offenePunkte($ma->fresh()), 'code'));
    }

    public function test_unterschriebener_vertrag_ist_kein_offener_punkt(): void
    {
        $ma = $this->anstellung();
        $this->unterschrieben($ma);

        $this->assertSame([], app(VertragLeser::class)->offenePunkte($ma));
    }

    public function test_blade_klick_und_knopf(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');

        $this->assertStringContainsString("str_starts_with(\$offenerPunkt['code'], 'vertrag:')", $blade);
        $this->assertStringContainsString("'oeffneVertrag(' . (int) substr(\$offenerPunkt['code'], 8) . ')'", $blade);
        $this->assertStringContainsString("\$dokZeigtUnterschreiben = !\$dok['signed_at'] && !empty(\$dok['sign_url']);", $blade);
    }

    public function test_route_traegt_den_token_am_ende(): void
    {
        $container = new Container();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $url = new UrlGenerator($router->getRoutes(), Request::create('https://mitarbeiter.rheingedeck.de'));

        $this->assertSame(
            'https://mitarbeiter.rheingedeck.de/recruiting/mitarbeiter/vertrag/abc123',
            $url->route('recruiting.public.contract-pdf-anstellung', ['token' => 'abc123'])
        );
        Facade::setFacadeApplication(Container::getInstance());
    }

    /**
     * Fix-Runde 1, Ruling Task 6: Aktivitaet zaehlt an der Anstellung der
     * Sitzung, nicht an der des Vertrags. Probe: zugriff() zurueck auf
     * gesperrt(..., $anstellung) → erste Zusicherung rot.
     */
    public function test_pdf_der_inaktiven_schwester_mit_sitzung_der_aktiven_anstellung(): void
    {
        $rg = $this->anstellung(['company' => 'RG', 'personnel_number' => 'RG4711']);
        $ma = $this->anstellung();
        $this->personVerbinden($ma, $rg);
        $v = $this->unterschrieben($rg, 'AV-RG-LOG');
        DB::table('rec_employees')->where('id', $rg->id)->update(['is_active' => false]);
        $sitzung = fn (int $id) => fn (string $key) => $key === PortalAuth::sessionKey($id);

        $vertrag = VertragPdfController::vertragZumToken($v->getOrCreatePublicFormLink()->token);
        $this->assertSame(200, VertragPdfController::zugriff($vertrag, $sitzung($ma->id)), 'RG->MA-Wechsel: RG-Vertrag bleibt abrufbar');
        $this->assertSame(403, VertragPdfController::zugriff($vertrag, $sitzung($rg->id)), 'Sitzung nur an der inaktiven Anstellung: nichts');

        DB::table('rec_employees')->where('id', $ma->id)->update(['is_active' => false]);
        $this->assertSame(403, VertragPdfController::zugriff($vertrag->fresh(), $sitzung($ma->id)), 'ganze Person inaktiv');
    }

    /** Review Minor 2: der Livewire-Weg selbst, nicht nur signierLink(). */
    public function test_oeffne_vertrag_leitet_nur_zum_eigenen_versendeten_vertrag(): void
    {
        $ma = $this->anstellung(['portal_v2_since' => '2026-10-01 10:00:00']);
        $eigener = $this->vertragAn($ma, $this->vorlage('AV-MA-LOG'));
        $fremd = $this->anstellung(['personnel_number' => 'MA9999', 'portal_v2_since' => '2026-10-01 10:00:00']);
        $fremder = $this->vertragAn($fremd, $this->vorlage('AV-MA-ZAP'));

        $shell = new class extends PortalShell {
            public ?string $ziel = null;

            public function redirect($url, $navigate = false)
            {
                $this->ziel = $url;
            }
        };

        $shell->oeffneVertrag($eigener->id);
        $this->assertNull($shell->ziel, 'unverified: kein Sprung');

        $shell->employeeId = $ma->id;
        $shell->state = 'verified';
        $shell->oeffneVertrag($fremder->id);
        $this->assertNull($shell->ziel, 'fremde ID aus dem Browser: kein Sprung');

        $shell->oeffneVertrag($eigener->id);
        $this->assertStringContainsString('recruiting.public.contract-signing', (string) $shell->ziel, 'Gegenprobe: eigener Vertrag');
    }

    /**
     * Review Minor 3: Durchstich durch __invoke — Sitzung aus der Anfrage,
     * abort_if, Cache-Header. DomPDF bleibt draussen (pdfAntwort ersetzt).
     */
    public function test_invoke_verdrahtet_sitzung_abbruch_und_cache_header(): void
    {
        $ma = $this->anstellung();
        $token = $this->unterschrieben($ma)->getOrCreatePublicFormLink()->token;
        $controller = new class extends VertragPdfController {
            protected function pdfAntwort(RecContract $vertrag)
            {
                return new Response('pdf-' . $vertrag->id);
            }
        };
        $anfrage = function (array $sitzung): Request {
            $store = new Store('t', new ArraySessionHandler(10));
            foreach ($sitzung as $k => $v) {
                $store->put($k, $v);
            }
            $r = Request::create('/recruiting/mitarbeiter/vertrag/x');
            $r->setLaravelSession($store);

            return $r;
        };

        $this->mitAbort(function () use ($controller, $anfrage, $token, $ma) {
            $antwort = $controller($anfrage([PortalAuth::sessionKey($ma->id) => true]), $token);
            $this->assertSame(200, $antwort->getStatusCode());
            $this->assertStringContainsString('no-store', (string) $antwort->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $antwort->headers->get('Cache-Control'));

            foreach ([[[], $token, 403], [[PortalAuth::sessionKey($ma->id) => true], 'gibt-es-nicht', 404]] as [$sitzung, $t, $erwartet]) {
                try {
                    $controller($anfrage($sitzung), $t);
                    $this->fail("erwartet {$erwartet}");
                } catch (HttpException $e) {
                    $this->assertSame($erwartet, $e->getStatusCode());
                }
            }
        });
    }

    /**
     * abort() ruft app()->abort(); der blanke Container kennt das nicht.
     * Fuer die Dauer des Tests ein Container mit abort() (wortgleich zu
     * Foundation\Application, Muster KontoAnlegenTest), Bindungen uebernommen.
     */
    private function mitAbort(callable $fn): void
    {
        $alt = Container::getInstance();
        $neu = new class extends Container {
            public function abort($code, $message = '', array $headers = [])
            {
                if ($code == 404) {
                    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException($message);
                }
                throw new HttpException($code, $message, null, $headers);
            }
        };
        foreach (['config', 'events', 'log', 'url', 'db', 'db.schema'] as $n) {
            if ($alt->bound($n)) {
                $neu->instance($n, $alt->make($n));
            }
        }
        Container::setInstance($neu);
        Facade::setFacadeApplication($neu);
        Facade::clearResolvedInstances();
        try {
            $fn();
        } finally {
            Container::setInstance($alt);
            Facade::setFacadeApplication($alt);
            Facade::clearResolvedInstances();
        }
    }
}
