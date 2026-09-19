<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ProcessAutoPilotApplicants;
use Platform\Recruiting\Models\RecApplicant;
use ReflectionMethod;

/**
 * Wen der AutoPilot-Cron als naechsten Bewerber aufgreift — gegen eine echte
 * DB, weil die Auswahl eine Query IST.
 *
 * Anlass: „Geparkt" hat den AutoPilot nie zuverlaessig angehalten. Das Parken
 * (Dashboard::parkApplicant) setzt dafuer nur auto_pilot=false — und der
 * saving-Guard in RecApplicant::booted() dreht genau dieses Ausschalten bei
 * progress<100 sofort wieder auf true. Der Cron selbst filtert is_parked
 * nicht, also laeuft eine geparkte Person weiter durch den Funnel und bekommt
 * Templates. Beim HR-Schreibtisch ist dieselbe Luecke schon einmal aufgefallen
 * und mit dem is_on_hr_desk-Filter geschlossen worden (4bcefd9, 10.08.2026);
 * beim Parken fehlte das Gegenstueck.
 *
 * Belegt am lebenden Fall 1942 (19.09.2026): is_parked=true UND
 * auto_pilot=true gleichzeitig in der Produktionsdatenbank.
 */
final class AutoPilotSkipsParkedTest extends TestCase
{
    private const TEAM = 3;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        Container::setInstance($container);

        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->capsule->setEventDispatcher(new \Illuminate\Events\Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->schema();

        $schema->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->string('uuid', 36)->nullable();
            $t->string('public_token')->nullable();
            $t->integer('team_id');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_parked')->default(false);
            $t->boolean('is_on_hr_desk')->default(false);
            $t->boolean('is_unrouted')->default(false);
            $t->boolean('auto_pilot')->default(false);
            $t->integer('auto_pilot_state_id')->nullable();
            $t->timestamp('auto_pilot_completed_at')->nullable();
            $t->integer('owned_by_user_id')->nullable();
            $t->integer('rec_phase_id')->nullable();
            $t->timestamps();
        });

        // Leere Tabellen fuer das Eager-Loading der Auswahl-Query
        // (autoPilotState, team, ownedByUser, phase).
        foreach (['rec_auto_pilot_states', 'teams', 'users', 'rec_phases'] as $table) {
            $schema->create($table, function ($t) {
                $t->increments('id');
                $t->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        $schema = $this->capsule->schema();
        foreach (['rec_phases', 'users', 'teams', 'rec_auto_pilot_states', 'rec_applicants'] as $table) {
            $schema->drop($table);
        }
        parent::tearDown();
    }

    public function testGeparkterBewerberWirdNichtAufgegriffen(): void
    {
        $geparkt = $this->kandidat(['is_parked' => true]);

        $this->assertNull(
            $this->naechsterKandidat(),
            "Geparkter Bewerber #{$geparkt->id} wird trotzdem vom AutoPilot verarbeitet.",
        );
    }

    /**
     * Die Gegenprobe traegt den Test: ohne sie wuerde auch eine Query, die
     * grundsaetzlich nichts findet, gruen aussehen.
     */
    public function testNichtGeparkterBewerberWirdWeiterhinAufgegriffen(): void
    {
        $offen = $this->kandidat();

        $this->assertSame($offen->id, $this->naechsterKandidat()?->id);
    }

    private function naechsterKandidat(): ?RecApplicant
    {
        $method = new ReflectionMethod(ProcessAutoPilotApplicants::class, 'nextAutoPilotApplicant');
        $method->setAccessible(true);

        return $method->invoke(new ProcessAutoPilotApplicants(), null, []);
    }

    /**
     * Ein Bewerber, der alle uebrigen Bedingungen der Auswahl erfuellt —
     * damit jeder Test genau eine Bedingung variiert.
     */
    private function kandidat(array $attributes = []): RecApplicant
    {
        $applicant = new RecApplicant();
        $applicant->forceFill(array_merge([
            'uuid' => 'uuid-' . uniqid('', true),
            'public_token' => 'tok-' . uniqid('', true),
            'team_id' => self::TEAM,
            'is_active' => true,
            'is_parked' => false,
            'is_on_hr_desk' => false,
            'is_unrouted' => false,
            'auto_pilot' => true,
            'auto_pilot_state_id' => null,
            'auto_pilot_completed_at' => null,
            'owned_by_user_id' => 5,
        ], $attributes));
        $applicant->save();

        return $applicant;
    }
}
