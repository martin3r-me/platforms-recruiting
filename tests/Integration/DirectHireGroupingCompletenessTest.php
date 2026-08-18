<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\DirectHire\Index as DirekteinstellungsSeite;

/**
 * INVARIANTE der Direkteinstellungs-Liste: jeder Bewerber, den der Filter der
 * Seite liefert, erscheint in GENAU EINER gerenderten Gruppe.
 *
 * Warum das eine eigene Zusicherung braucht: Filter und Gruppierung arbeiten
 * seit der Fassaden-Umstellung auf ZWEI verschiedenen Dimensionen.
 *
 *  - Geholt werden Bewerber ueber die ANZEIGE (`whereHas('postings.position',
 *    …$positionIds)`) — also darueber, woher die Bewerbung kam.
 *  - Gruppiert wird ueber die eigene STELLE der Bewerbung (`primaryPosition()`).
 *  - Gerendert wird im Blade nur `$byPosition[$position->id]` fuer jede Stelle
 *    aus `$positions` (aktive Direct-Hire-Stellen des Teams).
 *
 * Faellt die eigene Stelle nicht in diese Liste, landet der Bewerber unter einem
 * Gruppen-Schluessel, den das Blade nie abfragt: er verschwindet lautlos. Nichts
 * schlaegt fehl, es fehlt nur eine Zeile — dieselbe Klasse von Fehler wie der
 * Bewerber, der wegen eines leeren owned_by_user_id im Auto-Pilot unsichtbar war.
 *
 * Erreichbar ist der Zustand ohne Zutun: wer sich ueber eine Direct-Hire-Anzeige
 * bewirbt und danach eine Schulung an einer Filiale OHNE Direkteinstellung bucht,
 * bekommt die gebuchte Stelle ins Feld — die Anzeige zeigt weiter auf die
 * Herkunft. Genau dieser Bewerber (1030) steht im Bestand.
 *
 * Aufbau wie die anderen Integrationstests des Moduls (Container + Capsule von
 * Hand, ECHTE Migrationen per glob, auth()-Attrappe, feste Uhr).
 */
class DirectHireGroupingCompletenessTest extends TestCase
{
    private const TEAM = 8;

    /** Direct-Hire-Stelle, Herkunft aller drei Bewerber (Anzeige 810). */
    private const STELLE_ESSEN = 81;

    /** Zweite Direct-Hire-Stelle — steht mit in der Liste. */
    private const STELLE_KOELN = 82;

    /** KEINE Direct-Hire-Stelle: taucht in $positions nicht auf. */
    private const STELLE_OHNE_DIREKTEINSTELLUNG = 83;

    private const ANZEIGE_ESSEN = 810;
    private const ANZEIGE_KOELN = 820;

    private const PHASE_EINGANG = 101;

    /** Der Problemfall: Anzeige Essen (Direct Hire), Feld auf Stelle 83. */
    private const BEWERBER_AUSSERHALB = 1030;

    /** Normalfall: Anzeige Essen, Feld auf Koeln — gehoert unter Koeln. */
    private const BEWERBER_UMGEHAENGT = 1031;

    /** Bestandsfall: Anzeige Koeln, Feld leer — Fassaden-Fallback. */
    private const BEWERBER_OHNE_FELD = 1032;

    private const HEUTE = '2026-08-18 10:00:00';

    public static function setUpBeforeClass(): void
    {
        $container = Container::getInstance();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::unguard();
        Model::unsetEventDispatcher();

        $container->instance('db', $capsule->getDatabaseManager());
        $container->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);

