<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Platform\Core\Models\CoreExtraFieldDefinition;
use Platform\Core\Models\User;
use Platform\Crm\Models\CrmContact;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Models\RecContractTemplate;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Gemeinsame Welt fuer "Vertrag aus der Akte" (Tasks 2, 4-8): Container +
 * Capsule/SQLite je TEST frisch, Schema aus den ECHTEN Migrationen (Core-
 * Extrafelder, Lookups, Public-Form-Links, CRM-Kontakte, alle eigenen).
 * Muster VertragAnDerAnstellungTest + DokumenteHarness.
 *
 * Der Extrafeld-Cache (statisch, Schluessel Klasse:id) wird je Test geleert —
 * in einer frischen Datenbank kehren die ids wieder, und ein alter Eintrag
 * zeigte auf Definitionen, die es nicht mehr gibt.
 */
trait VertragAusAkteHarness
{
    protected int $team = 7;

    protected function weltAufbauen(): void
    {
        $container = Container::getInstance();
        Container::setInstance($container);
        if (!class_exists('Log', false)) {
            class_alias(\Illuminate\Support\Facades\Log::class, 'Log');
        }

        $container->instance('config', new ConfigRepository([
            'activity-log' => ['events' => []],
            'recruiting'   => ['zas' => [
                'company_prefix' => 'RG',
                'company_labels' => ['RG' => 'RheinGedeck GmbH', 'MA' => 'MA Dienstleistung für die Gastronomie UG'],
            ]],
        ]));
        $dispatcher = new Dispatcher($container);
        $container->instance('events', $dispatcher);
        $container->instance('log', new class {
            public function __call($m, $a) {}
        });
        $container->instance('url', new class {
            public function route($name, $parameters = [], $absolute = true): string
            {
                return '/' . $name . '/' . http_build_query($parameters);
            }
        });
        $team = $this->team;
        $container->singleton(AuthFactory::class, function () use ($team) {
            return new class($team) implements AuthFactory {
                public function __construct(private int $teamId) {}
                public function guard($name = null) { return $this; }
                public function user(): object { return (object) ['currentTeam' => (object) ['id' => $this->teamId]]; }
                public function check() { return false; }
                public function id() { return null; }
                public function shouldUse($name) {}
            };
        });
        $container->alias(AuthFactory::class, 'auth');

        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher($dispatcher);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        $this->extraFieldCacheLeeren();
        $this->migrationenFahren();
        $this->vertragsFelderAnlegen();

        Carbon::setTestNow('2026-10-09 10:00:00');
    }

    protected function weltAbbauen(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
        Model::clearBootedModels();
        $c = Container::getInstance();
        foreach (['config', 'events', 'log', 'url', 'db', 'db.schema'] as $n) {
            $c->forgetInstance($n);
        }
        Facade::clearResolvedInstances();
        $this->extraFieldCacheLeeren();
    }

    protected function extraFieldCacheLeeren(): void
    {
        foreach ([RecContract::class, RecApplicant::class, RecContractTemplate::class] as $klasse) {
            foreach (['extraFieldDefinitionsCache', 'extraFieldInheritanceStack'] as $name) {
                (new \ReflectionProperty($klasse, $name))->setValue(null, []);
            }
        }
    }

    /** Ohne diese Definitionen ist setExtraField() ein stiller No-Op (siehe ReissueContractTest). */
    private function vertragsFelderAnlegen(): void
    {
        foreach ([['vertragsbeginn', 'Vertragsbeginn', 'date', 10], ['vertragsende', 'Vertragsende', 'date', 20], ['zuschlag', 'Zuschlag (€/Std)', 'text', 30], ['herkunft', 'Herkunft (intern)', 'text', 90]] as [$name, $label, $typ, $order]) {
            CoreExtraFieldDefinition::create([
                'team_id' => $this->team, 'context_type' => RecContract::class, 'context_id' => null,
                'name' => $name, 'label' => $label, 'type' => $typ, 'order' => $order,
            ]);
        }
    }

