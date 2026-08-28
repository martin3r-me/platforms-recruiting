<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Campaign\NewDatesCampaignRecipients;

/**
 * Der Loader ist die einzige Eloquent-Seite der Segmentregel. Geprueft wird
 * hier NICHT die Regel (CampaignSegmentTest), sondern dass die Eingabe richtig
 * aus den Tabellen zusammenkommt: Telefon ueber den CRM-Kontakt, Buchungen
 * inkl. Storno-Akteur, offener Ort-Wartelisten-Eintrag, letzte Kampagne,
 * Phasen der Stelle — und dass Team-fremde IDs fehlen.
 *
 * Schema-Hinweis (Ruling task-6): rec_applicant.crmContactLinks() ist ein
 * morphMany auf crm_contact_links (linkable_id/linkable_type), nicht die im
 * Brief skizzierte rec_applicant_contact_links-Tabelle — siehe
 * src/Traits/HasApplicantContact.php + platforms-hcm/HasEmployeeContact.
 * crm_contacts.full_name ist ein Accessor (getFullNameAttribute), keine
 * Spalte. Morph-Typwerte werden aus getMorphClass() gelesen statt
 * hartkodiert, weil der CRM-Morph-Map-Eintrag ('crm_contact' => CrmContact)
 * ueber CrmServiceProvider registriert wird, der hier nicht bootet.
 */
