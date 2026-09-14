<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\ContractProposalService;
use Platform\Recruiting\Services\ContractProposalState;
use Platform\Recruiting\Services\ContractSendEligibility;

/**
 * Der Weg des Schulungsleiter-Vorschlags vom Vorschlagen bis zur Uebernahme
 * durch HR — und der eine Uebergang, der das Feature traegt: die Korrektur
 * NACH der Uebernahme.
 *
 * Warum dieser Test hier und nicht in der Unit-Suite: die Zeitstempel haengen
 * an datetime-Casts, und die brauchen eine DB-Verbindung (ohne sie stirbt
 * schon die Zuweisung mit "Call to a member function connection() on null").
 * Er faehrt deshalb die ECHTEN Migrationen — dann kann das Testschema nicht
 * von der Produktion abweichen.
 *
 * Der gefaehrliche Fall, gegen den die ganze Mechanik gebaut ist: Daniel
 * schlaegt 0,60 vor, Clara uebernimmt, Daniel merkt seinen Vertipper und
 * korrigiert auf 1,20. Ginge der Vorschlag dann NICHT wieder auf "offen",
 * bliebe Claras 0,60 stehen und wuerde so versendet — ohne dass jemand
 * merkt, dass eine Korrektur vorlag.
 */
class ContractProposalTakeTest extends TestCase
{
    private const TEAM = 1;

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['activity-log' => ['events' => []]]));

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        // Ohne Dispatcher feuern die creating-Hooks nicht (uuid, public_token) —
        // das echte Schema verlangt sie als NOT NULL.
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Model::clearBootedModels();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $own = dirname(__DIR__, 2);
        foreach ([
            'database/migrations/2026_02_09_000005_create_rec_applicants_table.php',
            'database/migrations/2026_02_12_000001_add_public_token_to_rec_applicants_table.php',
            'database/migrations/2026_06_09_000010_add_zuschlag_to_rec_applicants.php',
            'database/migrations/2026_09_14_000001_add_contract_proposal_to_rec_applicants.php',
        ] as $relative) {
            $path = $own . '/' . $relative;
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            (require $path)->up();
        }
    }

    public static function tearDownAfterClass(): void
    {
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
    }

    public function test_korrektur_nach_der_uebernahme_macht_den_vorschlag_wieder_offen(): void
    {
        $applicant = $this->bewerber();

        // 09:00 — Daniel schlaegt vor.
        ContractProposalService::propose(
            $applicant,
            zuschlag: '0,60',
            vertragsbeginn: '2026-10-01',
            vertragsende: '2026-12-31',
            userId: 42,
            now: new \DateTimeImmutable('2026-09-14 09:00:00'),
        );
        $applicant->save();
        $this->assertSame('open', $this->zustand($applicant));

        // 10:00 — Clara uebernimmt.
        ContractProposalService::take($applicant, new \DateTimeImmutable('2026-09-14 10:00:00'));
        $applicant->save();
        $this->assertSame('taken', $this->zustand($applicant));
        $this->assertSame('0.60', (string) $applicant->zuschlag);

        // 11:00 — Daniel korrigiert seinen Vertipper.
        ContractProposalService::propose(
            $applicant,
            zuschlag: '1,20',
            vertragsbeginn: '2026-10-01',
            vertragsende: '2026-12-31',
            userId: 42,
            now: new \DateTimeImmutable('2026-09-14 11:00:00'),
        );
        $applicant->save();

        $this->assertSame('changed', $this->zustand($applicant->fresh()));
    }

    public function test_uebernahme_traegt_lohn_und_laufzeit(): void
    {
        $applicant = $this->bewerber();

        ContractProposalService::propose(
            $applicant,
            zuschlag: '0,75',
            vertragsbeginn: '2026-11-01',
            vertragsende: '2027-01-31',
            userId: 42,
            now: new \DateTimeImmutable('2026-09-14 09:00:00'),
        );
        $applicant->save();

        ContractProposalService::take($applicant, new \DateTimeImmutable('2026-09-14 10:00:00'));
        $applicant->save();

        $frisch = $applicant->fresh();
        $this->assertSame('0.75', (string) $frisch->zuschlag);
        // Die Laufzeit hat kein scharfes Feld — sie geht in Claras Formular
        // und von dort auf den Vertrag. Der Vorschlag bleibt die Quelle.
        $this->assertSame('2026-11-01', $frisch->vertragsbeginn_vorschlag);
        $this->assertSame('2027-01-31', $frisch->vertragsende_vorschlag);
    }

    public function test_leerer_lohnvorschlag_loescht_den_wert_von_hr_nicht(): void
    {
        // Daniel empfiehlt nur eine Laufzeit und laesst den Lohn leer. Claras
        // bereits getippter Zuschlag darf davon nicht auf NULL gehen.
        $applicant = $this->bewerber();
        $applicant->zuschlag = '0.90';
        $applicant->save();

        ContractProposalService::propose(
            $applicant,
            zuschlag: '',
            vertragsbeginn: '2026-10-01',
            vertragsende: null,
            userId: 42,
            now: new \DateTimeImmutable('2026-09-14 09:00:00'),
        );
        $applicant->save();

        ContractProposalService::take($applicant, new \DateTimeImmutable('2026-09-14 10:00:00'));
        $applicant->save();

        $this->assertSame('0.90', (string) $applicant->fresh()->zuschlag);
    }

public function test_ein_vorschlag_allein_macht_keinen_versand_moeglich(): void
    {
        // Das Kernversprechen des Features, als Waechter: Solange HR nicht
        // uebernommen hat, bleibt der scharfe Zuschlag leer — und damit
        // haelt ContractSendEligibility den Versand mit 'missing_zuschlag'
        // an. Wuerde propose() jemals in $zuschlag schreiben, faellt dieser
        // Test, und genau das ist sein Zweck.
        $applicant = $this->bewerber();

        ContractProposalService::propose(
            $applicant,
            zuschlag: '0,60',
            vertragsbeginn: '2026-10-01',
            vertragsende: null,
            userId: 42,
            now: new \DateTimeImmutable('2026-09-14 09:00:00'),
        );
        $applicant->save();

        $frisch = $applicant->fresh();
        $this->assertNull($frisch->zuschlag);
        $this->assertSame('missing_zuschlag', ContractSendEligibility::state(
            hasSent: false,
            legalBlocked: false,
            hasBeginn: true,
            hasZuschlag: $frisch->zuschlag !== null,
        ));
    }

    private function zustand(RecApplicant $applicant): string
    {
        return ContractProposalState::state(
            $applicant->vorschlag_at?->getTimestamp(),
            $applicant->vorschlag_taken_at?->getTimestamp(),
            hasSent: false,
        );
    }

    private function bewerber(): RecApplicant
    {
        // rec_applicants hat keine Namensspalten — der Name haengt am
        // CRM-Kontakt. Fuer diesen Test ist der Bewerber nur ein Traeger
        // der Vorschlagsfelder.
        return RecApplicant::create(['team_id' => self::TEAM]);
    }
}