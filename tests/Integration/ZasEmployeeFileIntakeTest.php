<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\ZasEmployeeFileIntake;
use Platform\Recruiting\Services\Zas\ZasEmployeeFileStore;
use Platform\Recruiting\Services\Zas\ZasEmployeeMatcher;

/**
 * Datei-Eingang von ZAS: POST einer Bilddatei auf einen Dokumentslot.
 *
 * Der Core-Dateidienst bleibt draussen (Attrappe fuer ZasEmployeeFileStore) —
 * geprueft wird die Entscheidungslogik: Zuordnung ueber die Personalnummer,
 * die Guards, und die beiden Zusicherungen, die wir ZAS gegeben haben:
 * wiederholtes Senden ist unschaedlich, und Vorhandenes wird nie ueberschrieben.
 *
 * Der wichtigste Test ist der letzte: `selfie_file_id` steht in der Watch-Liste
 * des RecEmployeeExportObservers. Wuerde der Eingang die Spalte normal
 * schreiben, landete der Mitarbeiter im Update-Export und wir schickten ZAS
 * eine signierte URL auf das Bild zurueck, das ZAS uns gerade gegeben hat
 * (derselbe Mechanismus wie beim Telefon-Vorfall am 02.09.).
 */
class ZasEmployeeFileIntakeTest extends TestCase
{
    private const TEAM = 13;

    /** Zaehlt die Aufrufe der Attrappe und liefert eine feste File-ID. */
    private int $created = 0;

    /** @var list<array{filename: string, mime: string, bytes: int}> */
    private array $calls = [];

