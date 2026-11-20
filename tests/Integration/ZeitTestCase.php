<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Zeit\ZeitAkteur;
use Platform\Recruiting\Zeit\ZeitBezug;
use Platform\Recruiting\Zeit\ZeitRegeln;

/**
 * Basis der Zeiterfassungs-Tests. Laedt zusaetzlich zu DressTestCase die zwei
 * Zeit-Migrationen und die Spalten von rec_dispo_assignments, die DressTestCase
 * nicht anlegt (SQLite-Spaltenluecke): individual_note, Notiz-Zeitstempel,
 * late_marker und die Neu-Bestaetigungs-Felder. confirmed_at, reminder_sent_at,
 * declined_at, missing_since, deletion_marked_at kommen schon ueber
 * DressTestCase (08_12_000002, 08_14_000001, 09_04_000001) — das wird je
 * Migration per Schema::hasColumn geprueft, nichts doppelt angelegt.
 */
abstract class ZeitTestCase extends DressTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $own = dirname(__DIR__, 2);
        $schema = Capsule::schema();

        // [Migration, Tabelle, Pruefspalte] — nur laden, wenn die Spalte fehlt.
        foreach ([
            ['2026_08_20_000002_add_individual_note_to_rec_dispo_assignments', 'rec_dispo_assignments', 'individual_note'],
            ['2026_09_03_000002_add_note_timestamp_to_rec_dispo_assignments', 'rec_dispo_assignments', 'individual_note_updated_at'],
            ['2026_09_08_000001_add_late_marker_to_rec_dispo_assignments', 'rec_dispo_assignments', 'late_marked_at'],
            ['2026_08_28_000002_add_reconfirm_fields_to_rec_dispo_assignments', 'rec_dispo_assignments', 'confirmed_datum'],
            ['2026_11_18_000001_add_zeit_nur_teamleiter_to_rec_dispo_events', 'rec_dispo_events', 'zeit_nur_teamleiter'],
            ['2026_11_16_000001_create_rec_zeit_eintraege_table', 'rec_zeit_eintraege', null],
            ['2026_11_16_000002_create_rec_zeit_verlauf_table', 'rec_zeit_verlauf', null],
            ['2026_11_20_000001_add_zas_exported_at_to_rec_zeit_eintraege', 'rec_zeit_eintraege', 'zas_exported_at'],
            ['2026_11_20_000002_create_rec_zeit_waesche_table', 'rec_zeit_waesche', null],
        ] as [$name, $tabelle, $spalte]) {
            $fehlt = $spalte === null ? !$schema->hasTable($tabelle) : !$schema->hasColumn($tabelle, $spalte);
            if ($fehlt) {
                $path = "{$own}/database/migrations/{$name}.php";
                if (!file_exists($path)) {
                    throw new \RuntimeException("Migration fehlt: {$path}");
                }
                (require $path)->up();
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['rec_zeit_eintraege', 'rec_zeit_verlauf', 'rec_zeit_waesche'] as $t) {
            Capsule::table($t)->delete();
        }

        // Damit app(Stempeluhr::class) auch ohne Service-Provider aufloest.
        Container::getInstance()->instance(ZeitRegeln::class, $this->regeln());
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        $container->forgetInstance(ZeitRegeln::class);
        unset($container[ZeitRegeln::class]);
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function bezug(int $personId = 901, int $bezugId = 1, string $von = '2026-10-01 14:00', ?string $bis = '2026-10-01 22:00'): ZeitBezug
    {
        return new ZeitBezug(
            'rec_employee',
            $personId,
            'rec_dispo_assignment',
            $bezugId,
            substr($von, 0, 10),
            CarbonImmutable::parse($von, 'Europe/Berlin'),
            $bis === null ? null : CarbonImmutable::parse($bis, 'Europe/Berlin'),
            self::TEAM,
        );
    }

    protected function regeln(): ZeitRegeln
    {
        return new class implements ZeitRegeln {
            public function fruehestesKommen(ZeitBezug $b): ?CarbonImmutable
            {
                return $b->sollVon?->subMinutes(60);
            }

            public function spaetestesKommen(ZeitBezug $b): ?CarbonImmutable
            {
                return null;
            }

            public function spaetestesStempeln(ZeitBezug $b): ?CarbonImmutable
            {
                return $b->sollBis?->addMinutes(60);
            }

            public function runde(\DateTimeInterface $zeit): CarbonImmutable
            {
                return CarbonImmutable::instance($zeit);
            }

            public function pauseMinuten(int $anwesendMinuten): int
            {
                return $anwesendMinuten > 540 ? 45 : ($anwesendMinuten > 360 ? 30 : 0);
            }
        };
    }

    protected function akteur(string $typ = 'user', int $id = 7, string $name = 'Dispo Test'): ZeitAkteur
    {
        return new ZeitAkteur($typ, $id, $name);
    }
}
