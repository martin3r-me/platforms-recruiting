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
            $t->boolean('is_active')->default(true);
            $t->timestamp('portal_v2_since')->nullable();
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
    // --zurueck laeuft immer, ohne Bestaetigung -- die Notbremse darf nie klemmen
    // -----------------------------------------------------------------

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
