<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ReportSignedWithoutEmployee;
use Platform\Recruiting\Support\EmployeeMatchResolver;

/**
 * Deckt die drei Ladequeries des Berichts gegen ein echtes Schema ab — die
 * Match-Logik selbst haengt in EmployeeMatchResolverTest.
 *
 * Grund fuer diesen Test: die Queries greifen ueber vier Modulgrenzen
 * (rec_contracts/rec_employees, crm_contact_links/crm_contacts,
 * core_extra_field_*). Ein Spaltenname-Dreher faellt sonst erst auf Forge auf.
 * Das Schema wird hier absichtlich von Hand gebaut statt aus Migrationen
 * geladen: die CRM- und Core-Tabellen liegen in anderen Modulen, deren
 * Migrations-Baum hier nicht verfuegbar ist.
 *
 * Zweiter abgedeckter Fallstrick: linkable_type/fieldable_type stehen im
 * Bestand in BEIDEN Formen da — als Morph-Alias 'rec_applicant' (seit die
 * morphMap existiert) und als vollqualifizierte Klasse (aeltere Zeilen).
 * Beide muessen gefunden werden.
 */
class ReportSignedWithoutEmployeeQueriesTest extends TestCase
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
        Facade::setFacadeApplication($container);

        $schema = $capsule->getConnection()->getSchemaBuilder();

        $schema->create('rec_contract_templates', function ($table) {
            $table->increments('id');
            $table->string('code', 20)->nullable();
        });
        $schema->create('rec_contracts', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('rec_applicant_id');
            $table->unsignedBigInteger('rec_contract_template_id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('status', 30);
            $table->text('signature_data')->nullable();
            $table->dateTime('completed_at')->nullable();
        });
        $schema->create('crm_contacts', function ($table) {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        $schema->create('crm_contact_links', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('contact_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
        });
        $schema->create('core_extra_field_definitions', function ($table) {
            $table->increments('id');
            $table->string('name', 64);
        });
        $schema->create('core_extra_field_values', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('definition_id');
            $table->string('fieldable_type');
            $table->unsignedBigInteger('fieldable_id');
            $table->text('value')->nullable();
        });
        $schema->create('rec_employees', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('personnel_number', 32)->nullable();
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedBigInteger('rec_applicant_id')->nullable();
        });

        self::seed();
    }

    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
    }

    private static function seed(): void
    {
        Capsule::table('rec_contract_templates')->insert([
            ['id' => 1, 'code' => 'AV-010'],
            ['id' => 2, 'code' => 'IFSG'],
            ['id' => 3, 'code' => 'AV-default'],
        ]);

        Capsule::table('rec_contracts')->insert([
            // #501: zwei AV-Vertraege (frueheste Signatur zaehlt) + IFSG
            ['rec_applicant_id' => 501, 'rec_contract_template_id' => 1, 'team_id' => 3, 'status' => 'completed', 'signature_data' => 'x', 'completed_at' => '2026-05-04 10:00:00'],
            ['rec_applicant_id' => 501, 'rec_contract_template_id' => 3, 'team_id' => 3, 'status' => 'completed', 'signature_data' => 'x', 'completed_at' => '2026-06-01 10:00:00'],
            ['rec_applicant_id' => 501, 'rec_contract_template_id' => 2, 'team_id' => 3, 'status' => 'completed', 'signature_data' => 'x', 'completed_at' => '2026-05-04 11:00:00'],
            // #502: AV signiert, anderes Team
            ['rec_applicant_id' => 502, 'rec_contract_template_id' => 1, 'team_id' => 9, 'status' => 'completed', 'signature_data' => 'x', 'completed_at' => '2026-05-05 10:00:00'],
            // #503: NUR Infektionsschutz — gehoert nicht in die Kohorte
            ['rec_applicant_id' => 503, 'rec_contract_template_id' => 2, 'team_id' => 3, 'status' => 'completed', 'signature_data' => 'x', 'completed_at' => '2026-05-06 10:00:00'],
            // #504: completed, aber ohne Unterschrift
            ['rec_applicant_id' => 504, 'rec_contract_template_id' => 1, 'team_id' => 3, 'status' => 'completed', 'signature_data' => null, 'completed_at' => '2026-05-07 10:00:00'],
            // #505: noch nicht unterschrieben
            ['rec_applicant_id' => 505, 'rec_contract_template_id' => 1, 'team_id' => 3, 'status' => 'sent', 'signature_data' => null, 'completed_at' => null],
        ]);

        Capsule::table('crm_contacts')->insert([
            ['id' => 71, 'first_name' => 'Dario', 'last_name' => 'Halabarec'],
            ['id' => 72, 'first_name' => 'Sara', 'last_name' => 'Youssfi'],
        ]);
        Capsule::table('crm_contact_links')->insert([
            // Alias-Form (morphMap) ...
            ['contact_id' => 71, 'linkable_type' => 'rec_applicant', 'linkable_id' => 501],
            // ... und vollqualifizierte Klasse (Altzeilen)
            ['contact_id' => 72, 'linkable_type' => \Platform\Recruiting\Models\RecApplicant::class, 'linkable_id' => 502],
        ]);

        Capsule::table('core_extra_field_definitions')->insert([
            ['id' => 11, 'name' => 'vorname'],
            ['id' => 12, 'name' => 'nachname'],
            ['id' => 13, 'name' => 'geburtsdatum'],
            ['id' => 14, 'name' => 'strasse'],
        ]);
        Capsule::table('core_extra_field_values')->insert([
            // Extra-Feld-Schreibweise weicht vom CRM-Kontakt ab
            ['definition_id' => 11, 'fieldable_type' => 'rec_applicant', 'fieldable_id' => 501, 'value' => 'Dario'],
            ['definition_id' => 12, 'fieldable_type' => 'rec_applicant', 'fieldable_id' => 501, 'value' => 'Dhalabarec'],
            ['definition_id' => 13, 'fieldable_type' => 'rec_applicant', 'fieldable_id' => 501, 'value' => '1999-09-16'],
            ['definition_id' => 14, 'fieldable_type' => 'rec_applicant', 'fieldable_id' => 501, 'value' => 'Musterweg 1'],
            ['definition_id' => 13, 'fieldable_type' => \Platform\Recruiting\Models\RecApplicant::class, 'fieldable_id' => 502, 'value' => '04.09.2004'],
        ]);

        Capsule::table('rec_employees')->insert([
            ['id' => 81, 'team_id' => 3, 'personnel_number' => 'RG17786', 'first_name' => 'Dario', 'last_name' => 'Halabarec', 'birth_date' => '1999-09-16', 'rec_applicant_id' => null],
            ['id' => 82, 'team_id' => 9, 'personnel_number' => 'RG99', 'first_name' => 'Sara', 'last_name' => 'Youssfi', 'birth_date' => '2004-09-04', 'rec_applicant_id' => null],
        ]);
    }

    private function probe(): ReportSignedWithoutEmployeeProbe
    {
        return new ReportSignedWithoutEmployeeProbe();
    }

    public function test_cohort_contains_only_signed_av_contracts(): void
    {
        $cohort = $this->probe()->probeCohort(null, 'AV-');

        $this->assertSame([501, 502], array_keys($cohort));
        // Fruehester Signaturzeitpunkt, nicht der letzte.
        $this->assertSame('2026-05-04', $cohort[501]['signed_at']);
        $this->assertStringContainsString('AV-010', $cohort[501]['templates']);
        $this->assertStringNotContainsString('IFSG', $cohort[501]['templates']);
    }

    public function test_cohort_respects_team_filter(): void
    {
        $this->assertSame([501], array_keys($this->probe()->probeCohort(3, 'AV-')));
        $this->assertSame([502], array_keys($this->probe()->probeCohort(9, 'AV-')));
    }

    public function test_names_come_from_both_sources_and_both_morph_forms(): void
    {
        $names = $this->probe()->probeNames([501, 502]);

        $this->assertSame('1999-09-16', $names[501]['birth_date']);
        $this->assertEqualsCanonicalizing(
            [['first' => 'Dario', 'last' => 'Halabarec'], ['first' => 'Dario', 'last' => 'Dhalabarec']],
            $names[501]['names']
        );

        // Vollqualifizierte Morph-Form + deutsches Datumsformat
        $this->assertSame([['first' => 'Sara', 'last' => 'Youssfi']], $names[502]['names']);
        $this->assertSame('2004-09-04', $names[502]['birth_date']);
    }

    public function test_employees_are_team_filtered(): void
    {
        $this->assertCount(2, $this->probe()->probeEmployees(null));
        $this->assertSame([81], array_column($this->probe()->probeEmployees(3), 'id'));
    }

    /** Ende-zu-Ende: der verstuemmelte Extra-Feld-Name darf den MA nicht verstecken. */
    public function test_mangled_extra_field_name_still_resolves_to_the_employee(): void
    {
        $probe = $this->probe();
        $names = $probe->probeNames([501]);
        $employees = $probe->probeEmployees(3);

        $hits = EmployeeMatchResolver::match([
            'id' => 501,
            'names' => $names[501]['names'],
            'birth_date' => $names[501]['birth_date'],
        ], $employees);

        $this->assertSame(EmployeeMatchResolver::VERDICT_UNLINKED, EmployeeMatchResolver::verdict($hits));
        $this->assertSame([81], EmployeeMatchResolver::linkableEmployeeIds($hits, [81 => $employees[0]]));
    }
}

final class ReportSignedWithoutEmployeeProbe extends ReportSignedWithoutEmployee
{
    /** @return array<int,array{signed_at:string,templates:string}> */
    public function probeCohort(?int $teamId, string $prefix): array
    {
        return $this->loadCohort($teamId, $prefix);
    }

    /** @return array<int,array<string,mixed>> */
    public function probeNames(array $applicantIds): array
    {
        return $this->loadNames($applicantIds);
    }

    /** @return array<int,array<string,mixed>> */
    public function probeEmployees(?int $teamId): array
    {
        return $this->loadEmployees($teamId);
    }
}
