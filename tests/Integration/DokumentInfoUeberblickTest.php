<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Documents;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\DokumentAutoZustellung;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Support\DokumentAkteZeilen;

/**
 * HR-Ueberblick fuer Infos (Spec 2026-10-16 §3.3, §6): "gesehen x von y",
 * Abzeichen "automatisch an Neue seit …" samt Zahl der automatisch
 * zugestellten Zeilen; eine Info mit Automatik ist nie "erledigt", solange
 * der Haken sitzt (sonst verschwaende sie unter "Nur offene" und HR koennte
 * die Automatik nicht mehr stoppen). Zurueckziehen und "Automatik beenden"
 * nehmen den Haken.
 */
final class DokumentInfoUeberblickTest extends TestCase
{
    use DokumenteHarness;

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
        $container->instance(DokumentService::class, new DokumentService($this->speicher));
        $container->instance(BusDispatcher::class, new class {
            public function dispatch($job)
            {
            }
        });
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

    private function seite(string $zeige = 'offen'): Documents
    {
        $s = new Documents();
        ComputedWiring::wire($s);
        $s->zeige = $zeige;

        return $s;
    }

    private function info(bool $auto, string $publishedAt = '2026-10-16 10:00:00'): RecDocument
    {
        return RecDocument::create([
            'team_id' => 3, 'title' => 'Willkommen', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'Hallo', 'file_sha256' => hash('sha256', 'Hallo'),
            'auto_neue_mitarbeiter' => $auto, 'published_at' => $publishedAt,
        ]);
    }

    private function zustellen(RecDocument $d, RecEmployee $e, array $set = []): RecDocumentRecipient
    {
        $z = RecDocumentRecipient::create(['team_id' => 3, 'rec_document_id' => $d->id, 'rec_employee_id' => $e->id]);
        if ($set !== []) {
            DB::table('rec_document_recipients')->where('id', $z->id)->update($set);
        }

        return $z->fresh();
    }

    private function neueAnstellung(string $createdAt = '2026-10-17 09:00:00'): RecEmployee
    {
        $e = $this->anstellung(['company' => 'RG', 'phone' => '+491701234599', 'rec_applicant_id' => 501]);
        DB::table('rec_employees')->where('id', $e->id)->update(['created_at' => $createdAt]);

        return $e->fresh();
    }

    private function haken(int $id): bool
    {
        return (bool) DB::table('rec_documents')->where('id', $id)->value('auto_neue_mitarbeiter');
    }

