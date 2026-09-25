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
 * Das neue Portal ist noch kein Ersatz fuer das alte: es kann keine
 * Stammdaten pflegen und kennt die Arbeitgeber-Pflichtfrage nicht (an der
 * die Steuerklasse haengt). Wer trotzdem umstellt, muss das ausdruecklich
 * bestaetigen — --profil-fehlt-mir-egal. --zurueck (die Notbremse) bleibt
 * IMMER ohne Bestaetigung moeglich.
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

        // Die Meldung muss AUFZAEHLEN, was verloren geht.
        $this->assertStringContainsString('Stammdaten', $ausgabe);
        $this->assertStringContainsString('Hauptarbeitgeber', $ausgabe);
        // ... und sagen, was stattdessen zu tun ist.
        $this->assertStringContainsString('--profil-fehlt-mir-egal', $ausgabe);
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
            '--profil-fehlt-mir-egal' => true,
        ]);

        $this->assertSame(SwitchPortalVersion::SUCCESS, $exitCode);
        $this->assertNotNull(DB::table('rec_employees')->find($id)->portal_v2_since);
    }

    public function test_umstellen_mit_bestaetigung_und_alle_laeuft(): void
    {
        $id1 = $this->mitarbeiter();
        $id2 = $this->mitarbeiter();

        [$exitCode] = $this->runCommand(['--alle' => true, '--profil-fehlt-mir-egal' => true]);

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
