<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Services\Flynk\FlynkAttachment;
use Platform\Recruiting\Services\Flynk\FlynkClient;
use Platform\Recruiting\Services\Flynk\FlynkPostingPayloadBuilder;
use Platform\Recruiting\Services\Flynk\FlynkPostingReconciler;
use Platform\Recruiting\Services\Flynk\FlynkResponseMapper;
use Platform\Recruiting\Services\Flynk\FlynkResult;
use Platform\Recruiting\Services\Flynk\PostingAnzeigeStore;
use Platform\Recruiting\Services\PostingExpiry;

/**
 * Stellenanzeige als Datei an FLYNK + Start-/Enddatum (16.11.2026).
 *
 * Was hier festgenagelt wird:
 *  - die Datei geht mit „veroeffentlichen"/„aktualisieren" mit, nie mit „entfernen"
 *  - ein Dateitausch loest genau EINE „aktualisieren"-Meldung aus
 *  - lehnt FLYNK die Datei ab, steht das an der Zeile — und es entsteht KEINE
 *    Schleife neuer Aufgaben alle 30 Minuten
 *  - vor dem Startdatum geht nichts raus
 *  - der Endtag zaehlt mit; danach wird die Ausschreibung inaktiv und FLYNK
 *    bekommt „entfernen"
 *
 * Aufbau wie die anderen Integrationstests (Container + Capsule von Hand, echte
 * Migrationen, feste Uhr). Der FLYNK-Client ist eine Attrappe, die mitschreibt;
 * der echte Client wird am Ende einmal gegen Http::fake geprueft.
 */
class FlynkAnzeigeDateiTest extends TestCase
{
    private const TEAM = 10;
    private const ENDTAG_MITTAGS = '2026-11-16 12:00:00';
    private const FOLGETAG_FRUEH = '2026-11-17 00:10:00';

    private static string $root;
    private PostingAnzeigeStore $store;
    private object $client;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $schema = $capsule->getConnection()->getSchemaBuilder();
        foreach (['teams', 'users', 'hcm_job_titles', 'comms_channels'] as $fremd) {
            if (!$schema->hasTable($fremd)) {
                $schema->create($fremd, fn ($table) => $table->id());
            }
        }
        if (!$schema->hasTable('crm_contact_links')) {
            $schema->create('crm_contact_links', function ($table) {
                $table->id();
                $table->string('linkable_type');
                $table->unsignedBigInteger('linkable_id');
                $table->timestamps();
            });
        }

        self::runRealMigrations();

