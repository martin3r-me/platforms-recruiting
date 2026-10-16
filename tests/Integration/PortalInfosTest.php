<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\DokumentLeser;

/**
 * Infos im Portal (Spec 2026-10-16 §5.1–5.4): Kasten "Neu fuer dich" auf
 * Start, Block "Infos" im Reiter "Dokumente & Infos". Das Anzeigen gilt als
 * gesehen — kein Klick noetig, kein offener Punkt.
 *
 * GERENDERT wie PortalAufgabenBladeTest: das ganze portal-shell.blade.php
 * mit dem echten BladeCompiler, gebunden an eine echte PortalShell. Die Welt
 * kommt aus den Migrationen (DokumenteHarness).
 */
final class PortalInfosTest extends TestCase
{
    use DokumenteHarness;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
        $this->tmpDir = $this->root . '/blade';
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        TestPortalSitzung::aufraeumen();
        $this->harnessAbbauen();
        parent::tearDown();
    }

    /** @return array{0:RecEmployee, 1:int} Anstellung und Empfaenger-ID der Info */
    private function mitarbeiterMitInfo(string $markdown, ?string $personKey = null, string $titel = 'Willkommen'): array
    {
        $e = $this->anstellung(['person_key' => $personKey ?? 'pk-' . bin2hex(random_bytes(4))]);
        $recipientId = $this->infoAn($e, $markdown, $titel);

        return [$e, $recipientId];
    }

    private function infoAn(RecEmployee $e, string $markdown, string $titel = 'Willkommen'): int
    {
        $d = RecDocument::create([
            'team_id' => 3, 'title' => $titel, 'category' => 'info', 'action' => 'none',
            'content_markdown' => $markdown, 'file_sha256' => hash('sha256', $markdown),
            'published_at' => '2026-10-16 09:00:00',
        ]);

        return (int) RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id])->id;
    }

    private function portalKomponente(RecEmployee $e, bool $duzen = true): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $e->id;
        $shell->state = 'verified';
        $shell->duzen = $duzen;
        TestPortalSitzung::oeffne((int) $e->id);
        // Seitenaufbau: mount (gueltige Sitzung) und verify laufen beide durch identitaetLaden().
        $this->privat($shell, 'identitaetLaden', [$e]);

        return $shell;
    }

    private function privat(object $o, string $m, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($o, $m);
        $ref->setAccessible(true);

        return $ref->invoke($o, ...$args);
    }

    /** Das GANZE Blatt rendern (Muster PortalAufgabenBladeTest::rendereHuelle). */
    private function portalRendern(RecEmployee $e, ?PortalShell $shell = null, bool $duzen = true): string
    {
        $shell ??= $this->portalKomponente($e, $duzen);

        $variablen = array_merge(get_object_vars($shell), $this->privat($shell, 'ansichtsDaten'));

        $compiler = new BladeCompiler(new Filesystem(), $this->tmpDir);
        $datei = $this->tmpDir . '/huelle-' . uniqid('', true) . '.php';
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
        // Wie PortalAufgabenBladeTest: die Core-Komponente des Dokument-Blatts braucht den View-Factory des Hosts.
        $quelle = (string) preg_replace('/<x-ui-input-signature\b[^>]*\/>/s', '', $quelle);
        file_put_contents($datei, $compiler->compileString($quelle));

        $variablen['__env'] = new class {
            use \Illuminate\View\Concerns\ManagesLoops;
        };
        $variablen['__datei'] = $datei;

        $lauf = function (array $__v): string {
            extract($__v);
            ob_start();
            include $__datei;

            return (string) ob_get_clean();
        };

        return \Closure::bind($lauf, $shell, PortalShell::class)($variablen);
    }

    private function gesehenAm(int $recipientId): ?string
    {
        return DB::table('rec_document_recipients')->where('id', $recipientId)->value('first_viewed_at');
    }

    public function test_ungesehene_info_steht_oben_im_kasten_und_das_anzeigen_setzt_first_viewed_at(): void
    {
        [$e, $recipientId] = $this->mitarbeiterMitInfo("# Willkommen\n\nSchön, dass du da bist.");
        $html = $this->portalRendern($e);

        $this->assertStringContainsString('Neu für dich', $html);
        $this->assertStringContainsString('<h2>Willkommen</h2>', $html);
        $this->assertStringContainsString('Schön, dass du da bist.', $html);
        $pos1 = strpos($html, 'Neu für dich');
        $pos2 = strpos($html, 'Noch zu erledigen');
        if ($pos2 !== false) {
            $this->assertLessThan($pos2, $pos1, 'Infos stehen vor den offenen Punkten');
        }
        $erstes = $this->gesehenAm($recipientId);
        $this->assertNotNull($erstes, 'das Anzeigen gilt als gesehen');

        $this->portalRendern($e);
        $this->assertSame($erstes, $this->gesehenAm($recipientId), 'erster Zeitpunkt gewinnt');
    }

    public function test_sie_form_im_kasten(): void
    {
        [$e] = $this->mitarbeiterMitInfo('Text');
        $html = $this->portalRendern($e, duzen: false);

        $this->assertStringContainsString('Neu für Sie', $html);
        $this->assertStringNotContainsString('Neu für dich', $html);
    }

    public function test_gesehene_info_kommt_beim_naechsten_login_nicht_mehr_hoch_steht_aber_im_reiter(): void
    {
        [$e, $recipientId] = $this->mitarbeiterMitInfo('Text');
        DB::table('rec_document_recipients')->where('id', $recipientId)->update(['first_viewed_at' => '2026-10-20 10:00:00']);

        $html = $this->portalRendern($e);

        $this->assertStringNotContainsString('Neu für dich', $html);
        $this->assertStringContainsString('Dokumente &amp; Infos', $html);
        $this->assertStringContainsString('data-info="' . $recipientId . '"', $html);
        $this->assertStringContainsString('Gesehen', $html);
    }

    /**
     * Derselbe Mensch klickt waehrend der Sitzung etwas anderes an — die
     * Info ist seit dem ersten Render gesehen, der Kasten bleibt trotzdem
     * stehen, bis er "Schliessen" drueckt (§5.1 "fuer diese Sitzung").
     */
    public function test_kasten_bleibt_in_der_sitzung_bis_schliessen(): void
    {
        [$e, $recipientId] = $this->mitarbeiterMitInfo('Bleib da');
        $shell = $this->portalKomponente($e);

        $this->portalRendern($e, $shell);
        $this->assertNotNull($this->gesehenAm($recipientId));

        $zweiter = $this->portalRendern($e, $shell);
        $this->assertStringContainsString('Neu für dich', $zweiter, 'naechster Request derselben Sitzung');

        $shell->infosSchliessen();
        $dritter = $this->portalRendern($e, $shell);
        $this->assertStringNotContainsString('Neu für dich', $dritter);
        $this->assertStringContainsString('data-info="' . $recipientId . '"', $dritter, 'im Reiter steht sie weiter');
    }

    /**
     * Review Task 4, M1: gestempelt wird nur beim Seitenaufbau und beim
     * Aufklappen. Eine Info, die HR waehrend der Sitzung zustellt, ist bei
     * keinem spaeteren Render "gesehen" — sie kommt beim naechsten
     * Seitenaufbau in den Kasten.
     */
    public function test_waehrend_der_sitzung_zugestellte_info_wird_erst_beim_naechsten_seitenaufbau_gezeigt_und_gestempelt(): void
    {
        [$e, $ersteId] = $this->mitarbeiterMitInfo('Erste Nachricht');
        $shell = $this->portalKomponente($e);
        $this->portalRendern($e, $shell);

        $spaeterId = $this->infoAn($e, 'Spaete Nachricht', 'Spaet');
        $zweiter = $this->portalRendern($e, $shell);

        $this->assertNull($this->gesehenAm($spaeterId), 'spaetere Renders stempeln nichts');
        $kasten = $this->kasten($zweiter);
        $this->assertStringContainsString('Erste Nachricht', $kasten);
        $this->assertStringNotContainsString('Spaete Nachricht', $kasten);
        $this->assertStringContainsString('data-info="' . $spaeterId . '"', $zweiter, 'im Reiter steht sie als Neu');

        $frisch = $this->portalRendern($e);
        $this->assertStringContainsString('Spaete Nachricht', $this->kasten($frisch));
        $this->assertStringNotContainsString('Erste Nachricht', $this->kasten($frisch), 'die erste ist seit dem ersten Seitenaufbau gesehen');
        $this->assertNotNull($this->gesehenAm($spaeterId));
        $this->assertNotNull($this->gesehenAm($ersteId));
    }

    /** Nur der Kasten-Ausschnitt des Markups ('' ohne Kasten). */
    private function kasten(string $html): string
    {
        $start = strpos($html, 'class="info-kasten"');
        if ($start === false) {
            return '';
        }
        // Danach kommen "Noch zu erledigen" (falls offen) und immer die Spalten-Kaesten.
        $enden = array_filter([strpos($html, 'class="aufgaben-kasten"', $start), strpos($html, 'class="bcols"', $start)]);
        $ende = $enden !== [] ? min($enden) : strlen($html);

        return substr($html, $start, $ende - $start);
    }

    public function test_mehrere_neue_infos_aelteste_zuerst(): void
    {
        [$e] = $this->mitarbeiterMitInfo('Erster Text', titel: 'Erste Info');
        $this->infoAn($e, 'Zweiter Text', 'Zweite Info');

        $neu = app(DokumentLeser::class)->ungeseheneInfos($e);
        $this->assertSame(['Erste Info', 'Zweite Info'], array_column($neu, 'title'));

        $html = $this->portalRendern($e);
        $this->assertLessThan(strpos($html, 'Zweiter Text'), strpos($html, 'Erster Text'));
    }

    public function test_info_ist_kein_offener_punkt(): void
    {
        [$e] = $this->mitarbeiterMitInfo('Text');

        $this->assertSame([], app(DokumentLeser::class)->offenePunkte($e));
        $this->assertSame([], app(DokumentLeser::class)->offenePunkte($e, 3, '2026-10-16'));
    }

    public function test_aufklappen_im_reiter_setzt_gesehen_und_zeigt_den_text(): void
    {
        $e = $this->anstellung(['person_key' => 'pk-aufklappen']);
        $shell = $this->portalKomponente($e);
        // Waehrend der Sitzung zugestellt: der Seitenaufbau hat sie nicht gesehen, nur das Aufklappen stempelt.
        $recipientId = $this->infoAn($e, "Hallo **fett**");
        $this->portalRendern($e, $shell);
        $this->assertNull($this->gesehenAm($recipientId), 'Vorflug: Render allein stempelt nicht');

        $shell->infoAufklappen($recipientId);

        $this->assertSame($recipientId, $shell->infoOffen);
        $this->assertNotNull($this->gesehenAm($recipientId));
        $html = $this->portalRendern($e, $shell);
        $this->assertStringContainsString('<strong>fett</strong>', $html);
        $this->assertStringContainsString('Zuklappen', $html);

        $shell->infoAufklappen($recipientId);
        $this->assertNull($shell->infoOffen, 'zweiter Klick klappt zu');
    }

    public function test_fremde_empfaenger_id_beim_aufklappen_zeigt_nichts_und_stempelt_nichts(): void
    {
        [$e] = $this->mitarbeiterMitInfo('Text');
        [, $fremdId] = $this->mitarbeiterMitInfo('Fremd', personKey: 'andere-person');
        $komponente = $this->portalKomponente($e);

        $komponente->infoAufklappen($fremdId);

        $this->assertNull($komponente->infoOffen);
        $this->assertNull($this->gesehenAm($fremdId));
    }

    public function test_fremde_id_im_kasten_zustand_zeigt_nichts_fremdes(): void
    {
        [$e] = $this->mitarbeiterMitInfo('Eigener Text');
        [, $fremdId] = $this->mitarbeiterMitInfo('Geheimer Text', personKey: 'andere-person');
        $shell = $this->portalKomponente($e);
        $shell->infoKastenIds = [$fremdId];
        $shell->infoOffen = $fremdId;

        $html = $this->portalRendern($e, $shell);

        $this->assertStringNotContainsString('Geheimer Text', $html);
        $this->assertNull($this->gesehenAm($fremdId));
    }

    public function test_pdf_zeilen_tragen_kein_content_html(): void
    {
        $e = $this->anstellung();
        $ablage = $this->speicher->ablegen(3, 'p-' . uniqid(), "%PDF-1.4\nHallo");
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => $ablage['disk'], 'stored_path' => $ablage['stored_path'], 'original_filename' => 'h.pdf',
            'file_sha256' => $ablage['sha256'], 'file_size' => $ablage['size'],
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);

        $zeilen = app(DokumentLeser::class)->fuerMitarbeiter($e);

        $this->assertCount(1, $zeilen);
        $this->assertFalse($zeilen[0]['is_info']);
        $this->assertSame('', $zeilen[0]['content_html']);
        $this->assertFalse($zeilen[0]['gesehen']);
        $this->assertSame([], app(DokumentLeser::class)->ungeseheneInfos($e));
    }

    public function test_aufklappen_einer_pdf_zeile_tut_nichts(): void
    {
        $e = $this->anstellung();
        $ablage = $this->speicher->ablegen(3, 'p-' . uniqid(), "%PDF-1.4\nHallo");
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => $ablage['disk'], 'stored_path' => $ablage['stored_path'], 'original_filename' => 'h.pdf',
            'file_sha256' => $ablage['sha256'], 'file_size' => $ablage['size'],
        ]);
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        $shell = $this->portalKomponente($e);

        $shell->infoAufklappen((int) $z->id);

        $this->assertNull($shell->infoOffen);
        $this->assertNull($this->gesehenAm((int) $z->id), 'PDF wird nur ueber das Dokument-Blatt gesehen');
    }

    public function test_zurueckgezogene_info_erscheint_nirgends(): void
    {
        [$e, $recipientId] = $this->mitarbeiterMitInfo('Weg damit');
        DB::table('rec_document_recipients')->where('id', $recipientId)->update(['withdrawn_at' => '2026-10-16 10:00:00']);

        $html = $this->portalRendern($e);

        $this->assertStringNotContainsString('Weg damit', $html);
        $this->assertStringNotContainsString('Neu für dich', $html);
        $this->assertNull($this->gesehenAm($recipientId));
    }
}
