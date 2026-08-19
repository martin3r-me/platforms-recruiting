<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecEmployeeHrData;

/**
 * Zeitraum-Filter der MA-Liste ("wer wurde zwischen X und Y auf MA gestellt").
 * Er IST eine Query — deshalb Integrationstest gegen echtes SQLite, Muster
 * ManualBookingCandidatesTest.
 */
final class EmployeeMaSinceFilterTest extends TestCase
{
    private const TEAM = 7;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher fallen die creating-Hooks (uuid) der Models aus.
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();
        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->integer('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $schema->create('rec_employee_hr_data', function ($t) {
            $t->increments('id');
            $t->string('uuid')->nullable();
            $t->integer('rec_employee_id');
            $t->integer('team_id')->nullable();
            $t->string('export_status')->nullable();
            $t->date('status_ma_since')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->capsule->schema()->dropAllTables();
        parent::tearDown();
    }

    private function employee(string $name, ?string $maSince, bool $withHrRow = true): RecEmployee
    {
        $employee = RecEmployee::create([
            'team_id'    => self::TEAM,
            'first_name' => $name,
            'last_name'  => 'Test',
            'is_active'  => true,
        ]);
        if ($withHrRow) {
            RecEmployeeHrData::create([
                'rec_employee_id' => $employee->id,
                'team_id'         => self::TEAM,
                'export_status'   => $maSince === null ? 'GO' : 'MA',
                'status_ma_since' => $maSince,
            ]);
        }

        return $employee;
    }

    /** @return list<string> */
    private function filter(?string $from, ?string $to): array
    {
        return RecEmployee::query()
            ->statusMaSinceBetween($from, $to)
            ->orderBy('first_name')
            ->pluck('first_name')
            ->all();
    }

    private function seed(): void
    {
        $this->employee('Juni', '2026-06-30');
        $this->employee('JuliAnfang', '2026-07-01');
        $this->employee('JuliEnde', '2026-07-31');
        $this->employee('August', '2026-08-01');
        $this->employee('OhneDatum', null);
        $this->employee('OhneHrZeile', null, withHrRow: false);
    }

    public function test_zeitraum_ist_beidseitig_inklusiv(): void
    {
        $this->seed();

        $this->assertSame(['JuliAnfang', 'JuliEnde'], $this->filter('2026-07-01', '2026-07-31'));
    }

    public function test_nur_von_datum_filtert_ab(): void
    {
        $this->seed();

        $this->assertSame(['August', 'JuliEnde'], $this->filter('2026-07-31', null));
    }

    public function test_nur_bis_datum_filtert_bis(): void
    {
        $this->seed();

        $this->assertSame(['JuliAnfang', 'Juni'], $this->filter(null, '2026-07-01'));
    }

    public function test_ohne_datum_und_ohne_hr_zeile_fallen_bei_gesetztem_filter_raus(): void
    {
        $this->seed();

        $names = $this->filter('2026-01-01', '2026-12-31');
        $this->assertNotContains('OhneDatum', $names);
        $this->assertNotContains('OhneHrZeile', $names);
    }

    public function test_leerer_filter_laesst_alle_durch(): void
    {
        $this->seed();

        // Leerstrings kommen so aus den <input type="date">-Feldern der Liste.
        $this->assertCount(6, $this->filter('', ''));
    }
}