        self::$root = sys_get_temp_dir() . '/rec-posting-anzeige-' . bin2hex(random_bytes(4));
        mkdir(self::$root, 0777, true);
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance('config');
        Container::getInstance()->forgetInstance('log');
        Carbon::setTestNow();
        self::rrmdir(self::$root);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'recruiting' => ['flynk' => ['per_run_cap' => 50, 'max_attempts' => 5, 'careers_url' => null]],
        ]));
        $container->instance('log', new \Psr\Log\NullLogger());
        Facade::clearResolvedInstance('log');
        Facade::clearResolvedInstance('config');

        Capsule::table('rec_posting_flynk_syncs')->delete();
        Capsule::table('rec_postings')->delete();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));

        $this->store = new PostingAnzeigeStore(
            new FilesystemAdapter(new Flysystem(new LocalFilesystemAdapter(self::$root)), new LocalFilesystemAdapter(self::$root), ['root' => self::$root]),
            'testdisk'
        );

        $this->client = new class extends FlynkClient {
            /** @var array<int,array{payload:array,attachment:?FlynkAttachment}> */
            public array $calls = [];
            /** @var array<string,mixed>|null Antwort-Rumpf der naechsten 201 */
            public ?array $body = null;
            /** Statuscode der naechsten Antworten (null = 201) */
            public ?int $status = null;

            public function __construct()
            {
                parent::__construct('http://flynk.test/api', 't', 10);
            }

            public function createTask(array $payload, ?FlynkAttachment $attachment = null): FlynkResult
            {
                $this->calls[] = ['payload' => $payload, 'attachment' => $attachment];
                $body = $this->body ?? (['id' => count($this->calls)] + ($attachment ? ['attachments' => [['name' => $attachment->filename]], 'skipped_files' => []] : []));

                return FlynkResponseMapper::map($this->status ?? 201, $this->status ? ($this->body ?? []) : $body, fileSent: $attachment !== null);
            }
        };
    }

    // ------------------------------------------------------------------
    // Enddatum: der Endtag zaehlt mit
    // ------------------------------------------------------------------

    public function test_am_endtag_ist_die_ausschreibung_noch_offen_am_folgetag_nicht_mehr(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-16 00:00:00']);

        Carbon::setTestNow(Carbon::parse(self::ENDTAG_MITTAGS));
        $this->assertFalse(RecPosting::find($id)->isExpired());
        $this->assertSame([$id], RecPosting::query()->open()->pluck('id')->all(), 'Vorher lief sie am Endtag um 00:00 ab');

        Carbon::setTestNow(Carbon::parse('2026-11-16 23:59:00'));
        $this->assertSame([$id], RecPosting::query()->open()->pluck('id')->all());

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->assertTrue(RecPosting::find($id)->isExpired());
        $this->assertSame([], RecPosting::query()->open()->pluck('id')->all());
    }

    public function test_abgelaufene_werden_inaktiv_alle_anderen_bleiben(): void
    {
        $abgelaufen = $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $laeuftHeuteAus = $this->posting(['closes_at' => '2026-11-17 00:00:00']);
        $ohneEnde = $this->posting(['closes_at' => null]);
        $schonInaktiv = $this->posting(['closes_at' => '2026-10-01 00:00:00', 'is_active' => 0]);

        Carbon::setTestNow(Carbon::parse(self::ENDTAG_MITTAGS));
        $this->assertSame([], (new PostingExpiry())->deactivateExpired(), 'am Endtag passiert noch nichts');

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->assertSame([$abgelaufen], (new PostingExpiry())->deactivateExpired());

        $this->assertFalse((bool) RecPosting::find($abgelaufen)->is_active);
        $this->assertSame('published', RecPosting::find($abgelaufen)->status, 'nur der Schalter, nicht der Status');
        $this->assertTrue((bool) RecPosting::find($laeuftHeuteAus)->is_active);
        $this->assertTrue((bool) RecPosting::find($ohneEnde)->is_active, 'ohne Enddatum nie');
        $this->assertFalse((bool) RecPosting::find($schonInaktiv)->is_active);

        $this->assertSame([], (new PostingExpiry())->deactivateExpired(), 'zweiter Lauf findet nichts mehr');
    }

    // ------------------------------------------------------------------
    // Ablage der Datei
    // ------------------------------------------------------------------

    public function test_hochladen_ersetzen_entfernen(): void
    {
        $id = $this->posting();
        $p = RecPosting::find($id);

        $this->store->put($p, 'PDF-EINS', 'Anzeige Medica.pdf', 'application/pdf');
        $erste = RecPosting::find($id);
        $this->assertSame('Anzeige Medica.pdf', $erste->anzeige_filename);
        $this->assertSame(hash('sha256', 'PDF-EINS'), $erste->anzeige_hash);
        $this->assertSame(8, $erste->anzeige_size);
        $this->assertSame('testdisk', $erste->anzeige_disk);
        $this->assertSame('PDF-EINS', $this->store->contents($erste));
        $this->assertFileExists(self::$root . '/' . $erste->anzeige_path);

        $alterPfad = $erste->anzeige_path;
        $alterHash = $erste->anzeige_hash;
        $this->store->put($erste, 'PDF-ZWEI', 'neu.docx', null);
        $zweite = RecPosting::find($id);
        $this->assertNotSame($alterPfad, $zweite->anzeige_path);
        $this->assertSame('PDF-ZWEI', $this->store->contents($zweite));
        $this->assertFileDoesNotExist(self::$root . '/' . $alterPfad, 'die alte Datei ist weg');
        $this->assertNotSame($alterHash, $zweite->anzeige_hash);

        $pfad = $zweite->anzeige_path;
        $this->store->remove($zweite);
        $leer = RecPosting::find($id);
        $this->assertFalse($leer->hasAnzeigeDatei());
        $this->assertNull($leer->anzeige_hash);
        $this->assertFileDoesNotExist(self::$root . '/' . $pfad);
    }

    public function test_falscher_typ_wird_nicht_abgelegt(): void
    {
        $p = RecPosting::find($this->posting());

        try {
            $this->store->put($p, 'BILD', 'anzeige.png', 'image/png');
            $this->fail('PNG haette abgelehnt werden muessen');
        } catch (\InvalidArgumentException) {
        }

        $this->assertFalse(RecPosting::find($p->id)->hasAnzeigeDatei());
    }

    // ------------------------------------------------------------------
    // Versand
    // ------------------------------------------------------------------

    public function test_veroeffentlichen_schickt_die_datei_mit(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-16 00:00:00', 'published_at' => '2026-10-23 00:00:00']);
        $this->store->put(RecPosting::find($id), 'PDF-EINS', 'anzeige.pdf', 'application/pdf');

        $summary = $this->run_();

        $this->assertSame(1, $summary['sent']);
        $this->assertCount(1, $this->client->calls);
        $call = $this->client->calls[0];
        $this->assertSame('new_section', $call['payload']['type']);
        $this->assertSame('2026-11-16', $call['payload']['meta']['closes_at'], 'als Datum, nicht als Mitternachts-Zeitstempel');
        $this->assertSame('2026-10-23', $call['payload']['meta']['published_at']);
        $this->assertNotNull($call['attachment']);
        $this->assertSame('anzeige.pdf', $call['attachment']->filename);
        $this->assertSame('PDF-EINS', $call['attachment']->contents);
        $this->assertSame('application/pdf', $call['attachment']->mime);

        $row = Capsule::table('rec_posting_flynk_syncs')->first();
        $this->assertSame('sent', $row->status);
        $this->assertNull($row->last_error);
        $this->assertSame(
            FlynkPostingPayloadBuilder::contentHash('Titel', 'Text', null, null, hash('sha256', 'PDF-EINS')),
            $row->content_hash
        );
    }

    public function test_ohne_datei_laeuft_alles_wie_bisher(): void
    {
        $this->posting();

        $this->run_();

        $this->assertCount(1, $this->client->calls);
        $this->assertNull($this->client->calls[0]['attachment']);
        $this->assertSame(
            hash('sha256', "Titel\nText\n"),
            Capsule::table('rec_posting_flynk_syncs')->value('content_hash'),
            'der Hash ohne Datei ist der bisherige — sonst ginge beim Deploy eine Update-Welle raus'
        );
    }

    public function test_dateitausch_loest_genau_eine_aktualisierung_aus(): void
    {
        $id = $this->posting();
        $this->store->put(RecPosting::find($id), 'PDF-EINS', 'anzeige.pdf', 'application/pdf');
        $this->run_();
        $this->run_();
        $this->assertCount(1, $this->client->calls, 'unveraenderte Datei = keine zweite Meldung');

        $this->store->put(RecPosting::find($id), 'PDF-ZWEI', 'anzeige-v2.pdf', 'application/pdf');
        $this->run_();
        $this->run_();

        $this->assertCount(2, $this->client->calls);
        $this->assertSame('text_change', $this->client->calls[1]['payload']['type']);
        $this->assertSame('update', $this->client->calls[1]['payload']['meta']['event']);
        $this->assertSame('PDF-ZWEI', $this->client->calls[1]['attachment']->contents);
    }

    public function test_nachtraeglich_angehaengte_datei_geht_als_aktualisierung_raus(): void
    {
        $id = $this->posting();
        $this->run_();
        $this->assertNull($this->client->calls[0]['attachment']);

        $this->store->put(RecPosting::find($id), 'PDF-EINS', 'anzeige.pdf', 'application/pdf');
        $this->run_();

        $this->assertCount(2, $this->client->calls);
        $this->assertSame('PDF-EINS', $this->client->calls[1]['attachment']->contents);
    }

    public function test_abgelehnte_datei_steht_an_der_zeile_und_erzeugt_keine_schleife(): void
    {
        $id = $this->posting();
        $this->store->put(RecPosting::find($id), 'PDF-EINS', 'anzeige.pdf', 'application/pdf');
        $this->client->body = ['id' => 77, 'attachments' => [], 'skipped_files' => [['name' => 'anzeige.pdf', 'reason' => 'virus']]];

        $summary = $this->run_();

        $this->assertSame(1, $summary['sent']);
        $this->assertSame(1, $summary['file_rejected']);
        $row = Capsule::table('rec_posting_flynk_syncs')->first();
        $this->assertSame('sent', $row->status, 'die Aufgabe liegt bei FLYNK — kein Retry, der eine zweite anlegt');
        $this->assertStringContainsString('virus', (string) $row->last_error);

        $this->run_();
        $this->run_();
        $this->assertCount(1, $this->client->calls, 'keine neue Aufgabe alle 30 Minuten');

        // Neue Datei → geht raus, und die Warnung der alten Zeile ist ueberholt.
        $this->client->body = null;
        $this->store->put(RecPosting::find($id), 'PDF-ZWEI', 'besser.pdf', 'application/pdf');
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $letzte = Capsule::table('rec_posting_flynk_syncs')->orderByDesc('id')->first();
        $this->assertSame('update', $letzte->event_type);
        $this->assertNull($letzte->last_error);
    }

    public function test_fehlt_die_datei_auf_der_platte_wird_nichts_gesendet(): void
    {
        $id = $this->posting();
        $this->store->put(RecPosting::find($id), 'PDF-EINS', 'anzeige.pdf', 'application/pdf');
        unlink(self::$root . '/' . RecPosting::find($id)->anzeige_path);

        $summary = $this->run_();

        $this->assertCount(0, $this->client->calls, 'lieber gar nicht senden als eine Anzeige ohne Anzeige');
        $this->assertSame(1, $summary['permanent']);
        $row = Capsule::table('rec_posting_flynk_syncs')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->permanent_failure, 'eine fehlende Datei kommt nicht von selbst zurueck');
        $this->assertStringContainsString('nicht lesbar', (string) $row->last_error);

        // Sechs weitere Laeufe (mehr als max_attempts): nichts Neues.
        for ($i = 0; $i < 6; $i++) {
            $this->run_();
        }
        $this->assertCount(0, $this->client->calls);
        $this->assertSame(1, Capsule::table('rec_posting_flynk_syncs')->count(), 'keine neue Zeile je Lauf');

        // HR laedt eine neue Datei hoch → die Sackgasse loest sich.
        $this->store->put(RecPosting::find($id), 'PDF-NEU', 'neu.pdf', 'application/pdf');
        $this->run_();
        $this->assertCount(1, $this->client->calls);
        $this->assertSame('publish', $this->client->calls[0]['payload']['meta']['event']);
        $this->assertSame('PDF-NEU', $this->client->calls[0]['attachment']->contents);
        $this->assertSame(['sent'], Capsule::table('rec_posting_flynk_syncs')->pluck('status')->all());
    }

    public function test_413_beim_aktualisieren_ist_endgueltig_und_feuert_nicht_alle_30_minuten(): void
    {
        $id = $this->posting();
        $this->run_();
        $this->assertCount(1, $this->client->calls);

        $this->store->put(RecPosting::find($id), 'ZU-GROSS', 'riesig.pdf', 'application/pdf');
        $this->client->status = 413;
        for ($i = 0; $i < 7; $i++) {
            $this->run_();
        }

        $this->assertCount(2, $this->client->calls, 'genau EIN Versuch — vorher waere es je Lauf einer gewesen');
        $update = Capsule::table('rec_posting_flynk_syncs')->where('event_type', 'update')->get();
        $this->assertCount(1, $update);
        $this->assertSame('failed', $update[0]->status);
        $this->assertSame(1, (int) $update[0]->permanent_failure);

        // Andere Datei → neuer Versuch.
        $this->client->status = null;
        $this->store->put(RecPosting::find($id), 'KLEINER', 'klein.pdf', 'application/pdf');
        $this->run_();
        $this->assertCount(3, $this->client->calls);
        $this->assertSame('KLEINER', $this->client->calls[2]['attachment']->contents);
    }

    public function test_endgueltig_gescheiterte_veroeffentlichung_geht_nach_einer_aenderung_neu_raus(): void
    {
        $id = $this->posting();
        $this->client->status = 422;
        $this->run_();
        $this->run_();
        $this->assertCount(1, $this->client->calls, 'unveraendert wird nicht wiederholt');

        $this->client->status = null;
        Capsule::table('rec_postings')->where('id', $id)->update(['description' => 'Besserer Text']);
        $this->run_();

        $this->assertCount(2, $this->client->calls);
        $this->assertSame('publish', $this->client->calls[1]['payload']['meta']['event']);
        $this->assertSame(['sent'], Capsule::table('rec_posting_flynk_syncs')->pluck('status')->all());
    }

    public function test_startdatum_nachtraeglich_in_die_zukunft_entfernt_keine_gemeldete_anzeige(): void
    {
        $id = $this->posting();
        $this->run_();
        $this->assertCount(1, $this->client->calls);

        Capsule::table('rec_postings')->where('id', $id)->update(['published_at' => '2026-12-24 00:00:00']);
        $this->run_();
        $this->run_();

        $this->assertCount(1, $this->client->calls, 'weder „entfernen" noch eine zweite Veroeffentlichung');
        $this->assertSame(0, Capsule::table('rec_posting_flynk_syncs')->where('event_type', 'close')->count());
    }

    public function test_haengende_veroeffentlichung_wird_vor_dem_startdatum_nicht_nachgesendet(): void
    {
        $id = $this->posting(['published_at' => '2026-11-10 00:00:00']);
        Capsule::table('rec_posting_flynk_syncs')->insert([
            'rec_posting_id' => $id, 'generation' => 1, 'event_type' => 'publish', 'seq' => 0,
            'content_hash' => 'x', 'status' => 'pending', 'attempts' => 1, 'permanent_failure' => 0,
            'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
        ]);

        $summary = $this->run_();

        $this->assertCount(0, $this->client->calls, 'der Retry-Pass darf nicht am Startdatum vorbei senden');
        $this->assertSame(1, $summary['stale_deleted']);
        $this->assertSame(0, Capsule::table('rec_posting_flynk_syncs')->count());
    }

    public function test_enddatum_mit_uhrzeit_laeuft_ebenfalls_bis_zum_abend(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-16 14:00:00']);

        Carbon::setTestNow(Carbon::parse('2026-11-16 23:00:00'));
        $this->assertFalse(RecPosting::find($id)->isExpired());
        $this->assertSame([$id], RecPosting::query()->open()->pluck('id')->all());
        $this->assertSame([], (new PostingExpiry())->deactivateExpired());

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->assertTrue(RecPosting::find($id)->isExpired());
        $this->assertSame([], RecPosting::query()->open()->pluck('id')->all());
        $this->assertSame([$id], (new PostingExpiry())->deactivateExpired());
    }

    public function test_entwuerfe_und_geschlossene_fasst_der_ablauf_nicht_an(): void
    {
        $entwurf = $this->posting(['status' => 'draft', 'closes_at' => '2026-10-01 00:00:00']);
        $geschlossen = $this->posting(['status' => 'closed', 'closes_at' => '2026-10-01 00:00:00']);

        $this->assertSame([], (new PostingExpiry())->deactivateExpired());
        $this->assertTrue((bool) RecPosting::find($entwurf)->is_active);
        $this->assertTrue((bool) RecPosting::find($geschlossen)->is_active);
    }

    public function test_vor_dem_startdatum_geht_nichts_raus_danach_schon(): void
    {
        $this->posting(['published_at' => '2026-11-10 00:00:00']);

        $this->run_();
        $this->assertCount(0, $this->client->calls);
        $this->assertSame(0, Capsule::table('rec_posting_flynk_syncs')->count(), 'auch keine haengende Zeile');

        Carbon::setTestNow(Carbon::parse('2026-11-10 00:20:00'));
        $this->run_();
        $this->assertCount(1, $this->client->calls);
        $this->assertSame('publish', $this->client->calls[0]['payload']['meta']['event']);
    }

    public function test_nach_dem_endtag_inaktiv_und_entfernen_ohne_datei(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->store->put(RecPosting::find($id), 'PDF-EINS', 'anzeige.pdf', 'application/pdf');
        $this->run_();

        Carbon::setTestNow(Carbon::parse(self::ENDTAG_MITTAGS));
        (new PostingExpiry())->deactivateExpired();
        $this->run_();
        $this->assertCount(1, $this->client->calls, 'am Endtag bleibt die Anzeige stehen');

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        (new PostingExpiry())->deactivateExpired();
        $this->run_();

        $this->assertFalse((bool) RecPosting::find($id)->is_active);
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('close', $this->client->calls[1]['payload']['meta']['event']);
        $this->assertNull($this->client->calls[1]['attachment'], '„entfernen" braucht keine Datei');

        // Verlaengern: neues Enddatum + wieder aktiv → neue Veroeffentlichung mit Datei.
        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-12-01 00:00:00', 'is_active' => 1]);
        $this->run_();
        $this->assertCount(3, $this->client->calls);
        $this->assertSame('publish', $this->client->calls[2]['payload']['meta']['event']);
        $this->assertSame(2, $this->client->calls[2]['payload']['meta']['generation']);
        $this->assertSame('PDF-EINS', $this->client->calls[2]['attachment']->contents);
    }

    // ------------------------------------------------------------------
    // Enddatum geaendert → „aktualisieren" (FLYNK laesst selbst auslaufen)
    // ------------------------------------------------------------------

    public function test_nur_das_enddatum_geaendert_loest_genau_eine_aktualisierung_aus(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();
        $this->assertCount(1, $this->client->calls);
        $this->assertStringContainsString('Laufzeit: bis einschließlich 30.11.2026.', $this->client->calls[0]['payload']['description']);

        $this->run_();
        $this->assertCount(1, $this->client->calls, 'unveraendert: nichts');

        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-12-15 00:00:00']);
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('update', $this->client->calls[1]['payload']['meta']['event']);
        $this->assertSame('2026-12-15', $this->client->calls[1]['payload']['meta']['closes_at']);
        $this->assertStringContainsString('Laufzeit: bis einschließlich 15.12.2026.', $this->client->calls[1]['payload']['description']);

        $this->run_();
        $this->assertCount(2, $this->client->calls, 'kein Dauerfeuer');
    }

    public function test_enddatum_nachtraeglich_gesetzt_und_wieder_entfernt(): void
    {
        $id = $this->posting();
        $this->run_();
        $this->assertStringContainsString('Laufzeit: ohne Enddatum.', $this->client->calls[0]['payload']['description']);

        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('2026-11-30', $this->client->calls[1]['payload']['meta']['closes_at']);

        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => null]);
        $this->run_();
        $this->assertCount(3, $this->client->calls);
        $this->assertSame('update', $this->client->calls[2]['payload']['meta']['event']);
        $this->assertNull($this->client->calls[2]['payload']['meta']['closes_at']);
        $this->assertStringContainsString('Laufzeit: ohne Enddatum.', $this->client->calls[2]['payload']['description']);
    }

    public function test_zeile_ohne_gemerktes_enddatum_loest_bei_datumsaenderung_nichts_aus(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();
        Capsule::table('rec_posting_flynk_syncs')->update(['closes_key' => null]); // wie vor der Migration

        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-12-15 00:00:00']);
        $this->run_();
        $this->assertCount(1, $this->client->calls, 'unbekannt heisst: nicht raten');
    }

    public function test_migration_gibt_dem_bestand_das_heutige_enddatum_ohne_meldung(): void
    {
        $mit = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $ohne = $this->posting();
        $this->run_();
        $this->assertCount(2, $this->client->calls);

        $schema = Capsule::schema();
        $schema->table('rec_posting_flynk_syncs', fn ($t) => $t->dropColumn(['closes_key', 'closes_assumed']));
        (require dirname(__DIR__, 2) . '/database/migrations/2026_11_17_000001_add_closes_key_to_rec_posting_flynk_syncs.php')->up();

        $keys = Capsule::table('rec_posting_flynk_syncs')->pluck('closes_key', 'rec_posting_id')->all();
        $this->assertSame('2026-11-30', $keys[$mit]);
        $this->assertSame('none', $keys[$ohne]);
        $this->assertSame(2, Capsule::table('rec_posting_flynk_syncs')->where('closes_assumed', 1)->count());

        $this->run_();
        $this->assertCount(2, $this->client->calls, 'der Ausgangswert loest selbst nichts aus');

        Capsule::table('rec_postings')->where('id', $mit)->update(['closes_at' => '2026-12-15 00:00:00']);
        $this->run_();
        $this->assertCount(3, $this->client->calls, 'aber eine spaetere Aenderung wird erkannt');
        $this->assertSame('update', $this->client->calls[2]['payload']['meta']['event']);
    }

    public function test_nachgesendete_meldung_traegt_das_aktuelle_enddatum_und_loest_keine_zweite_aus(): void
    {
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->client->status = 500;
        $this->run_();
        $this->client->status = null;
        $this->assertCount(1, $this->client->calls);

        // Zwischen Fehlversuch und Nachsenden wird das Enddatum geaendert.
        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-12-15 00:00:00']);
        $this->run_();
        $this->assertCount(2, $this->client->calls, 'nur das Nachsenden, kein „aktualisieren" obendrauf');
        $this->assertSame('publish', $this->client->calls[1]['payload']['meta']['event']);
        $this->assertSame('2026-12-15', $this->client->calls[1]['payload']['meta']['closes_at']);

        $this->run_();
        $this->assertCount(2, $this->client->calls, 'auch im naechsten Lauf nicht');
    }

    // ------------------------------------------------------------------
    // Stilles Auslaufen: FLYNK nimmt am Enddatum selbst von der Seite
    // ------------------------------------------------------------------

    public function test_mit_stichtag_laeuft_die_anzeige_still_aus_und_verlaengern_veroeffentlicht_neu(): void
    {
        $this->stichtag('2026-11-01');
        $id = $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->run_();

        Carbon::setTestNow(Carbon::parse(self::ENDTAG_MITTAGS));
        (new PostingExpiry())->deactivateExpired();
        $this->run_();
        $this->assertSame(0, Capsule::table('rec_posting_flynk_syncs')->where('event_type', 'close')->count(), 'am Endtag noch nichts');

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        (new PostingExpiry())->deactivateExpired();
        $summary = $this->run_();

        $this->assertFalse((bool) RecPosting::find($id)->is_active);
        $this->assertCount(1, $this->client->calls, 'kein „entfernen" an FLYNK');
        $this->assertSame(1, $summary['expired_silent']);
        $close = Capsule::table('rec_posting_flynk_syncs')->where('event_type', 'close')->first();
        $this->assertSame('expired', $close->status);

        $summary = $this->run_();
        $this->assertCount(1, $this->client->calls);
        $this->assertSame(0, $summary['expired_silent'], 'nur einmal verbucht');

        // Verlaengern: frische Veroeffentlichung, nicht „aktualisieren" einer
        // Anzeige, die FLYNK schon heruntergenommen hat.
        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-12-01 00:00:00', 'is_active' => 1]);
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('publish', $this->client->calls[1]['payload']['meta']['event']);
        $this->assertSame(2, $this->client->calls[1]['payload']['meta']['generation']);
    }

    public function test_vor_dem_stichtag_gemeldete_anzeige_bekommt_weiter_entfernen(): void
    {
        $this->stichtag('2026-11-10'); // gemeldet wird am 02.11.
        $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->run_();

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        (new PostingExpiry())->deactivateExpired();
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('close', $this->client->calls[1]['payload']['meta']['event']);
    }

    public function test_am_stichtag_selbst_gemeldet_zaehlt_schon(): void
    {
        $this->stichtag('2026-11-02');
        $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->run_();

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->run_();
        $this->assertCount(1, $this->client->calls);
    }

    public function test_enddatum_nachtraeglich_in_die_vergangenheit_schickt_echtes_entfernen(): void
    {
        $this->stichtag('2026-11-01');
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();

        // FLYNK kennt den 30.11. — bei uns wird am 17.11. auf den 10.11. gekuerzt.
        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-11-10 00:00:00']);
        (new PostingExpiry())->deactivateExpired();
        $this->run_();

        $this->assertCount(2, $this->client->calls);
        $this->assertSame('close', $this->client->calls[1]['payload']['meta']['event']);
    }

    public function test_von_hand_vor_dem_enddatum_geschlossen_schickt_entfernen(): void
    {
        $this->stichtag('2026-11-01');
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();

        Capsule::table('rec_postings')->where('id', $id)->update(['is_active' => 0]);
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('close', $this->client->calls[1]['payload']['meta']['event']);
    }

    public function test_kam_das_neue_enddatum_nie_bei_flynk_an_gibt_es_ein_echtes_entfernen(): void
    {
        $this->stichtag('2026-11-01');
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();

        // Kuerzen auf den 16.11. — die „aktualisieren"-Meldung scheitert endgueltig.
        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-11-16 00:00:00']);
        $this->client->status = 422;
        $this->run_();
        $this->client->status = null;
        $this->assertCount(2, $this->client->calls);

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        (new PostingExpiry())->deactivateExpired();
        $this->run_();
        $this->assertCount(3, $this->client->calls, 'FLYNK kennt noch den 30.11.');
        $this->assertSame('close', $this->client->calls[2]['payload']['meta']['event']);
    }

    public function test_bei_der_migration_nur_angenommenes_enddatum_reicht_nicht_fuer_stilles_auslaufen(): void
    {
        // Stichtag vor dem Deploy: gemeldet mit 30.11., VOR der Migration auf
        // den 16.11. gekuerzt (der alte Code meldete das nicht). Die Migration
        // traegt den 16.11. als Annahme ein — FLYNK kennt aber den 30.11.
        $this->stichtag('2026-11-01');
        $id = $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $this->run_();
        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-11-16 00:00:00']);
        Capsule::table('rec_posting_flynk_syncs')->update(['closes_key' => '2026-11-16', 'closes_assumed' => 1]);

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        (new PostingExpiry())->deactivateExpired();
        $this->run_();

        $this->assertCount(2, $this->client->calls);
        $this->assertSame('close', $this->client->calls[1]['payload']['meta']['event']);
    }

    public function test_auch_die_verlaengerte_anzeige_laeuft_wieder_still_aus(): void
    {
        $this->stichtag('2026-11-01');
        $id = $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->run_();
        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->run_();

        Capsule::table('rec_postings')->where('id', $id)->update(['closes_at' => '2026-11-20 00:00:00', 'is_active' => 1]);
        $this->run_();
        $this->assertCount(2, $this->client->calls);

        Carbon::setTestNow(Carbon::parse('2026-11-21 00:10:00'));
        $summary = $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame(1, $summary['expired_silent']);
        $this->assertSame(2, Capsule::table('rec_posting_flynk_syncs')->where('status', 'expired')->count());
    }

    public function test_nachgesendete_meldung_ist_keine_annahme_mehr(): void
    {
        // Haengende Meldung zum Zeitpunkt der Migration: bekommt dort einen
        // angenommenen Wert, wird danach aber WIRKLICH mit Datum zugestellt.
        $this->stichtag('2026-11-01');
        $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->client->status = 500;
        $this->run_();
        $this->client->status = null;
        Capsule::table('rec_posting_flynk_syncs')->update(['closes_assumed' => 1]);

        $this->run_();
        $this->assertSame(0, (int) Capsule::table('rec_posting_flynk_syncs')->value('closes_assumed'));

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->run_();
        $this->assertCount(2, $this->client->calls, 'still ausgelaufen, kein „entfernen"');
    }

    public function test_erst_nach_dem_stichtag_zugestellte_meldung_zaehlt(): void
    {
        // Angelegt am 02.11. (Fehlversuch), zugestellt am 12.11. — Stichtag 10.11.
        $this->stichtag('2026-11-10');
        $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->client->status = 500;
        $this->run_();
        $this->client->status = null;

        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00'));
        $this->run_();
        $this->assertCount(2, $this->client->calls);

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->run_();
        $this->assertCount(2, $this->client->calls, 'still ausgelaufen');
    }

    public function test_ohne_die_neue_spalte_wird_gar_nicht_gesendet(): void
    {
        $this->posting(['closes_at' => '2026-11-30 00:00:00']);
        $schema = Capsule::schema();
        $schema->table('rec_posting_flynk_syncs', fn ($t) => $t->dropColumn(['closes_key', 'closes_assumed']));
        try {
            $this->run_();
            $this->assertCount(0, $this->client->calls);
        } finally {
            (require dirname(__DIR__, 2) . '/database/migrations/2026_11_17_000001_add_closes_key_to_rec_posting_flynk_syncs.php')->up();
        }

        $this->run_();
        $this->assertCount(1, $this->client->calls, 'nach der Migration laeuft es normal');
    }

    public function test_unlesbarer_stichtag_laesst_die_automatik_aus(): void
    {
        $this->stichtag('demnaechst irgendwann');
        $this->posting(['closes_at' => '2026-11-16 00:00:00']);
        $this->run_();

        Carbon::setTestNow(Carbon::parse(self::FOLGETAG_FRUEH));
        $this->run_();
        $this->assertCount(2, $this->client->calls);
        $this->assertSame('close', $this->client->calls[1]['payload']['meta']['event']);
    }

    // ------------------------------------------------------------------
    // Der echte Client: Formular-Upload statt JSON
    // ------------------------------------------------------------------

    public function test_der_client_schickt_mit_datei_ein_formular_und_ohne_json(): void
    {
        $http = new HttpFactory();
        Container::getInstance()->instance(HttpFactory::class, $http);
        Facade::clearResolvedInstance(HttpFactory::class);
        $http->fake(['*' => $http->response(['id' => 9, 'attachments' => [['name' => 'anzeige.pdf']], 'skipped_files' => []], 201)]);

        $client = new FlynkClient('http://flynk.test/api', 'geheim', 10, 120);
        $payload = ['title' => 'Stellenanzeige: X', 'type' => 'new_section', 'description' => 'Text', 'meta' => ['ref_code' => 'RG-FZCV', 'activity' => null]];

        $mit = $client->createTask($payload, new FlynkAttachment('anzeige.pdf', 'PDF-INHALT', 'application/pdf'));
        $ohne = $client->createTask($payload);

        $this->assertTrue($mit->ok);
        $this->assertNull($mit->fileProblem);
        $this->assertTrue($ohne->ok);

        // Abgelehnte Datei muss ueber den ECHTEN Client als Problem ankommen
        // (nicht nur ueber die Attrappe der anderen Tests).
        $http2 = new HttpFactory();
        Container::getInstance()->instance(HttpFactory::class, $http2);
        Facade::clearResolvedInstance(HttpFactory::class);
        $http2->fake(['*' => $http2->response(['id' => 10, 'attachments' => [], 'skipped_files' => [['name' => 'anzeige.pdf', 'reason' => 'zu gross']]], 201)]);
        $abgelehnt = $client->createTask($payload, new FlynkAttachment('anzeige.pdf', 'PDF-INHALT'));
        $ohneDatei = $client->createTask($payload);
        $this->assertTrue($abgelehnt->ok);
        $this->assertStringContainsString('zu gross', (string) $abgelehnt->fileProblem);
        $this->assertNull($ohneDatei->fileProblem, 'ohne Datei kann keine fehlen');

        $recorded = $http->recorded();
        $this->assertCount(2, $recorded);

        /** @var HttpRequest $upload */
        $upload = $recorded[0][0];
        $this->assertSame('http://flynk.test/api/webhooks/tasks', $upload->url());
        $this->assertTrue($upload->isMultipart());
        $this->assertTrue($upload->hasHeader('Authorization', 'Bearer geheim'));
        $this->assertTrue($upload->hasHeader('Accept', 'application/json'));
        $body = $upload->body();
        $this->assertStringContainsString('name="files[]"; filename="anzeige.pdf"', $body);
        $this->assertStringContainsString('PDF-INHALT', $body);
        $this->assertStringContainsString('name="meta[ref_code]"', $body);
        $this->assertStringContainsString('RG-FZCV', $body);
        $this->assertStringContainsString('name="title"', $body);
        $this->assertStringNotContainsString('meta[activity]', $body, 'null-Werte werden nicht als leere Felder geschickt');

        /** @var HttpRequest $json */
        $json = $recorded[1][0];
        $this->assertTrue($json->isJson());
        $this->assertSame('RG-FZCV', $json->data()['meta']['ref_code']);

        Container::getInstance()->forgetInstance(HttpFactory::class);
        Facade::clearResolvedInstance(HttpFactory::class);
    }

    // ------------------------------------------------------------------

    private function stichtag(string $wert): void
    {
        Container::getInstance()->make('config')->set('recruiting.flynk.auto_expiry_since', $wert);
    }

    private function run_(): array
    {
        return (new FlynkPostingReconciler($this->client, $this->store))->run();
    }

    private function posting(array $o = []): int
    {
        return (int) Capsule::table('rec_postings')->insertGetId($o + [
            'uuid' => bin2hex(random_bytes(16)),
            'rec_position_id' => 1,
            'team_id' => self::TEAM,
            'title' => 'Titel',
            'description' => 'Text',
            'status' => 'published',
            'is_active' => 1,
            'published_at' => null,
            'closes_at' => null,
            'created_at' => '2026-10-01 00:00:00',
            'updated_at' => '2026-10-01 00:00:00',
        ]);
    }

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        throw new \RuntimeException("Paketwurzel nicht gefunden: {$class}");
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
