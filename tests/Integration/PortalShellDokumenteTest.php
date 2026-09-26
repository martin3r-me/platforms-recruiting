<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Core\Models\CorePublicFormLink;
use Platform\Recruiting\Livewire\Public\PortalShell;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecTrainingCertificate;
use ReflectionMethod;

/**
 * `PortalShell::dokumente()` — Aufgabe 8, Portal-Gleichstand.
 *
 * Das alte Portal (EmployeePortal) ist der EINZIGE Ort, an dem ein
 * Mitarbeiter seinen Arbeitsvertrag unterschreiben kann. Ohne diese Methode
 * haette ein auf portal_v2_since umgestellter Mensch ueberhaupt keinen Weg
 * mehr zur Unterschrift. Der Rumpf ist wortgleich aus
 * EmployeePortal::contracts() (658–701) plus certificateRows() (718–730)
 * uebernommen — nicht neu erfunden.
 *
 * Zwei Nebenwirkungen, die dieser Test bewusst NICHT verhindert, weil sie
 * Bestandsverhalten des alten Portals sind:
 *  - N8: das blosse ANZEIGEN legt CorePublicFormLink-Zeilen an (eine fuer
 *    den Bewerber, eine je nicht storniertem Vertrag). Ein Test unten misst
 *    die ANZAHL, damit eine spaetere Aenderung nicht MEHR Zeilen erzeugt als
 *    das alte Portal.
 *  - E17: die Zertifikat-Zeile traegt das Ausstellungsdatum in signed_at und
 *    gewinnt damit die Bedingung "completed || signed_at" im Blade, wenn der
 *    issued-Zweig nicht VOR ihr steht. Gemessen an der gerenderten Blade
 *    (testDasZertifikatBehauptetKeineUnterschrift), nicht am Quelltext-Diff.
 *
 * Harness wie ReissueContractTest/PortalShellProfilDatenTest: handgebauter
 * Container + Capsule/SQLite, Schema aus den ECHTEN Migrationen — kein
 * testbench in diesem Modul. Zusaetzlich ein echter Router mit den echten
 * Routen aus routes/public.php, weil dokumente() route() aufruft (wie
 * TrainingCertificatePublicRouteTest::testFormDerMetaButtonUrlKommtAusDerRoute).
 *
 * dokumente() ist PRIVATE — Zugriff ueber ReflectionMethod wie bei
 * profilDaten() in PortalShellProfilDatenTest, nicht ueber render() (kein
 * 'view'-Binding in dieser Suite).
 */
