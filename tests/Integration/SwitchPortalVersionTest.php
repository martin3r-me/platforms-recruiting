<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\SwitchPortalVersion;
use Platform\Recruiting\Support\ProofTypes;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Die Bremse am Umstell-Kommando (recruiting:portal-umstellen).
 *
 * RICHTIGGESTELLT am 26.09.2026 (Schlussfix F2): die Bremse behauptete, wer
 * umgestellt werde, verliere "Stammdaten pflegen" und "die Pflichtfrage zum
 * Hauptarbeitgeber". Genau das haben die Aufgaben 4 bis 7 gebaut — der Text
 * stimmte nicht mehr, und die Option hiess danach (--profil-fehlt-mir-egal).
 * Eine Bremse, die einen falschen Grund nennt, wird beim naechsten Lesen
 * weggeraeumt: "das koennen wir doch laengst".
 *
 * Die drei ECHTEN Auflagen stehen jetzt im Text und werden hier gemessen:
 *   1. Der Sichttest auf einem echten Geraet ist nicht gelaufen.
 *   2. is_main_employer muss bei Bestandsteams im Lohn-Tracking gesetzt sein.
 *   3. Der Arbeitgeber-Erklaertext wartet auf Freigabe durch die
 *      Lohnabrechnung.
 *
 * --zurueck (die Notbremse) bleibt IMMER ohne Bestaetigung moeglich.
 */
