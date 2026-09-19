<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Support\HrDeskCaseVisibility;

/**
 * Welche offenen HR-Faelle auf dem Schreibtisch landen — gegen eine echte DB,
 * weil die Regel eine Query IST (Muster ManualBookingCandidatesTest).
 *
 * Anlass sind zwei Faelle vom 14.09.2026, die beide unsichtbar auf dem
 * Schreibtisch lagen, obwohl ihr Fall offen war:
 *
 *  - Bewerber 1940: is_active=false (Enrichment-Treffer vom 12.09.)
 *  - Bewerber 1942: is_parked=true
 *
 * Beide Flags standen als Ausschluss in der whereHas der Liste. Der
 * Schreibtisch ist aber das Sicherheitsnetz — er darf einen offenen Fall
 * nicht verschlucken, sondern muss den Zustand ANZEIGEN. Genau das pruefen
 * die ersten beiden Tests.
 *
 * Weiter ausgeschlossen bleiben abgelehnte Bewerber (erledigt) und solche
 * ohne Stelle (nicht im Funnel) — dafuer stehen die Gegenprobe-Tests.
 */
final class HrDeskCaseVisibilityTest extends TestCase
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
        // Ohne Dispatcher fallen die creating-Hooks (uuid, public_token) aus.
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
            $t->timestamp('rejected_at')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_employees', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id')->nullable();
            $t->timestamps();
        });

        $schema->create('rec_hr_desk_cases', function ($t) {
            $t->increments('id');
            $t->string('uuid', 36)->nullable();
            $t->integer('rec_applicant_id');
            $t->integer('team_id');
            $t->string('reason', 50);
            $t->string('status', 20)->default('open');
            $t->text('notes')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->integer('opened_by_user_id')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->integer('resolved_by_user_id')->nullable();
            $t->text('resolution_notes')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->capsule->schema()->drop('rec_hr_desk_cases');
        $this->capsule->schema()->drop('rec_employees');
        $this->capsule->schema()->drop('rec_applicants');
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Die beiden echten Faelle
    // -----------------------------------------------------------------

    public function testStillgelegterBewerberBleibtAufDemSchreibtisch(): void
    {
        $fall = $this->offenerFall(['is_active' => false]);

        $this->assertSame(
            [$fall->id],
            HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all(),
            'Ein offener Fall verschwindet, sobald der Bewerber stillgelegt ist (Fall 1940).',
        );
    }

    public function testGeparkterBewerberBleibtAufDemSchreibtisch(): void
    {
        $fall = $this->offenerFall(['is_parked' => true]);

        $this->assertSame(
            [$fall->id],
            HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all(),
            'Ein offener Fall verschwindet, sobald der Bewerber geparkt ist (Fall 1942).',
        );
    }

    // -----------------------------------------------------------------
    // Gegenproben — was weiterhin NICHT erscheinen darf
    // -----------------------------------------------------------------

    public function testAbgelehnterBewerberBleibtAusgeblendet(): void
    {
        $this->offenerFall(['rejected_at' => '2026-09-14 19:46:46']);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    public function testBewerberOhneStelleBleibtAusgeblendet(): void
    {
        $this->offenerFall(['is_unrouted' => true]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    /**
     * Charakterisierung, kein Wunsch: Die Liste haengt weiter am Flag, nicht
     * allein am offenen Fall. Ein Fall ohne gesetztes Flag ist ein
     * inkonsistenter Zustand (alle Schliess-Pfade raeumen beides zusammen ab),
     * und MigrateNonEuCases loescht das Flag ohne die Faelle zu schliessen —
     * wer das Flag hier rauswirft, holt damit Altfaelle zurueck an die
     * Oberflaeche. Das waere eine eigene Entscheidung mit eigenem Datencheck.
     */
    public function testFallOhneSchreibtischFlagBleibtAusgeblendet(): void
    {
        $this->offenerFall(['is_on_hr_desk' => false]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    /**
     * Der Schreibtisch ist die Triage-Liste fuer BEWERBER. Wer schon einen
     * Mitarbeiter-Datensatz hat, ist aus dem Funnel raus — sein Fall gehoert
     * dort nicht hin, egal welche Flags am alten Bewerber-Datensatz haengen.
     *
     * Die beiden Realfaelle (19.09.2026): Fall #19 wurde zwei Tage NACH der
     * MA-Anlage eroeffnet (der Bewerber fuellte danach noch das Onboarding-
     * Formular aus, die Nicht-EU-Antwort loeste die Regel aus), Fall #34
     * sogar sechs Wochen danach. Deshalb ist „bei der MA-Anlage schliessen"
     * kein Ersatz fuer diesen Schnitt.
     */
    public function testBewerberMitMitarbeiterDatensatzErscheintNicht(): void
    {
        $fall = $this->offenerFall();
        $this->capsule->table('rec_employees')->insert([
            'rec_applicant_id' => $fall->rec_applicant_id,
        ]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    /** Dieselbe Regel muss auch die Zaehler je Grund treffen. */
    public function testZaehlerUebergehenBewerberMitMitarbeiterDatensatz(): void
    {
        $fall = $this->offenerFall();
        $this->capsule->table('rec_employees')->insert([
            'rec_applicant_id' => $fall->rec_applicant_id,
        ]);

        $this->assertSame(0, HrDeskCaseVisibility::applicants(self::TEAM)->count());
    }

    public function testGeschlossenerFallErscheintNicht(): void
    {
        $this->offenerFall([], ['status' => RecHrDeskCase::STATUS_APPROVED]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    public function testFremdesTeamErscheintNicht(): void
    {
        $this->offenerFall(['team_id' => 4], ['team_id' => 4]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    // -----------------------------------------------------------------
    // Das Badge, das den ausgeblendeten Zustand ersetzt
    // -----------------------------------------------------------------

    public function testZustandsBadgeNenntStillgelegtUndGeparkt(): void
    {
        $applicant = $this->applicant(['is_active' => false, 'is_parked' => true]);

        $this->assertSame(
            ['stillgelegt', 'geparkt'],
            HrDeskCaseVisibility::stateLabels($applicant),
        );
    }

    public function testZustandsBadgeBleibtLeerImNormalfall(): void
    {
        $applicant = $this->applicant();

        $this->assertSame([], HrDeskCaseVisibility::stateLabels($applicant));
    }

    // -----------------------------------------------------------------

    private function applicant(array $attributes = []): RecApplicant
    {
        $applicant = new RecApplicant();
        $applicant->forceFill(array_merge([
            'uuid' => 'uuid-' . uniqid('', true),
            'public_token' => 'tok-' . uniqid('', true),
            'team_id' => self::TEAM,
            'is_active' => true,
            'is_parked' => false,
            'is_on_hr_desk' => true,
            'is_unrouted' => false,
            'rejected_at' => null,
        ], $attributes));
        $applicant->save();

        return $applicant;
    }

    private function offenerFall(array $applicantAttributes = [], array $caseAttributes = []): RecHrDeskCase
    {
        $applicant = $this->applicant($applicantAttributes);

        $case = new RecHrDeskCase();
        $case->forceFill(array_merge([
            'uuid' => 'uuid-' . uniqid('', true),
            'rec_applicant_id' => $applicant->id,
            'team_id' => self::TEAM,
            'reason' => RecHrDeskCase::REASON_TRAINING_CLARIFICATION,
            'status' => RecHrDeskCase::STATUS_OPEN,
            'opened_at' => '2026-09-14 19:46:46',
        ], $caseAttributes));
        $case->save();

        return $case;
    }
}
