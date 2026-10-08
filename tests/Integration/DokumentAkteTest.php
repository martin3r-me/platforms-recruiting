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
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Support\DokumentAkteZeilen;

final class DokumentAkteTest extends TestCase
{
    use DokumenteHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harnessAufsetzen();
    }

    protected function tearDown(): void
    {
        $this->harnessAbbauen();
        parent::tearDown();
    }

    public function test_zeilen_tragen_status_benachrichtigung_und_rechte(): void
    {
        $e = $this->anstellung();
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'Hausordnung', 'category' => 'instruction', 'action' => 'sign',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        $offen = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        DB::table('rec_document_recipients')->where('id', $offen->id)->update(['notify_error' => 'no_phone']);
        $d2 = RecDocument::create([
            'team_id' => 3, 'title' => 'Vertrag', 'category' => 'contract', 'action' => 'sign',
            'disk' => 'test-local', 'stored_path' => 'y.pdf', 'original_filename' => 'y.pdf',
            'file_sha256' => str_repeat('b', 64), 'file_size' => 1,
        ]);
        $fertig = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d2->id, 'rec_employee_id' => $e->id]);
        DB::table('rec_document_recipients')->where('id', $fertig->id)->update([
            'notified_at' => '2026-10-09 09:00:00', 'first_viewed_at' => '2026-10-09 09:05:00',
            'acknowledged_at' => '2026-10-09 09:06:00', 'signed_at' => '2026-10-09 09:07:00',
        ]);

        $zeilen = DokumentAkteZeilen::fuer(RecDocumentRecipient::with('document')->orderBy('id')->get()->all());

        $this->assertSame('offen', $zeilen[0]['status']);
        $this->assertSame('no_phone', $zeilen[0]['fehler']);
        $this->assertNull($zeilen[0]['benachrichtigt']);
        $this->assertFalse($zeilen[0]['versand_laeuft']);
        $this->assertSame('nicht erreicht (no_phone)', $zeilen[0]['versand_text']);
        $this->assertTrue($zeilen[0]['kann_zurueckziehen']);
        $this->assertFalse($zeilen[0]['hat_nachweis']);
        $this->assertSame('Belehrung / Unterweisung', $zeilen[0]['category_label']);
        $this->assertSame('Unterschreiben', $zeilen[0]['action_label']);

        $this->assertSame('unterschrieben', $zeilen[1]['status']);
        $this->assertSame('2026-10-09 09:00:00', $zeilen[1]['benachrichtigt']);
        $this->assertSame('WhatsApp 09.10. 09:00', $zeilen[1]['versand_text']);
        $this->assertFalse($zeilen[1]['kann_zurueckziehen']);
        $this->assertTrue($zeilen[1]['hat_nachweis']);
        $this->assertSame($fertig->uuid, $zeilen[1]['recipient_uuid']);
        $this->assertSame($d2->uuid, $zeilen[1]['document_uuid']);
    }

    public function test_noch_nicht_versuchte_zustellung_heisst_wird_verschickt(): void
    {
        $e = $this->anstellung();
        $d = RecDocument::create([
            'team_id' => 3, 'title' => 'T', 'category' => 'instruction', 'action' => 'acknowledge',
            'disk' => 'test-local', 'stored_path' => 'x.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        $dNone = RecDocument::create([
            'team_id' => 3, 'title' => 'Lohn', 'category' => 'payslip', 'action' => 'none',
            'disk' => 'test-local', 'stored_path' => 'y.pdf', 'original_filename' => 'y.pdf',
            'file_sha256' => str_repeat('b', 64), 'file_size' => 1,
        ]);
        RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $dNone->id, 'rec_employee_id' => $e->id]);

        $zeilen = DokumentAkteZeilen::fuer(RecDocumentRecipient::with('document')->orderBy('id')->get()->all());

        $this->assertTrue($zeilen[0]['versand_laeuft']);
        $this->assertSame('wird verschickt …', $zeilen[0]['versand_text']);
        $this->assertFalse($zeilen[1]['versand_laeuft']);
        $this->assertSame('ohne Nachricht', $zeilen[1]['versand_text']);
    }

    public function test_hr_routen_sind_registriert(): void
    {
        $container = Container::getInstance();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/web.php';
        });
        $router->getRoutes()->refreshNameLookups();
        $url = new UrlGenerator($router->getRoutes(), Request::create('https://meingedeck.de'));

        $this->assertSame('https://meingedeck.de/recruiting/employees/dokumente/abc/datei', $url->route('recruiting.employees.dokument.datei', ['uuid' => 'abc']));
        $this->assertSame('https://meingedeck.de/recruiting/employees/dokumente/abc/nachweis', $url->route('recruiting.employees.dokument.nachweis', ['uuid' => 'abc']));
        $container->forgetInstance('router');
        Facade::clearResolvedInstances();
    }
}