    private function migrationenFahren(): void
    {
        $core = $this->paketWurzel(User::class);
        $crm  = $this->paketWurzel(CrmContact::class);
        $own  = dirname(__DIR__, 2);

        $fremd = [];
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2026_02_07_000001_create_core_extra_field_definitions_table.php',
            '2026_02_07_000002_create_core_extra_field_values_table.php',
            '2026_02_08_120000_add_is_mandatory_to_core_extra_field_definitions_table.php',
            '2026_02_12_000001_add_llm_verification_to_extra_fields.php',
            '2026_02_12_000002_add_auto_fill_to_extra_fields.php',
            '2026_02_12_000003_create_core_lookups_tables.php',
            '2026_02_16_000001_add_visibility_config_to_extra_field_definitions.php',
            '2026_02_23_000001_create_core_public_form_links_table.php',
            '2026_03_19_000001_add_description_to_core_extra_field_definitions_table.php',
        ] as $datei) {
            $fremd[] = $core . '/database/migrations/' . $datei;
        }
        $crmDateien = array_merge(
            glob($crm . '/database/migrations/*postal_address*.php') ?: [],
            glob($crm . '/database/migrations/*phone_number*.php') ?: [],
            glob($crm . '/database/migrations/*email_address*.php') ?: [],
            [
                $crm . '/database/migrations/2024_01_01_000016_create_crm_contacts_table.php',
                $crm . '/database/migrations/2024_01_01_000020_create_crm_contact_links_table.php',
                $crm . '/database/migrations/2026_02_18_220000_make_created_by_user_id_nullable_on_crm_contact_links.php',
                $crm . '/database/migrations/2026_03_19_000001_add_is_blacklisted_to_crm_contacts_table.php',
            ],
        );
        usort($crmDateien, fn (string $a, string $b) => strcmp(basename($a), basename($b)));

        $eigene = glob($own . '/database/migrations/*.php') ?: [];
        sort($eigene);

        foreach (array_merge($fremd, array_values(array_unique($crmDateien)), $eigene) as $pfad) {
            if (!file_exists($pfad)) {
                throw new \RuntimeException("Migration fehlt: {$pfad}");
            }
            (require $pfad)->up();
        }
    }

    private function paketWurzel(string $klasse): string
    {
        $dir = dirname((new \ReflectionClass($klasse))->getFileName());
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/database/migrations')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        throw new \RuntimeException('Paketwurzel nicht gefunden: ' . $klasse);
    }

    // ---- Fixtures --------------------------------------------------------

    protected function anstellung(array $set = []): RecEmployee
    {
        return RecEmployee::create(array_merge([
            'team_id' => $this->team, 'first_name' => 'Mia', 'last_name' => 'Muster',
            'company' => 'MA', 'personnel_number' => 'MA4711', 'is_active' => true,
            'street' => 'Ringstraße', 'house_number' => '5', 'zip' => '40210', 'city' => 'Düsseldorf',
            'birth_date' => '2001-04-03', 'email' => 'mia@example.org', 'phone' => '+4915112345678',
            'employment_type' => 'aushilfe', 'is_eu_citizen' => true, 'is_first_aider' => false,
        ], $set));
    }

    protected function vorlage(string $code, string $company = 'MA', array $set = []): RecContractTemplate
    {
        return RecContractTemplate::create(array_merge([
            'team_id' => $this->team, 'name' => $code, 'code' => $code, 'company' => $company,
            'is_active' => true, 'content' => '<p>{{vorname}} {{nachname}}</p>',
            'field_mappings' => ['vorname' => 'contact.first_name', 'nachname' => 'contact.last_name'],
        ], $set));
    }

    /** Vertrag an einer Anstellung; Extrafelder werden NACH dem Anlegen gesetzt. */
    protected function vertragAn(RecEmployee $a, RecContractTemplate $t, array $set = [], array $felder = []): RecContract
    {
        $v = RecContract::create(array_merge([
            'rec_applicant_id' => $a->rec_applicant_id, 'rec_employee_id' => $a->id,
            'rec_contract_template_id' => $t->id, 'team_id' => $a->team_id,
            'status' => 'sent', 'sent_at' => '2026-09-20 09:00:00', 'personalized_content' => '<p>Vertrag</p>',
        ], $set));
        foreach ($felder as $name => $wert) {
            $v->setExtraField($name, $wert);
        }

        return $v;
    }

    protected function bewerberMitKontakt(string $vorname, string $nachname, ?float $zuschlag = 1.5): RecApplicant
    {
        $b = RecApplicant::create(['team_id' => $this->team, 'is_active' => true, 'auto_pilot' => false, 'zuschlag' => $zuschlag]);
        $k = CrmContact::create(['team_id' => $this->team, 'is_active' => true, 'first_name' => $vorname, 'last_name' => $nachname]);
        $b->crmContactLinks()->create(['contact_id' => $k->id, 'team_id' => $this->team]);

        return $b;
    }

    protected function verknuepfen(RecEmployee $a, RecApplicant $b): RecEmployee
    {
        DB::table('rec_employees')->where('id', $a->id)->update(['rec_applicant_id' => $b->id]);

        return $a->fresh();
    }

    /** Eine Personen-Zeile fuer mehrere Anstellungen — Query Builder, wie PersonLinker. */
    protected function personVerbinden(RecEmployee ...$anstellungen): int
    {
        $personId = (int) DB::table('rec_persons')->insertGetId([
            'uuid' => 'p-' . bin2hex(random_bytes(6)), 'team_id' => $this->team,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rec_employees')->whereIn('id', array_map(fn (RecEmployee $a) => $a->id, $anstellungen))
            ->update(['rec_person_id' => $personId]);

        return $personId;
    }
}
