<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Facade;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentStore;

class DispoAttachmentStoreTest extends TestCase
{
    private static string $root;
    private FilesystemAdapter $files;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_08_12_000001_create_rec_dispo_events_table.php',
            'database/migrations/2026_08_12_000002_create_rec_dispo_assignments_table.php',
            'database/migrations/2026_08_14_000001_add_confirmation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_14_000002_add_vorlauf_minuten_to_rec_dispo_events.php',
            'database/migrations/2026_08_19_000001_add_ansprechpartner_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000001_add_filiale_to_rec_dispo_events.php',
            'database/migrations/2026_08_20_000002_add_individual_note_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_21_000001_add_filial_nr_to_rec_dispo_events.php',
            'database/migrations/2026_08_24_000002_add_escalation_fields_to_rec_dispo_assignments.php',
            'database/migrations/2026_08_24_000003_add_alarm_message_id_to_rec_dispo_events.php',
            'database/migrations/2026_08_27_000001_create_rec_dispo_attachments_table.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }

        self::$root = sys_get_temp_dir() . '/rec-dispo-attach-' . bin2hex(random_bytes(4));
        mkdir(self::$root, 0777, true);
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        self::rrmdir(self::$root);
    }

    protected function setUp(): void
    {
        Capsule::table('rec_dispo_attachments')->delete();
        Capsule::table('rec_dispo_events')->delete();
        $adapter = new LocalFilesystemAdapter(self::$root);
        $this->files = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => self::$root]);
    }

    private function store(): DispoAttachmentStore
    {
        return new DispoAttachmentStore($this->files, 'test-local');
    }

    public function test_put_creates_row_and_file(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ATT-1', 'name' => 'VA']);

        $a = $this->store()->putContents($event->id, 42, 'PDFDATA', 'Lageplan.pdf', 'application/pdf', 7);

        $this->assertSame('test-local', $a->disk);
        $this->assertSame('Lageplan.pdf', $a->original_filename);
        $this->assertSame(7, (int) $a->uploaded_by_user_id);
        $this->assertSame(strlen('PDFDATA'), (int) $a->size_bytes);
        $this->assertStringStartsWith("zas-dispo-attachments/{$event->id}/", $a->stored_path);
        $this->assertStringEndsWith('.pdf', $a->stored_path);
        $this->assertTrue($this->files->exists($a->stored_path));
        $this->assertNotEmpty($a->uuid);
    }

    public function test_put_again_replaces_row_and_deletes_old_file(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ATT-2', 'name' => 'VA']);
        $old = $this->store()->putContents($event->id, 42, 'ONE', 'a.pdf', 'application/pdf');

        $new = $this->store()->putContents($event->id, 42, 'TWO', 'b.png', 'image/png');

        $this->assertSame(1, RecDispoAttachment::count());
        $this->assertNotSame($old->stored_path, $new->stored_path);
        $this->assertFalse($this->files->exists($old->stored_path));
        $this->assertTrue($this->files->exists($new->stored_path));
        $this->assertSame('b.png', RecDispoAttachment::first()->original_filename);
    }

    public function test_second_employee_same_event_keeps_both(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ATT-3', 'name' => 'VA']);
        $this->store()->putContents($event->id, 42, 'A', 'a.pdf', 'application/pdf');
        $this->store()->putContents($event->id, 43, 'B', 'b.pdf', 'application/pdf');
        $this->assertSame(2, RecDispoAttachment::count());
    }

    public function test_remove_deletes_row_and_file(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ATT-4', 'name' => 'VA']);
        $a = $this->store()->putContents($event->id, 42, 'A', 'a.pdf', 'application/pdf');

        $this->store()->remove($a);

        $this->assertSame(0, RecDispoAttachment::count());
        $this->assertFalse($this->files->exists($a->stored_path));
    }

    public function test_remove_all_returns_count_and_clears_files(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ATT-5', 'name' => 'VA']);
        $a = $this->store()->putContents($event->id, 42, 'A', 'a.pdf', 'application/pdf');
        $b = $this->store()->putContents($event->id, 43, 'B', 'b.pdf', 'application/pdf');

        $this->assertSame(2, $this->store()->removeAll());
        $this->assertSame(0, RecDispoAttachment::count());
        $this->assertFalse($this->files->exists($a->stored_path));
        $this->assertFalse($this->files->exists($b->stored_path));
    }

    public function test_extension_is_sanitized_and_defaults_to_bin(): void
    {
        $event = RecDispoEvent::create(['einsatz_ref' => 'RG-ATT-6', 'name' => 'VA']);
        $a = $this->store()->putContents($event->id, 42, 'X', 'weird', null);
        $this->assertStringEndsWith('.bin', $a->stored_path);
        $b = $this->store()->putContents($event->id, 43, 'X', 'Plan.JPEG', 'image/jpeg');
        $this->assertStringEndsWith('.jpeg', $b->stored_path);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
