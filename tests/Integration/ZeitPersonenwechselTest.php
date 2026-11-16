<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoPersonenwechsel;
use Platform\Recruiting\Services\Zeit\DispoZeiten;
use Platform\Recruiting\Services\Zeit\PortalStempeln;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;

class ZeitWechselLog
{
    /** @var list<array{string, array}> */
    public array $warnungen = [];

    public function warning(string $m, array $c = []): void
    {
        $this->warnungen[] = [$m, $c];
    }

    /** @var list<array{string, array}> */
    public array $infos = [];

    public function error(string $m, array $c = []): void {}

    public function info(string $m, array $c = []): void
    {
        $this->infos[] = [$m, $c];
    }
}

/** Identitaets-Attrappe: Person 901 (RG) und 950 (MA) sind eine Gruppe, wenn $gruppe gesetzt ist. */
class ZeitWechselIdentitaet extends DispoIdentityResolver
{
    /** @param list<int> $gruppe */
    public function __construct(private array $gruppe = []) {}

    public function groupFor(int $employeeId): array
    {
        return in_array($employeeId, $this->gruppe, true) ? $this->gruppe : [$employeeId];
    }
}

/**
 * Spec 2026-11-16 §2.2 „Personenwechsel“ und §7.5: Wechselt in ZAS die Person
 * an einer Einbuchung, zieht der Zeitdatensatz der ALTEN Person in die
 * Ablage-Zeile (~alt) um und bleibt bei ihr.
 */
