<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Services\DokumentService;
use Platform\Recruiting\Services\DokumentSpeicher;

/** Sender ohne Meta: merkt sich, wen er anschreiben sollte, und antwortet nach Plan. */
final class DokumentSenderAttrappe extends DokumentHinweisSender
{
    /** @var list<int> */
    public array $angeschrieben = [];
    /** @var array<int,string> employee_id => Status */
    public array $antworten = [];

    public function sende(RecEmployee $employee): string
    {
        $this->angeschrieben[] = (int) $employee->id;

        return $this->antworten[(int) $employee->id] ?? self::STATUS_SENT;
    }
}

final class DokumentServiceTest extends TestCase
{
    private const TEAM = 3;
    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private string $root;
    private DokumentSpeicher $speicher;
    private DokumentSenderAttrappe $sender;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['company_prefix' => 'RG']]]));
        $container->instance('log', new class { public function __call($m, $a) {} });

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $eigen = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_05_20_000001_create_rec_employees_table.php',
            'database/migrations/2026_05_21_000005_add_zas_export_markers_to_rec_employees.php',
            'database/migrations/2026_08_26_000002_add_company_to_rec_employees.php',
            'database/migrations/2026_09_10_000001_add_person_key_to_rec_employees.php',
            'database/migrations/2026_09_24_000001_add_portal_v2_since_to_rec_employees.php',
            'database/migrations/2026_09_28_000002_add_rec_person_id_to_rec_employees.php',
            'database/migrations/2026_10_09_000001_create_rec_documents_table.php',
            'database/migrations/2026_10_09_000002_create_rec_document_recipients_table.php',
        ] as $relativ) {
            $pfad = $eigen . '/' . $relativ;
            if (!file_exists($pfad)) {
                throw new \RuntimeException("Migration fehlt: {$pfad}");
            }
            (require $pfad)->up();
        }

        $this->root = sys_get_temp_dir() . '/rec-dokservice-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        $adapter = new LocalFilesystemAdapter($this->root);
        $this->speicher = new DokumentSpeicher(new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $this->root]), 'test-local');
        $this->sender = new DokumentSenderAttrappe();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['config', 'log', 'db', 'db.schema'] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    private function service(): DokumentService
    {
        return new DokumentService($this->speicher, $this->sender);
    }

    private function ma(array $set = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => self::TEAM, 'first_name' => 'Anna', 'last_name' => 'Test', 'is_active' => true,
            'portal_v2_since' => '2026-10-01 00:00:00',
        ], $set));
    }

    private function daten(array $set = []): array
    {
        return array_merge(['title' => 'Verschwiegenheit', 'category' => 'contract', 'action' => 'sign'], $set);
    }

    public function test_bereitstellen_legt_dokument_und_empfaenger_an_und_benachrichtigt(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben']);

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'Verschwiegenheit.pdf', [$a->id, $b->id], null, 9);

        $dok = $r['dokument'];
        $this->assertInstanceOf(RecDocument::class, $dok);
        $this->assertSame(hash('sha256', self::PDF), $dok->file_sha256);
        $this->assertSame(strlen(self::PDF), $dok->file_size);
        $this->assertSame('test-local', $dok->disk);
        $this->assertSame(9, $dok->created_by_user_id);
        $this->assertSame(self::PDF, $this->speicher->inhalt($dok->stored_path));

        $this->assertSame(2, $r['empfaenger']);
        $this->assertTrue($r['versand_noetig']);
        $this->assertSame([], $this->sender->angeschrieben, 'Bereitstellen schickt nichts — das tut der Job');

        $zeilen = RecDocumentRecipient::where('rec_document_id', $dok->id)->orderBy('rec_employee_id')->get();
        $this->assertCount(2, $zeilen);
        $this->assertNull($zeilen[0]->notified_at);
        $this->assertNull($zeilen[0]->notify_error);
        $this->assertNotEmpty($zeilen[0]->uuid);
    }

    public function test_hinweise_versenden_schreibt_erfolg_und_fehler_je_person(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben', 'phone' => null]);
        $this->sender->antworten[$b->id] = DokumentHinweisSender::STATUS_NO_PHONE;
        $dok = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id, $b->id], null, null)['dokument'];

        $e = $this->service()->hinweiseVersenden($dok);

        $this->assertSame(['versucht' => 2, 'benachrichtigt' => 1, 'fehler' => ['no_phone' => 1]], $e);
        $this->assertSame([$a->id, $b->id], $this->sender->angeschrieben);
        $za = RecDocumentRecipient::where('rec_employee_id', $a->id)->first();
        $zb = RecDocumentRecipient::where('rec_employee_id', $b->id)->first();
        $this->assertNotNull($za->notified_at);
        $this->assertNull($za->notify_error);
        $this->assertNull($zb->notified_at);
        $this->assertSame('no_phone', $zb->notify_error);
    }

    public function test_hinweise_versenden_ist_idempotent_und_laesst_zurueckgezogene_aus(): void
    {
        $a = $this->ma();
        $b = $this->ma(['first_name' => 'Ben']);
        $c = $this->ma(['first_name' => 'Cem']);
        $this->sender->antworten[$b->id] = DokumentHinweisSender::STATUS_FAILED;
        $dok = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id, $b->id, $c->id], null, null)['dokument'];
        DB::table('rec_document_recipients')->where('rec_employee_id', $c->id)->update(['withdrawn_at' => '2026-10-09 10:00:00']);

        $erster = $this->service()->hinweiseVersenden($dok);
        $this->sender->angeschrieben = [];
        $zweiter = $this->service()->hinweiseVersenden($dok);

        $this->assertSame(['versucht' => 2, 'benachrichtigt' => 1, 'fehler' => ['failed' => 1]], $erster, 'Cem ist zurueckgezogen');
        $this->assertSame(['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => []], $zweiter, 'auch der Fehlschlag wird nicht von selbst wiederholt — das ist Erneut senden');
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_nur_ablegen_braucht_keinen_versand(): void
    {
        $a = $this->ma();

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(['category' => 'payslip', 'action' => 'none']), self::PDF, 'x.pdf', [$a->id], null, null);

        $this->assertFalse($r['versand_noetig']);
        $this->assertSame(['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => []], $this->service()->hinweiseVersenden($r['dokument']));
        $this->assertSame([], $this->sender->angeschrieben);
    }

    public function test_zwei_anstellungen_einer_person_sind_ein_empfaenger(): void
    {
        $rg = $this->ma(['person_key' => 'p-1', 'company' => 'RG']);
        $maUg = $this->ma(['person_key' => 'p-1', 'company' => 'MA']);

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$maUg->id, $rg->id], null, null);

        $this->assertSame(1, $r['empfaenger']);
        $this->service()->hinweiseVersenden($r['dokument']);
        $this->assertSame([$rg->id], $this->sender->angeschrieben, 'kleinere id traegt die Zustellung');
        $this->assertSame('p-1', RecDocumentRecipient::first()->person_key);
    }

    public function test_fremdes_team_wird_stumm_uebersprungen(): void
    {
        $eigen = $this->ma();
        $fremd = $this->ma(['team_id' => 99]);

        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$eigen->id, $fremd->id, 123456], null, null);

        $this->assertSame(1, $r['empfaenger']);
        $this->assertSame([$eigen->id], RecDocumentRecipient::pluck('rec_employee_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_keine_pdf_wird_abgelehnt_und_nichts_gespeichert(): void
    {
        $a = $this->ma();

        try {
            $this->service()->bereitstellen(self::TEAM, $this->daten(), "\xFF\xD8\xFFjpg", 'foto.pdf', [$a->id], null, null);
            $this->fail('Ausnahme erwartet');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Die Datei ist keine PDF.', $e->getMessage());
        }
        $this->assertSame(0, RecDocument::count());
        $this->assertSame([], glob($this->root . '/recruiting/dokumente/*/*') ?: []);
    }

    public function test_leere_empfaengerliste_und_leerer_titel_werden_abgelehnt(): void
    {
        $a = $this->ma();

        try {
            $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [], null, null);
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Bitte mindestens einen Empfänger wählen.', $e->getMessage());
        }
        try {
            $this->service()->bereitstellen(self::TEAM, $this->daten(['title' => '  ']), self::PDF, 'x.pdf', [$a->id], null, null);
            $this->fail();
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Bitte einen Titel angeben.', $e->getMessage());
        }
        $this->assertSame(0, RecDocument::count());
    }

    public function test_unbekannte_aktion_wird_abgelehnt(): void
    {
        $a = $this->ma();
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->bereitstellen(self::TEAM, $this->daten(['action' => 'read']), self::PDF, 'x.pdf', [$a->id], null, null);
    }

    public function test_veranstaltung_wird_am_dokument_vermerkt(): void
    {
        $a = $this->ma();
        $r = $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id], 77, null);
        $this->assertSame(77, $r['dokument']->rec_dispo_event_id);
    }

    public function test_bereitstellen_schreibt_nie_auf_rec_employees(): void
    {
        $a = $this->ma();
        $vorher = DB::table('rec_employees')->where('id', $a->id)->value('updated_at');
        $marker = DB::table('rec_employees')->where('id', $a->id)->value('zas_changed_at');

        $this->service()->bereitstellen(self::TEAM, $this->daten(), self::PDF, 'x.pdf', [$a->id], null, null);

        $this->assertSame($vorher, DB::table('rec_employees')->where('id', $a->id)->value('updated_at'));
        $this->assertSame($marker, DB::table('rec_employees')->where('id', $a->id)->value('zas_changed_at'));
    }
}
