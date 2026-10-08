<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecContract;
use Platform\Recruiting\Support\GeplanteVertragsdaten;

/**
 * Vertragsdaten der Nachbereitung sofort speichern (08.10.2026, Feedback
 * MGL-Runde 07.10.: auf zwei Laptops gingen Eingaben verloren und mussten
 * am Ende neu gesetzt werden).
 */
final class GeplanteVertragsdatenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        $capsule = new Capsule($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher($container));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->schema()->create('rec_applicants', function ($t) {
            $t->increments('id');
            $t->integer('team_id');
            $t->date('vertragsbeginn_geplant')->nullable();
            $t->date('vertragsende_geplant')->nullable();
            $t->dateTime('vertragsdaten_geplant_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        $capsule->schema()->create('rec_interview_bookings', function ($t) {
            $t->increments('id');
            $t->integer('rec_interview_id');
            $t->integer('rec_applicant_id');
            $t->timestamp('deleted_at')->nullable();
        });
        $capsule->schema()->create('rec_contracts', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->string('status');
            $t->dateTime('sent_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        $capsule->schema()->create('rec_contract_send_reservations', function ($t) {
            $t->increments('id');
            $t->integer('rec_applicant_id');
            $t->date('vertragsbeginn')->nullable();
            $t->date('vertragsende')->nullable();
            $t->dateTime('claimed_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
        });
        Capsule::table('rec_applicants')->insert([
            ['id' => 1, 'team_id' => 3, 'updated_at' => '2026-10-01 00:00:00'],
            ['id' => 2, 'team_id' => 9, 'updated_at' => '2026-10-01 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['rec_applicants', 'rec_interview_bookings', 'rec_contracts', 'rec_contract_send_reservations'] as $t) {
            Capsule::schema()->drop($t);
        }
        Container::getInstance()->forgetInstance('db');
        Container::getInstance()->forgetInstance('db.schema');
        Model::unsetEventDispatcher();
        parent::tearDown();
    }

    private function zeile(int $id): object
    {
        return Capsule::table('rec_applicants')->find($id);
    }

    public function test_speichern_schreibt_beide_daten(): void
    {
        GeplanteVertragsdaten::speichern(3, 1, '2026-10-08', '2027-09-30');

        $this->assertSame('2026-10-08', $this->zeile(1)->vertragsbeginn_geplant);
        $this->assertSame('2027-09-30', $this->zeile(1)->vertragsende_geplant);
        $this->assertNotNull($this->zeile(1)->vertragsdaten_geplant_at);
    }

    public function test_speichern_fasst_fremdes_team_nicht_an(): void
    {
        GeplanteVertragsdaten::speichern(3, 2, '2026-10-08', '2027-09-30');

        $this->assertNull($this->zeile(2)->vertragsbeginn_geplant);
    }

    /** Kein Model-Event, also auch kein ZAS-Export-Marker und kein updated_at. */
    public function test_speichern_ohne_model_events(): void
    {
        $gefeuert = [];
        foreach (['saving', 'updating', 'saved', 'updated'] as $event) {
            RecApplicant::{$event}(function () use (&$gefeuert, $event) {
                $gefeuert[] = $event;
            });
        }

        GeplanteVertragsdaten::speichern(3, 1, '2026-10-08', null);

        $this->assertSame([], $gefeuert);
        $this->assertSame('2026-10-01 00:00:00', $this->zeile(1)->updated_at);
    }

    public function test_ungueltige_daten_werden_zu_leer(): void
    {
        GeplanteVertragsdaten::speichern(3, 1, '08.10.2026', '2027-02-30');

        $this->assertNull($this->zeile(1)->vertragsbeginn_geplant);
        $this->assertNull($this->zeile(1)->vertragsende_geplant);
        $this->assertSame('2026-10-08', GeplanteVertragsdaten::normalisieren(' 2026-10-08 '));
    }

    private function bewerber(int $id, ?string $beginn, ?string $ende, array $vertraege = [], bool $marker = true): RecApplicant
    {
        $a = new RecApplicant();
        $a->forceFill(['id' => $id, 'vertragsbeginn_geplant' => $beginn, 'vertragsende_geplant' => $ende, 'vertragsdaten_geplant_at' => $marker ? '2026-10-07 19:00:00' : null]);
        $a->setRelation('contracts', new Collection(array_map(
            fn (array $v) => (new RecContract())->forceFill($v),
            $vertraege,
        )));

        return $a;
    }

    /** Laptop B hatte noch den alten Stand im Fenster — die Datenbank gewinnt. */
    public function test_uebernehmen_gespeicherter_stand_gewinnt(): void
    {
        $alt = [7 => ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2027-09-30', 'anderes' => 'bleibt']];

        $neu = GeplanteVertragsdaten::uebernehmen($alt, [$this->bewerber(7, '2026-10-08', '2027-09-30')]);

        $this->assertSame('2026-10-08', $neu[7]['vertragsbeginn']);
        $this->assertSame('bleibt', $neu[7]['anderes']);
    }

    public function test_uebernehmen_ohne_planung_laesst_fenster_stand_stehen(): void
    {
        $alt = [7 => ['vertragsbeginn' => '2026-10-01', 'vertragsende' => null]];

        $this->assertSame($alt, GeplanteVertragsdaten::uebernehmen($alt, [$this->bewerber(7, null, null, [], false), null]));
    }

    /** Nach dem Versand ist der Vertrag die Wahrheit. */
    public function test_uebernehmen_ignoriert_bewerber_mit_versendetem_vertrag(): void
    {
        $versendet = $this->bewerber(7, '2026-10-08', null, [['status' => 'sent', 'sent_at' => '2026-10-07 20:00:00']]);
        $storniert = $this->bewerber(8, '2026-10-09', null, [['status' => 'cancelled', 'sent_at' => '2026-10-07 20:00:00']]);

        $neu = GeplanteVertragsdaten::uebernehmen([], [$versendet, $storniert]);

        $this->assertArrayNotHasKey(7, $neu);
        $this->assertSame('2026-10-09', $neu[8]['vertragsbeginn']);
    }

    public function test_uebernehmen_mit_datumsobjekt_aus_dem_cast(): void
    {
        $a = $this->bewerber(7, null, null);
        $a->setRawAttributes(['id' => 7, 'vertragsbeginn_geplant' => '2026-10-08 00:00:00', 'vertragsende_geplant' => null, 'vertragsdaten_geplant_at' => '2026-10-07 19:00:00']);

        $this->assertSame('2026-10-08', GeplanteVertragsdaten::uebernehmen([], [$a])[7]['vertragsbeginn']);
    }

    /** Laptop A leert beide Felder — Laptop B darf die alten Werte nicht behalten. */
    public function test_geleerte_felder_kommen_auf_dem_anderen_fenster_an(): void
    {
        $alt = [7 => ['vertragsbeginn' => '2026-10-01', 'vertragsende' => '2027-09-30']];

        $neu = GeplanteVertragsdaten::uebernehmen($alt, [$this->bewerber(7, null, null)]);

        $this->assertNull($neu[7]['vertragsbeginn']);
        $this->assertNull($neu[7]['vertragsende']);
    }

    public function test_fuer_termin_liefert_nur_geplante_bewerber_dieses_termins_mit_vertraegen(): void
    {
        Capsule::table('rec_applicants')->insert([
            ['id' => 3, 'team_id' => 3, 'vertragsbeginn_geplant' => '2026-10-08', 'vertragsdaten_geplant_at' => '2026-10-07 19:00:00'],
            ['id' => 4, 'team_id' => 3, 'vertragsbeginn_geplant' => null, 'vertragsdaten_geplant_at' => null],
            ['id' => 5, 'team_id' => 3, 'vertragsbeginn_geplant' => '2026-10-09', 'vertragsdaten_geplant_at' => '2026-10-07 19:00:00'],
        ]);
        Capsule::table('rec_interview_bookings')->insert([
            ['rec_interview_id' => 85, 'rec_applicant_id' => 3],
            ['rec_interview_id' => 85, 'rec_applicant_id' => 4],
            ['rec_interview_id' => 99, 'rec_applicant_id' => 5],
        ]);
        Capsule::table('rec_contracts')->insert(['rec_applicant_id' => 3, 'status' => 'sent', 'sent_at' => '2026-10-07 20:00:00']);

        $liste = GeplanteVertragsdaten::fuerTermin(85);

        $this->assertSame([3], $liste->pluck('id')->all());
        $this->assertTrue($liste->first()->relationLoaded('contracts'));
        $this->assertCount(1, $liste->first()->contracts);
    }

    public function test_vormerkung_wird_mitgezogen_nur_wenn_offen_und_unberuehrt(): void
    {
        Capsule::table('rec_contract_send_reservations')->insert([
            ['id' => 1, 'rec_applicant_id' => 1, 'vertragsbeginn' => '2026-10-01', 'claimed_at' => null, 'completed_at' => null],
            ['id' => 2, 'rec_applicant_id' => 1, 'vertragsbeginn' => '2026-10-01', 'claimed_at' => '2026-10-08 09:00:00', 'completed_at' => null],
            ['id' => 3, 'rec_applicant_id' => 1, 'vertragsbeginn' => '2026-10-01', 'claimed_at' => null, 'completed_at' => '2026-10-08 09:00:00'],
            ['id' => 4, 'rec_applicant_id' => 2, 'vertragsbeginn' => '2026-10-01', 'claimed_at' => null, 'completed_at' => null],
        ]);

        GeplanteVertragsdaten::vormerkungNachziehen(1, '2026-10-15', '2027-09-30');

        $beginn = Capsule::table('rec_contract_send_reservations')->orderBy('id')->pluck('vertragsbeginn')->all();
        $this->assertSame(['2026-10-15', '2026-10-01', '2026-10-01', '2026-10-01'], $beginn);
    }

    public function test_vormerkung_bleibt_ohne_vertragsbeginn_stehen(): void
    {
        Capsule::table('rec_contract_send_reservations')->insert(['id' => 1, 'rec_applicant_id' => 1, 'vertragsbeginn' => '2026-10-01']);

        GeplanteVertragsdaten::vormerkungNachziehen(1, null, '2027-09-30');

        $this->assertSame('2026-10-01', Capsule::table('rec_contract_send_reservations')->value('vertragsbeginn'));
    }
}
