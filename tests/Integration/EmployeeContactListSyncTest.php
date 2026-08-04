<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactLink;
use Platform\Crm\Models\CrmContactList;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Crm\Services\Comms\SubscriptionService;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Integrationstests des Kontaktbuch-Syncs gegen die ECHTEN Modelle auf SQLite
 * in-memory via Capsule. Bekannte Grenze: das handgebaute Schema unten prueft
 * KEINE NOT-NULL-/Spalten-Drift gegenueber den echten Migrationen.
 */
class EmployeeContactListSyncTest extends TestCase
{
    protected const TEAM = 7;

    protected static Container $container;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        self::$container = $container;

        // LogsActivity (CrmContact/CrmContactList) verlangt config().
        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
        ]));

        // event()-Helper (ContactListSubscriptionChanged::dispatch) + Model-Hooks.
        $dispatcher = new \Illuminate\Events\Dispatcher($container);
        $container->instance('events', $dispatcher);

        // Log-Facade (Fehlerpfade des Service/Observers).
        $container->instance('log', new class {
            public function __call(string $name, array $args): void
            {
            }
        });

        // CrmContactLink::creating ruft auth()->check() — Guard-Stub ohne User.
        $container->singleton(\Illuminate\Contracts\Auth\Factory::class, function () {
            return new class implements \Illuminate\Contracts\Auth\Factory {
                public function guard($name = null)
                {
                    return new class implements \Illuminate\Contracts\Auth\Guard {
                        public function check() { return false; }
                        public function guest() { return true; }
                        public function user() { return null; }
                        public function id() { return null; }
                        public function validate(array $credentials = []) { return false; }
                        public function hasUser() { return false; }
                        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) { return $this; }
                    };
                }
                public function shouldUse($name) {}
                public function __call($method, $args) { return $this->guard()->{$method}(...$args); }
            };
        });
        $container->alias(\Illuminate\Contracts\Auth\Factory::class, 'auth');

        Facade::setFacadeApplication($container);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();

        self::createSchema();
    }

    protected function setUp(): void
    {
        foreach (['rec_employees', 'crm_contacts', 'crm_contact_links', 'crm_contact_lists', 'crm_contact_list_members', 'rec_applicant_settings'] as $table) {
            Capsule::table($table)->delete();
        }
    }

    protected static function createSchema(): void
    {
        $schema = Capsule::schema();

        $schema->create('rec_employees', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('portal_token')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->boolean('is_active')->default(true);
            $t->date('employment_ended_at')->nullable();
            $t->timestamps();
        });

        $schema->create('crm_contacts', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('owned_by_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('contact_status_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        $schema->create('crm_contact_links', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('linkable_id');
            $t->string('linkable_type');
            $t->timestamps();
        });

        $schema->create('crm_contact_lists', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('color')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('requires_doi')->default(false);
            $t->string('doi_confirmation_subject')->nullable();
            $t->text('doi_confirmation_body')->nullable();
            $t->integer('member_count')->default(0);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('owned_by_user_id')->nullable();
            $t->unsignedBigInteger('team_id');
            $t->timestamps();
            $t->softDeletes();
        });

        $schema->create('crm_contact_list_members', function ($t) {
            $t->id();
            $t->string('uuid')->nullable();
            $t->unsignedBigInteger('contact_list_id');
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('added_by_user_id')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('subscribed');
            $t->timestamp('subscribed_at')->nullable();
            $t->timestamp('unsubscribed_at')->nullable();
            $t->string('consent_source', 100)->nullable();
            $t->timestamp('opt_in_confirmed_at')->nullable();
            $t->string('doi_token', 64)->nullable();
            $t->timestamps();
        });

        $schema->create('rec_applicant_settings', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->text('settings')->nullable();
            $t->timestamps();
        });
    }

    // ---- Helpers -----------------------------------------------------

    protected function service(): EmployeeContactListSyncService
    {
        return new EmployeeContactListSyncService(new SubscriptionService());
    }

    protected function makeList(): CrmContactList
    {
        $list = CrmContactList::create([
            'name' => 'Aktive Mitarbeiter',
            'team_id' => self::TEAM,
            'is_active' => true,
            'requires_doi' => false,
        ]);

        $settings = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        $settings->setSetting(EmployeeContactListSyncService::SETTING_LIST_ID, $list->id);
        $settings->save();

        return $list;
    }

    /**
     * @param array $contactOverrides z.B. ['is_active' => false] oder ['owned_by_user_id' => 9]
     */
    protected function makeEmployeeWithContact(array $employeeOverrides = [], array $contactOverrides = []): array
    {
        $employee = RecEmployee::create(array_merge([
            'team_id' => self::TEAM,
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'is_active' => true,
        ], $employeeOverrides));

        $contact = CrmContact::create(array_merge([
            'first_name' => 'Max',
            'last_name' => 'Muster',
            'team_id' => self::TEAM,
            'is_active' => true,
        ], $contactOverrides));

        $this->link($employee, $contact);

        return [$employee, $contact];
    }

    protected function link(RecEmployee $employee, CrmContact $contact): CrmContactLink
    {
        return CrmContactLink::create([
            'contact_id' => $contact->id,
            'team_id' => self::TEAM,
            'linkable_id' => $employee->id,
            'linkable_type' => $employee->getMorphClass(),
        ]);
    }

    /** Invariante nach jedem syncAll: nur subscribed-Zeilen, member_count == Zeilenzahl. */
    protected function assertListInvariant(CrmContactList $list): void
    {
        $rows = CrmContactListMember::where('contact_list_id', $list->id)->get();

        $this->assertTrue(
            $rows->every(fn ($m) => $m->status === 'subscribed'),
            'Invariante verletzt: Liste enthaelt Nicht-subscribed-Zeilen.'
        );
        $this->assertSame(
            $rows->count(),
            (int) $list->fresh()->member_count,
            'Invariante verletzt: member_count != Zeilenzahl.'
        );
    }

    // ---- Tests -------------------------------------------------------

    public function test_aktiver_ma_mit_link_wird_mitglied(): void
    {
        $list = $this->makeList();
        [, $contact] = $this->makeEmployeeWithContact();

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('ok', $report->status);
        $this->assertSame(1, $report->added);
        $this->assertNotNull(
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()
        );
        $this->assertListInvariant($list);
    }

    public function test_inaktiver_ma_zeile_wird_geloescht(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact();
        // Zweiter, unberuehrter aktiver MA haelt die Soll-Menge nach der
        // Deaktivierung von $employee non-empty. Ohne ihn waere die Soll-Menge
        // team-weit komplett leer und wuerde den haerteren, mit force NICHT
        // uebersteuerbaren empty_soll-Guard treffen statt des Schwellen-Guards
        // (siehe computeDiff: leere Soll-Menge wischt nie die Liste).
        $this->makeEmployeeWithContact(['first_name' => 'Bleibt']);

        $this->service()->syncAll(self::TEAM);
        $employee->update(['is_active' => false]);

        $report = $this->service()->syncAll(self::TEAM, force: true); // 1 von 2 Ist-Zeilen entfernt (50 %) -> unter Schwelle; force nur Belt-and-Braces

        $this->assertSame('ok', $report->status);
        $this->assertSame(1, $report->removed);
        $this->assertSame(
            0,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count(),
            'Zeile muss geloescht sein (nicht nur unsubscribed) — CardDAV filtert nicht auf Status.'
        );
        $this->assertListInvariant($list);
    }

    public function test_ma_ohne_link_wird_gezaehlt_und_uebersprungen(): void
    {
        $list = $this->makeList();
        RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Ohne', 'last_name' => 'Link', 'is_active' => true]);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(1, $report->skipped_without_contact);
        $this->assertSame(0, $report->added);
        $this->assertListInvariant($list);
    }

    public function test_nicht_auslieferbarer_kontakt_zaehlt_als_hidden(): void
    {
        $list = $this->makeList();
        // Kontakt inaktiv -> nicht CardDAV-auslieferbar.
        $this->makeEmployeeWithContact([], ['is_active' => false]);
        // Kontakt owned -> nicht auslieferbar fuer Team-Abos.
        $this->makeEmployeeWithContact(['first_name' => 'Zweiter'], ['owned_by_user_id' => 42]);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(2, $report->hidden_from_carddav);
        $this->assertSame(0, $report->added);
        $this->assertListInvariant($list);
    }

    public function test_mehrfach_link_auslieferbarer_gewinnt_und_wird_gezaehlt(): void
    {
        $list = $this->makeList();
        [$employee, $ownedContact] = $this->makeEmployeeWithContact([], ['owned_by_user_id' => 42]);
        $deliverable = CrmContact::create([
            'first_name' => 'Zweit',
            'last_name' => 'Kontakt',
            'team_id' => self::TEAM,
            'is_active' => true,
        ]);
        $this->link($employee, $deliverable);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(1, $report->ambiguous_multi_link);
        $this->assertSame(1, $report->added);
        $this->assertNotNull(
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $deliverable->id)->first()
        );
        $this->assertSame(
            0,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $ownedContact->id)->count()
        );
        $this->assertListInvariant($list);
    }

    public function test_von_aussen_unsubscribed_wird_renormalisiert(): void
    {
        $list = $this->makeList();
        [, $contact] = $this->makeEmployeeWithContact();
        $this->service()->syncAll(self::TEAM);

        // Simuliert globalUnsubscribe() von aussen.
        CrmContactListMember::where('contact_list_id', $list->id)
            ->where('contact_id', $contact->id)
            ->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame(1, $report->normalized);
        $this->assertSame('subscribed', CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()->status);
        $this->assertListInvariant($list);
    }

    public function test_fremdkontakt_fliegt_raus(): void
    {
        $list = $this->makeList();
        $this->makeEmployeeWithContact();
        $fremd = CrmContact::create(['first_name' => 'Manuell', 'last_name' => 'Dazu', 'team_id' => self::TEAM, 'is_active' => true]);
        (new SubscriptionService())->subscribe($list, $fremd, 'manual_admin');

        $report = $this->service()->syncAll(self::TEAM, force: true); // 1 von 2 = 50 %? -> nein: > 50 % noetig; force schadet nicht

        $this->assertSame(1, $report->removed);
        $this->assertSame(0, CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $fremd->id)->count());
        $this->assertListInvariant($list);
    }

    public function test_zweiter_lauf_ist_idempotent(): void
    {
        $list = $this->makeList();
        $this->makeEmployeeWithContact();
        $this->service()->syncAll(self::TEAM);

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('ok', $report->status);
        $this->assertSame(0, $report->added);
        $this->assertSame(0, $report->removed);
        $this->assertSame(0, $report->normalized);
        $this->assertSame(1, $report->unchanged);
        $this->assertListInvariant($list);
    }

    public function test_dry_run_schreibt_nichts(): void
    {
        $list = $this->makeList();
        $this->makeEmployeeWithContact();

        $report = $this->service()->syncAll(self::TEAM, dryRun: true);

        $this->assertTrue($report->dry_run);
        $this->assertSame(1, $report->added);
        $this->assertSame(0, CrmContactListMember::where('contact_list_id', $list->id)->count());
        $this->assertNull(
            RecApplicantSettings::getOrCreateForTeam(self::TEAM)->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC)
        );
    }

    public function test_ohne_konfiguration_not_configured(): void
    {
        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('not_configured', $report->status);
    }

    public function test_geloeschte_liste_list_missing(): void
    {
        $list = $this->makeList();
        $list->delete(); // SoftDelete

        $report = $this->service()->syncAll(self::TEAM);

        $this->assertSame('list_missing', $report->status);
    }

    public function test_last_sync_wird_nur_bei_echtem_ok_lauf_geschrieben(): void
    {
        $this->makeList();
        $this->makeEmployeeWithContact();

        $this->service()->syncAll(self::TEAM);

        $this->assertNotNull(
            RecApplicantSettings::getOrCreateForTeam(self::TEAM)->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC)
        );
    }

    public function test_fehlgeschlagener_subscribe_ergibt_partial_und_echte_zaehler(): void
    {
        $this->makeList();
        $this->makeEmployeeWithContact();
        [, $contactFail] = $this->makeEmployeeWithContact(['first_name' => 'Zweiter'], ['first_name' => 'Zweiter']);

        // Wirft nur fuer den zweiten Kontakt — der Rest laeuft echt durch.
        $failing = new class((int) $contactFail->id) extends SubscriptionService {
            public function __construct(private readonly int $failForContactId)
            {
            }

            public function subscribe(CrmContactList $list, CrmContact $contact, string $source = 'manual_admin', ?int $userId = null): CrmContactListMember
            {
                if ((int) $contact->id === $this->failForContactId) {
                    throw new \RuntimeException('subscribe kaputt (Testfall partial)');
                }

                return parent::subscribe($list, $contact, $source, $userId);
            }
        };

        $report = (new EmployeeContactListSyncService($failing))->syncAll(self::TEAM);

        $this->assertSame('partial', $report->status);
        $this->assertSame(1, $report->added, 'added zaehlt nur den tatsaechlich erfolgreichen Write.');
        $this->assertNull(
            RecApplicantSettings::getOrCreateForTeam(self::TEAM)->getSetting(EmployeeContactListSyncService::SETTING_LAST_SYNC),
            'last_sync darf bei partial nicht geschrieben werden.'
        );
    }
}
