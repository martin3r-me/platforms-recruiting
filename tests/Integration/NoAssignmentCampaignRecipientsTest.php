<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignRecipients;
use Platform\Recruiting\Services\Campaign\NoAssignmentCampaignSender;

/**
 * Der Loader beantwortet fuer die Auswahl im Modal und noch einmal im Job:
 * Darf diese Person angeschrieben werden, und soll sie vorausgewaehlt sein?
 *
 * Der Unterschied zwischen den beiden ist der Grund, warum es ihn gibt: das
 * Modal zeigt den Stand beim Oeffnen, der Job sendet Sekunden bis Minuten
 * spaeter — wer inzwischen einen Einsatz hat, darf die Nachricht „wir haben
 * nichts mehr von dir gehoert" nicht mehr bekommen.
 */
final class NoAssignmentCampaignRecipientsTest extends TestCase
{
    private Capsule $capsule;
    private string $applicantMorph;
    private string $contactMorph;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 12:00:00');

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
        $this->contactMorph = (new CrmContact())->getMorphClass();

        $s = $this->capsule->schema();
        $s->create('rec_applicants', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->string('public_token')->nullable();
            $t->integer('team_id'); $t->boolean('is_active')->default(true); $t->boolean('is_parked')->default(false);
            $t->timestamp('rejected_at')->nullable(); $t->timestamps();
        });
        $s->create('rec_employees', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id')->nullable();
            $t->integer('rec_applicant_id')->nullable(); $t->string('personnel_number')->nullable();
            $t->string('person_key')->nullable(); $t->timestamps();
        });
        $s->create('rec_dispo_assignments', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('rec_employee_id')->nullable();
            $t->date('datum')->nullable(); $t->integer('status_id')->default(1);
            $t->timestamp('zas_removed_at')->nullable(); $t->timestamp('deletion_confirmed_at')->nullable();
            $t->timestamps();
        });
        $s->create('rec_interview_bookings', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable(); $t->integer('team_id')->nullable();
            $t->integer('rec_interview_id')->nullable(); $t->integer('rec_applicant_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('einsatz_geklaert_at')->nullable(); $t->string('einsatz_geklaert_note')->nullable();
            $t->date('einsatz_wiedervorlage_am')->nullable();
            $t->timestamp('deleted_at')->nullable(); $t->timestamps();
        });
        $s->create('rec_auto_pilot_logs', function ($t) {
            $t->increments('id'); $t->integer('rec_applicant_id'); $t->string('type', 30);
            $t->text('summary')->nullable(); $t->text('details')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function person(int $id, string $vorname, string $nachname, array $attr = [], bool $phone = true): void
    {
        RecApplicant::forceCreate(array_merge(['id' => $id, 'team_id' => 3], $attr));
        Capsule::table('crm_contacts')->insert(['id' => 900 + $id, 'first_name' => $vorname, 'last_name' => $nachname]);
        Capsule::table('crm_contact_links')->insert(['contact_id' => 900 + $id, 'linkable_id' => $id, 'linkable_type' => $this->applicantMorph]);
        if ($phone) {
            Capsule::table('crm_phone_numbers')->insert(['phoneable_type' => $this->contactMorph, 'phoneable_id' => 900 + $id, 'international' => '+49176722834' . $id, 'is_active' => true, 'is_primary' => true]);
        }
    }

    private function load(array $ids, ?int $interviewId = null): array
    {
        return (new NoAssignmentCampaignRecipients())->load(3, $ids, $interviewId);
    }

    public function testWaehlbarUndVorausgewaehltMitNamen(): void
    {
        $this->person(1, 'Paulina', 'Marenin');

        $rows = $this->load([1]);

        $this->assertSame('Paulina Marenin', $rows[1]['name']);
        $this->assertTrue($rows[1]['selectable']);
        $this->assertTrue($rows[1]['checked']);
        $this->assertSame([], $rows[1]['badges']);
    }

    public function testOhneTelefonnummerNichtWaehlbar(): void
    {
        $this->person(2, 'Milan', 'Rudmann', phone: false);

        $rows = $this->load([2]);

        $this->assertFalse($rows[2]['selectable']);
        $this->assertFalse($rows[2]['checked']);
        $this->assertSame(['keine Telefonnummer'], $rows[2]['badges']);
    }

    public function testInzwischenImEinsatzIstNichtMehrWaehlbar(): void
    {
        $this->person(3, 'Finn', 'Schulz');
        Capsule::table('rec_employees')->insert(['id' => 30, 'team_id' => 3, 'rec_applicant_id' => 3, 'personnel_number' => 'RG18300']);
        Capsule::table('rec_dispo_assignments')->insert(['rec_employee_id' => 30, 'datum' => '2026-09-20', 'status_id' => 1]);

        $rows = $this->load([3]);

        $this->assertFalse($rows[3]['selectable'], 'Wer disponiert ist, darf die Nachfrage nicht bekommen.');
        $this->assertSame(['inzwischen im Einsatz'], $rows[3]['badges']);
    }

    /**
     * Das ZWEITE Tor (21.09.2026): zwischen Auswahl und Senden kann jemand den
     * Klaerungs-Haken setzen. „Wir haben nichts mehr von dir gehoert" an
     * jemanden, bei dem gerade jemand notiert hat, dass alles besprochen ist,
     * ist genau die Peinlichkeit, die dieses Tor verhindert.
     */
    public function testGeklaerteBekommenDieNachfrageNicht(): void
    {
        $this->person(30, 'Lydia', 'Bontioti');
        Capsule::table('rec_interview_bookings')->insert([
            'id' => 3001, 'uuid' => 'ivb-3001', 'team_id' => 3, 'rec_interview_id' => 55,
            'rec_applicant_id' => 30, 'status' => 'attended',
            'einsatz_geklaert_at' => '2026-09-20 09:00:00',
            'einsatz_geklaert_note' => 'Faengt im Oktober an.',
            'einsatz_wiedervorlage_am' => null,
        ]);

        $rows = $this->load([30], 55);

        $this->assertFalse($rows[30]['selectable'], 'Wer geklaert ist, bekommt keine Nachfrage.');
        $this->assertFalse($rows[30]['checked']);
        $this->assertSame(['inzwischen geklärt'], $rows[30]['badges']);
    }

    /**
     * Der Haken gilt je Schulung: an einem ANDEREN Termin sagt er nichts ueber
     * diesen aus — sonst schwiege eine alte Notiz eine neue Nachfrage tot.
     */
    public function testKlaerungAnEinemAnderenTerminSperrtNicht(): void
    {
        $this->person(31, 'Mert', 'Hasanoglou');
        Capsule::table('rec_interview_bookings')->insert([
            'id' => 3101, 'uuid' => 'ivb-3101', 'team_id' => 3, 'rec_interview_id' => 50,
            'rec_applicant_id' => 31, 'status' => 'attended',
            'einsatz_geklaert_at' => '2026-09-20 09:00:00',
            'einsatz_geklaert_note' => 'Alte Klaerung aus der Juni-Schulung.',
            'einsatz_wiedervorlage_am' => null,
        ]);

        $rows = $this->load([31], 55);

        $this->assertTrue($rows[31]['selectable']);
        $this->assertSame([], $rows[31]['badges']);
    }

    /**
     * Abgelaufene Klaerung: der Fall ist wieder offen, die Nachfrage also wieder
     * richtig. Dieselbe Regel wie in der Statistik, dieselbe Einheit.
     */
    public function testAbgelaufeneKlaerungSperrtNichtMehr(): void
    {
        $this->person(32, 'Giada', 'Festge');
        Capsule::table('rec_interview_bookings')->insert([
            'id' => 3201, 'uuid' => 'ivb-3201', 'team_id' => 3, 'rec_interview_id' => 55,
            'rec_applicant_id' => 32, 'status' => 'attended',
            'einsatz_geklaert_at' => '2026-08-01 09:00:00',
            'einsatz_geklaert_note' => 'Wollte im August starten.',
            'einsatz_wiedervorlage_am' => '2026-09-01',
        ]);

        $rows = $this->load([32], 55);

        $this->assertTrue($rows[32]['selectable'], 'die Wiedervorlage ist durch');
        $this->assertSame([], $rows[32]['badges']);
    }

    public function testBereitsAngeschriebeneBleibenWaehlbarAberNichtVorausgewaehlt(): void
    {
        $this->person(4, 'Silke', 'Schurig');
        Capsule::table('rec_auto_pilot_logs')->insert([
            'rec_applicant_id' => 4, 'type' => NoAssignmentCampaignSender::LOG_TYPE,
            'summary' => 'gesendet', 'details' => '[]', 'created_at' => '2026-09-10 09:30:00',
        ]);

        $rows = $this->load([4]);

        $this->assertTrue($rows[4]['selectable']);
        $this->assertFalse($rows[4]['checked'], 'Zweimal dieselbe Nachfrage ist eine Entscheidung, kein Versehen.');
        $this->assertSame(['angeschrieben 10.09.2026'], $rows[4]['badges']);
    }

    /**
     * DER FALL, DER DIE ERSTE FASSUNG LEER LIESS (Live-Blick 14.09.2026): wer
     * es bis zum Mitarbeiter geschafft hat, ist als BEWERBUNG inaktiv —
     * CreateEmployeeFromApplicantService setzt is_active=false, „raus aus dem
     * Dashboard“. Der Topf „ohne Einsatz“ besteht per Definition aus genau
     * diesen Menschen (sie haben eine Personalnummer). Ein aus der
     * Bewerber-Kampagne uebernommener is_active-Filter loeschte deshalb JEDE
     * Zeile: 16 Teilnehmer im Modal, „0 von 0 ausgewaehlt“.
     */
    public function testZumMitarbeiterGewordeneBewerbungenSindAnschreibbar(): void
    {
        $this->person(5, 'Karin', 'Pohl', ['is_active' => false]);

        $rows = $this->load([5]);

        $this->assertArrayHasKey(5, $rows, 'Inaktive Bewerbung ist der Normalfall für Mitarbeiter.');
        $this->assertTrue($rows[5]['selectable']);
        $this->assertTrue($rows[5]['checked']);
        $this->assertSame([], $rows[5]['badges']);
    }

    /**
     * Abgesagte und geparkte bleiben SICHTBAR mit Grund, statt stillschweigend
     * zu verschwinden — eine Zeile, die ohne Erklaerung fehlt, war schon einmal
     * der teuerste Teil dieses Features.
     */
    public function testAbgesagteUndGeparkteStehenMitGrundDaAberGesperrt(): void
    {
        $this->person(6, 'Abgesagt', 'Weg', ['rejected_at' => '2026-09-01 10:00:00']);
        $this->person(7, 'Geparkt', 'Weg', ['is_parked' => true]);

        $rows = $this->load([6, 7]);

        $this->assertFalse($rows[6]['selectable']);
        $this->assertSame(['abgesagt'], $rows[6]['badges']);
        $this->assertFalse($rows[7]['selectable']);
        $this->assertSame(['geparkt'], $rows[7]['badges']);
    }

    public function testTeamFremdeTauchenNichtAuf(): void
    {
        $this->person(8, 'Fremdes', 'Team', ['team_id' => 4]);
        $this->person(9, 'Bleibt', 'Drin');

        $rows = $this->load([8, 9]);

        $this->assertSame([9], array_keys($rows));
    }
}