    private function blade(string $datei): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/employees/' . $datei);
    }

    public function test_ueberblick_zeigt_info_mit_gesehen_fortschritt_und_automatik_abzeichen(): void
    {
        $d = $this->info(true);
        $this->zustellen($d, $this->anstellung(), ['first_viewed_at' => '2026-10-16 11:00:00']);
        $this->zustellen($d, $this->anstellung(['first_name' => 'Ben']), ['notify_error' => DokumentHinweisSender::STATUS_NICHT_NOETIG]);

        $dok = $this->seite('alle')->dokumente()[0];
        $this->assertSame(1, $dok['fortschritt']['erledigt']);
        $this->assertSame(2, $dok['fortschritt']['gesamt']);
        $this->assertTrue($dok['auto']);
        $this->assertSame('16.10.2026', $dok['published']);
        $this->assertTrue($dok['is_info']);
        $this->assertSame('Info / News', $dok['category_label']);
        $this->assertSame(1, $dok['automatisch'], 'Zahl der automatisch zugestellten Zeilen (Spec §6)');
    }

    /** 26.10.2026: Text einer bestehenden Info in der Admin-Liste ansehen (wie im Portal). */
    public function test_text_anzeigen_zeigt_bestehende_info_wie_im_portal(): void
    {
        $d = $this->info(false);
        DB::table('rec_documents')->where('id', $d->id)->update(['content_markdown' => "## Willkommen\n\n**Schön**, dass du da bist."]);

        $s = $this->seite('alle');
        $this->assertNull($s->angezeigteInfo);
        $s->textAnzeigen((int) $d->id);

        $this->assertTrue($s->textOffen);
        $info = $s->angezeigteInfo;
        $this->assertSame('Willkommen', $info['title']);
        $this->assertStringContainsString('<strong>Schön</strong>', $info['html']);
        $this->assertStringContainsString('wire:click="textAnzeigen({{ $dok[\'id\'] }})"', $this->blade('documents.blade.php'));
    }

    public function test_text_anzeigen_nur_eigene_infos(): void
    {
        $fremd = RecDocument::create([
            'team_id' => 99, 'title' => 'Fremd', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'Geheim', 'file_sha256' => hash('sha256', 'Geheim'),
        ]);

        $s = $this->seite('alle');
        $s->textAnzeigen((int) $fremd->id);

        $this->assertFalse($s->textOffen);
        $this->assertNull($s->angezeigteInfo);
    }

    public function test_pdf_hat_kein_automatik_abzeichen(): void
    {
        RecDocument::create(['team_id' => 3, 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'acknowledge',
            'stored_path' => 'x.pdf', 'file_sha256' => hash('sha256', 'p'), 'auto_neue_mitarbeiter' => false]);
        $dok = $this->seite('alle')->dokumente()[0];
        $this->assertFalse($dok['auto']);
        $this->assertFalse($dok['is_info']);
        $this->assertSame(0, $dok['automatisch']);
    }

    public function test_info_mit_automatik_ohne_empfaenger_bleibt_offen(): void
    {
        $d = $this->info(true);
        $dokumente = $this->seite('offen')->dokumente();
        $this->assertCount(1, $dokumente, 'Automatik-Info ohne Empfaenger verschwindet nicht unter "Nur offene"');
        $this->assertFalse($dokumente[0]['erledigt']);
        $this->assertSame((int) $d->id, $dokumente[0]['id']);
    }

    public function test_info_mit_automatik_bleibt_offen_auch_wenn_alle_gesehen_haben(): void
    {
        $d = $this->info(true);
        $this->zustellen($d, $this->anstellung(), ['first_viewed_at' => '2026-10-16 11:00:00']);
        $dokumente = $this->seite('offen')->dokumente();
        $this->assertCount(1, $dokumente);
        $this->assertFalse($dokumente[0]['erledigt']);
    }

    public function test_info_ohne_automatik_ist_erledigt_wenn_alle_gesehen_haben(): void
    {
        $d = $this->info(false);
        $this->zustellen($d, $this->anstellung(), ['first_viewed_at' => '2026-10-16 11:00:00']);
        $this->assertSame([], $this->seite('offen')->dokumente());
        $this->assertTrue($this->seite('alle')->dokumente()[0]['erledigt']);
    }

    public function test_zurueckziehen_einer_info_nimmt_den_automatik_haken(): void
    {
        $d = $this->info(true);
        $this->zustellen($d, $this->anstellung());

        $this->seite()->dokumentZurueckziehen((int) $d->id);

        $this->assertFalse($this->haken((int) $d->id), 'Haken in der Datenbank weg — nicht nur ueber das Loeschen verdeckt');
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($this->neueAnstellung()));
    }

    public function test_zurueckziehen_im_service_nimmt_den_haken_auch_wenn_das_dokument_bleibt(): void
    {
        // Unterschriebene Zeile haelt das Dokument (kommt bei Infos nicht vor) — der Haken muss trotzdem weg.
        $d = $this->info(true);
        $this->zustellen($d, $this->anstellung(), ['signed_at' => '2026-10-16 11:00:00']);

        $e = app(DokumentService::class)->dokumentZurueckziehen($d);

        $this->assertFalse($e['geloescht']);
        $this->assertFalse($this->haken((int) $d->id));
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($this->neueAnstellung()));
    }

    public function test_automatik_beenden_nimmt_nur_den_haken(): void
    {
        $d = $this->info(true);
        $z = $this->zustellen($d, $this->anstellung(), ['first_viewed_at' => '2026-10-16 11:00:00']);
        $s = $this->seite();

        $s->automatikBeenden((int) $d->id);

        $this->assertFalse($this->haken((int) $d->id));
        $this->assertNotNull(RecDocument::find($d->id), 'Info bleibt fuer alle, die sie schon haben');
        $this->assertNull(DB::table('rec_document_recipients')->where('id', $z->id)->value('withdrawn_at'));
        $this->assertSame('Automatik für „Willkommen“ beendet — wer die Info schon hat, behält sie.', $s->flash);
        $this->assertSame(0, (new DokumentAutoZustellung())->fuerErstesOeffnen($this->neueAnstellung()));
        $this->assertSame([], $this->seite('offen')->dokumente(), 'ohne Haken und alle gesehen: erledigt');
    }

    public function test_erledigte_info_laesst_sich_weiter_zurueckziehen(): void
    {
        // Schlussreview I-1: falsche Telefonnummer verschickt, alle haben gesehen → muss trotzdem weg koennen.
        $d = $this->info(false);
        $this->zustellen($d, $this->anstellung(), ['first_viewed_at' => '2026-10-16 11:00:00']);
        $dok = $this->seite('alle')->dokumente()[0];
        $this->assertTrue($dok['erledigt']);
        $this->assertTrue($dok['zurueckziehbar']);

        // Automatik beendet + alle gesehen: ebenso.
        $a = $this->info(true);
        $this->zustellen($a, $this->anstellung(['first_name' => 'Ben']), ['first_viewed_at' => '2026-10-16 11:00:00']);
        $this->seite()->automatikBeenden((int) $a->id);
        $zeile = collect($this->seite('alle')->dokumente())->firstWhere('id', (int) $a->id);
        $this->assertTrue($zeile['erledigt']);
        $this->assertTrue($zeile['zurueckziehbar']);

        $this->assertStringContainsString("@if (\$dok['zurueckziehbar'])\n                                <button type=\"button\" wire:click=\"dokumentZurueckziehen(", $this->blade('documents.blade.php'));
    }

    public function test_pdf_erledigt_oder_zurueckgezogene_info_ohne_zurueckziehen(): void
    {
        RecDocument::create(['team_id' => 3, 'title' => 'Hausordnung', 'category' => 'other', 'action' => 'none',
            'stored_path' => 'x.pdf', 'file_sha256' => hash('sha256', 'p'), 'auto_neue_mitarbeiter' => false]);
        $pdf = $this->seite('alle')->dokumente()[0];
        $this->assertTrue($pdf['erledigt'], 'PDF ohne Empfaenger gilt als erledigt');
        $this->assertFalse($pdf['zurueckziehbar']);

        $d = $this->info(false);
        $this->zustellen($d, $this->anstellung());
        $d->delete();
        $zeile = collect($this->seite('alle')->dokumente())->firstWhere('id', (int) $d->id);
        $this->assertTrue($zeile['geloescht']);
        $this->assertFalse($zeile['zurueckziehbar']);
    }

    public function test_automatik_beenden_fremdes_team_tut_nichts(): void
    {
        $fremd = RecDocument::create(['team_id' => 4, 'title' => 'Fremd', 'category' => 'info', 'action' => 'none',
            'content_markdown' => 'x', 'file_sha256' => hash('sha256', 'x'), 'auto_neue_mitarbeiter' => true, 'published_at' => '2026-10-16 10:00:00']);
        $this->seite()->automatikBeenden((int) $fremd->id);
        $this->assertTrue($this->haken((int) $fremd->id));
    }

    public function test_blade_zeigt_abzeichen_gesehen_text_und_knopf(): void
    {
        $blade = $this->blade('documents.blade.php');
        $this->assertStringContainsString("@if (\$dok['auto'])", $blade);
        $this->assertStringContainsString("'automatisch an Neue seit ' . (\$dok['published']", $blade);
        $this->assertStringContainsString('{{ $dokAutoText }}', $blade);
        $this->assertStringContainsString('{{ $dok[\'automatisch\'] }} automatisch zugestellt', $blade);
        $this->assertStringContainsString("'gesehen ' . \$dok['fortschritt']['erledigt'] . ' von ' . \$dok['fortschritt']['gesamt']", $blade);
        $this->assertStringContainsString('wire:click="automatikBeenden({{ $dok[\'id\'] }})"', $blade);
        $this->assertStringContainsString("@if (\$dok['is_info'])", $blade);
    }

    public function test_akte_zeile_kennt_info_und_blade_zeigt_dafuer_keinen_pdf_link(): void
    {
        $d = $this->info(false);
        $z = $this->zustellen($d, $this->anstellung());
        $z->setRelation('document', $d);
        $this->assertTrue(DokumentAkteZeilen::fuer([$z])[0]['is_info']);

        $show = $this->blade('show.blade.php');
        $this->assertStringContainsString("@if (!\$dz['is_info'])", $show);
    }
}
