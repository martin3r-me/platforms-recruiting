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
            $t->timestamp('updated_at')->nullable();
        });
        Capsule::table('rec_applicants')->insert([
            ['id' => 1, 'team_id' => 3, 'updated_at' => '2026-10-01 00:00:00'],
            ['id' => 2, 'team_id' => 9, 'updated_at' => '2026-10-01 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Capsule::schema()->drop('rec_applicants');
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

    private function bewerber(int $id, ?string $beginn, ?string $ende, array $vertraege = []): RecApplicant
    {
        $a = new RecApplicant();
        $a->forceFill(['id' => $id, 'vertragsbeginn_geplant' => $beginn, 'vertragsende_geplant' => $ende]);
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

        $this->assertSame($alt, GeplanteVertragsdaten::uebernehmen($alt, [$this->bewerber(7, null, null), null]));
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
        $a->setRawAttributes(['id' => 7, 'vertragsbeginn_geplant' => '2026-10-08 00:00:00', 'vertragsende_geplant' => null]);

        $this->assertSame('2026-10-08', GeplanteVertragsdaten::uebernehmen([], [$a])[7]['vertragsbeginn']);
    }
}
