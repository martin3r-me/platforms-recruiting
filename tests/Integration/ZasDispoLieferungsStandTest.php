<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecZasDispoInboundFile;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoLieferungsStand;

/**
 * „ZAS-Stand“ auf der VA-Liste (Spec 2026-11-04): letzte Lieferung MIT
 * Einbuchungen, Alterstufen, und ob eine neuere noch in der Warteschlange
 * haengt. Die Zahlen sind die vom 03.11. (Dateien #393 bis #401).
 */
final class ZasDispoLieferungsStandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-03 19:30:00');

        $container = Container::getInstance();
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $basis = dirname(__DIR__, 2) . '/database/migrations/';
        (require $basis . '2026_08_06_000001_create_rec_zas_dispo_inbound_files_table.php')->up();
        (require $basis . '2026_08_12_000003_add_processed_at_to_rec_zas_dispo_inbound_files.php')->up();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    /** @param 'processed'|'viewable'|'failed' $status */
    private function datei(string $eingang, string $status, ?int $einbuchungen = null, bool $test = false): RecZasDispoInboundFile
    {
        $notes = match (true) {
            $status !== 'processed' => null,
            $einbuchungen === null  => json_encode(['blocks_found' => false]),
            default                 => json_encode(['blocks_found' => true, 'stats' => ['assignments' => $einbuchungen, 'events' => 7]]),
        };
        $f = new RecZasDispoInboundFile([
            'source' => 'zas', 'disk' => 'local', 'stored_path' => 'x', 'is_test' => $test,
            'parse_status' => $status, 'notes' => $notes,
            'processed_at' => $status === 'viewable' ? null : $eingang,
        ]);
        $f->created_at = Carbon::parse($eingang);
        $f->save();

        return $f;
    }

    public function test_ohne_lieferung_gibt_es_keinen_stand(): void
    {
        $this->assertNull(ZasDispoLieferungsStand::aktuell());
        $this->assertSame('leer', ZasDispoLieferungsStand::anzeige()['stufe']);
    }

    public function test_dateien_ohne_dispo_zeilen_zaehlen_nicht_als_stand(): void
    {
        $voll = $this->datei('2026-11-03 18:15:06', 'processed', 4195);
        $this->datei('2026-11-03 19:24:46', 'processed');            // {Zeiten}
        $this->datei('2026-11-03 19:25:00', 'failed');
        $this->datei('2026-11-03 19:26:00', 'processed', 9999, test: true);

        $s = ZasDispoLieferungsStand::aktuell();
        $this->assertSame($voll->id, $s['datei_id']);
        $this->assertSame(4195, $s['einbuchungen']);
        $this->assertSame('ok', $s['stufe']);
        $this->assertNull($s['offen']);
        $this->assertSame('ZAS-Stand: Di 03.11. 18:15 · 4.195 Einbuchungen', ZasDispoLieferungsStand::anzeige()['text']);
    }

    public function test_stufen_gelb_ab_vier_und_rot_ab_24_stunden(): void
    {
        $this->datei('2026-11-02 14:30:45', 'processed', 4244);

        Carbon::setTestNow('2026-11-02 18:30:44');
        $this->assertSame('ok', ZasDispoLieferungsStand::anzeige()['stufe'], 'eine Sekunde unter 4 Std.');

        Carbon::setTestNow('2026-11-02 18:30:45');
        $a = ZasDispoLieferungsStand::anzeige();
        $this->assertSame('gelb', $a['stufe'], 'genau 4 Std. ist gelb');
        $this->assertStringContainsString('· 4 Std. alt', $a['text']);

        Carbon::setTestNow('2026-11-03 14:30:44');
        $this->assertSame('gelb', ZasDispoLieferungsStand::anzeige()['stufe'], 'eine Sekunde unter 24 Std.');
        Carbon::setTestNow('2026-11-03 14:30:45');
        $this->assertSame('rot', ZasDispoLieferungsStand::anzeige()['stufe'], 'genau 24 Std. ist rot');

        Carbon::setTestNow('2026-11-03 14:45:00');                   // der Vorfall: Stand vom Vortag
        $a = ZasDispoLieferungsStand::anzeige();
        $this->assertSame('rot', $a['stufe']);
        $this->assertSame('ZAS-Stand: Mo 02.11. 14:30 · 4.244 Einbuchungen · 24 Std. alt', $a['text']);

        Carbon::setTestNow('2026-11-05 15:00:00');
        $this->assertStringContainsString('· 3 Tage alt', ZasDispoLieferungsStand::anzeige()['text']);
    }

    public function test_wartende_neuere_datei_wird_genannt_liegengebliebene_nicht(): void
    {
        $alt = $this->datei('2026-10-30 13:48:05', 'viewable');      // #383: nie verarbeitet, liegt unter der letzten
        $voll = $this->datei('2026-11-03 18:15:06', 'processed', 4195);
        $probe = $this->datei('2026-11-03 19:20:00', 'viewable', test: true); // Probe zaehlt nicht

        $this->assertNull(ZasDispoLieferungsStand::aktuell()['offen'], 'alte Liegengebliebene und Proben sind nicht „offen“');

        $neu = $this->datei('2026-11-03 19:24:46', 'viewable');

        // Eingangsliste nutzt dieselbe Grenze (Review 04.11. Fund 4).
        $grenze = $voll->id;
        $this->assertFalse($alt->wartet($grenze));
        $this->assertTrue($alt->liegengeblieben($grenze));
        $this->assertTrue($neu->wartet($grenze));
        $this->assertFalse($neu->liegengeblieben($grenze));
        $this->assertFalse($probe->wartet($grenze));
        $this->assertFalse($probe->liegengeblieben($grenze));
        $this->assertFalse($voll->wartet($grenze));

        $s = ZasDispoLieferungsStand::aktuell();
        $this->assertSame('2026-11-03 19:24:46', $s['offen']->format('Y-m-d H:i:s'));
        $this->assertSame(
            'ZAS-Stand: Di 03.11. 18:15 · 4.195 Einbuchungen · neue Lieferung von 19:24 wird verarbeitet',
            ZasDispoLieferungsStand::anzeige()['text']
        );
    }
}
