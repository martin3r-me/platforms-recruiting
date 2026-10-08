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
        Container::getInstance()->forgetInstance(\Illuminate\Contracts\Auth\Factory::class);
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

    private function dok(string $aktion = 'sign', string $titel = 'T'): RecDocument
    {
        return RecDocument::create([
            'team_id' => 3, 'title' => $titel, 'category' => 'instruction', 'action' => $aktion,
            'disk' => 'test-local', 'stored_path' => uniqid() . '.pdf', 'original_filename' => 'x.pdf',
            'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
        ]);
    }

    public function test_liegengebliebene_zustellung_heisst_nicht_gesendet_und_pollt_nicht(): void
    {
        $e = $this->anstellung();
        $d = $this->dok();
        $frisch = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        $alt = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok()->id, 'rec_employee_id' => $e->id]);
        // Job tot / Worker steht: unversucht und aelter als die Versandfrist.
        DB::table('rec_document_recipients')->where('id', $alt->id)
            ->update(['created_at' => now()->subMinutes(DokumentAkteZeilen::VERSAND_FRIST_MINUTEN + 1)->format('Y-m-d H:i:s')]);

        $zeilen = DokumentAkteZeilen::fuer(RecDocumentRecipient::with('document')->orderBy('id')->get()->all());

        $this->assertTrue($zeilen[0]['versand_laeuft']);
        $this->assertSame('wird verschickt …', $zeilen[0]['versand_text']);
        $this->assertTrue($zeilen[0]['kann_erneut_senden'], 'Erneut senden auch waehrend "laeuft" — Rettung bei totem Job');
        $this->assertFalse($zeilen[1]['versand_laeuft'], 'liegengeblieben: kein Poll mehr');
        $this->assertSame('nicht gesendet', $zeilen[1]['versand_text']);
        $this->assertTrue($zeilen[1]['kann_erneut_senden']);
    }

    public function test_erneut_senden_fehlt_bei_erledigten_zurueckgezogenen_und_benachrichtigten(): void
    {
        $e = $this->anstellung();
        $unterschrieben = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok('sign')->id, 'rec_employee_id' => $e->id]);
        $bestaetigt = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok('acknowledge')->id, 'rec_employee_id' => $e->id]);
        $zurueck = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok('sign')->id, 'rec_employee_id' => $e->id]);
        $gesendet = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok('sign')->id, 'rec_employee_id' => $e->id]);
        $fehler = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok('sign')->id, 'rec_employee_id' => $e->id]);
        DB::table('rec_document_recipients')->where('id', $unterschrieben->id)->update(['notify_error' => 'failed', 'first_viewed_at' => '2026-10-09 09:00:00', 'acknowledged_at' => '2026-10-09 09:00:00', 'signed_at' => '2026-10-09 09:00:00']);
        DB::table('rec_document_recipients')->where('id', $bestaetigt->id)->update(['notify_error' => 'failed', 'first_viewed_at' => '2026-10-09 09:00:00', 'acknowledged_at' => '2026-10-09 09:00:00']);
        DB::table('rec_document_recipients')->where('id', $zurueck->id)->update(['notify_error' => 'failed', 'withdrawn_at' => '2026-10-09 09:00:00']);
        DB::table('rec_document_recipients')->where('id', $gesendet->id)->update(['notified_at' => '2026-10-09 09:00:00']);
        DB::table('rec_document_recipients')->where('id', $fehler->id)->update(['notify_error' => 'no_phone']);

        $zeilen = DokumentAkteZeilen::fuer(RecDocumentRecipient::with('document')->orderBy('id')->get()->all());

        $this->assertSame([false, false, false, false, true], array_column($zeilen, 'kann_erneut_senden'));
    }

    public function test_zaehler_rechnen_wie_die_zeilen_und_trennen_liegengebliebene(): void
    {
        $e1 = $this->anstellung();
        $e2 = $this->anstellung(['first_name' => 'Ben']);
        $e3 = $this->anstellung(['first_name' => 'Cem']);
        $e4 = $this->anstellung(['first_name' => 'Dan']);
        $d = $this->dok('sign');
        $leer = $this->dok('acknowledge');
        $z = [];
        foreach ([$e1, $e2, $e3, $e4] as $e) {
            $z[] = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        }
        DB::table('rec_document_recipients')->where('id', $z[0]->id)->update(['notified_at' => '2026-10-09 09:00:00', 'first_viewed_at' => '2026-10-09 09:01:00', 'acknowledged_at' => '2026-10-09 09:02:00', 'signed_at' => '2026-10-09 09:02:00', 'signature_data' => str_repeat('x', 50000)]);
        DB::table('rec_document_recipients')->where('id', $z[1]->id)->update(['withdrawn_at' => '2026-10-09 09:00:00', 'first_viewed_at' => '2026-10-09 08:00:00']);
        DB::table('rec_document_recipients')->where('id', $z[2]->id)->update(['created_at' => now()->subHours(2)->format('Y-m-d H:i:s')]);
        // z[3]: frisch und unversucht

        $n = \Platform\Recruiting\Services\DokumentZaehler::fuer([$d->id, $leer->id]);

        $this->assertSame([
            'gesamt' => 4, 'zurueckgezogen' => 1, 'unterschrieben' => 1, 'bestaetigt' => 1, 'gesehen' => 1,
            'benachrichtigt' => 1, 'ausstehend' => 2, 'ausstehend_alt' => 1,
        ], $n[$d->id]);
        $this->assertArrayNotHasKey($leer->id, $n, 'ohne Zustellungen keine Zeile — Aufrufer nimmt leer()');

        $ausZeilen = \Platform\Recruiting\Support\DokumentFortschritt::fuer(
            RecDocumentRecipient::where('rec_document_id', $d->id)->get()->map(fn ($r) => $r->zeitstempel())->all(), 'sign'
        );
        $this->assertSame($ausZeilen, \Platform\Recruiting\Support\DokumentFortschritt::ausZaehlern($n[$d->id], 'sign'));
        $this->assertSame('1 von 3 unterschrieben', $ausZeilen['text']);
        foreach (['acknowledge', 'none'] as $aktion) {
            $this->assertSame(
                \Platform\Recruiting\Support\DokumentFortschritt::fuer(RecDocumentRecipient::where('rec_document_id', $d->id)->get()->map(fn ($r) => $r->zeitstempel())->all(), $aktion),
                \Platform\Recruiting\Support\DokumentFortschritt::ausZaehlern($n[$d->id], $aktion),
                $aktion
            );
        }
    }

    public function test_listen_laden_kein_unterschriftsbild(): void
    {
        $e = $this->anstellung();
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $this->dok()->id, 'rec_employee_id' => $e->id]);
        DB::table('rec_document_recipients')->where('id', $z->id)->update(['signature_data' => 'data:image/png;base64,AAAA']);

        $liste = RecDocumentRecipient::query()->ohneUnterschriftsbild()->whereHas('document')->with('document')->get();
        $this->assertCount(1, $liste);
        $this->assertArrayNotHasKey('signature_data', $liste[0]->getAttributes());
        $this->assertSame((int) $z->id, (int) $liste[0]->id);
        $this->assertNotNull($liste[0]->document);

        $leser = (new \Platform\Recruiting\Services\DokumentLeser())->empfaenger($e, (int) $z->id);
        $this->assertNotNull($leser);
        $this->assertArrayNotHasKey('signature_data', $leser->getAttributes(), 'DokumentLeser (Portal) laedt das Bild nicht');
        $this->assertSame('data:image/png;base64,AAAA', $leser->fresh()->signature_data, 'gespeichert bleibt es — das Nachweisblatt liest es');
    }

    public function test_seite_dokumente_rechnet_aus_den_zaehlern_und_klappt_ohne_bild_auf(): void
    {
        $container = Container::getInstance();
        $container->instance(\Illuminate\Contracts\Auth\Factory::class, new class implements \Illuminate\Contracts\Auth\Factory {
            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function user(): object
            {
                return (object) ['currentTeam' => (object) ['id' => 3]];
            }
        });
        $e1 = $this->anstellung();
        $e2 = $this->anstellung(['first_name' => 'Ben']);
        $d = $this->dok('sign', 'Hausordnung');
        $z1 = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e1->id]);
        $z2 = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e2->id]);
        DB::table('rec_document_recipients')->where('id', $z1->id)->update(['notified_at' => '2026-10-09 09:00:00', 'signed_at' => '2026-10-09 09:02:00', 'signature_data' => 'data:image/png;base64,AAAA']);
        DB::table('rec_document_recipients')->where('id', $z2->id)->update(['created_at' => now()->subHour()->format('Y-m-d H:i:s')]);

        $seite = new \Platform\Recruiting\Livewire\Employees\Documents();
        $seite->zeige = 'alle';
        $seite->aufgeklappt = (int) $d->id;
        $liste = $seite->dokumente();

        $this->assertCount(1, $liste);
        $this->assertSame('1 von 2 unterschrieben', $liste[0]['fortschritt']['text']);
        $this->assertSame(1, $liste[0]['benachrichtigt']);
        $this->assertSame(1, $liste[0]['ausstehend']);
        $this->assertFalse($liste[0]['versand_laeuft'], 'die eine Unversuchte ist liegengeblieben — kein Poll');
        $this->assertSame(['Anna Test', 'Ben Test'], array_column($liste[0]['empfaenger'], 'name'));
        $this->assertSame('nicht gesendet', $liste[0]['empfaenger'][1]['versand_text']);
        $this->assertSame([false, true], array_column($liste[0]['empfaenger'], 'kann_erneut_senden'));
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
        $this->assertSame('https://meingedeck.de/recruiting/employees/documents', $url->route('recruiting.employees.documents'));
        $container->forgetInstance('router');
        Facade::clearResolvedInstances();
    }
}