final class NewDatesCampaignRecipientsTest extends TestCase
{
    private Capsule $capsule;
    private string $applicantMorph;
    private string $contactMorph;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists('Str', false)) {
            class_alias(\Illuminate\Support\Str::class, 'Str');
        }

        $container = Container::getInstance();
        Container::setInstance($container);
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $this->applicantMorph = (new RecApplicant())->getMorphClass();
        $this->contactMorph = (new \Platform\Crm\Models\CrmContact())->getMorphClass();

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('auto_pilot')->default(true);
            $t->boolean('is_on_hr_desk')->default(false); $t->integer('rec_phase_id')->nullable();
            $t->integer('rec_position_id')->nullable(); $t->date('applied_at')->nullable();
            $t->integer('auto_pilot_state_id')->nullable(); $t->integer('auto_pilot_reminder_count')->default(0);
            $t->timestamp('auto_pilot_last_reminder_at')->nullable(); $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('progress')->default(0); $t->timestamps();
        });
        $s->create('rec_positions', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id'); $t->string('title');
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $s->create('rec_phases', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id'); $t->integer('rec_position_id');
            $t->string('name'); $t->integer('order'); $t->boolean('is_active')->default(true);
            $t->boolean('auto_advance')->default(true); $t->string('completion_type')->default('fields');
            $t->text('completion_config')->nullable(); $t->text('auto_pilot_settings')->nullable(); $t->timestamps();
        });
        $s->create('rec_postings', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_position_id')->nullable();
            $t->integer('team_id')->nullable(); $t->string('title')->nullable(); $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        $s->create('rec_applicant_posting', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->integer('rec_posting_id');
            $t->date('applied_at')->nullable(); $t->text('notes')->nullable();
            $t->string('matched_via')->nullable(); $t->decimal('match_confidence', 5, 2)->nullable();
            $t->timestamps();
        });
        $s->create('rec_interview_bookings', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_applicant_id'); $t->integer('rec_interview_id');
            $t->integer('team_id')->nullable(); $t->string('status')->default('booked'); $t->boolean('is_active')->default(true);
            $t->timestamp('booked_at')->nullable(); $t->timestamp('seat_released_at')->nullable();
            $t->string('cancelled_by')->nullable(); $t->timestamp('cancelled_at')->nullable();
            $t->integer('created_by_user_id')->nullable(); $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        $s->create('rec_interview_waitlist', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_applicant_id');
            $t->integer('rec_interview_id')->nullable(); $t->integer('team_id')->nullable(); $t->boolean('armed')->default(false);
            $t->text('wunschorte')->nullable(); $t->timestamp('enrolled_at')->nullable(); $t->timestamp('notified_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable(); $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        // CRM-Kontakt-Kette wie sie primaryContactPhone() liest: morphMany
        // 'linkable' auf crm_contact_links, morphMany 'phoneable' auf
        // crm_phone_numbers (src/Traits/HasApplicantContact.php ->
        // platforms-hcm/HasEmployeeContact::crmContactLinks(); platform-crm
        // CrmContact::phoneNumbers()).
        $s->create('crm_contact_links', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('contact_id');
            $t->integer('company_id')->nullable(); $t->integer('linkable_id'); $t->string('linkable_type');
            $t->integer('team_id')->nullable(); $t->integer('created_by_user_id')->nullable(); $t->timestamps();
        });
        $s->create('crm_contacts', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('first_name')->nullable();
            $t->string('last_name')->nullable(); $t->string('middle_name')->nullable(); $t->string('nickname')->nullable();
            $t->timestamps();
        });
        $s->create('crm_phone_numbers', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('phoneable_type')->nullable();
            $t->integer('phoneable_id')->nullable(); $t->string('raw_input')->nullable(); $t->string('international')->nullable();
            $t->string('national')->nullable(); $t->string('country_code')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_primary')->default(false); $t->timestamps();
        });

        Capsule::table('rec_positions')->insert(['id' => 11, 'team_id' => 3, 'title' => 'MGL allgemein']);
        Capsule::table('rec_phases')->insert([
            ['id' => 40, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Bewerbung', 'order' => 1, 'completion_type' => 'fields'],
            ['id' => 41, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Schulung buchen', 'order' => 2, 'completion_type' => 'booking'],
            ['id' => 42, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Onboarding', 'order' => 3, 'completion_type' => 'fields'],
            ['id' => 43, 'team_id' => 3, 'rec_position_id' => 11, 'name' => 'Verträge', 'order' => 4, 'completion_type' => 'contract_sent'],
        ]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function applicant(int $id, int $phaseId, bool $phone = true, bool $hrDesk = false): void
    {
        Capsule::table('rec_applicants')->insert([
            'id' => $id, 'team_id' => 3, 'rec_phase_id' => $phaseId, 'rec_position_id' => 11,
            'applied_at' => '2026-07-15', 'is_on_hr_desk' => $hrDesk,
        ]);
        Capsule::table('crm_contacts')->insert(['id' => 1000 + $id, 'first_name' => 'Test', 'last_name' => 'Nr' . $id]);
        Capsule::table('crm_contact_links')->insert([
            'contact_id' => 1000 + $id, 'linkable_id' => $id, 'linkable_type' => $this->applicantMorph,
        ]);
        if ($phone) {
            Capsule::table('crm_phone_numbers')->insert([
                'phoneable_type' => $this->contactMorph, 'phoneable_id' => 1000 + $id,
                'raw_input' => '0176' . $id, 'international' => '+49176' . $id, 'is_active' => true, 'is_primary' => true,
            ]);
        }
    }

    public function testBaugruppenKommenZusammen(): void
    {
        $this->applicant(1, 40);                      // P1
        $this->applicant(2, 41);                      // P2 + Warteliste
        $this->applicant(3, 42);                      // P3 mit HR-Storno
        $this->applicant(4, 43);                      // P4 mit Selbst-Storno
        $this->applicant(5, 41, phone: false);        // kein Telefon
        $this->applicant(6, 41, hrDesk: true);        // HR-Desk + juengste Kampagne
        $this->applicant(7, 41);                      // hat aktive Buchung

        Capsule::table('rec_interview_waitlist')->insert(['rec_applicant_id' => 2, 'team_id' => 3, 'wunschorte' => '["moenchengladbach"]', 'enrolled_at' => '2026-07-10 09:00:00', 'notified_at' => '2026-07-15 09:00:00']);
        Capsule::table('rec_interview_bookings')->insert([
            ['rec_applicant_id' => 3, 'rec_interview_id' => 45, 'status' => 'cancelled', 'cancelled_by' => 'hr', 'cancelled_at' => '2026-08-26 17:35:00'],
            ['rec_applicant_id' => 4, 'rec_interview_id' => 49, 'status' => 'cancelled', 'cancelled_by' => 'applicant', 'cancelled_at' => '2026-08-26 12:50:00'],
            ['rec_applicant_id' => 7, 'rec_interview_id' => 86, 'status' => 'booked', 'cancelled_by' => null, 'cancelled_at' => null],
        ]);
        Capsule::table('rec_auto_pilot_logs')->insert([
            ['rec_applicant_id' => 6, 'type' => 'campaign_sent', 'summary' => 'x', 'created_at' => '2026-08-20 10:00:00'],
            ['rec_applicant_id' => 6, 'type' => 'campaign_sent', 'summary' => 'x', 'created_at' => '2026-08-27 10:00:00'],
        ]);

        $rows = (new NewDatesCampaignRecipients())->load(3, [1, 2, 3, 4, 5, 6, 7, 999], new \DateTimeImmutable('2026-08-28 12:00:00'));

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($rows), 'Reihenfolge wie angefragt, 999 (fremd/fehlt) faellt raus.');

        $this->assertSame('A', $rows[1]['template']);
        $this->assertSame('Bewerbung', $rows[1]['phase']);
        $this->assertSame('Test Nr1', $rows[1]['name']);
        $this->assertSame('2026-07-15', $rows[1]['applied_at']);

        $this->assertSame('B', $rows[2]['template']);
        $this->assertContains('Warteliste seit 10.07.2026, benachrichtigt am 15.07.2026', $rows[2]['badges']);
        $this->assertTrue($rows[2]['checked']);

        $this->assertContains('Storniert am 26.08.2026 (HR)', $rows[3]['badges']);
        $this->assertTrue($rows[3]['checked']);

        $this->assertFalse($rows[4]['checked']);
        $this->assertContains('Termin selbst storniert am 26.08.2026', $rows[4]['badges']);

        $this->assertFalse($rows[5]['selectable']);
        $this->assertContains('kein Telefon', $rows[5]['badges']);

        $this->assertFalse($rows[6]['checked']);
        $this->assertContains('HR-Schreibtisch', $rows[6]['badges']);
        $this->assertContains('angeschrieben am 27.08.2026', $rows[6]['badges'], 'juengste Kampagne, nicht die aeltere');

        $this->assertFalse($rows[7]['selectable']);
        $this->assertContains('hat inzwischen gebucht', $rows[7]['badges']);
    }

    public function testLeereEingabeLeeresErgebnis(): void
    {
        $this->assertSame([], (new NewDatesCampaignRecipients())->load(3, [], new \DateTimeImmutable('2026-08-28')));
    }

    /**
     * Kein crm_contact_links-Datensatz (Altbestand/Import-Luecke) — der Name
     * faellt auf "Bewerber #<id>" zurueck statt auf einen Nullpointer, und
     * ohne Kontakt gibt es auch kein Telefon.
     */
    public function testNameFallbackOhneKontakt(): void
    {
        Capsule::table('rec_applicants')->insert([
            'id' => 8, 'team_id' => 3, 'rec_phase_id' => 41, 'rec_position_id' => 11,
            'applied_at' => '2026-07-15', 'is_on_hr_desk' => false,
        ]);

        $rows = (new NewDatesCampaignRecipients())->load(3, [8], new \DateTimeImmutable('2026-08-28 12:00:00'));

        $this->assertSame('Bewerber #8', $rows[8]['name']);
        $this->assertFalse($rows[8]['selectable']);
        $this->assertContains('kein Telefon', $rows[8]['badges']);
    }
}
