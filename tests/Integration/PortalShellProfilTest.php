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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecEmployee;

/**
 * `PortalShell` gruppenweise: Gruppe oeffnen, ausfuellen, speichern,
 * schliessen (Aufgabe 6, Portal-Gleichstand).
 *
 * Muster wie PortalShellEmployerTest/PortalShellUploadTest: echte Modelle
 * auf SQLite via Capsule, kein Testbench (die Livewire-Komponente laesst
 * sich in dieser Suite nicht rendern -- render() wird deshalb NICHT
 * aufgerufen, nur oeffneGruppe()/speichereGruppe()/schliesseGruppe()
 * direkt).
 */
final class PortalShellProfilTest extends TestCase
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
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_locked_at')->nullable();
            $t->timestamp('portal_v2_since')->nullable();
            $t->timestamp('portal_verified_at')->nullable();
            $t->timestamp('zas_changed_at')->nullable();

            // Adresse -- ALLE Felder der Gruppe, nicht nur die im jeweiligen
            // Test gelesenen: speichereGruppe() schreibt beim Erfolg die
            // GANZE offene Gruppe (PortalProfileWriter setzt auch leere
            // Felder auf NULL), eine fehlende Spalte waere ein SQL-Fehler.
            $t->string('street')->nullable();
            $t->string('house_number')->nullable();
            $t->string('zip')->nullable();
            $t->string('city')->nullable();
            $t->string('country_code')->nullable();
            $t->string('birth_country')->nullable();
            $t->string('nationality')->nullable();

            $t->boolean('is_eu_citizen')->nullable();

            // Bankdaten
            $t->string('iban')->nullable();
            $t->string('bic')->nullable();
            $t->string('bank_institute')->nullable();
            $t->string('account_holder')->nullable();

            // Arbeitgeber
            $t->boolean('is_main_employer')->nullable();
            $t->string('other_employer', 128)->nullable();

            // Ausweis
            $t->date('identity_card_valid_until')->nullable();
            $t->unsignedBigInteger('identity_card_front_file_id')->nullable();
            $t->unsignedBigInteger('identity_card_back_file_id')->nullable();
            $t->unsignedBigInteger('selfie_file_id')->nullable();

            // Arbeitskleidung
            $t->string('shirt_size', 8)->nullable();
            $t->unsignedSmallInteger('pants_size')->nullable();
            $t->unsignedSmallInteger('shoe_size')->nullable();

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

    private function shell(RecEmployee $ma, bool $angemeldet = true): PortalShell
    {
        $shell = new PortalShell();
        $shell->employeeId = $ma->id;
        $shell->state = $angemeldet ? 'verified' : 'unverified';

        return $shell;
    }

    // -----------------------------------------------------------------
    // Aus dem Brief, Schritt 1
    // -----------------------------------------------------------------

    public function test_ohne_anmeldung_wird_keine_gruppe_geoeffnet(): void
    {
        // R3/R12: kein Feldladen ohne gueltigen Zustand. Anders als im alten
        // Portal, das loadFieldValues() schon in mount() ruft (§6).
        $shell = $this->shell($ma = $this->mitarbeiter(), angemeldet: false);

        $shell->oeffneGruppe('Bankdaten');

        $this->assertNull($shell->profilGruppe);
        $this->assertSame([], $shell->profilWerte);
    }

    public function test_unbekannte_gruppe_laeuft_still_ins_leere(): void
    {
        $shell = $this->shell($this->mitarbeiter());

        $shell->oeffneGruppe('Gibtesnicht');

        $this->assertNull($shell->profilGruppe);
    }

    public function test_nicht_sichtbare_gruppe_laesst_sich_nicht_oeffnen(): void
    {
        // R30: Non-EU-Gruppe nur bei is_eu_citizen === false. Bei null gibt es
        // sie nicht — auch nicht ueber $wire.call('oeffneGruppe', …).
        $shell = $this->shell($this->mitarbeiter(['is_eu_citizen' => null]));

        $shell->oeffneGruppe('Aufenthalt (Non-EU)');

        $this->assertNull($shell->profilGruppe);
    }

    public function test_oeffnen_belegt_die_felder_der_gruppe_vor(): void
    {
        $shell = $this->shell($this->mitarbeiter(['iban' => 'DE02', 'city' => 'Koeln']));

        $shell->oeffneGruppe('Bankdaten');

        $this->assertSame('Bankdaten', $shell->profilGruppe);
        $this->assertSame('DE02', $shell->profilWerte['iban']);
        // Nichts aus anderen Gruppen faehrt mit.
        $this->assertArrayNotHasKey('city', $shell->profilWerte);
    }

    public function test_datei_felder_stehen_nicht_im_formular(): void
    {
        // R20/E8 und §1.4 Punkt 2: kein zweiter Upload-Weg, keine zweite Liste.
        $shell = $this->shell($this->mitarbeiter());

        $shell->oeffneGruppe('Ausweis');

        $this->assertArrayHasKey('identity_card_valid_until', $shell->profilWerte);
        $this->assertArrayNotHasKey('identity_card_front_file_id', $shell->profilWerte);
    }

    public function test_speichern_schreibt_und_schliesst(): void
    {
        $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => true]);
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Bankdaten');
        $shell->profilWerte['iban'] = 'DE89370400440532013000';
        $shell->speichereGruppe();

        $this->assertSame('DE89370400440532013000', $ma->fresh()->iban);
        $this->assertNull($shell->profilGruppe);
        $this->assertSame('', $shell->profilFehler);
    }

    public function test_waechter_haelt_das_blatt_offen_und_die_eingaben_stehen(): void
    {
        // GEDREHT 25.09.2026, Fixrunde 1 zu Aufgabe 6 (Ruling C1): eine
        // fremde Verletzung (Staatsangehoerigkeit) blockt eine Gruppe wie
        // Arbeitskleidung nicht mehr, siehe
        // PortalShellEmployerTest::test_fehlende_staatsangehoerigkeit_blockt_dieses_formular_nicht_mehr.
        // Die Absicht des Tests bleibt aber richtig: verletzt die offene
        // Gruppe IHRE EIGENE Regel, bleibt das Blatt offen und die Eingaben
        // stehen — Early-Return OHNE Neuladen (EmployeePortal.php:288). Hier
        // mit einer Verletzung, die zur offenen Gruppe ("Arbeitgeber")
        // gehoert: "nein" ohne Namen.
        $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => true]);
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Arbeitgeber');
        $shell->profilWerte['is_main_employer'] = '0';
        $shell->profilWerte['other_employer'] = '';
        $shell->speichereGruppe();

        $this->assertSame('Arbeitgeber', $shell->profilGruppe);
        $this->assertSame('0', $shell->profilWerte['is_main_employer']);
        $this->assertStringContainsString('Hauptarbeitgeber', $shell->profilFehler);
        $this->assertTrue((bool) $ma->fresh()->is_main_employer);
    }

    public function test_gesperrter_zugang_speichert_nicht(): void
    {
        // R14: frisch aus der DB, nicht aus dem Sitzungszustand.
        $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => true]);
        $shell = $this->shell($ma);
        $shell->oeffneGruppe('Bankdaten');
        $shell->profilWerte['iban'] = 'DE02';

        DB::table('rec_employees')->where('id', $ma->id)->update(['portal_locked_at' => now()]);
        $shell->speichereGruppe();

        $this->assertNull($ma->fresh()->iban);
        $this->assertSame('gesperrt', $shell->state);
    }

    public function test_hauptarbeitgeber_ist_eine_gruppe_wie_jede_andere(): void
    {
        $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => null]);
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Arbeitgeber');
        $shell->profilWerte['is_main_employer'] = '0';
        $shell->profilWerte['other_employer'] = 'Mueller GmbH';
        $shell->speichereGruppe();

        $this->assertFalse($ma->fresh()->is_main_employer);
        $this->assertSame('Mueller GmbH', $ma->fresh()->other_employer);
    }

    public function test_die_synthetische_aufgabe_verschwindet_nach_der_antwort(): void
    {
        $ma = $this->mitarbeiter(['nationality' => 'DE', 'is_main_employer' => null]);
        $shell = $this->shell($ma);

        $shell->oeffneGruppe('Arbeitgeber');
        $shell->profilWerte['is_main_employer'] = '1';
        $shell->speichereGruppe();

        $this->assertNotNull($ma->fresh()->is_main_employer);
    }

    // -----------------------------------------------------------------
    // Punkt 1 (Koordinator-Auftrag): Seit Aufgabe 4 laeuft die volle
    // Waechter-Kaskade auch am Arbeitgeber-Knopf. Wer keine
    // Staatsangehoerigkeit hinterlegt hat, konnte die Arbeitgeber-Frage im
    // neuen Portal bislang NICHT beantworten -- und hatte KEIN Feld, um die
    // Staatsangehoerigkeit nachzutragen (siehe
    // PortalShellEmployerTest::test_fehlende_staatsangehoerigkeit_blockt_auch_dieses_formular,
    // die die Bremse zeigt, aber keinen Ausweg). Diese Aufgabe schliesst das
    // Fenster, weil sie die Gruppen bringt: 'nationality' steckt in der
    // GLEICHEN Gruppe, die auch 'Adresse' anzeigt (RecEmployee::
    // editableFieldGroups()). Der Gruppenname wird bewusst NICHT von Hand
    // eingegeben, sondern aus der Quelle gesucht -- derselbe Grund wie beim
    // kaufmaennischen Und in "Steuer & Versicherung".
    // -----------------------------------------------------------------

    public function test_fenster_geht_zu_staatsangehoerigkeit_nachtragen_oeffnet_die_arbeitgeber_frage(): void
    {
        // GEDREHT 25.09.2026, Fixrunde 1 zu Aufgabe 6 (Ruling C1): der Weg
        // (Staatsangehoerigkeit ueber ihre EIGENE Gruppe nachtragen) bleibt
        // gueltig, nur ohne den Umweg ueber eine vorherige Blockade -- die
        // Arbeitgeber-Gruppe ist seit C1 nicht mehr blockiert, solange die
        // Staatsangehoerigkeit fehlt, weil sie ausserhalb ihrer Reichweite
        // liegt (siehe PortalShellEmployerTest::
        // test_fehlende_staatsangehoerigkeit_blockt_dieses_formular_nicht_mehr).
        //
        // Ergaenzt um den Fall, der VOR C1 unmoeglich war: Doppel-Null
        // (nationality UND is_main_employer GLEICHZEITIG null) -- vorher ein
        // echter Ping-Pong-Deadlock, der GAR KEINE der beiden Gruppen mehr
        // speichern liess, nicht mal eine voellig unbeteiligte wie Bankdaten
        // (PortalProfileGuards-Docblock). Jetzt: Adresse mit
        // Staatsangehoerigkeit speichern GEHT, dazwischen geht auch
        // Bankdaten (unbeteiligte Gruppe, is_main_employer ist zu diesem
        // Zeitpunkt IMMER NOCH null), und danach laesst sich auch die
        // Arbeitgeber-Frage beantworten.
        $ma = $this->mitarbeiter([
            'nationality'      => null,
            'is_main_employer' => null,
        ]);
        $shell = $this->shell($ma);

        $nationalitaetsGruppe = $this->gruppeMitFeld($ma, 'nationality');
        $this->assertNotNull($nationalitaetsGruppe, 'Kein editierbares Feld heisst nationality -- die Quelle hat sich geaendert.');

        // 1. Staatsangehoerigkeit ueber die Gruppe nachtragen, in der sie
        //    tatsaechlich steht -- geht trotz Doppel-Null, weil
        //    is_main_employer ausserhalb der Reichweite dieser Gruppe liegt.
        $shell->oeffneGruppe($nationalitaetsGruppe);
        $this->assertSame($nationalitaetsGruppe, $shell->profilGruppe);
        $this->assertArrayHasKey('nationality', $shell->profilWerte, 'Die Gruppe muss das Feld auch wirklich anbieten.');
        $shell->profilWerte['nationality'] = 'deutsch';
        $shell->speichereGruppe();
        $this->assertSame('', $shell->profilFehler);
        $this->assertSame('deutsch', $ma->fresh()->nationality);

        // 2. Dazwischen: eine voellig unbeteiligte Gruppe (Bankdaten)
        //    speichert ebenfalls, obwohl is_main_employer noch immer null
        //    ist -- vor C1 waere auch das blockiert gewesen.
        $shell->oeffneGruppe('Bankdaten');
        $shell->profilWerte['iban'] = 'DE89370400440532013000';
        $shell->speichereGruppe();
        $this->assertSame('', $shell->profilFehler);
        $this->assertSame('DE89370400440532013000', $ma->fresh()->iban);

        // 3. Jetzt laesst sich auch die Arbeitgeber-Frage beantworten.
        $shell->oeffneGruppe('Arbeitgeber');
        $shell->profilWerte['is_main_employer'] = '1';
        $shell->speichereGruppe();
        $this->assertSame('', $shell->profilFehler);
        $this->assertTrue($ma->fresh()->is_main_employer);
        $this->assertNull($ma->fresh()->other_employer, '"Ja" leert den anderen Arbeitgeber (R21).');
    }

    private function gruppeMitFeld(RecEmployee $employee, string $feld): ?string
    {
        foreach ($employee->editableFieldGroups() as $name => $felder) {
            if (array_key_exists($feld, $felder)) {
                return $name;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Punkt 3 (Koordinator-Auftrag): Gruppennamen kommen IMMER aus
    // RecEmployee::editableFieldGroups() -- auch in diesem Test, nicht per
    // Hand abgetippt. Belegt ausdruecklich, dass "Steuer & Versicherung"
    // (kaufmaennisches Und) genauso oeffnet wie jede andere Gruppe.
    // -----------------------------------------------------------------

    public function test_jede_gruppe_aus_der_quelle_laesst_sich_oeffnen_inklusive_kaufmaennischem_und(): void
    {
        $ma = $this->mitarbeiter([
            'nationality'     => 'deutsch',
            'is_eu_citizen'   => false,   // damit auch "Aufenthalt (Non-EU)" mitgeprueft wird
            'is_main_employer' => true,
        ]);
        $shell = $this->shell($ma);

        $gruppennamen = array_keys($ma->editableFieldGroups());
        $this->assertContains('Steuer & Versicherung', $gruppennamen, 'Testvoraussetzung: die Quelle hat weiterhin das kaufmaennische Und.');
        $this->assertContains('Aufenthalt (Non-EU)', $gruppennamen);

        foreach ($gruppennamen as $gruppe) {
            $shell->oeffneGruppe($gruppe);
            $this->assertSame($gruppe, $shell->profilGruppe, "Gruppe '{$gruppe}' aus der Quelle liess sich nicht oeffnen.");
            $shell->schliesseGruppe();
        }
    }
}
