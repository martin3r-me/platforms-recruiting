<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;

/**
 * Stufe "Nur Veranstaltungen" (Gate Stufe 1): Zuordnung per E-Mail aus dem
 * Setting dispo_event_only_emails am Anker-Team — case-insensitiv, Opt-in
 * (nicht gelistet = normaler Nutzer), fail-closed Richtung "normal" ohne
 * Anker-Team. Echte Migration, kein Testbench.
 */
class DispoAccessTest extends TestCase
{
    private const TEAM = 501;

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

        $container->instance('config', new ConfigRepository([
            'recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]],
        ]));

        $own = dirname(__DIR__, 2);
        (require $own . '/database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php')->up();
    }

    public static function tearDownAfterClass(): void
    {
        DispoAccess::flush();
        Container::getInstance()->forgetInstance('config');
        Facade::clearResolvedInstances();
    }

    protected function setUp(): void
    {
        Capsule::table('rec_applicant_settings')->delete();
        DispoAccess::flush();
    }

    private function user(?string $email): object
    {
        return new class($email) {
            public function __construct(public ?string $email) {}
        };
    }

    private function seed(array $emails): void
    {
        $settings = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        $settings->setSetting('dispo_event_only_emails', $emails);
        $settings->save();
        DispoAccess::flush();
    }

    private function seedTrainingLeaders(array $emails): void
    {
        $settings = RecApplicantSettings::getOrCreateForTeam(self::TEAM);
        $settings->setSetting('dispo_training_leader_emails', $emails);
        $settings->save();
        DispoAccess::flush();
    }

    public function test_listed_email_is_restricted_case_insensitively(): void
    {
        $this->seed(['event@rheingedeck.de']);

        $this->assertTrue(DispoAccess::eventOnly($this->user('event@rheingedeck.de')));
        $this->assertTrue(DispoAccess::eventOnly($this->user('EVENT@Rheingedeck.DE')), 'SSO liefert Mails in beliebiger Schreibung.');
    }

    public function test_unlisted_null_and_empty_are_normal_users(): void
    {
        $this->seed(['event@rheingedeck.de']);

        $this->assertFalse(DispoAccess::eventOnly($this->user('hr@rheingedeck.de')));
        $this->assertFalse(DispoAccess::eventOnly($this->user('')));
        $this->assertFalse(DispoAccess::eventOnly(null));
    }

    public function test_missing_setting_and_missing_anchor_mean_normal_user(): void
    {
        $this->assertFalse(DispoAccess::eventOnly($this->user('event@rheingedeck.de')), 'Kein Setting -> niemand eingeschraenkt.');

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => null]]]));
        DispoAccess::flush();
        try {
            $this->assertFalse(DispoAccess::eventOnly($this->user('event@rheingedeck.de')), 'Ohne Anker-Team keine Liste.');
        } finally {
            $container->instance('config', new ConfigRepository(['recruiting' => ['zas' => ['inbound_team_id' => self::TEAM]]]));
            DispoAccess::flush();
        }
    }

    public function test_settings_roundtrip_normalizes_via_settings_page_logic(): void
    {
        // Gleiche Normalisierung wie Settings::save(): kleingeschrieben + dedupliziert.
        $emails = array_values(array_unique(array_filter(array_map(
            fn ($line) => mb_strtolower(trim($line)),
            preg_split('/\r?\n/', "Event@Rheingedeck.de\n\n event@rheingedeck.de \nzweiter@rheingedeck.de") ?: []
        ))));
        $this->seed($emails);

        $this->assertSame(['event@rheingedeck.de', 'zweiter@rheingedeck.de'], DispoAccess::eventOnlyEmails());
    }

    public function test_teamleiter_liste_ist_von_der_veranstaltungs_liste_getrennt(): void
    {
        // Die Trennung ist der Zweck der zweiten Liste: ein Veranstaltungs-Konto
        // darf NICHT automatisch Bewerberdaten sehen, nur weil es existiert.
        $this->seed(['event@rheingedeck.de']);
        $this->seedTrainingLeaders(['schulung@rheingedeck.de']);

        $this->assertTrue(DispoAccess::trainingLeader($this->user('schulung@rheingedeck.de')));
        $this->assertFalse(DispoAccess::trainingLeader($this->user('event@rheingedeck.de')));
        $this->assertFalse(DispoAccess::eventOnly($this->user('schulung@rheingedeck.de')));
    }

    public function test_ein_konto_darf_auf_beiden_listen_stehen(): void
    {
        // Der erwartete Normalfall beim Kunden: dasselbe Sammelkonto sieht
        // Veranstaltungen UND bewertet seine Schulungen.
        $this->seed(['event@rheingedeck.de']);
        $this->seedTrainingLeaders(['event@rheingedeck.de']);

        $this->assertTrue(DispoAccess::eventOnly($this->user('event@rheingedeck.de')));
        $this->assertTrue(DispoAccess::trainingLeader($this->user('event@rheingedeck.de')));
    }

    public function test_teamleiter_zuordnung_ist_case_insensitiv_und_opt_in(): void
    {
        $this->seedTrainingLeaders(['schulung@rheingedeck.de']);

        $this->assertTrue(DispoAccess::trainingLeader($this->user('Schulung@Rheingedeck.DE')));
        $this->assertFalse(DispoAccess::trainingLeader($this->user('hr@rheingedeck.de')));
        $this->assertFalse(DispoAccess::trainingLeader($this->user('')));
        $this->assertFalse(DispoAccess::trainingLeader(null));
    }

    public function test_ohne_setting_ist_niemand_teamleiter(): void
    {
        // Fehlerrichtung wie bei eventOnly: fehlende Liste heisst "niemand",
        // nicht "alle".
        $this->assertFalse(DispoAccess::trainingLeader($this->user('schulung@rheingedeck.de')));
    }
}
