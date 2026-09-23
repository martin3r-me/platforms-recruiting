<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasLookupReverseResolver;

/**
 * "Ich bin" — die drei Werte aus Claras Liste (28.08.2026):
 * „Bitte bei Status bzw. 'Ich bin' noch 'Student erwerbst.' /
 * 'Zwischen Schule und Studium' und 'FSJ' ergaenzen wie in ZAS".
 *
 * ZWEI DINGE, die dieser Test festnagelt:
 *
 * 1. "Student erwerbst." darf NICHT mehr auf `student` eingedampft werden.
 *    Genau das tat die Alias-Tabelle bisher — der eigene Wert kam bei uns
 *    nie an, und Clara sah in ZAS etwas anderes als bei uns.
 *
 * 2. "Zwischen Schule und Studium" und "FSJ" kannte die Uebersetzung
 *    ueberhaupt nicht. Sie landeten als ROHTEXT in der Spalte — derselbe
 *    stille Datenfehler wie bei den Nationalitaeten (23.09.2026). Der
 *    Label-Match greift erst, seit die Werte im Lookup stehen.
 */
class ZasEmploymentTypeAliasTest extends TestCase
{
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
        $container->instance('config', new ConfigRepository([]));
        Facade::setFacadeApplication($container);

        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        (require $core . '/database/migrations/2026_02_12_000003_create_core_lookups_tables.php')->up();
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('core_lookup_values')->delete();
        Capsule::table('core_lookups')->delete();

        $lookupId = Capsule::table('core_lookups')->insertGetId([
            'team_id' => 1, 'name' => 'beschaeftigung_art', 'label' => 'Ich bin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $values = [
            'schueler'                => 'Schüler',
            'student'                 => 'Student',
            'arbeitslos'              => 'Arbeitslos',
            'erwerbstaetig'           => 'Erwerbstätig',
            'hausmann_frau'           => 'Hausmann/-Frau',
            'azubi'                   => 'Azubi',
            'rentner'                 => 'Rentner',
            'student_erwerbstaetig'   => 'Student erwerbstätig',
            'zwischen_schule_studium' => 'Zwischen Schule und Studium',
            'fsj'                     => 'FSJ',
        ];
        $sort = 0;
        foreach ($values as $value => $label) {
            Capsule::table('core_lookup_values')->insert([
                'lookup_id' => $lookupId, 'value' => $value, 'label' => $label,
                'order' => $sort++, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function resolve(string $incoming): array
    {
        return (new ZasLookupReverseResolver())->resolve('beschaeftigung_art', $incoming);
    }

    public function test_zas_kurzform_landet_auf_dem_eigenen_wert_statt_auf_student(): void
    {
        $this->assertSame(
            ['value' => 'student_erwerbstaetig', 'matched' => true],
            $this->resolve('Student erwerbst.'),
        );
    }

    public function test_ausgeschriebene_zas_schreibweise_ebenfalls(): void
    {
        $this->assertSame(
            ['value' => 'student_erwerbstaetig', 'matched' => true],
            $this->resolve('Student, erwerbstätig'),
        );
    }

    public function test_zwischen_schule_und_studium_wird_erkannt(): void
    {
        $this->assertSame(
            ['value' => 'zwischen_schule_studium', 'matched' => true],
            $this->resolve('Zwischen Schule und Studium'),
        );
    }

    public function test_fsj_wird_erkannt(): void
    {
        $this->assertSame(['value' => 'fsj', 'matched' => true], $this->resolve('FSJ'));
    }

    /**
     * ZAS schreibt den Zwischenzeitraum auch ohne "und". Ohne Alias landete
     * der Satz als Rohtext in der Spalte.
     */
    public function test_schreibvariante_ohne_und_wird_erkannt(): void
    {
        $this->assertSame(
            ['value' => 'zwischen_schule_studium', 'matched' => true],
            $this->resolve('Zwischen Schule / Studium'),
        );
    }

    public function test_der_reine_student_bleibt_der_reine_student(): void
    {
        $this->assertSame(['value' => 'student', 'matched' => true], $this->resolve('Student'));
        $this->assertSame(['value' => 'student', 'matched' => true], $this->resolve('Studentin'));
        $this->assertSame(['value' => 'student', 'matched' => true], $this->resolve('Dualer Student'));
    }

    /**
     * Schutzschalter der Alias-Stufe: ein Alias greift nur, wenn der
     * Ziel-Code wirklich im Lookup steht. Solange die drei Werte auf einer
     * Umgebung fehlen, faellt "Student erwerbst." auf Rohtext zurueck statt
     * auf einen Code, den niemand anzeigen kann.
     */
    public function test_ohne_angelegten_wert_greift_der_alias_nicht(): void
    {
        Capsule::table('core_lookup_values')->where('value', 'student_erwerbstaetig')->delete();

        $this->assertSame(
            ['value' => 'Student erwerbst.', 'matched' => false],
            $this->resolve('Student erwerbst.'),
        );
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((string) (new \ReflectionClass($class))->getFileName());
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }
}