class ZeitPersonenwechselTest extends ZeitTestCase
{
    private object $schalter;
    private string $standardZone;
    private ZeitWechselLog $log;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $schema = Capsule::schema();
        if (!$schema->hasTable('rec_employees')) {
            $schema->create('rec_employees', function ($t) {
                $t->increments('id');
                $t->unsignedBigInteger('team_id')->nullable();
                $t->string('uuid', 64)->nullable();
                $t->string('portal_token', 64)->nullable();
                $t->boolean('is_test')->default(false);
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamp('portal_locked_at')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
        } elseif (!$schema->hasColumn('rec_employees', 'portal_locked_at')) {
            $schema->table('rec_employees', fn ($t) => $t->timestamp('portal_locked_at')->nullable());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('rec_employees')->delete();
        $this->standardZone = date_default_timezone_get();
        $this->schalter = new class extends ZeitSchalter {
            public function aktiv(): bool
            {
                return true;
            }

            public function teamId(): int
            {
                return 1101;
            }
        };
        $this->log = new ZeitWechselLog();
        $container = Container::getInstance();
        $container->instance('log', $this->log);
        Facade::clearResolvedInstance('log');
        Carbon::setTestNow(Carbon::parse('2026-10-01 13:30', 'Europe/Berlin'));
    }

    protected function tearDown(): void
    {
        $container = Container::getInstance();
        $container->forgetInstance('log');
        $container->forgetInstance(Stempeluhr::class);
        Facade::clearResolvedInstance('log');
        date_default_timezone_set($this->standardZone);
        parent::tearDown();
    }

    private function ma(int $id): RecEmployee
    {
        return RecEmployee::create(['id' => $id, 'team_id' => self::TEAM, 'first_name' => 'Erika', 'last_name' => 'Muster', 'is_active' => true]);
    }

    private function einsatz(int $person = 901): RecDispoAssignment
    {
        return $this->assignment($this->event(['name' => 'Gala Nacht']), [
            'rec_employee_id' => $person, 'von' => '14:00', 'bis' => '22:00',
            'confirmed_at' => '2026-09-30 10:00:00', 'reminder_sent_at' => '2026-09-29 10:00:00',
        ]);
    }

    private function eintrag(RecDispoAssignment $a, int $person = 901, array $felder = []): ZeitEintrag
    {
        return ZeitEintrag::create(array_merge([
            'team_id' => self::TEAM, 'person_typ' => 'rec_employee', 'person_id' => $person,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $a->id, 'tag' => '2026-10-01',
            'soll_von_at' => '2026-10-01 12:00:00', 'soll_bis_at' => '2026-10-01 20:00:00',
            'kommen_at' => '2026-10-01 11:30:00',
        ], $felder));
    }

    /** Wie der Import: alte Besetzung ablegen, Live-Zeile gehoert danach der neuen Person. */
    private function wechseln(RecDispoAssignment $live, int $neuePerson): RecDispoAssignment
    {
        (new DispoPersonenwechsel())->archivieren((string) $live->ds_ref, 'RG999');
        $live->update(['rec_employee_id' => $neuePerson, 'confirmed_at' => null]);

        return RecDispoAssignment::query()->where('ds_ref', $live->ds_ref . '~alt1')->firstOrFail();
    }

    private function portal(array $gruppe = []): PortalStempeln
    {
        return new PortalStempeln(new Stempeluhr($this->regeln()), $this->schalter, new ZeitWechselIdentitaet($gruppe), $this->regeln());
    }

    private function webapp(): DispoZeiten
    {
        return new DispoZeiten(new Stempeluhr($this->regeln()), $this->schalter, $this->regeln());
    }

    public function test_zeit_zieht_in_die_ablage_zeile(): void
    {
        $x = $this->einsatz();
        $e = $this->eintrag($x);

        $ablage = $this->wechseln($x, 950);

        $this->assertSame((int) $ablage->id, (int) $e->fresh()->bezug_id);
        $this->assertSame(901, (int) $e->fresh()->person_id);
        $this->assertSame(0, ZeitEintrag::query()->where('bezug_id', $x->id)->count());
    }

    public function test_gesperrter_datensatz_zieht_ebenfalls_um(): void
    {
        $x = $this->einsatz();
        $e = $this->eintrag($x, 901, ['gehen_at' => '2026-10-01 20:00:00', 'gesperrt_at' => '2026-10-01 21:00:00', 'gesperrt_grund' => 'erledigt']);

        $ablage = $this->wechseln($x, 950);

        $e = $e->fresh();
        $this->assertSame((int) $ablage->id, (int) $e->bezug_id);
        $this->assertTrue($e->istGesperrt());
        $this->assertSame(0, \Platform\Recruiting\Zeit\ZeitVerlauf::query()->count(), 'kein Zeitwert geaendert, kein Verlauf');
    }

    public function test_alte_person_kann_nach_dem_wechsel_gehen(): void
    {
        $alt = $this->ma(901);
        $neu = $this->ma(950);
        $x = $this->einsatz();
        $this->eintrag($x);
        $ablage = $this->wechseln($x, 950);
        $ablage->update(['missing_since' => now()]);
        $x->update(['confirmed_at' => '2026-09-30 11:00:00']);

        $zeilen = $this->portal()->zeilen($alt, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame((int) $ablage->id, $zeilen[0]['assignment_id']);
        $this->assertTrue($zeilen[0]['kann_gehen']);
        $this->assertSame('ok', $this->portal()->gehen($alt, (int) $ablage->id, Carbon::now()));

        // Die neue Person sieht X frisch und stempelt eigenstaendig.
        $zeilen = $this->portal()->zeilen($neu, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame((int) $x->id, $zeilen[0]['assignment_id']);
        $this->assertSame('offen', $zeilen[0]['zustand']);
        $this->assertSame('ok', $this->portal()->kommen($neu, (int) $x->id, Carbon::now()));
        $this->assertSame(2, ZeitEintrag::query()->count());
    }

    public function test_webapp_findet_beide_und_korrigiert_in_der_ablage(): void
    {
        $x = $this->einsatz();
        $this->eintrag($x);
        $ablage = $this->wechseln($x, 950);
        $ablage->update(['missing_since' => now()]);
        $this->eintrag($x->fresh(), 950, ['kommen_at' => '2026-10-01 11:45:00']);

        $karte = $this->webapp()->fuerEvent((int) $x->rec_dispo_event_id);
        $this->assertSame(950, (int) $karte[(int) $x->id]->person_id);
        $this->assertSame(901, (int) $karte[(int) $ablage->id]->person_id);
        $this->assertSame('eingecheckt 13:30', $this->webapp()->zelle($karte[(int) $ablage->id])['text']);
        $this->assertSame('eingecheckt 13:45', $this->webapp()->zelle($karte[(int) $x->id])['text']);

        $d = $this->webapp();
        $this->assertSame('13:30', substr($d->fenster($ablage->fresh())['kommen'], 11, 5));
        $this->assertSame('ok', $d->korrigieren($ablage->fresh(), $this->akteur(), ['gehen_at' => '2026-10-01 20:00:00'], 'Teamleiter bestaetigt'));
        $this->assertSame('ok', $d->erledigt($ablage->fresh(), $this->akteur()));
        $this->assertTrue(ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', (int) $ablage->id)->istGesperrt());
        $this->assertFalse(ZeitEintrag::finde('rec_employee', 950, 'rec_dispo_assignment', (int) $x->id)->istGesperrt());
    }

    public function test_fehler_beim_umhaengen_bricht_den_wechsel_nicht_ab(): void
    {
        $x = $this->einsatz();
        $e = $this->eintrag($x);
        Container::getInstance()->instance(Stempeluhr::class, new class($this->regeln()) extends Stempeluhr {
            public function umhaengen(string $bezugTyp, int $vonBezugId, int $nachBezugId, string $personTyp, int $personId): void
            {
                throw new \RuntimeException('Datenbank weg');
            }
        });

        $ablage = $this->wechseln($x, 950);

        $this->assertSame(1, RecDispoAssignment::query()->where('ds_ref', $x->ds_ref . '~alt1')->count(), 'Ablage steht trotzdem');
        $this->assertSame((int) $x->id, (int) $e->fresh()->bezug_id, 'Datensatz bleibt, wo er war');
        $this->assertCount(1, $this->log->warnungen);
        $this->assertSame('zeit_umhaengen_fehlgeschlagen', $this->log->warnungen[0][0]);
        $this->assertSame((int) $ablage->id, $this->log->warnungen[0][1]['ablage_id']);
    }

    public function test_ohne_zeit_tabelle_laeuft_der_wechsel_weiter(): void
    {
        $x = $this->einsatz();
        Capsule::schema()->rename('rec_zeit_eintraege', 'rec_zeit_eintraege_weg');
        try {
            $ablage = $this->wechseln($x, 950);
        } finally {
            Capsule::schema()->rename('rec_zeit_eintraege_weg', 'rec_zeit_eintraege');
        }

        $this->assertSame($x->ds_ref . '~alt1', $ablage->ds_ref);
        $this->assertSame([], $this->log->warnungen);
    }

    public function test_gleiche_person_andere_anstellung_verwaist_den_stempel_nicht(): void
    {
        $rg = $this->ma(901);
        $ma = $this->ma(950);
        $x = $this->einsatz(901);
        $this->eintrag($x, 901);
        // Der Import fuehrt die Zeile weiter (dieselbe Person) und setzt nur die Anstellung um.
        $x->update(['rec_employee_id' => 950]);

        $zeilen = $this->portal([901, 950])->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen, 'keine zweite Zeile fuer denselben Einsatz');
        $this->assertSame('eingecheckt', $zeilen[0]['zustand']);
        $this->assertFalse($zeilen[0]['kann_kommen']);
        $this->assertTrue($zeilen[0]['kann_gehen']);
        $this->assertSame('schon', $this->portal([901, 950])->kommen($ma, (int) $x->id, Carbon::now()));
        $this->assertSame('ok', $this->portal([901, 950])->gehen($ma, (int) $x->id, Carbon::now()));
        $this->assertNotNull(ZeitEintrag::finde('rec_employee', 901, 'rec_dispo_assignment', (int) $x->id)->gehen_at);
        $this->assertSame(1, ZeitEintrag::query()->count());
        unset($rg);
    }

    // ---- Schluss-Review: dieselbe Person, andere Anstellung (W1/W3), K2, K6, M5 ----

    /** Wie der Import bei „dieselbe Person, andere Anstellung“: Zeile umstellen, Zeit ueber die Import-Methode umschreiben. */
    private function anstellungWechseln(RecDispoAssignment $x, int $von, int $nach): string
    {
        $x->update(['rec_employee_id' => $nach]);

        return (new DispoPersonenwechsel())->zeitPersonUmschreiben((int) $x->id, $von, $nach);
    }

    public function test_anstellungswechsel_ein_datensatz_webapp_und_portal_sehen_den_stempel(): void
    {
        $this->ma(901);
        $ma = $this->ma(950);
        $x = $this->einsatz(901);
        $this->eintrag($x, 901);

        $this->assertSame('ok', $this->anstellungWechseln($x, 901, 950));

        $this->assertSame(1, ZeitEintrag::query()->count());
        $this->assertSame(950, (int) ZeitEintrag::query()->first()->person_id);

        // Webapp: eingecheckt, „Jetzt kommen“ legt keinen zweiten Datensatz an.
        $karte = $this->webapp()->fuerEvent((int) $x->rec_dispo_event_id);
        $this->assertSame('eingecheckt 13:30', $this->webapp()->zelle($karte[(int) $x->id] ?? null)['text']);
        $this->assertSame('schon', $this->webapp()->jetztKommen($x->fresh(), $this->akteur()));
        $this->assertSame(1, ZeitEintrag::query()->count());

        // Portal: Gehen geht, weiter ein Datensatz.
        $this->assertSame('ok', $this->portal([901, 950])->gehen($ma, (int) $x->id, Carbon::now()));
        $e = ZeitEintrag::query()->first();
        $this->assertSame(1, ZeitEintrag::query()->count());
        $this->assertSame(950, (int) $e->person_id);
        $this->assertNotNull($e->gehen_at);
    }

    public function test_anstellungswechsel_erzeugt_keinen_vermerk_und_einen_lesbaren_verlauf(): void
    {
        $this->ma(901);
        $ma = $this->ma(950);
        $x = $this->einsatz(901);
        $this->eintrag($x, 901);
        $this->anstellungWechseln($x, 901, 950);

        $zeiten = $this->portal([901, 950])->meineZeiten($ma, Carbon::now());
        $this->assertCount(1, $zeiten);
        $this->assertNull($zeiten[0]['vermerk'], 'ein Umzug ist keine Korrektur und kein stellvertretender Stempel');

        $verlauf = $this->webapp()->fenster($x->fresh())['verlauf'];
        $this->assertCount(1, $verlauf);
        $this->assertSame('Anstellung gewechselt (ZAS)', $verlauf[0]['text']);
        $this->assertSame('ZAS-Import', $verlauf[0]['wer']);
        $this->assertSame('Import', $verlauf[0]['art']);
        $this->assertNull($verlauf[0]['alt'], 'keine rohen Datensatz-IDs');
        $this->assertNull($verlauf[0]['neu']);
        $this->assertStringNotContainsString('901', json_encode($verlauf));
        $this->assertStringNotContainsString('950', json_encode($verlauf));
    }

    public function test_verlauf_anderer_zeilen_hat_keinen_fertigen_text(): void
    {
        $x = $this->einsatz(901);
        $this->assertSame('ok', $this->webapp()->jetztKommen($x, $this->akteur()));

        $verlauf = $this->webapp()->fenster($x->fresh())['verlauf'];
        $this->assertNull($verlauf[0]['text']);
        $this->assertNotNull($verlauf[0]['neu']);
    }

    public function test_anstellungswechsel_mit_vorhandenem_ziel_bleibt_unveraendert_und_wird_protokolliert(): void
    {
        $x = $this->einsatz(901);
        $alt = $this->eintrag($x, 901);
        $ziel = $this->eintrag($x, 950, ['kommen_at' => '2026-10-01 11:45:00']);

        $this->assertSame('ziel_existiert', $this->anstellungWechseln($x, 901, 950));

        $this->assertSame(901, (int) $alt->fresh()->person_id);
        $this->assertSame('2026-10-01 11:45:00', $ziel->fresh()->kommen_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, \Platform\Recruiting\Zeit\ZeitVerlauf::query()->count());
        $this->assertSame([['zeit_person_umschreiben_ziel_existiert', ['assignment_id' => (int) $x->id, 'von_person_id' => 901, 'nach_person_id' => 950]]], $this->log->infos);
        $this->assertSame([], $this->log->warnungen);
    }

    public function test_anstellungswechsel_ohne_datensatz_ist_still(): void
    {
        $x = $this->einsatz(901);

        $this->assertSame('kein_datensatz', $this->anstellungWechseln($x, 901, 950));
        $this->assertSame([], $this->log->infos);
        $this->assertSame([], $this->log->warnungen);
    }

    public function test_nebenlaeufigkeitsfehler_beim_umhaengen_wird_nicht_verschluckt(): void
    {
        $x = $this->einsatz();
        $this->eintrag($x);
        Container::getInstance()->instance(Stempeluhr::class, new class($this->regeln()) extends Stempeluhr {
            public function umhaengen(string $bezugTyp, int $vonBezugId, int $nachBezugId, string $personTyp, int $personId): void
            {
                throw new \Illuminate\Database\DeadlockException('Deadlock');
            }
        });

        try {
            $this->wechseln($x, 950);
            $this->fail('Deadlock haette durchschlagen muessen');
        } catch (\Illuminate\Database\DeadlockException) {
        }
        $this->assertSame([], $this->log->warnungen);
    }

    public function test_nebenlaeufigkeitsfehler_beim_umschreiben_wird_nicht_verschluckt_andere_schon(): void
    {
        $x = $this->einsatz();
        $this->eintrag($x);
        $wirft = function (\Throwable $fehler): void {
            Container::getInstance()->instance(Stempeluhr::class, new class($this->regeln(), $fehler) extends Stempeluhr {
                public function __construct(\Platform\Recruiting\Zeit\ZeitRegeln $r, private \Throwable $fehler)
                {
                    parent::__construct($r);
                }

                public function personUmschreiben(string $bezugTyp, int $bezugId, string $personTyp, int $vonPersonId, int $nachPersonId, \Platform\Recruiting\Zeit\ZeitAkteur $akteur): string
                {
                    throw $this->fehler;
                }
            });
        };

        $wirft(new \RuntimeException('Datenbank weg'));
        $this->assertSame('fehler', $this->anstellungWechseln($x, 901, 950));
        $this->assertCount(1, $this->log->warnungen);
        $this->assertSame('zeit_person_umschreiben_fehlgeschlagen', $this->log->warnungen[0][0]);
        $this->assertSame(['assignment_id' => (int) $x->id, 'von_person_id' => 901, 'nach_person_id' => 950, 'fehler' => 'Datenbank weg'], $this->log->warnungen[0][1]);

        $wirft(new \RuntimeException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
        try {
            $this->anstellungWechseln($x, 901, 950);
            $this->fail('Lock-Timeout haette durchschlagen muessen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        }
        $this->assertCount(1, $this->log->warnungen, 'keine zweite Warnung');
    }

    /** M5: Der Schwester-Fallback greift nur innerhalb der Identitaetsgruppe. */
    public function test_fremder_datensatz_an_derselben_einbuchung_greift_nicht_ueber_den_schwester_fallback(): void
    {
        $this->ma(901);
        $ma = $this->ma(950);
        $x = $this->einsatz(950);
        $fremd = $this->eintrag($x, 777); // Rest einer Person ausserhalb der Gruppe

        $zeilen = $this->portal([901, 950])->zeilen($ma, Carbon::now());
        $this->assertCount(1, $zeilen);
        $this->assertSame('offen', $zeilen[0]['zustand'], 'der fremde Stempel zaehlt nicht als eigener');
        $this->assertNull($zeilen[0]['kommen']);
        $this->assertTrue($zeilen[0]['kann_kommen']);
        $this->assertFalse($zeilen[0]['kann_gehen']);

        $this->assertSame('kein_kommen', $this->portal([901, 950])->gehen($ma, (int) $x->id, Carbon::now()));
        $this->assertNull($fremd->fresh()->gehen_at, 'fremder Datensatz bleibt unberuehrt');
        $this->assertSame(777, (int) $fremd->fresh()->person_id);
    }

    /** K6: Im Verlauf steht der Angemeldete, nicht die alte Anstellung. */
    public function test_schwester_stempel_nennt_den_angemeldeten_als_akteur(): void
    {
        $this->ma(901);
        $ma = $this->ma(950);
        $x = $this->einsatz(901);
        $this->eintrag($x, 901);
        $x->update(['rec_employee_id' => 950]); // ohne Umschreiben: das Netz im Portal traegt

        $this->assertSame('ok', $this->portal([901, 950])->gehen($ma, (int) $x->id, Carbon::now()));

        $v = \Platform\Recruiting\Zeit\ZeitVerlauf::query()->where('feld', 'gehen_at')->firstOrFail();
        $this->assertSame(['rec_employee', 950], [$v->akteur_typ, (int) $v->akteur_id]);
    }
}
