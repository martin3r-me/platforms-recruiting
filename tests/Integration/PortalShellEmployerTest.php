<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\PortalAuth;

/**
 * Die Arbeitgeber-Pflichtfrage (Markus 24.09.2026) im NEUEN Portal.
 *
 * Wiederverwendet die Waechter-Kaskade (PortalProfileGuards, darin
 * MainEmployerRequiredGuard) -- keine zweite Regel, siehe
 * MainEmployerRequiredGuardTest fuer die Logik selbst. Hier wird nur die
 * VERDRAHTUNG im neuen Portal geprueft: Waechter vor dem Schreiben, "ja" leert
 * den anderen Arbeitgeber, kein Schreiben ohne Anmeldung.
 *
 * Der ZAS-Export-Marker wird hier NICHT geprueft -- in dieser Klasse laeuft
 * kein Beobachter, die Zusicherung waere eine Behauptung. Sie steht gemessen
 * in PortalProfileWriterTest (je verbotener Spalte einzeln) und fuer das alte
 * Portal in EmployerFieldsNoExportMarkerTest. Die Absicht dahinter:
 * is_main_employer/other_employer stehen NICHT in
 * RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS -- siehe Kommentar dort
 * und Migration 2026_09_23_000002_add_employer_fields_to_rec_employees.
 *
 * Muster wie PortalShellUploadTest: echte Modelle auf SQLite via Capsule,
 * kein Testbench.
 */
