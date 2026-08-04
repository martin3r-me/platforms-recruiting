<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Crm\Models\CrmContact;
use Platform\Crm\Models\CrmContactListMember;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Observers\RecEmployeeContactListObserver;
use Platform\Recruiting\Services\EmployeeContactListSyncService;

/**
 * Observer-Pfad. WICHTIG: RecruitingServiceProvider::boot() laeuft hier nicht —
 * der Observer wird manuell registriert. Gleiche Schema-Grenze wie im Sync-Test.
 */
class EmployeeContactListObserverTest extends EmployeeContactListSyncTest
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // app()-Aufloesung im Observer: Service-Abhaengigkeiten sind auto-resolvebar.
        RecEmployee::observe(RecEmployeeContactListObserver::class);
    }

    public function test_is_active_flip_aendert_mitgliedschaft(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact();
        // Kein created()-Hook (s. Observer-Docblock): Ausgangszustand hier
        // einmal explizit ueber denselben Produktionspfad herstellen, den ein
        // echter Link-Anleger nach dem create() selbst aufrufen wuerde.
        $this->service()->syncEmployee($employee);

        $this->assertSame('subscribed', CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()?->status);

        $employee->update(['is_active' => false]);

        $this->assertSame(0, CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count());
        $this->assertSame(0, (int) $list->fresh()->member_count);
    }

    public function test_geteilter_kontakt_bleibt_wenn_zweiter_ma_aktiv_ist(): void
    {
        $list = $this->makeList();
        [$employee1, $contact] = $this->makeEmployeeWithContact();
        $employee2 = RecEmployee::create(['team_id' => self::TEAM, 'first_name' => 'Zwei', 'last_name' => 'Aktiv', 'is_active' => true]);
        $this->link($employee2, $contact);

        // Vorbedingung herstellen (kein created()-Hook, s. Observer-Docblock):
        // die Zeile muss VOR dem Flip existieren, sonst nimmt der anschliessende
        // Sync den Add-Zweig statt des zu testenden Removal-Vermeidungs-Zweigs
        // (elseif ($member) { $member->delete(); } wuerde nie ausgefuehrt).
        $this->service()->syncEmployee($employee1);
        $this->assertSame(
            'subscribed',
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()?->status,
            'Vorbedingung: Zeile muss vor dem Flip existieren, sonst wird der Removal-Vermeidungs-Zweig nicht getestet.'
        );

        $employee1->update(['is_active' => false]);

        $this->assertSame(
            1,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count(),
            'Kontakt des aktiven MA #2 darf durch Deaktivierung von MA #1 nicht entfernt werden.'
        );
    }

    public function test_unbeteiligtes_feld_update_loest_keinen_sync_aus(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact();
        // s.o. (test_is_active_flip_aendert_mitgliedschaft): kein created()-Hook,
        // Ausgangszustand explizit herstellen.
        $this->service()->syncEmployee($employee);

        // Zeile von aussen manipulieren; ein Nicht-Trigger-Update darf sie nicht anfassen.
        CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)
            ->update(['status' => 'unsubscribed']);

        $employee->update(['phone' => '+491234567']);

        $this->assertSame(
            'unsubscribed',
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->first()->status,
            'phone-Update darf den Sync nicht triggern (dirty-Check auf is_active/employment_ended_at).'
        );
    }

    public function test_crm_exception_kippt_den_save_nicht(): void
    {
        $this->makeList();
        [$employee] = $this->makeEmployeeWithContact();

        // Member-Tabelle wegreissen -> jeder Sync-Zugriff wirft.
        Capsule::schema()->drop('crm_contact_list_members');

        try {
            $employee->update(['is_active' => false]); // darf NICHT werfen
            $this->assertFalse($employee->fresh()->is_active);
        } finally {
            // Tabelle fuer nachfolgende Tests wiederherstellen.
            self::createSchema_members();
        }
    }

    public function test_ohne_konfigurierte_liste_ist_der_observer_ein_noop(): void
    {
        [$employee] = $this->makeEmployeeWithContact(); // keine Liste konfiguriert

        $employee->update(['is_active' => false]);

        $this->assertSame(0, CrmContactListMember::count());
    }

    public function test_employee_erstellung_alleine_loest_keinen_sync_aus(): void
    {
        $this->makeList();

        // Kein created()-Hook (s. Observer-Docblock): Employee+Link-Erstellung
        // legt fuer sich allein NIE eine Mitgliedschaftszeile an, selbst mit
        // konfigurierter Liste. Neuzugaenge holt der Voll-/Scheduler-Sync nach,
        // bzw. der Anlage-Code ruft syncEmployee() selbst auf (Spec: Regel).
        $this->makeEmployeeWithContact();

        $this->assertSame(0, CrmContactListMember::count());
    }

    /**
     * Override der von EmployeeContactListSyncTest geerbten Version: mit
     * registriertem Observer entfernt schon employee->update(['is_active' =>
     * false]) die Mitgliedschaftszeile (updated()-Hook — unabhaengig vom
     * entfernten created()-Hook, verifiziert per Vergleichslauf ohne diesen
     * Override), bevor der anschliessende force-Sync laeuft. Der
     * anschliessende Sync findet dann nichts mehr zu entfernen (removed: 0
     * statt 1) — die eigentliche Invariante (Zeile ist weg, nicht bloss
     * unsubscribed) bleibt unveraendert bestehen. Doppelte Abdeckung ist laut
     * Aufgabenstellung akzeptiert; dieses eine geerbte Test ist der einzige,
     * dessen Zaehler sich durch die doppelte Verarbeitung (Observer +
     * expliziter syncAll) verschiebt.
     */
    public function test_inaktiver_ma_zeile_wird_geloescht(): void
    {
        $list = $this->makeList();
        [$employee, $contact] = $this->makeEmployeeWithContact();
        $this->makeEmployeeWithContact(['first_name' => 'Bleibt']);

        $this->service()->syncAll(self::TEAM);
        $employee->update(['is_active' => false]);

        $report = $this->service()->syncAll(self::TEAM, force: true);

        $this->assertSame('ok', $report->status);
        $this->assertSame(0, $report->removed, 'Observer hat die Zeile bereits beim is_active-Flip entfernt.');
        $this->assertSame(
            0,
            CrmContactListMember::where('contact_list_id', $list->id)->where('contact_id', $contact->id)->count(),
            'Zeile muss geloescht sein (nicht nur unsubscribed) — CardDAV filtert nicht auf Status.'
        );
        $this->assertListInvariant($list);
    }

    /** Nur die Member-Tabelle neu anlegen (fuer den Exception-Test). */
    protected static function createSchema_members(): void
    {
        Capsule::schema()->create('crm_contact_list_members', function ($t) {
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
    }
}
