<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Applicants\IchBinLabels;

/**
 * „Ich bin“ in der Schulungsnachbereitung (Kunde 13.10.2026): ein Query für
 * alle Bewerber, Label aus der Lookup-Liste des Feldes.
 */
class IchBinLabelsTest extends TestCase
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

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $core = self::packageRootOf(\Platform\Core\Models\CoreLookup::class);
        foreach ([
            '2026_02_07_000001_create_core_extra_field_definitions_table.php',
            '2026_02_07_000002_create_core_extra_field_values_table.php',
            '2026_02_12_000003_create_core_lookups_tables.php',
        ] as $f) {
            $path = $core . '/database/migrations/' . $f;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        foreach (['core_extra_field_values', 'core_extra_field_definitions', 'core_lookup_values', 'core_lookups'] as $t) {
            Capsule::table($t)->delete();
        }
    }

    public function test_labels_come_from_the_lookup_list(): void
    {
        $def = $this->definition('ich_bin', $this->lookup(['azubi' => 'Auszubildende/r', 'teilzeit' => 'Teilzeit angestellt']));
        $this->value($def, 11, 'azubi');
        $this->value($def, 12, 'teilzeit');

        $this->assertSame([11 => 'Auszubildende/r', 12 => 'Teilzeit angestellt'], self::sorted(IchBinLabels::forApplicants([11, 12, 13])));
    }

    public function test_only_the_ich_bin_field_and_only_the_requested_applicants_count(): void
    {
        $lookup = $this->lookup(['azubi' => 'Azubi']);
        $ichBin = $this->definition('ich_bin', $lookup, contextId: 1);
        $anderes = $this->definition('krankenkasse', $lookup, contextId: 1);
        $this->value($anderes, 11, 'azubi');
        $this->value($ichBin, 99, 'azubi');

        $this->assertSame([], IchBinLabels::forApplicants([11]));
    }

    public function test_empty_values_and_other_models_are_ignored(): void
    {
        $def = $this->definition('ich_bin', $this->lookup(['azubi' => 'Azubi']));
        $this->value($def, 11, '');
        $this->value($def, 12, 'azubi', type: 'rec_employee');

        $this->assertSame([], IchBinLabels::forApplicants([11, 12]));
    }

    public function test_with_two_definitions_the_latest_value_wins(): void
    {
        $lookup = $this->lookup(['schueler' => 'Schüler/in', 'azubi' => 'Azubi']);
        $alt = $this->definition('ich_bin', $lookup, contextId: 1);
        $neu = $this->definition('ich_bin', $lookup, contextId: 2);
        $this->value($alt, 11, 'schueler', at: '2026-09-01 10:00:00');
        $this->value($neu, 11, 'azubi', at: '2026-10-01 10:00:00');

        $this->assertSame([11 => 'Azubi'], IchBinLabels::forApplicants([11]));
    }

    public function test_unknown_value_is_shown_raw_and_no_ids_query_nothing(): void
    {
        $def = $this->definition('ich_bin', $this->lookup(['azubi' => 'Azubi']));
        $this->value($def, 11, 'Rentner');

        $this->assertSame([11 => 'Rentner'], IchBinLabels::forApplicants([11, null, 0]));
        $this->assertSame([], IchBinLabels::forApplicants([]));
    }

    // ---------------------------------------------------------------

    /** @param array<string,string> $values */
    private function lookup(array $values): int
    {
        $id = (int) Capsule::table('core_lookups')->insertGetId([
            'team_id' => 1, 'name' => 'beschaeftigung_art_' . uniqid(), 'label' => 'Ich bin',
        ]);
        foreach ($values as $value => $label) {
            Capsule::table('core_lookup_values')->insert(['lookup_id' => $id, 'value' => $value, 'label' => $label]);
        }

        return $id;
    }

    private function definition(string $name, int $lookupId, ?int $contextId = null): int
    {
        return (int) Capsule::table('core_extra_field_definitions')->insertGetId([
            'team_id' => 1, 'context_type' => 'rec_position', 'context_id' => $contextId,
            'name' => $name, 'label' => $name, 'type' => 'lookup',
            'options' => json_encode(['lookup_id' => $lookupId]),
        ]);
    }

    private function value(int $definitionId, int $applicantId, string $value, string $type = 'rec_applicant', string $at = '2026-10-01 09:00:00'): void
    {
        Capsule::table('core_extra_field_values')->insert([
            'definition_id' => $definitionId, 'fieldable_type' => $type, 'fieldable_id' => $applicantId,
            'value' => $value, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @param array<int,string> $a */
    private static function sorted(array $a): array
    {
        ksort($a);

        return $a;
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
