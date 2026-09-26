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
 * GEDREHT am 25.09.2026 (Aufgabe 6): "Arbeitgeber" ist seit dem Gruppen-Umbau
 * eine Gruppe wie jede andere -- speichereArbeitgeber() und die drei
 * arbeitgeber*-Eigenschaften sind entfallen. Diese Klasse ruft jetzt
 * oeffneGruppe('Arbeitgeber')/speichereGruppe() statt der alten,
 * arbeitgeber-eigenen Methode. Die GEPRUEFTE REGEL bleibt dieselbe.
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

    /** Oeffnet die Arbeitgeber-Gruppe und traegt die Formularwerte ein -- der Weg, den jeder Test unten braucht. */
    private function shellMitOffenerArbeitgeberGruppe(RecEmployee $ma, ?string $istHaupt = null, ?string $anderer = null): PortalShell
    {
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Arbeitgeber');
        if ($istHaupt !== null) {
            $shell->profilWerte['is_main_employer'] = $istHaupt;
        }
        if ($anderer !== null) {
            $shell->profilWerte['other_employer'] = $anderer;
        }

        return $shell;
    }

    // -----------------------------------------------------------------
    // Der Waechter wird wiederverwendet, nicht neu erfunden
    // -----------------------------------------------------------------

    public function test_ohne_antwort_wird_nichts_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma);

        $shell->speichereGruppe();

        $this->assertNotSame('', $shell->profilFehler);
        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_nein_ohne_namen_wird_ueber_den_waechter_abgelehnt(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '0', '');

        $shell->speichereGruppe();

        $this->assertNotSame('', $shell->profilFehler);
        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_ja_ohne_namen_wird_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '1');

        $shell->speichereGruppe();

        $this->assertSame('', $shell->profilFehler);
        $frisch = RecEmployee::find($ma->id);
        $this->assertTrue($frisch->is_main_employer);
        $this->assertNull($frisch->other_employer);
    }

    public function test_nein_mit_namen_wird_gespeichert(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '0', 'Musterkantine GmbH');

        $shell->speichereGruppe();

        $this->assertSame('', $shell->profilFehler);
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
        // Steht noch im Formular (z.B. nicht geleert, bevor umgestellt wurde)
        // -- muss trotzdem verworfen werden.
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '1', 'Alte Firma GmbH');

        $shell->speichereGruppe();

        $frisch = RecEmployee::find($ma->id);
        $this->assertTrue($frisch->is_main_employer);
        $this->assertNull($frisch->other_employer, 'other_employer ist AUSSCHLIESSLICH die Antwort auf "wenn nicht wir, wer dann".');
    }

    // -----------------------------------------------------------------
    // Kein Schreiben ohne gueltige Anmeldung
    // -----------------------------------------------------------------

    public function test_ohne_anmeldung_wird_nichts_gespeichert(): void
    {
        // Auch das Oeffnen selbst laeuft ohne Anmeldung ins Leere -- die
        // Gruppe bleibt null, es gibt nichts einzutragen. Der Speicherversuch
        // dahinter ist damit die Gegenprobe, dass wirklich nichts passiert.
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);
        $shell->state = 'unverified';
        $shell->oeffneGruppe('Arbeitgeber');
        $this->assertNull($shell->profilGruppe, 'Ohne Anmeldung darf keine Gruppe oeffnen.');

        $shell->speichereGruppe();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_gesperrter_mitarbeiter_kann_nicht_speichern(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '1');

        RecEmployee::where('id', $ma->id)->update(['portal_locked_at' => now()]);
        $shell->speichereGruppe();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
        $this->assertSame('gesperrt', $shell->state);
    }

    public function test_zurueckgenommener_pilot_kann_nicht_speichern(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '1');

        RecEmployee::where('id', $ma->id)->update(['portal_v2_since' => null]);
        $shell->speichereGruppe();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_deaktivierter_mitarbeiter_kann_nicht_speichern(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '1');

        RecEmployee::where('id', $ma->id)->update(['is_active' => false]);
        $shell->speichereGruppe();

        $this->assertNull(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_fehlende_staatsangehoerigkeit_blockt_dieses_formular_nicht_mehr(): void
    {
        // GEDREHT 25.09.2026, Fixrunde 1 zu Aufgabe 6 (Ruling C1): vorher
        // haengte die GANZE Kaskade an jedem Speichern, auch an einer Gruppe,
        // die mit der Staatsangehoerigkeit nichts zu tun hat -- das erzeugte
        // bei gleichzeitig fehlender Staatsangehoerigkeit UND fehlendem
        // Hauptarbeitgeber einen Ping-Pong-Deadlock (siehe
        // PortalProfileGuards-Docblock). Jetzt blockt ein Waechter nur noch
        // fuer die Reichweite der offenen Gruppe -- die Arbeitgeber-Antwort
        // geht also durch, auch ohne Staatsangehoerigkeit. Die fehlende
        // Staatsangehoerigkeit bleibt als eigene offene Aufgabe sichtbar
        // (Gegenprobe: PortalShellProfilTest, Fenster-Test).
        $ma = $this->mitarbeiter(['nationality' => null]);
        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '1');

        $shell->speichereGruppe();

        $this->assertSame('', $shell->profilFehler);
        $this->assertTrue((bool) RecEmployee::find($ma->id)->is_main_employer);
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
        // An diesem Ereignis haengt der Lohn-Trigger: an ihm merkt die
        // Lohnbuchhaltung, dass sich der Hauptarbeitgeber geaendert hat.
        $ma = $this->mitarbeiter();
        $gefeuert = false;
        RecEmployee::updated(function () use (&$gefeuert) { $gefeuert = true; });

        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '0', 'Musterkantine GmbH');
        $shell->speichereGruppe();

        $this->assertTrue($gefeuert, 'Ohne Eloquent-Ereignis gaebe es keinen Lohn-Trigger.');
        // Gegenprobe, dass ueberhaupt geschrieben wurde -- sonst waere die
        // Zusicherung oben nur deshalb erfuellbar, weil nichts passiert ist.
        $this->assertFalse(RecEmployee::find($ma->id)->is_main_employer);
    }

    public function test_aenderung_der_angabe_wird_wirklich_geschrieben(): void
    {
        $ma = $this->mitarbeiter(['is_main_employer' => true]);

        $shell = $this->shellMitOffenerArbeitgeberGruppe($ma, '0', 'Andere GmbH');
        $shell->speichereGruppe();

        $frisch = RecEmployee::find($ma->id);
        $this->assertFalse($frisch->is_main_employer);
        $this->assertSame('Andere GmbH', $frisch->other_employer);
        // ... und das Blatt ist zu, kein Fehler steht mehr.
        $this->assertNull($shell->profilGruppe);
        $this->assertSame('', $shell->profilFehler);
    }

    // -----------------------------------------------------------------
    // oeffneGruppe() belegt das Formular mit dem aktuellen Stand vor --
    // sonst zeigt die Auswahl leer, obwohl schon geantwortet wurde.
    // -----------------------------------------------------------------

    public function test_oeffnen_belegt_das_formular_mit_dem_aktuellen_stand_vor(): void
    {
        $ma = $this->mitarbeiter([
            'is_main_employer' => false,
            'other_employer'   => 'Musterkantine GmbH',
        ]);
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Arbeitgeber');

        $this->assertSame('0', $shell->profilWerte['is_main_employer']);
        $this->assertSame('Musterkantine GmbH', $shell->profilWerte['other_employer']);
    }

    public function test_unbeantwortet_bleibt_das_formular_leer(): void
    {
        $ma = $this->mitarbeiter();
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Arbeitgeber');

        $this->assertSame('', $shell->profilWerte['is_main_employer']);
        // other_employer ist nur sichtbar, solange is_main_employer !== true
        // (visible_if) -- bei "unbeantwortet" bleibt es also im Formular.
        $this->assertSame('', $shell->profilWerte['other_employer']);
    }
}
