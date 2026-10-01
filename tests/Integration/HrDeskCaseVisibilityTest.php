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
            // rec_employee_id und das nullable auf rec_applicant_id kommen
            // aus 2026_10_01_000002: ein Fall haengt seit dem an EINEM von
            // beiden. Fehlte die Spalte hier, machte SQLite aus ihrem Namen
            // in der Sichtbarkeits-Abfrage ein String-Literal statt eines
            // Fehlers — der Test waere gruen und wertlos.
            $t->integer('rec_applicant_id')->nullable();
            $t->unsignedBigInteger('rec_employee_id')->nullable();
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
    // Die Faelle, die an einem MITARBEITER haengen (Einsatz-Trigger)
    // -----------------------------------------------------------------

    /**
     * Pflichttest der Entscheidung (Aufgabe 10): ein Fall ohne Bewerber
     * steht auf dem Schreibtisch. Vorher fiel er aus der Abfrage heraus,
     * weil `whereHas('applicant', ...)` keinen Bewerber zum Anhaengen hatte
     * — die harte Sperre des Einsatz-Triggers waere damit genau so folgenlos
     * gewesen wie die fehlende Verbindung zu ZAS.
     */
    public function testEinFallOhneBewerberStehtImHrSchreibtisch(): void
    {
        $fall = $this->mitarbeiterFall();

        $this->assertSame(
            [$fall->id],
            HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all(),
        );
    }

    /** Und er zaehlt in den Gruenden mit, sonst findet HR ihn ueber den Filter nicht. */
    public function testErZaehltAuchInDenGruendenMit(): void
    {
        $this->mitarbeiterFall();

        $zaehler = HrDeskCaseVisibility::reasonCounts(self::TEAM);

        $this->assertSame(1, $zaehler['all']);
        $this->assertSame(1, $zaehler[RecHrDeskCase::REASON_WORK_PERMIT]);
        $this->assertSame(0, $zaehler[RecHrDeskCase::REASON_MINOR], 'nur SEIN Grund, nicht alle');
    }

    /**
     * DIE WICHTIGSTE ZUSICHERUNG DIESER ENTSCHEIDUNG, und sie geht in BEIDE
     * Richtungen: der neue Zweig darf an den Bewerber-Faellen NICHTS
     * aendern.
     *
     *  - was sichtbar war, bleibt sichtbar,
     *  - was ausgeblendet war, bleibt ausgeblendet (hier: der Bewerber, der
     *    schon Mitarbeiter ist — das ist der Ausschluss, der am ehesten
     *    mitfallen koennte, weil der neue Zweig gerade Mitarbeiter
     *    hereinlaesst),
     *  - und die Zahl der Bewerber-Zaehler bleibt dieselbe.
     *
     * Ohne diese Probe liesse sich `whereHas('applicant', ...)` bedingungslos
     * machen (oder `whereDoesntHave('employee')` streichen), ohne dass etwas
     * rot wuerde.
     */
    public function testBewerberFaelleAendernSichDurchDenMitarbeiterZweigNicht(): void
    {
        $sichtbar = $this->offenerFall(['is_parked' => true]);

        $versteckt = $this->offenerFall();
        $this->capsule->table('rec_employees')->insert([
            'rec_applicant_id' => $versteckt->rec_applicant_id,
        ]);

        $vorher = HrDeskCaseVisibility::reasonCounts(self::TEAM);

        $mitarbeiterFall = $this->mitarbeiterFall();

        $this->assertSame(
            [$mitarbeiterFall->id, $sichtbar->id],
            HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all(),
            'Der versteckte Bewerber-Fall darf durch den neuen Zweig nicht hereinrutschen.',
        );

        $nachher = HrDeskCaseVisibility::reasonCounts(self::TEAM);

        $this->assertSame(
            $vorher[RecHrDeskCase::REASON_TRAINING_CLARIFICATION],
            $nachher[RecHrDeskCase::REASON_TRAINING_CLARIFICATION],
            'Die Bewerber-Zaehler duerfen sich nicht veraendert haben.',
        );
        $this->assertSame(1, $vorher[RecHrDeskCase::REASON_TRAINING_CLARIFICATION]);
    }

    /**
     * B1/B2 (Pruefung Runde 1, schwerster Fund) — DIE DISJUNKTHEIT SELBST.
     *
     * Die ganze Begruendung der Entscheidung ruht auf einem Satz: der
     * Mitarbeiter-Zweig kann an Bewerber-Faellen nichts aendern, weil
     * `rec_applicant_id IS NULL` jeden Bewerber-Fall ausschliesst. Diese
     * Bedingung war ungemessen — sie liess sich an BEIDEN Stellen
     * (constrainEmployeeCase und employeeCases) ersatzlos streichen, ohne
     * dass ein Test fiel, weil kein Test je einen Fall mit BEIDEN Kennungen
     * baute.
     *
     * Hier ist er: ein Bewerber, der schon Mitarbeiter ist (also durch
     * `whereDoesntHave('employee')` verdeckt), dessen Fall ZUSAETZLICH die
     * Mitarbeiter-Kennung traegt. Heute entsteht so ein Fall nicht — der
     * Einsatz-Trigger setzt `rec_applicant_id` immer auf null. Der naechste
     * "HR-Fall fuer den Bewerber, der schon Mitarbeiter ist" erzeugt ihn,
     * und dann macht das Streichen der scheinbar redundanten Zeile
     * versteckte Bewerber-Faelle sichtbar.
     */
    public function testEinFallMitBeidenKennungenBleibtVerstecktWieJederBewerberFall(): void
    {
        $fall = $this->fallMitBeidenKennungen();

        $this->assertSame(
            [],
            HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all(),
            'Der Bewerber ist schon Mitarbeiter und damit verdeckt — die zweite Kennung '
            .'am Fall darf ihn nicht durch die Hintertuer hereinlassen.',
        );

        // Vorflug: der Fall ist wirklich da und offen, der Test prueft also
        // nicht eine leere Tabelle.
        $this->assertSame(RecHrDeskCase::STATUS_OPEN, $fall->refresh()->status);
        $this->assertNotNull($fall->rec_applicant_id);
        $this->assertNotNull($fall->rec_employee_id);
    }

    /** Dieselbe Bedingung an der zweiten Stelle: in den Zaehlern. */
    public function testEinFallMitBeidenKennungenZaehltAuchNichtMit(): void
    {
        $this->fallMitBeidenKennungen();

        $zaehler = HrDeskCaseVisibility::reasonCounts(self::TEAM);

        $this->assertSame(0, $zaehler['all']);
        $this->assertSame(0, $zaehler[RecHrDeskCase::REASON_WORK_PERMIT]);
        $this->assertSame(0, $zaehler[RecHrDeskCase::REASON_TRAINING_CLARIFICATION]);
    }

    /**
     * Die Gegenprobe zum neuen Zweig selbst: ein Fall OHNE Bewerber UND OHNE
     * Mitarbeiter bleibt draussen. Er gehoert zu niemandem, und ohne diese
     * Probe liesse sich der Zweig auf ein blosses `whereNull('rec_applicant_id')`
     * verkuerzen — dann stuenden alle verwaisten Altfaelle auf dem
     * Schreibtisch.
     */
    public function testEinFallOhneBewerberUndOhneMitarbeiterBleibtDraussen(): void
    {
        $this->mitarbeiterFall(['rec_employee_id' => null]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
        $this->assertSame(0, HrDeskCaseVisibility::reasonCounts(self::TEAM)['all']);
    }

    /** Geschlossen heisst geschlossen — auch beim Mitarbeiter-Fall. */
    public function testEinGeschlossenerMitarbeiterFallErscheintNicht(): void
    {
        $this->mitarbeiterFall(['status' => RecHrDeskCase::STATUS_APPROVED]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    /** Und das fremde Team bleibt fremd. */
    public function testEinMitarbeiterFallEinesFremdenTeamsErscheintNicht(): void
    {
        $this->mitarbeiterFall(['team_id' => self::TEAM + 1]);

        $this->assertSame([], HrDeskCaseVisibility::openCases(self::TEAM)->pluck('id')->all());
    }

    // -----------------------------------------------------------------

    /**
     * Ein Fall mit BEIDEN Kennungen, dessen Bewerber auf dem Schreibtisch
     * nichts zu suchen hat (er ist schon Mitarbeiter). Genau die Kombination,
     * an der die Disjunktheit haengt.
     */
    private function fallMitBeidenKennungen(): RecHrDeskCase
    {
        $applicant = $this->applicant();

        // Dieser Mitarbeiter-Datensatz ist es, der den Bewerber verdeckt
        // (constrainApplicant: whereDoesntHave('employee')).
        $employeeId = $this->capsule->table('rec_employees')->insertGetId([
            'rec_applicant_id' => $applicant->id,
        ]);

        $case = new RecHrDeskCase();
        $case->forceFill([
            'uuid'             => 'uuid-'.uniqid('', true),
            'rec_applicant_id' => $applicant->id,
            'rec_employee_id'  => $employeeId,
            'team_id'          => self::TEAM,
            'reason'           => RecHrDeskCase::REASON_WORK_PERMIT,
            'status'           => RecHrDeskCase::STATUS_OPEN,
            'opened_at'        => '2026-10-01 09:00:00',
        ]);
        $case->save();

        return $case;
    }

    /** Ein offener Fall am MITARBEITER, ohne jeden Bewerber (Einsatz-Trigger). */
    private function mitarbeiterFall(array $caseAttributes = []): RecHrDeskCase
    {
        $employeeId = $this->capsule->table('rec_employees')->insertGetId([
            'rec_applicant_id' => null,
        ]);

        $case = new RecHrDeskCase();
        $case->forceFill(array_merge([
            'uuid'             => 'uuid-'.uniqid('', true),
            'rec_applicant_id' => null,
            'rec_employee_id'  => $employeeId,
            'team_id'          => self::TEAM,
            'reason'           => RecHrDeskCase::REASON_WORK_PERMIT,
            'status'           => RecHrDeskCase::STATUS_OPEN,
            'opened_at'        => '2026-10-01 09:00:00',
        ], $caseAttributes));
        $case->save();

        return $case;
    }

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