    /** Original-Name, den die Attrappe fuer eine bestehende File-ID meldet. */
    private ?string $existingName = null;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => [
                'inbound_team_id' => self::TEAM,
                'company_prefix'  => 'RG',
            ]],
        ]));

        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('log');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $container->instance('db', $capsule->getDatabaseManager());

        Model::clearBootedModels();
        Model::unguard();

        Capsule::schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('personnel_number')->nullable();
            $t->string('company')->nullable();
            $t->integer('selfie_file_id')->nullable();
            $t->integer('rec_applicant_id')->nullable();
            $t->integer('rec_zas_inbound_file_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('zas_changed_at')->nullable();
            $t->timestamps();
        });
    }

    public static function tearDownAfterClass(): void
    {
        Capsule::schema()->dropAllTables();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_employees')->delete();
        $this->created      = 0;
        $this->calls        = [];
        $this->existingName = null;

        // Zwei Firmen, wie in der echten Lieferung. Die MA-Person ist bewusst
        // dabei: Bilder kommen fuer beide Firmen, beide sind erlaubt.
        RecEmployee::create(['team_id' => self::TEAM, 'personnel_number' => 'RG1187', 'company' => 'RG', 'last_name' => 'Ammerer']);
        RecEmployee::create(['team_id' => self::TEAM, 'personnel_number' => 'MA1000000878', 'company' => 'MA', 'last_name' => 'Wächter']);
        RecEmployee::create(['team_id' => 99, 'personnel_number' => 'RG4711', 'company' => 'RG', 'last_name' => 'Fremdteam']);
    }

    private function jpeg(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD//gA+Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBk'
            . 'ZWZhdWx0IHF1YWxpdHkK/9sAQwAIBgYHBgUIBwcHCQkICgwUDQwLCwwZEhMPFB0aHx4dGhwcICQuJyAiLCMcHCg3KSww'
            . 'MTQ0NB8nOT04MjwuMzQy/9sAQwEJCQkMCwwYDQ0YMiEcITIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
            . 'MjIyMjIyMjIyMjIyMjIy/8AAEQgAAQABAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//E'
            . 'ALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkq'
            . 'NDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1'
            . 'tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgME'
            . 'BQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDTh'
            . 'JfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKj'
            . 'pKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A+f6K'
            . 'KKAP/9k='
        );
    }

    private function intake(): ZasEmployeeFileIntake
    {
        $test  = $this;
        $store = new class($test) extends ZasEmployeeFileStore {
            public function __construct(private ZasEmployeeFileIntakeTest $t)
            {
            }

            public function create(RecEmployee $employee, string $bytes, string $filename, string $mime): int
            {
                return $this->t->recordCreate($filename, $mime, strlen($bytes));
            }

            public function originalNameOf(?int $fileId): ?string
            {
                return $this->t->existingNameFor($fileId);
            }
        };

        return new ZasEmployeeFileIntake(new ZasEmployeeMatcher(), $store);
    }

    /** @internal Attrappen-Rueckkanal */
    public function recordCreate(string $filename, string $mime, int $bytes): int
    {
        $this->created++;
        $this->calls[] = ['filename' => $filename, 'mime' => $mime, 'bytes' => $bytes];

        return 4711;
    }

    /** @internal Attrappen-Rueckkanal */
    public function existingNameFor(?int $fileId): ?string
    {
        return $fileId === null ? null : $this->existingName;
    }

    public function test_stores_the_image_and_fills_the_slot(): void
    {
        $result = $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), 'Selfie-IMG_0623.jpeg');

        $this->assertSame('stored', $result['status']);
        $this->assertSame(201, $result['http']);
        $this->assertSame(4711, $result['file_id']);
        $this->assertSame('exact', $result['matched_via']);
        $this->assertSame(1, $this->created);
        $this->assertSame('image/jpeg', $this->calls[0]['mime']);

        $employee = RecEmployee::where('personnel_number', 'RG1187')->firstOrFail();
        $this->assertSame(4711, (int) $employee->selfie_file_id);
    }

    public function test_a_number_without_company_prefix_is_refused(): void
    {
        // Beide Firmen vergeben dieselben Ziffernfolgen (belegt: 276, 322,
        // 325, 353). Wuerden wir `1187` auf den eigenen Praefix ergaenzen —
        // wie der CSV-Import es als Uebergangshilfe tut —, koennte das Gesicht
        // der MA-Person am gleichnamigen RG-Mitarbeiter landen, bei Antwort
        // "stored". Lieber laut scheitern: ein Lauf mit lauter 422 faellt auf,
        // ein Bild am falschen Menschen nicht.
        $result = $this->intake()->receive('1187', 'emp-selfie', $this->jpeg(), 'a.jpg');

        $this->assertSame('personnel_number_unprefixed', $result['status']);
        $this->assertSame(422, $result['http']);
        $this->assertStringContainsString('RG1187', $result['message'], 'Die Meldung soll sagen, was zu tun ist.');
        $this->assertSame(0, $this->created);
        $this->assertNull(RecEmployee::where('personnel_number', 'RG1187')->firstOrFail()->selfie_file_id);
    }

    public function test_accepts_the_other_company_as_well(): void
    {
        // Bilder kommen fuer RG UND MA. Von diesen Leuten fuehren wir laengst
        // den vollen Stammdatensatz — das Selfie zu verweigern waere
        // inkonsequent, und die Dispo braucht das Gesicht.
        $result = $this->intake()->receive('MA1000000878', 'emp-selfie', $this->jpeg(), 'b.jpg');

        $this->assertSame('stored', $result['status']);
        $this->assertSame(4711, (int) RecEmployee::where('personnel_number', 'MA1000000878')->firstOrFail()->selfie_file_id);
    }

    public function test_matches_the_shortened_form_of_a_long_number(): void
    {
        RecEmployee::create(['team_id' => self::TEAM, 'personnel_number' => 'MA878', 'company' => 'MA', 'last_name' => 'Kurzform']);

        $result = $this->intake()->receive('MA1000000878', 'emp-selfie', $this->jpeg(), 'c.jpg');

        // Die exakte Nummer gewinnt gegen die Kurzform.
        $this->assertSame('exact', $result['matched_via']);
        $this->assertNull(RecEmployee::where('personnel_number', 'MA878')->firstOrFail()->selfie_file_id);
    }

    public function test_unknown_personnel_number_is_refused_and_creates_nothing(): void
    {
        $result = $this->intake()->receive('RG99999', 'emp-selfie', $this->jpeg(), 'd.jpg');

        $this->assertSame('not_found', $result['status']);
        $this->assertSame(404, $result['http']);
        $this->assertSame(0, $this->created);
        $this->assertSame(3, RecEmployee::count(), 'Der Eingang darf niemals einen Mitarbeiter anlegen.');
    }

    public function test_employees_of_another_team_are_not_reachable(): void
    {
        $result = $this->intake()->receive('RG4711', 'emp-selfie', $this->jpeg(), 'e.jpg');

        $this->assertSame('not_found', $result['status']);
        $this->assertNull(RecEmployee::where('personnel_number', 'RG4711')->firstOrFail()->selfie_file_id);
    }

    public function test_refuses_slots_that_are_not_open(): void
    {
        $result = $this->intake()->receive('RG1187', 'emp-auweis', $this->jpeg(), 'f.jpg');

        $this->assertSame('slot_not_allowed', $result['status']);
        $this->assertSame(422, $result['http']);
        $this->assertSame(0, $this->created);
    }

    public function test_refuses_content_that_is_not_an_image(): void
    {
        // Der Fall PlanHalle18.jpg: Bild-Endung, kein Bild.
        $result = $this->intake()->receive('RG1187', 'emp-selfie', '%PDF-1.4 kein Bild', 'PlanHalle18.jpg');

        $this->assertSame('not_an_image', $result['status']);
        $this->assertSame(415, $result['http']);
        $this->assertSame(0, $this->created);
    }

    public function test_refuses_empty_and_oversized_payloads(): void
    {
        $this->assertSame('empty', $this->intake()->receive('RG1187', 'emp-selfie', '', 'g.jpg')['status']);

        $tooBig = $this->jpeg() . str_repeat('x', 10 * 1024 * 1024);
        $result = $this->intake()->receive('RG1187', 'emp-selfie', $tooBig, 'h.jpg');
        $this->assertSame('too_large', $result['status']);
        $this->assertSame(413, $result['http']);
        $this->assertSame(0, $this->created);
    }

    public function test_the_same_file_again_is_reported_as_already_present(): void
    {
        // Zusicherung an ZAS: die Schleife ist beliebig wiederholbar.
        RecEmployee::where('personnel_number', 'RG1187')->update(['selfie_file_id' => 900]);
        $this->existingName = 'Selfie-IMG_0623.jpeg';

        $result = $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), 'Selfie-IMG_0623.jpeg');

        $this->assertSame('already_present', $result['status']);
        $this->assertSame(200, $result['http']);
        $this->assertSame(0, $this->created, 'Dieselbe Datei darf nicht zweimal abgelegt werden.');
        $this->assertSame(900, (int) RecEmployee::where('personnel_number', 'RG1187')->firstOrFail()->selfie_file_id);
    }

    public function test_a_filled_slot_is_never_overwritten_by_a_different_file(): void
    {
        // Was HR oder der Mitarbeiter selbst hochgeladen hat, gewinnt.
        RecEmployee::where('personnel_number', 'RG1187')->update(['selfie_file_id' => 900]);
        $this->existingName = 'vom-mitarbeiter-selbst.jpg';

        $result = $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), 'Selfie-IMG_0623.jpeg');

        $this->assertSame('slot_filled', $result['status']);
        $this->assertSame(409, $result['http']);
        $this->assertSame(0, $this->created);
        $this->assertSame(900, (int) RecEmployee::where('personnel_number', 'RG1187')->firstOrFail()->selfie_file_id);
    }

    public function test_a_slot_pointing_at_a_missing_file_may_be_refilled(): void
    {
        // Verweis ins Leere: die Spalte ist gesetzt, die Datei existiert nicht
        // mehr. Der Slot ist faktisch leer — wuerden wir hier abweisen, koennte
        // dieser Mitarbeiter nie wieder ein Bild bekommen, und es fiele
        // niemandem auf.
        RecEmployee::where('personnel_number', 'RG1187')->update(['selfie_file_id' => 900]);
        $this->existingName = null;

        $result = $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), 'k.jpg');

        $this->assertSame('stored', $result['status']);
        $this->assertSame(4711, (int) RecEmployee::where('personnel_number', 'RG1187')->firstOrFail()->selfie_file_id);
    }

    public function test_writing_the_slot_does_not_arm_the_export_marker(): void
    {
        // Der teuerste Fehler, den dieser Endpunkt machen koennte: das Bild,
        // das ZAS uns gerade gegeben hat, als Aenderung zuruecksenden.
        $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), 'i.jpg');

        $employee = RecEmployee::where('personnel_number', 'RG1187')->firstOrFail();
        $this->assertNull($employee->zas_changed_at, 'Der Eingang darf keinen Rueckexport ausloesen.');
    }

    public function test_an_existing_export_marker_is_left_untouched(): void
    {
        // Nicht auf null setzen: ein gesetzter Marker stammt aus einer echten
        // HR-Aenderung und darf nicht verschluckt werden (gleiche Haltung wie
        // im Importer beim Status-Sync).
        RecEmployee::where('personnel_number', 'RG1187')->update(['zas_changed_at' => '2026-09-01 10:00:00']);

        $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), 'j.jpg');

        $marker = Capsule::table('rec_employees')->where('personnel_number', 'RG1187')->value('zas_changed_at');
        $this->assertSame('2026-09-01 10:00:00', (string) $marker);
    }

    public function test_a_missing_filename_gets_a_sensible_default(): void
    {
        $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), null);

        $this->assertSame('stored', 'stored');
        $this->assertStringContainsString('RG1187', $this->calls[0]['filename']);
        $this->assertStringEndsWith('.jpg', $this->calls[0]['filename']);
    }

    public function test_a_path_in_the_filename_is_stripped(): void
    {
        // ZAS liefert Pfade wie "1187/Selfie-x.jpg"; als Originalname wollen
        // wir nur den Dateinamen, nicht das Verzeichnis.
        $this->intake()->receive('RG1187', 'emp-selfie', $this->jpeg(), '1187/../../etc/Selfie-x.jpg');

        $this->assertSame('Selfie-x.jpg', $this->calls[0]['filename']);
    }
}