        // Mini-Shims fuer die FREMDEN Tabellen, die die Eager Loads der Seite
        // beruehren. Sie bleiben LEER: bei leerem Ergebnis fragt Eloquent die
        // verschachtelten Relationen gar nicht mehr ab (Builder::get() laedt
        // Relationen nur bei count($models) > 0), deshalb reichen die Tabellen
        // selbst. Ohne sie bricht der Eager Load mit "no such table".
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
        });
        // deleted_at gehoert dazu: CommsChannel (und die CRM-Modelle) benutzen
        // SoftDeletes, der Global Scope setzt "where deleted_at is null" in JEDE
        // Abfrage — ohne die Spalte bricht schon der Eager Load der Anzeigen.
        $schema->create('comms_channels', function ($table) {
            $table->id();
            $table->softDeletes();
        });
        $schema->create('crm_contact_links', function ($table) {
            $table->id();
            $table->string('uuid')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('linkable_id')->nullable();
            $table->string('linkable_type')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('crm_contacts', function ($table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('crm_email_addresses', function ($table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('crm_phone_numbers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Die Seite benutzt die Auth-FASSADE (Auth::user()), deren Accessor die
        // Container-Bindung 'auth' ist — nicht AuthFactory::class, den der
        // auth()-Helper der anderen Testklassen aufloest. Deshalb hier BEIDE
        // Schluessel auf dieselbe Attrappe; nur AuthFactory zu binden endete in
        // "Target class [auth] does not exist".
        $attrappe = new class(self::TEAM) implements AuthFactory
        {
            public function __construct(private int $teamId) {}

            public function user(): object
            {
                return new class($this->teamId)
                {
                    public object $currentTeam;

                    public function __construct(int $teamId)
                    {
                        $this->currentTeam = (object) ['id' => $teamId];
                    }
                };
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
                // nicht benutzt: die Seite ruft nur Auth::user()->currentTeam
            }
        };

        $container->instance(AuthFactory::class, $attrappe);
        $container->instance('auth', $attrappe);

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        self::runRealMigrations();
        self::seed();
    }

    /**
     * Model::clearBootedModels() ist hier LOAD-BEARING — gemessen, nicht vermutet.
     *
     * Diese Klasse bootet Eloquent-Modelle OHNE Dispatcher
     * (Model::unsetEventDispatcher() oben). Eloquents $booted-Cache ist statisch:
     * wer eine Modellklasse zuerst ohne Dispatcher bootet, laesst deren
     * creating-Hooks fuer ALLE spaeteren Testklassen im selben Prozess still
     * ausfallen. In der Default-Reihenfolge liegt diese Klasse zwischen
     * ContractTemplateTypeInvariantsTest (raeumt genau das auf) und
     * DuplicateMatchQueryTest (braucht den uuid-Hook von RecApplicant) — ohne
     * diese Zeile stirbt Letzterer im GESAMTLAUF mit "NOT NULL constraint failed:
     * rec_applicants.uuid", im gefilterten Lauf nie. Genau so beobachtet: 8 Fehler
     * in DuplicateMatchQueryTest, allein durch das Hinzufuegen dieser Klasse.
     * Siehe den Reihenfolge-Hinweis in phpunit.xml.
     */
    public static function tearDownAfterClass(): void
    {
        Facade::clearResolvedInstances();
        Container::getInstance()->forgetInstance(AuthFactory::class);
        Container::getInstance()->forgetInstance('auth');
        Model::clearBootedModels();
        Carbon::setTestNow();
    }

    public function test_kein_gefilterter_bewerber_faellt_aus_der_liste(): void
    {
        $gruppen = $this->gruppenDerSeite();

        // Was der Filter der Seite geliefert hat (alle Gruppen, auch die, die das
        // Blade nie abfragt) — und was davon tatsaechlich gerendert wird.
        $geliefert = collect($gruppen)->flatten(1)->pluck('id')->sort()->values()->all();
        $gerendert = collect($gruppen)->only($this->gerenderteStellenIds())
            ->flatten(1)->pluck('id')->sort()->values()->all();

        $this->assertSame([1030, 1031, 1032], $geliefert,
            'Vorflug: der Filter liefert alle drei Bewerber');

        $this->assertSame($geliefert, $gerendert,
            'jeder gefilterte Bewerber muss in einer gerenderten Gruppe stehen');

        // Und genau EINMAL: groupBy vergibt einen Schluessel pro Bewerber, das
        // haelt der Test explizit fest, damit ein spaeterer Umbau auf mehrere
        // Gruppen (z. B. eine Zeile pro Anzeige) hier auffaellt.
        $this->assertSame(count($gerendert), count(array_unique($gerendert)),
            'kein Bewerber darf doppelt erscheinen');

        // Die konkrete Zuordnung des Problemfalls: er kam ueber die Essener
        // Anzeige in die Liste, also gehoert er unter Essen.
        $this->assertContains(self::BEWERBER_AUSSERHALB,
            collect($gruppen[self::STELLE_ESSEN] ?? [])->pluck('id')->all(),
            'der Bewerber mit Stelle ausserhalb der Liste gehoert unter die Stelle seiner Anzeige');
    }

    public function test_die_eigene_stelle_bleibt_die_zuordnung(): void
    {
        // Gegenprobe zum Rueckfall: solange die eigene Stelle in der Liste steht,
        // gewinnt sie — der Rueckfall darf normale Faelle nicht an die Anzeige
        // zurueckhaengen. 1031 kam ueber die ESSENER Anzeige, ist aber auf KOELN
        // festgelegt und gehoert deshalb unter Koeln.
        $gruppen = $this->gruppenDerSeite();

        $this->assertSame([self::BEWERBER_UMGEHAENGT, self::BEWERBER_OHNE_FELD],
            collect($gruppen[self::STELLE_KOELN] ?? [])->pluck('id')->sort()->values()->all(),
            'Festlegung auf Koeln und Fassaden-Fallback auf Koeln stehen unter Koeln');
    }

    // -----------------------------------------------------------------
    // Werkzeug
    // -----------------------------------------------------------------

    /**
     * Ruft applicantsByPosition() der echten Seite auf.
     *
     * Die Methode liest intern `$this->positions` — im Betrieb eine
     * #[Computed]-Property, deren Aufloesung die Livewire-Laufzeit braucht (die
     * es in dieser Testumgebung nicht gibt). Die Probe deklariert dafuer eine
     * echte Property und fuellt sie mit dem Ergebnis derselben Methode, die auch
     * das Blade rendert — gleiche Abfrage, nur ohne Livewire-Magie.
     *
     * @return array<int|null, mixed>
     */
    private function gruppenDerSeite(): array
    {
        $probe = new DirekteinstellungsProbe();
        $probe->positions = $probe->positionsAbfragen();

        return $probe->gruppenAbfragen();
    }

    /** @return list<int> */
    private function gerenderteStellenIds(): array
    {
        $probe = new DirekteinstellungsProbe();

        return $probe->positionsAbfragen()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // -----------------------------------------------------------------
    // Schema und Datenbestand
    // -----------------------------------------------------------------

    private static function runRealMigrations(): void
    {
        $core = self::packageRootOf(\Platform\Core\Models\CoreExtraFieldDefinition::class);

        $files = [
            $core . '/database/migrations/2026_02_07_000001_create_core_extra_field_definitions_table.php',
            $core . '/database/migrations/2026_02_07_000002_create_core_extra_field_values_table.php',
        ];

        $own = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($own);

        foreach (array_merge($files, $own) as $path) {
            if (!file_exists($path)) {
                throw new \RuntimeException("Migration fehlt: {$path}");
            }
            $migration = require $path;
            $migration->up();
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

    private static function seed(): void
    {
        $now = self::HEUTE;

        Capsule::table('rec_positions')->insert([
            ['id' => self::STELLE_ESSEN, 'uuid' => 'dhg-81', 'team_id' => self::TEAM,
             'title' => 'Essen', 'is_active' => 1, 'is_direct_hire' => 1,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::STELLE_KOELN, 'uuid' => 'dhg-82', 'team_id' => self::TEAM,
             'title' => 'Koeln', 'is_active' => 1, 'is_direct_hire' => 1,
             'created_at' => $now, 'updated_at' => $now],
            // Aktiv, aber KEINE Direkteinstellung: steht nicht in $positions und
            // ist damit kein Gruppen-Schluessel, den das Blade abfragt.
            ['id' => self::STELLE_OHNE_DIREKTEINSTELLUNG, 'uuid' => 'dhg-83', 'team_id' => self::TEAM,
             'title' => 'Neuss (Schulungsstandort)', 'is_active' => 1, 'is_direct_hire' => 0,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_phases')->insert([
            ['id' => self::PHASE_EINGANG, 'uuid' => 'dhg-101', 'team_id' => self::TEAM,
             'rec_position_id' => self::STELLE_ESSEN, 'name' => 'Eingang', 'order' => 1,
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_postings')->insert([
            ['id' => self::ANZEIGE_ESSEN, 'uuid' => 'dhg-810', 'rec_position_id' => self::STELLE_ESSEN,
             'team_id' => self::TEAM, 'title' => 'Essen Anzeige', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::ANZEIGE_KOELN, 'uuid' => 'dhg-820', 'rec_position_id' => self::STELLE_KOELN,
             'team_id' => self::TEAM, 'title' => 'Koeln Anzeige', 'status' => 'published',
             'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicants')->insert([
            ['id' => self::BEWERBER_AUSSERHALB, 'uuid' => 'dhg-a1030', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-01', 'rec_phase_id' => self::PHASE_EINGANG,
             'rec_position_id' => self::STELLE_OHNE_DIREKTEINSTELLUNG,
             'is_active' => 1, 'is_parked' => 0, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::BEWERBER_UMGEHAENGT, 'uuid' => 'dhg-a1031', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-02', 'rec_phase_id' => self::PHASE_EINGANG,
             'rec_position_id' => self::STELLE_KOELN,
             'is_active' => 1, 'is_parked' => 0, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
            ['id' => self::BEWERBER_OHNE_FELD, 'uuid' => 'dhg-a1032', 'team_id' => self::TEAM,
             'applied_at' => '2026-07-03', 'rec_phase_id' => self::PHASE_EINGANG,
             'rec_position_id' => null,
             'is_active' => 1, 'is_parked' => 0, 'is_test' => 0,
             'created_at' => $now, 'updated_at' => $now],
        ]);

        Capsule::table('rec_applicant_posting')->insert([
            ['rec_applicant_id' => self::BEWERBER_AUSSERHALB, 'rec_posting_id' => self::ANZEIGE_ESSEN,
             'applied_at' => '2026-07-01', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::BEWERBER_UMGEHAENGT, 'rec_posting_id' => self::ANZEIGE_ESSEN,
             'applied_at' => '2026-07-02', 'created_at' => $now, 'updated_at' => $now],
            ['rec_applicant_id' => self::BEWERBER_OHNE_FELD, 'rec_posting_id' => self::ANZEIGE_KOELN,
             'applied_at' => '2026-07-03', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}

/**
 * Reicht die zwei #[Computed]-Methoden der Direkteinstellungs-Seite heraus und
 * ersetzt die Livewire-Property-Magie durch eine echte Property (siehe
 * gruppenDerSeite()). Muster wie ScheduleScopeProbe in
 * InterviewPostingTeamScopeTest.
 */
final class DirekteinstellungsProbe extends DirekteinstellungsSeite
{
    /** Echte Property — die Elternmethode liest $this->positions ohne __get(). */
    public $positions;

    public function positionsAbfragen()
    {
        return parent::positions();
    }

    /** @return array<int|null, mixed> */
    public function gruppenAbfragen(): array
    {
        return parent::applicantsByPosition();
    }
}