final class PortalShellDokumenteTest extends TestCase
{
    private const TEAM = 88;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        self::registerRoutes($container);
        self::runRealMigrations();
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_training_certificates')->delete();
        Capsule::table('rec_contracts')->delete();
        Capsule::table('rec_contract_templates')->delete();
        Capsule::table('core_public_form_links')->delete();
        Capsule::table('rec_employee_hr_data')->delete();
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_applicants')->delete();
    }

    // -----------------------------------------------------------------
    // Die vier Faelle aus dem Aufgaben-Zuschnitt (task-8-brief.md)
    // -----------------------------------------------------------------

    /** §5 Punkt 11 — stornierte Vertraege gehoeren nicht in die Liste. */
    public function test_stornierte_vertraege_werden_nicht_gezeigt(): void
    {
        $dokumente = $this->dokumenteFuer($this->mitarbeiterMitVertraegen(['sent', 'cancelled']));

        $this->assertCount(1, $dokumente);
        $this->assertSame('sent', $dokumente[0]['status']);
    }

    public function test_pdf_gibt_es_erst_bei_completed(): void
    {
        $offen = $this->dokumenteFuer($this->mitarbeiterMitVertraegen(['sent']));
        $this->assertNull($offen[0]['pdf_url']);

        $fertig = $this->dokumenteFuer($this->mitarbeiterMitVertraegen(['completed']));
        $this->assertNotNull($fertig[0]['pdf_url']);
    }

    /**
     * E17: der issued-Zweig muss im Blade VOR der Bedingung
     * `status === 'completed' || signed_at` stehen — sonst behauptet die
     * Zertifikat-Zeile "Unterschrieben am ..." ueber ein Dokument, das
     * niemand unterschrieben hat. Gemessen an der GERENDERTEN Blade in
     * PortalCertificateBadgeTest, hier nur die Reihenfolge im Quelltext.
     */
    public function test_das_zertifikat_behauptet_keine_unterschrift(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');

        $issued = strpos($blade, "'issued'");
        $signed = strpos($blade, "'completed'");

        $this->assertNotFalse($issued, 'Der issued-Zweig fehlt');
        $this->assertNotFalse($signed);
        $this->assertLessThan($signed, $issued, 'Der issued-Zweig muss VOR der Unterschrieben-Bedingung stehen (E17)');
    }

    public function test_ohne_bewerber_gibt_es_keine_dokumente_und_keinen_fehler(): void
    {
        $this->assertSame([], $this->dokumenteFuer($this->mitarbeiter()));
    }

    // -----------------------------------------------------------------
    // Ergaenzende Faelle: Sicherheit (eigene Vertraege) + N8 (Zeilenzahl)
    // -----------------------------------------------------------------

    /**
     * Ein Mensch darf nur SEINE eigenen Vertraege sehen. Da dokumente() den
     * Mitarbeiter als Parameter bekommt (kein eigener Lookup ueber eine ID),
     * ist die einzige Art, wie das schiefgehen koennte, eine Query ohne
     * Scope auf applicant_id — dieser Test waere dagegen rot.
     */
    public function test_dokumente_zeigen_nur_die_vertraege_des_eigenen_bewerbers(): void
    {
        $ersterVertrag = $this->mitarbeiterMitVertraegen(['sent'])->applicant->contracts->first();
        $zweiterMitarbeiter = $this->mitarbeiterMitVertraegen(['sent']);

        $dokumente = $this->dokumenteFuer($zweiterMitarbeiter);

        $this->assertCount(1, $dokumente);
        $this->assertNotSame($ersterVertrag->id, $dokumente[0]['id']);
    }

    /** Zertifikate laufen in derselben Liste mit — kein zweiter Aufruf noetig. */
    public function test_zertifikat_erscheint_in_der_liste_mit_ausstellungsdatum(): void
    {
        $ma = $this->mitarbeiterMitVertraegen([]);
        RecTrainingCertificate::create([
            'team_id' => self::TEAM,
            'rec_applicant_id' => $ma->applicant->id,
            'kind' => RecTrainingCertificate::KIND_SERVICE_BASIS,
            'issued_at' => '2026-08-12 09:30:00',
        ]);

        $dokumente = $this->dokumenteFuer($ma->fresh());

        $this->assertCount(1, $dokumente);
        $this->assertSame('issued', $dokumente[0]['status']);
        $this->assertNotNull($dokumente[0]['pdf_url']);
        $this->assertNull($dokumente[0]['sign_url']);
    }

    /**
     * N8: schon das ANZEIGEN legt CorePublicFormLink-Zeilen an — eine fuer
     * den Bewerber (PDF-Download) und eine je NICHT storniertem Vertrag
     * (Unterschreiben-Link). Zwei offene/fertige Vertraege + ein stornierter
     * => 1 (Bewerber) + 2 (Vertraege) = 3 Zeilen, NICHT 4. Diese Zaehlung
     * ist Bestandsverhalten (identisch zum alten Portal) und KEIN Fehler —
     * der Test soll nur verhindern, dass eine kuenftige Aenderung MEHR
     * Zeilen erzeugt als das alte Portal.
     */
    public function test_anzeigen_legt_nicht_mehr_public_form_link_zeilen_an_als_das_alte_portal(): void
    {
        $ma = $this->mitarbeiterMitVertraegen(['sent', 'completed', 'cancelled']);

        $this->assertSame(0, CorePublicFormLink::count(), 'Testvoraussetzung: vor dem Anzeigen gibt es noch keine Zeilen.');

        $this->dokumenteFuer($ma);

        $this->assertSame(3, CorePublicFormLink::count());
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function mitarbeiter(array $ueberschreiben = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id'   => self::TEAM,
            'is_active' => true,
        ], $ueberschreiben));
    }

    /** @param list<string> $statuses */
    private function mitarbeiterMitVertraegen(array $statuses): RecEmployee
    {
        $applicant = RecApplicant::create(['team_id' => self::TEAM]);
        $employee = $this->mitarbeiter(['rec_applicant_id' => $applicant->id]);

        $template = RecContractTemplate::create([
            'name'      => 'AV-default',
            'code'      => 'AV-default',
            'team_id'   => self::TEAM,
            'is_active' => true,
        ]);

        foreach ($statuses as $status) {
            $attributes = [
                'rec_applicant_id'         => $applicant->id,
                'rec_contract_template_id' => $template->id,
                'team_id'                  => self::TEAM,
                'status'                   => $status,
                'personalized_content'     => '<p>Vertrag</p>',
            ];
            if (in_array($status, ['sent', 'completed'], true)) {
                $attributes['sent_at'] = now()->subDays(3);
            }
            if ($status === 'completed') {
                $attributes['signed_at'] = now()->subDay();
                $attributes['completed_at'] = now()->subDay();
            }
            RecContract::create($attributes);
        }

        return $employee->fresh(['applicant.contracts.contractTemplate']);
    }

    private function dokumenteFuer(RecEmployee $employee): array
    {
        $shell = new PortalShell();
        $method = new ReflectionMethod($shell, 'dokumente');
        $method->setAccessible(true);

        return $method->invoke($shell, $employee);
    }

    // -----------------------------------------------------------------
    // Container-/Schema-Aufbau
    // -----------------------------------------------------------------

    /**
     * Echter Router mit den echten Routen aus routes/public.php, als 'url'
     * gebunden — route() (das dokumente() fuer sign_url/pdf_url aufruft)
     * loest ueber app('url') auf. Muster: TrainingCertificatePublicRouteTest
     * ::testFormDerMetaButtonUrlKommtAusDerRoute.
     */
    private static function registerRoutes(Container $container): void
    {
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);

        $router->prefix('recruiting')->group(function () {
            require dirname(__DIR__, 2) . '/routes/public.php';
        });
        $router->getRoutes()->refreshNameLookups();

        $url = new UrlGenerator(
            $router->getRoutes(),
            Request::create('https://mitarbeiter.rheingedeck.de')
        );
        $container->instance('url', $url);
    }

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(CorePublicFormLink::class);
        $own = dirname(__DIR__, 2);

        $files = [
            [$own, 'database/migrations/2026_02_09_000005_create_rec_applicants_table.php'],
            [$own, 'database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php'],
            [$core, 'database/migrations/2026_02_23_000001_create_core_public_form_links_table.php'],
            [$own, 'database/migrations/2026_04_15_100000_create_rec_contract_tables.php'],
            [$own, 'database/migrations/2026_08_12_000001_add_type_to_rec_contract_templates.php'],
            [$own, 'database/migrations/2026_05_20_000001_create_rec_employees_table.php'],
            // rec_employee_hr_data — RecContract::booted() schreibt bei
            // signed_at auf ensureHrData(), sobald der Bewerber einen
            // RecEmployee hat und alle AV-Vertraege signiert sind.
            [$own, 'database/migrations/2026_05_21_000002_create_rec_employee_hr_data_table.php'],
            [$own, 'database/migrations/2026_05_21_000003_add_full_hr_field_set_to_rec_employees_and_hr_data.php'],
            [$own, 'database/migrations/2026_08_12_000002_create_rec_training_certificates_table.php'],
        ];

        foreach ($files as [$root, $relative]) {
            $path = $root . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    private static function packageRootOf(string $class): string
    {
        $dir = dirname((new \ReflectionClass($class))->getFileName());

        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $class);
    }
}