final class SwitchPortalVersionTest extends TestCase
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
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->capsule->schema()->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('team_id')->nullable();
            $t->string('person_key', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_v2_since')->nullable();
            // Die Altspalten kommen aus dem Katalog, nicht aus einer
            // abgetippten Liste -- sonst prueft dieser Test eine andere Welt
            // als die, in der die Warnung rechnet.
            foreach (array_unique(ProofTypes::legacyFileColumnsAll()) as $spalte) {
                $t->integer($spalte)->nullable();
            }
            foreach (ProofTypes::legacyExpiryColumnsAll() as $spalte) {
                $t->date($spalte)->nullable();
            }
            $t->timestamps();
        });

        $this->capsule->schema()->create('rec_employee_proofs', function ($t) {
            $t->increments('id');
            $t->string('uuid', 64);
            $t->integer('team_id')->nullable();
            $t->integer('rec_employee_id');
            $t->string('person_key', 64)->nullable();
            $t->string('proof_type_code', 40);
            $t->integer('file_id')->nullable();
            $t->integer('file_back_id')->nullable();
            $t->date('valid_until')->nullable();
            $t->integer('version')->default(1);
            $t->timestamp('superseded_at')->nullable();
            $t->string('uploaded_via', 20)->default('employee');
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

    private function mitarbeiter(array $attr = []): int
    {
        return (int) DB::table('rec_employees')->insertGetId(array_merge([
            'team_id'         => 3,
            'is_active'       => true,
            'portal_v2_since' => null,
        ], $attr));
    }

    private function nachweis(int $employeeId, string $code): void
    {
        DB::table('rec_employee_proofs')->insert([
            'uuid'            => 'p-' . uniqid('', true),
            'team_id'         => 3,
            'rec_employee_id' => $employeeId,
            'proof_type_code' => $code,
            'file_id'         => 5001,
            'version'         => 1,
            'uploaded_via'    => 'import',
        ]);
    }

    /** @return array{0: int, 1: string} [exitCode, komplette Konsolenausgabe] */
    private function runCommand(array $options = []): array
    {
        $command = new SwitchPortalVersion();
        $command->setLaravel(new SwitchPortalVersionFakeLaravel());

        $input = new ArrayInput($options, $command->getDefinition());
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        return [$exitCode, $output->fetch()];
    }

    // -----------------------------------------------------------------
    // Umstellen ohne Bestaetigung bricht ab und aendert nichts
    // -----------------------------------------------------------------

    public function test_umstellen_ohne_bestaetigung_bricht_ab_und_aendert_nichts(): void
    {
        $id = $this->mitarbeiter();

        [$exitCode, $ausgabe] = $this->runCommand(['--ids' => (string) $id]);

        $this->assertSame(SwitchPortalVersion::FAILURE, $exitCode);
        $this->assertNull(DB::table('rec_employees')->find($id)->portal_v2_since);

        // Die Meldung muss die DREI ECHTEN Auflagen aufzaehlen.
        $this->assertStringContainsString('Sichttest', $ausgabe);
        $this->assertStringContainsString('is_main_employer', $ausgabe);
        $this->assertStringContainsString('Lohnabrechnung', $ausgabe);
        // ... und sagen, was stattdessen zu tun ist.
        $this->assertStringContainsString('--ich-habe-den-sichttest-gemacht', $ausgabe);
    }

    public function test_die_bremse_nennt_die_ueberholten_gruende_nicht_mehr(): void
    {
        // Die beiden alten Gruende sind seit den Aufgaben 4 bis 7 falsch:
        // das neue Portal pflegt Stammdaten und stellt die Arbeitgeber-Frage.
        // Stuenden sie weiter da, wuerde die Bremse beim naechsten Lesen als
        // veraltet weggeraeumt — samt der drei Auflagen, die wirklich offen
        // sind.
        [, $ausgabe] = $this->runCommand(['--ids' => (string) $this->mitarbeiter()]);

        $this->assertStringNotContainsString('Stammdaten pflegen', $ausgabe);
        $this->assertStringNotContainsString('profil-fehlt-mir-egal', $ausgabe);
    }

    public function test_die_alte_option_gibt_es_nicht_mehr(): void
    {
        // Wer den alten Namen im Deploy-Skript stehen laesst, soll einen
        // Fehler sehen und nicht still umstellen.
        $command = new SwitchPortalVersion();

        $this->assertFalse($command->getDefinition()->hasOption('profil-fehlt-mir-egal'));
        $this->assertTrue($command->getDefinition()->hasOption('ich-habe-den-sichttest-gemacht'));
    }

    public function test_umstellen_mit_alle_ohne_bestaetigung_bricht_ebenfalls_ab(): void
    {
        $id = $this->mitarbeiter();

        [$exitCode] = $this->runCommand(['--alle' => true]);

        $this->assertSame(SwitchPortalVersion::FAILURE, $exitCode);
        $this->assertNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    public function test_trockenlauf_ohne_bestaetigung_bricht_ebenfalls_ab(): void
    {
        // Die Bremse gilt unabhaengig davon, ob der Lauf ohnehin nichts
        // schreiben wuerde -- ein Trockenlauf ist trotzdem "das Umstellen".
        $id = $this->mitarbeiter();

        [$exitCode] = $this->runCommand(['--ids' => (string) $id, '--dry-run' => true]);

        $this->assertSame(SwitchPortalVersion::FAILURE, $exitCode);
        $this->assertNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    // -----------------------------------------------------------------
    // Umstellen mit Bestaetigung laeuft
    // -----------------------------------------------------------------

    public function test_umstellen_mit_bestaetigung_laeuft(): void
    {
        $id = $this->mitarbeiter();

        [$exitCode] = $this->runCommand([
            '--ids' => (string) $id,
            '--ich-habe-den-sichttest-gemacht' => true,
        ]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertNotNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    public function test_umstellen_mit_bestaetigung_und_alle_laeuft(): void
    {
        $id1 = $this->mitarbeiter();
        $id2 = $this->mitarbeiter();

        [$exitCode] = $this->runCommand(['--alle' => true, '--ich-habe-den-sichttest-gemacht' => true]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertNotNull(DB::table('rec_employees')->find($id1)->portal_v2_since);
        $this->assertNotNull(DB::table('rec_employees')->find($id2)->portal_v2_since);
    }

    // -----------------------------------------------------------------
    // F3 -- die Reihenfolge wird nicht erzwungen, aber genannt
    // -----------------------------------------------------------------

    public function test_warnt_wenn_altspalten_gefuellt_sind_aber_keine_nachweis_zeile(): void
    {
        // Wer umgestellt wird, BEVOR recruiting:nachweise-umziehen lief, hat
        // seinen Ausweis in der Altspalte und keine Nachweis-Zeile. Start
        // sagt dann "Fehlt noch", das Profil zeigt dieselbe Sache als
        // hochgeladen -- und die Pilotgruppe laedt alles zweimal hoch.
        $id = $this->mitarbeiter(['identity_card_front_file_id' => 5001]);

        [$exitCode, $ausgabe] = $this->runCommand([
            '--ids' => (string) $id,
            '--ich-habe-den-sichttest-gemacht' => true,
        ]);

        // WARNEN, nicht abbrechen.
        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertNotNull(DB::table('rec_employees')->find($id)->portal_v2_since);

        $this->assertStringContainsString('1', $ausgabe);
        $this->assertStringContainsString('nachweise-umziehen', $ausgabe);
    }

    public function test_warnt_nicht_wenn_die_nachweise_schon_umgezogen_sind(): void
    {
        $id = $this->mitarbeiter(['identity_card_front_file_id' => 5001]);
        $this->nachweis($id, 'ausweis');

        [$exitCode, $ausgabe] = $this->runCommand([
            '--ids' => (string) $id,
            '--ich-habe-den-sichttest-gemacht' => true,
        ]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('nachweise-umziehen', $ausgabe);
    }

    public function test_warnt_nicht_wenn_gar_keine_altspalte_gefuellt_ist(): void
    {
        // Ein frisch angelegter Mensch ohne Unterlagen hat nichts umzuziehen
        // -- eine Warnung waere hier nur Rauschen, das die echte entwertet.
        [$exitCode, $ausgabe] = $this->runCommand([
            '--ids' => (string) $this->mitarbeiter(),
            '--ich-habe-den-sichttest-gemacht' => true,
        ]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('nachweise-umziehen', $ausgabe);
    }

    public function test_warnt_auch_im_trockenlauf(): void
    {
        // Der Trockenlauf ist die Stelle, an der man es noch merken KANN.
        $id = $this->mitarbeiter(['identity_card_front_file_id' => 5001]);

        [$exitCode, $ausgabe] = $this->runCommand([
            '--ids' => (string) $id,
            '--ich-habe-den-sichttest-gemacht' => true,
            '--dry-run' => true,
        ]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertStringContainsString('nachweise-umziehen', $ausgabe);
        $this->assertNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    public function test_die_warnung_zaehlt_menschen_und_nicht_nachweise(): void
    {
        // Ein Mensch mit drei unumgezogenen Arten ist EIN Fall, nicht drei --
        // sonst liest sich die Zahl wie eine Betroffenenzahl und ist keine.
        $id = $this->mitarbeiter([
            'identity_card_front_file_id' => 5001,
            'selfie_file_id'              => 5002,
            'health_insurance_card_file_id' => 5003,
        ]);
        $zweiter = $this->mitarbeiter(['selfie_file_id' => 5004]);

        [, $ausgabe] = $this->runCommand([
            '--ids' => $id . ',' . $zweiter,
            '--ich-habe-den-sichttest-gemacht' => true,
        ]);

        $this->assertStringContainsString('Achtung: 2 der Betroffenen', $ausgabe);
    }

    // -----------------------------------------------------------------
    // --zurueck laeuft immer, ohne Bestaetigung -- die Notbremse darf nie klemmen
    // -----------------------------------------------------------------

    public function test_zurueck_warnt_nicht_wegen_der_nachweise(): void
    {
        // Wer zurueckgestellt wird, landet im alten Portal -- das liest die
        // Altspalten direkt. Eine Umzugs-Warnung an der Notbremse waere
        // Rauschen.
        $id = $this->mitarbeiter([
            'portal_v2_since' => now(),
            'identity_card_front_file_id' => 5001,
        ]);

        [$exitCode, $ausgabe] = $this->runCommand(['--ids' => (string) $id, '--zurueck' => true]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('nachweise-umziehen', $ausgabe);
    }

    public function test_zurueck_laeuft_immer_ohne_bestaetigung(): void
    {
        $id = $this->mitarbeiter(['portal_v2_since' => now()]);

        [$exitCode] = $this->runCommand(['--ids' => (string) $id, '--zurueck' => true]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    public function test_zurueck_mit_alle_laeuft_ebenfalls_ohne_bestaetigung(): void
    {
        $id = $this->mitarbeiter(['portal_v2_since' => now()]);

        [$exitCode] = $this->runCommand(['--alle' => true, '--zurueck' => true]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    public function test_zurueck_im_trockenlauf_laeuft_ebenfalls_ohne_bestaetigung(): void
    {
        $id = $this->mitarbeiter(['portal_v2_since' => now()]);

        [$exitCode] = $this->runCommand(['--ids' => (string) $id, '--zurueck' => true, '--dry-run' => true]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        // Trockenlauf: unveraendert, aber NICHT weil die Bremse griff.
        $this->assertNotNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }
}

/**
 * Minimaler Ersatz fuer die volle Laravel-Application (Muster
 * ProofReminderFakeLaravel in SendProofRemindersTest): Illuminate\Console\
 * Command::run() braucht runningUnitTests(), sonst nichts.
 */
final class SwitchPortalVersionFakeLaravel extends Container
{
    public function runningUnitTests(): bool
    {
        return true;
    }
}