final class PortalShellEmployerTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        Model::clearBootedModels();

        $container->instance('db', $this->capsule->getDatabaseManager());
        $container->instance('db.schema', $this->capsule->getConnection()->getSchemaBuilder());
        $container->instance('cache', new Repository(new ArrayStore()));
        $container->instance('session', new Store('test', new ArraySessionHandler(60)));

        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64)->nullable();
            $t->string('portal_token', 64)->nullable();
            $t->integer('team_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->string('phone')->nullable();
            // Die Waechter-Kaskade (PortalProfileGuards) prueft den
            // Endzustand -- ohne Staatsangehoerigkeit blockt JEDES Speichern,
            // und dieser Test pruefte dann nichts mehr.
            $t->string('nationality')->nullable();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_verified_at')->nullable();
            $t->timestamp('zas_changed_at')->nullable();
            $t->boolean('is_main_employer')->nullable();
            $t->string('other_employer', 128)->nullable();
            $t->timestamps();
        });

        // usesInformalAddress() (ResolvesPublicAddressStyle) liest die
        // Team-Einstellungen -- ohne die Tabelle scheitert schon mount().
        $this->capsule->schema()->create('rec_applicant_settings', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->unique();
            $t->text('settings')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        parent::tearDown();
    }

    private function mitarbeiter(array $attr = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'uuid'            => 'u-' . bin2hex(random_bytes(4)),
            'team_id'         => 3,
            'first_name'      => 'Kevin',
            'last_name'       => 'Muster',
            'is_active'       => true,
            'nationality'     => 'deutsch',
            'portal_v2_since' => '2026-09-24 08:00:00',
        ], $attr));
    }

    private function shell(RecEmployee $ma): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = 'verified';

        return $shell;
    }

    // -----------------------------------------------------------------
    // Der Waechter wird wiederverwendet, nicht neu erfunden
    // -----------------------------------------------------------------

    public function test_ohne_antwort_wird_nichts_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);

        $shell->speichereArbeitgeber();

        $this->assertNotSame('', $shell->arbeitgeberFehler);
        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_nein_ohne_namen_wird_ueber_den_waechter_abgelehnt(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '0';
        $shell->arbeitgeberAnderer = '';

        $shell->speichereArbeitgeber();

        $this->assertNotSame('', $shell->arbeitgeberFehler);
        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_ja_ohne_namen_wird_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '1';

        $shell->speichereArbeitgeber();

        $this->assertSame('', $shell->arbeitgeberFehler);
        $frisch = RecEmployee::find($ma->id);
        $this->assertTrue($frisch->is_main_employer);
        $this->assertNull($frisch->other_employer);
    }

    public function test_nein_mit_namen_wird_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '0';
        $shell->arbeitgeberAnderer = 'Musterkantine GmbH';

        $shell->speichereArbeitgeber();

        $this->assertSame('', $shell->arbeitgeberFehler);
        $frisch = RecEmployee::find($ma->id);
        $this->assertFalse($frisch->is_main_employer);
        $this->assertSame('Musterkantine GmbH', $frisch->other_employer);
    }

    // -----------------------------------------------------------------
    // "Ja" leert einen zuvor eingetragenen anderen Arbeitgeber
    // -----------------------------------------------------------------

    public function test_ja_leert_einen_zuvor_eingetragenen_anderen_arbeitgeber(): void
    {
        $ma = $this->mitarbeiter(['is_main_employer' => false, 'other_employer' => 'Alte Firma GmbH']);
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '1';
        // Steht noch im Formular (z.B. nicht geleert, bevor umgestellt wurde)
        // -- muss trotzdem verworfen werden.
        $shell->arbeitgeberAnderer = 'Alte Firma GmbH';

        $shell->speichereArbeitgeber();

        $frisch = RecEmployee::find($ma->id);
        $this->assertTrue($frisch->is_main_employer);
        $this->assertNull($frisch->other_employer, 'other_employer ist AUSSCHLIESSLICH die Antwort auf "wenn nicht wir, wer dann".');
    }

    // -----------------------------------------------------------------
    // Kein Schreiben ohne gueltige Anmeldung
    // -----------------------------------------------------------------

    public function test_ohne_anmeldung_wird_nichts_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->state = 'unverified';
        $shell->arbeitgeberIstHaupt = '1';

        $shell->speichereArbeitgeber();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_gesperrter_mitarbeiter_kann_nicht_speichern(): void
    {
        $ma = $this->mitarbeiter(['portal_locked_at' => now()]);
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '1';

        $shell->speichereArbeitgeber();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
        $this->assertSame('gesperrt', $shell->state);
    }

    public function test_zurueckgenommener_pilot_kann_nicht_speichern(): void
    {
        $ma = $this->mitarbeiter(['portal_v2_since' => null]);
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '1';

        $shell->speichereArbeitgeber();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_deaktivierter_mitarbeiter_kann_nicht_speichern(): void
    {
        $ma = $this->mitarbeiter(['is_active' => false]);
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '1';

        $shell->speichereArbeitgeber();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_fehlende_staatsangehoerigkeit_blockt_auch_dieses_formular(): void
    {
        // Seit dem gemeinsamen Schreibweg haengt die GANZE Kaskade an diesem
        // Knopf, nicht mehr nur die Arbeitgeber-Regel -- dieselbe
        // Endzustandspruefung wie in EmployeePortal::saveAll(). Bis das Profil
        // die Staatsangehoerigkeit selbst anbietet (Aufgabe 6), ist das der
        // sichtbarste Unterschied zum bisherigen Verhalten.
        $ma = $this->mitarbeiter(['nationality' => null]);
        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '1';

        $shell->speichereArbeitgeber();

        $this->assertStringContainsString('Staatsangeh', $shell->arbeitgeberFehler);
        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    // -----------------------------------------------------------------
    // Schreibart: Eloquent, damit die Beobachter ueberhaupt anspringen
    //
    // Die ZAS-Marker-Zusicherung steht bewusst NICHT hier. In dieser
    // Testklasse ist kein RecEmployeeExportObserver registriert -- eine
    // Zeile "der Marker bleibt leer" koennte also gar nicht rot werden, auch
    // dann nicht, wenn jemand is_main_employer morgen in
    // RELEVANT_EMPLOYEE_FIELDS aufnimmt. Sie stuende hier als Behauptung.
    // Gemessen wird sie in PortalProfileWriterTest, je verbotener Spalte
    // einzeln und mit registriertem Beobachter.
    // -----------------------------------------------------------------

    public function test_speichern_loest_das_eloquent_ereignis_aus(): void
    {
        // GEDREHT am 25.09.2026: vorher stand hier assertFalse($gefeuert) --
        // "der Query Builder darf kein Eloquent-Event ausloesen". Seit die
        // Arbeitgeber-Felder ueber den gemeinsamen Schreibweg laufen, SOLL das
        // Ereignis feuern: an ihm haengt der Lohn-Trigger, und ohne ihn merkt
        // den Wechsel erst die Lohnbuchhaltung.
        $ma = $this->mitarbeiter();
        $gefeuert = false;
        RecEmployee::updated(function () use (&$gefeuert) { $gefeuert = true; });

        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '0';
        $shell->arbeitgeberAnderer = 'Musterkantine GmbH';
        $shell->speichereArbeitgeber();

        $this->assertTrue($gefeuert, 'Ohne Eloquent-Ereignis gaebe es keinen Lohn-Trigger.');
        // Gegenprobe, dass ueberhaupt geschrieben wurde -- sonst waere die
        // Zusicherung oben nur deshalb erfuellbar, weil nichts passiert ist.
        $this->assertFalse(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_aenderung_der_angabe_wird_wirklich_geschrieben(): void
    {
        // Vorher pruefte dieser Test nur, dass zas_changed_at leer bleibt --
        // ohne registrierten Beobachter und ohne positive Gegenprobe war er
        // gruen, egal was passierte. Jetzt misst er den Wechsel selbst.
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        $shell = $this->shell($ma);
        $shell->arbeitgeberIstHaupt = '0';
        $shell->arbeitgeberAnderer = 'Andere GmbH';
        $shell->speichereArbeitgeber();

        $frisch = RecEmployee::find($ma->id);
        $this->assertFalse($frisch->is_main_employer);
        $this->assertSame('Andere GmbH', $frisch->other_employer);
        // ... und das Formular zeigt danach den gespeicherten Stand.
        $this->assertSame('0', $shell->arbeitgeberIstHaupt);
        $this->assertSame('Andere GmbH', $shell->arbeitgeberAnderer);
    }

    // -----------------------------------------------------------------
    // identitaetLaden() (mount()/verify()) belegt das Formular mit dem
    // aktuellen Stand vor -- sonst zeigt die Auswahl leer, obwohl schon
    // geantwortet wurde.
    // -----------------------------------------------------------------

    public function test_anmeldung_belegt_das_formular_mit_dem_aktuellen_stand_vor(): void
    {
        $ma = $this->mitarbeiter([
            'portal_token'     => 'tok-vorbelegt',
            'is_main_employer' => false,
            'other_employer'   => 'Musterkantine GmbH',
        ]);
        // Die Anmeldung selbst wird nicht ueber verify() getestet (siehe
        // PortalShellVerifyTest) -- hier nur identitaetLaden() ueber eine
        // bereits erfolgreiche Session, mount() nimmt den Kurzschluss.
        session()->put(PortalAuth::sessionKey($ma->id), true);

        $shell = new PortalShell();
        $shell->token = 'tok-vorbelegt';
        $shell->employeeId = $ma->id;
        $shell->mount('tok-vorbelegt', new PortalAuth(new Repository(new ArrayStore())));

        $this->assertSame('0', $shell->arbeitgeberIstHaupt);
        $this->assertSame('Musterkantine GmbH', $shell->arbeitgeberAnderer);
    }

    public function test_unbeantwortet_bleibt_das_formular_leer(): void
    {
        $ma = $this->mitarbeiter(['portal_token' => 'tok-leer']);
        session()->put(PortalAuth::sessionKey($ma->id), true);

        $shell = new PortalShell();
        $shell->mount('tok-leer', new PortalAuth(new Repository(new ArrayStore())));

        $this->assertSame('', $shell->arbeitgeberIstHaupt);
        $this->assertSame('', $shell->arbeitgeberAnderer);
    }
}
