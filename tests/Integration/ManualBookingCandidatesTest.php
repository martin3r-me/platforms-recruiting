<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ManualBookingCandidates;

/**
 * Die Kandidaten-Regel des Buchungs-Dialogs gegen eine echte DB. Sie IST eine
 * Query — ein reiner Unit-Test koennte nur ihre Uebersetzung nachbauen, nicht
 * ihr Verhalten pruefen. Handgebaute Capsule mit Dispatcher, Muster
 * SettingsModalToggleWriteTest.
 */
final class ManualBookingCandidatesTest extends TestCase
{
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

        $schema->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_parked')->default(false);
            $t->boolean('is_on_hr_desk')->default(false);
            $t->integer('duplicate_of_applicant_id')->nullable();
            $t->integer('rec_phase_id')->nullable();
            $t->string('import_source')->nullable();
            $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_phases', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->integer('rec_position_id');
            $t->string('name');
            $t->integer('order');
            $t->boolean('auto_advance')->default(true);
            $t->boolean('allow_manual_booking')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('completion_type')->default('fields');
            $t->timestamps();
        });

        $schema->create('rec_contracts', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('status')->default('pending');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable();
            $t->string('status')->default('booked');
            $t->boolean('is_active')->default(true);
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_positions', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->string('title');
            $t->timestamps();
        });

        $schema->create('rec_postings', function ($t) {
            $t->increments('id');
            $t->integer('rec_position_id');
            $t->integer('team_id');
            $t->timestamps();
        });

        $schema->create('rec_applicant_posting', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->integer('rec_posting_id');
            $t->date('applied_at')->nullable();
            $t->string('notes')->nullable();
            $t->string('matched_via')->nullable();
            $t->float('match_confidence')->nullable();
            $t->timestamps();
        });

        // Phase 1 ohne Schalter, Phase 2 mit, Phase 3 mit Schalter aber
        // stillgelegt — alle an Stelle 8.
        Capsule::table('rec_phases')->insert([
            ['id' => 1, 'team_id' => 3, 'rec_position_id' => 8, 'name' => 'Bewerbung', 'order' => 1, 'allow_manual_booking' => false, 'is_active' => true, 'completion_type' => 'fields'],
            ['id' => 2, 'team_id' => 3, 'rec_position_id' => 8, 'name' => 'Schulung buchen', 'order' => 2, 'allow_manual_booking' => true, 'is_active' => true, 'completion_type' => 'booking'],
            ['id' => 3, 'team_id' => 3, 'rec_position_id' => 8, 'name' => 'Stillgelegt', 'order' => 3, 'allow_manual_booking' => true, 'is_active' => false, 'completion_type' => 'fields'],
        ]);

        Capsule::table('rec_positions')->insert([
            ['id' => 8, 'team_id' => 3, 'title' => 'Duesseldorf allgemein'],
            ['id' => 9, 'team_id' => 3, 'title' => 'Koeln allgemein'],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => 81, 'rec_position_id' => 8, 'team_id' => 3],
            ['id' => 91, 'rec_position_id' => 9, 'team_id' => 3],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'rec_applicants', 'rec_phases', 'rec_contracts', 'rec_interview_bookings',
            'rec_positions', 'rec_postings', 'rec_applicant_posting',
        ] as $table) {
            Capsule::schema()->drop($table);
        }

        // Sonst zeigt 'db'/'db.schema' aus DIESER Capsule in spaetere Testklassen.
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');

        parent::tearDown();
    }

    private function applicant(array $overrides = []): int
    {
        return Capsule::table('rec_applicants')->insertGetId(array_merge([
            'team_id' => 3,
            'is_active' => true,
            'rec_phase_id' => 2,
            'import_source' => null,
        ], $overrides));
    }

    /** @return list<int> */
    private function ids(?int $positionId = null): array
    {
        return ManualBookingCandidates::query(3, $positionId)->pluck('id')->all();
    }

    public function test_bewerber_in_phase_mit_schalter_erscheint(): void
    {
        $id = $this->applicant();

        $this->assertSame([$id], $this->ids());
    }

    public function test_bewerber_in_phase_ohne_schalter_erscheint_nicht(): void
    {
        $this->applicant(['rec_phase_id' => 1]);

        $this->assertSame([], $this->ids());
    }

    public function test_csv_altbestand_ohne_phase_erscheint(): void
    {
        $id = $this->applicant(['rec_phase_id' => null, 'import_source' => 'csv_import']);

        $this->assertSame([$id], $this->ids());
    }

    public function test_bewerber_ohne_phase_und_ohne_import_erscheint_nicht(): void
    {
        $this->applicant(['rec_phase_id' => null]);

        $this->assertSame([], $this->ids());
    }

    public function test_importierter_bewerber_bleibt_auch_mit_phase_sichtbar(): void
    {
        // Ein CSV-Import startet phasenlos, bleibt es aber nicht: sobald ein
        // Posting verknuepft wird, setzt reconcilePositionState() ihn auf die
        // ERSTE Phase der Stelle (RecApplicant:1966 fasst "Phase fehlt"
        // ausdruecklich mit; PhaseMatcher::sameOrderOrFirst(null, …) liefert
        // die order-kleinste). Ohne diesen Fall verschwindet Altbestand
        // stillschweigend aus dem Dialog, obwohl er heute sichtbar ist.
        $id = $this->applicant(['rec_phase_id' => 1, 'import_source' => 'csv_import']);

        $this->assertSame([$id], $this->ids());
    }

    public function test_stillgelegte_phase_zaehlt_nicht_trotz_schalter(): void
    {
        // Der Backfill-Planner ueberspringt inaktive Phasen bewusst; eine
        // Phase, die NACH dem Schalten stillgelegt wird, darf ihre Bewerber
        // nicht fuer immer buchbar halten.
        $this->applicant(['rec_phase_id' => 3]);

        $this->assertSame([], $this->ids());
    }

    public function test_geparkter_bewerber_erscheint_nicht(): void
    {
        $this->applicant(['is_parked' => true]);

        $this->assertSame([], $this->ids());
    }

    public function test_bewerber_am_hr_schreibtisch_erscheint_nicht(): void
    {
        $this->applicant(['is_on_hr_desk' => true]);

        $this->assertSame([], $this->ids());
    }

    public function test_als_dublette_markierter_bewerber_erscheint_nicht(): void
    {
        $original = $this->applicant();
        $this->applicant(['duplicate_of_applicant_id' => $original]);

        $this->assertSame([$original], $this->ids());
    }

    public function test_versendeter_vertrag_schliesst_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_contracts')->insert([
            'rec_applicant_id' => $id, 'status' => 'sent', 'sent_at' => '2026-08-01 10:00:00',
        ]);

        $this->assertSame([], $this->ids());
    }

    public function test_stornierter_vertrag_schliesst_nicht_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_contracts')->insert([
            'rec_applicant_id' => $id, 'status' => 'cancelled', 'sent_at' => '2026-08-01 10:00:00',
        ]);

        $this->assertSame([$id], $this->ids());
    }

    public function test_unversendeter_vertrag_schliesst_nicht_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_contracts')->insert([
            'rec_applicant_id' => $id, 'status' => 'pending', 'sent_at' => null,
        ]);

        $this->assertSame([$id], $this->ids());
    }

    public function test_aktive_buchung_schliesst_aus(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_interview_bookings')->insert([
            'rec_applicant_id' => $id, 'rec_interview_id' => 7, 'status' => 'booked',
        ]);

        $this->assertSame([], $this->ids());
    }

    public function test_nicht_erschienen_sperrt_weiterhin(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_interview_bookings')->insert([
            'rec_applicant_id' => $id, 'rec_interview_id' => 7, 'status' => 'no_show',
        ]);

        $this->assertSame([], $this->ids());
    }

    public function test_stornierte_buchung_sperrt_nicht(): void
    {
        $id = $this->applicant();
        Capsule::table('rec_interview_bookings')->insert([
            'rec_applicant_id' => $id, 'rec_interview_id' => 7, 'status' => 'cancelled',
        ]);

        $this->assertSame([$id], $this->ids());
    }

    public function test_inaktiver_bewerber_erscheint_nicht(): void
    {
        $this->applicant(['is_active' => false]);

        $this->assertSame([], $this->ids());
    }

    public function test_fremdes_team_erscheint_nicht(): void
    {
        $this->applicant(['team_id' => 4]);

        $this->assertSame([], $this->ids());
    }

    public function test_stellen_filter_laesst_nur_passende_stelle_durch(): void
    {
        $duesseldorf = $this->applicant();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => $duesseldorf, 'rec_posting_id' => 81, 'applied_at' => '2026-07-01',
        ]);

        $koeln = $this->applicant();
        Capsule::table('rec_applicant_posting')->insert([
            'rec_applicant_id' => $koeln, 'rec_posting_id' => 91, 'applied_at' => '2026-07-01',
        ]);

        $this->assertSame([$duesseldorf], $this->ids(8));
        $this->assertSame([$koeln], $this->ids(9));
    }

    public function test_importierte_umgehen_den_stellen_filter(): void
    {
        // Legacy-CSV-Importe haben keine Postings — sie sollen trotzdem in
        // jeden Termin buchbar bleiben, unabhaengig von der Termin-Stelle.
        $import = $this->applicant(['rec_phase_id' => null, 'import_source' => 'csv_import']);

        $this->assertSame([$import], $this->ids(8));
    }
}
