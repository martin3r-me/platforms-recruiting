<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;
use Platform\Recruiting\Services\Zeit\DispoZeiten;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\Stempeluhr;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

/**
 * „Erledigt“, Sammel-Aktion und „Wieder oeffnen“ (Spec 2026-11-16 §4) — Dienst und
 * echte Show-Komponente (ohne Livewire-Mount), Zeitdatensaetze echt in SQLite.
 * Die Entsperr-Liste liest der ECHTE ZeitSchalter aus rec_applicant_settings.
 */
class DispoZeitErledigtTest extends ZeitTestCase
{
    private object $schalter;
    private object $auth;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!Capsule::schema()->hasTable('rec_employees')) {
            Capsule::schema()->create('rec_employees', function ($t) {
                $t->id();
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
        }
        if (!Capsule::schema()->hasTable('rec_applicant_settings')) {
            (require dirname(__DIR__, 2) . '/database/migrations/2026_02_09_000008_create_rec_applicant_settings_table.php')->up();
        }
        Container::getInstance()->instance('view', new class {
            public function getShared(): array
            {
                return [];
            }
        });
        Container::getInstance()->singleton(\Livewire\Mechanisms\DataStore::class);
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('view');
        Container::getInstance()->forgetInstance(\Livewire\Mechanisms\DataStore::class);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Capsule::table('rec_employees')->delete();
        Capsule::table('rec_applicant_settings')->delete();
        Carbon::setTestNow('2026-10-01 18:30:00');
        $this->schalter = new class extends ZeitSchalter {
            public bool $an = true;

            public function aktiv(): bool
            {
                return $this->an;
            }

            public function teamId(): int
            {
                return 1101;
            }
        };
        Container::getInstance()->instance(ZeitSchalter::class, $this->schalter);
        $this->auth = new class implements \Illuminate\Contracts\Auth\Factory {
            public object $nutzer;

            public function __construct()
            {
                $this->nutzer = (object) ['id' => 7, 'name' => 'Sebastian Test', 'email' => 'sebastian@test.de'];
            }

            public function guard($name = null)
            {
                return $this;
            }

            public function shouldUse($name)
            {
            }

            public function user(): object
            {
                return $this->nutzer;
            }

            public function id(): int
            {
                return $this->nutzer->id;
            }
        };
        Container::getInstance()->instance('auth', $this->auth);
        Container::getInstance()->instance(\Illuminate\Contracts\Auth\Factory::class, $this->auth);
        $this->eventOnlyListe([]);
    }

    protected function tearDown(): void
    {
        $this->eventOnlyListe([]);
        $c = Container::getInstance();
        $c->forgetInstance(ZeitSchalter::class);
        unset($c[ZeitSchalter::class]);
        $c->forgetInstance('auth');
        $c->forgetInstance(\Illuminate\Contracts\Auth\Factory::class);
        unset($c['auth']);
        parent::tearDown();
    }

    /** @param list<string> $emails */
    private function eventOnlyListe(array $emails): void
    {
        DispoAccess::flush();
        if ($emails !== []) {
            $ref = new \ReflectionProperty(DispoAccess::class, 'memo');
            $ref->setAccessible(true);
            $ref->setValue(null, [DispoAccess::SETTING_EVENT_ONLY => $emails]);
        }
    }

    /** @param mixed $liste Wert von zeit_entsperren_emails */
    private function entsperrListe(mixed $liste): void
    {
        Capsule::table('rec_applicant_settings')->delete();
        Capsule::table('rec_applicant_settings')->insert([
            'team_id' => self::TEAM,
            'settings' => json_encode([ZeitSchalter::SETTING_ENTSPERREN => $liste]),
        ]);
    }

    private function dienst(): DispoZeiten
    {
        return new DispoZeiten(new Stempeluhr($this->regeln()), $this->schalter, $this->regeln());
    }

    private function einbuchung(?object $event = null, array $attrs = []): RecDispoAssignment
    {
        return $this->assignment($event ?? $this->event(), array_merge(['rec_employee_id' => 901, 'von' => '14:00', 'bis' => '22:00'], $attrs));
    }

    private function eintrag(RecDispoAssignment $a, array $felder = []): ZeitEintrag
    {
        return ZeitEintrag::create(array_merge([
            'team_id' => self::TEAM, 'person_typ' => 'rec_employee', 'person_id' => (int) $a->rec_employee_id,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $a->id, 'tag' => '2026-10-01',
            'soll_von_at' => '2026-10-01 12:00:00', 'soll_bis_at' => '2026-10-01 20:00:00',
        ], $felder));
    }

    private function aus(RecDispoAssignment $a, array $felder = []): ZeitEintrag
    {
        return $this->eintrag($a, array_merge(['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00'], $felder));
    }

    private function gespeichert(RecDispoAssignment $a): ?ZeitEintrag
    {
        return ZeitEintrag::finde('rec_employee', (int) $a->rec_employee_id, 'rec_dispo_assignment', (int) $a->id);
    }

    private function offen(RecDispoAssignment $a): \Platform\Recruiting\Livewire\Dispo\Events\Show
    {
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);
        $c->openZeit((int) $a->id);

        return $c;
    }

    // ---- Dienst ----

    public function test_erledigt_sperrt_auch_die_disposition(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $d = $this->dienst();

        $this->assertSame('ok', $d->erledigt($a, $this->akteur()));

        $this->assertSame('erledigt', $this->gespeichert($a)->zustand());
        $this->assertSame('gesperrt', $d->korrigieren($a, $this->akteur(), ['bemerkung' => 'x'], 'g'));
        $this->assertSame('gesperrt', $d->jetztGehen($a, $this->akteur()));
        $this->assertSame('gesperrt', $d->jetztKommen($a, $this->akteur()));
        $uhr = new Stempeluhr($this->regeln());
        $bezug = \Platform\Recruiting\Services\Zeit\DispoZeitBezug::fuer($a, self::TEAM);
        $this->assertSame('gesperrt', $uhr->kommen($bezug, $this->akteur('employee', 901, 'MA'), Stempeluhr::ART_SELBST));
        $this->assertSame('gesperrt', $uhr->gehen($bezug, $this->akteur('employee', 901, 'MA'), Stempeluhr::ART_SELBST));
        $v = ZeitVerlauf::query()->where('art', 'sperre')->get();
        $this->assertCount(1, $v);
        $this->assertSame('erledigt', $v[0]->grund);
        $this->assertTrue($d->zelle($this->gespeichert($a))['gesperrt']);
    }

    public function test_erledigt_nur_wenn_ausgecheckt(): void
    {
        $d = $this->dienst();
        $ohne = $this->einbuchung();
        $this->assertSame('nicht_ausgecheckt', $d->erledigt($ohne, $this->akteur()), 'ohne Datensatz');

        $ein = $this->einbuchung(null, ['rec_employee_id' => 902]);
        $this->eintrag($ein, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->assertSame('nicht_ausgecheckt', $d->erledigt($ein, $this->akteur()), 'nur eingecheckt');

        $leer = $this->einbuchung(null, ['rec_employee_id' => 903]);
        $this->eintrag($leer);
        $this->assertSame('nicht_ausgecheckt', $d->erledigt($leer, $this->akteur()), 'geleerter Datensatz');

        $this->assertSame(0, ZeitEintrag::query()->whereNotNull('gesperrt_at')->count());
        $this->assertSame(0, ZeitVerlauf::query()->where('art', 'sperre')->count());
    }

    public function test_erledigt_zweimal_ist_schon_aus_und_ohne_person(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $d = $this->dienst();
        $this->assertSame('ok', $d->erledigt($a, $this->akteur()));
        $this->assertSame('schon', $d->erledigt($a, $this->akteur()));
        $this->assertSame(1, ZeitVerlauf::query()->where('art', 'sperre')->count());

        $ohne = $this->einbuchung(null, ['rec_employee_id' => null]);
        $this->assertSame('ohne_person', $d->erledigt($ohne, $this->akteur()));

        $b = $this->einbuchung(null, ['rec_employee_id' => 905]);
        $this->aus($b);
        $this->schalter->an = false;
        $this->assertSame('aus', $d->erledigt($b, $this->akteur()));
        $this->assertNull($this->gespeichert($b)->gesperrt_at);
    }

    public function test_sammel_aktion_trifft_nur_ausgecheckte(): void
    {
        $event = $this->event();
        $x = $this->einbuchung($event, ['rec_employee_id' => 911]);
        $y = $this->einbuchung($event, ['rec_employee_id' => 912]);
        $z = $this->einbuchung($event, ['rec_employee_id' => 913]);
        $w = $this->einbuchung($event, ['rec_employee_id' => 914]);
        $this->aus($x);
        $this->eintrag($y, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->aus($z, ['gesperrt_at' => '2026-10-01 20:30:00']);

        $n = $this->dienst()->alleErledigt([$x, $y, $z, $w], $this->akteur());

        $this->assertSame(1, $n);
        $this->assertSame('erledigt', $this->gespeichert($x)->zustand());
        $this->assertSame('eingecheckt', $this->gespeichert($y)->zustand());
        $this->assertNull($this->gespeichert($w));
        $this->assertSame(1, ZeitVerlauf::query()->where('art', 'sperre')->count(), 'schon Erledigtes bekommt keine zweite Zeile');
    }

    /** K1: „nur ausgecheckt“ entscheidet die Sperr-Transaktion des Kerns, nicht die Vorab-Pruefung des Dienstes. */
    public function test_erledigt_reicht_die_ausgecheckt_bedingung_und_das_ergebnis_des_kerns_durch(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $uhr = new class($this->regeln()) extends Stempeluhr {
            /** @var list<bool> */
            public array $nurAusgecheckt = [];

            public function sperren(ZeitEintrag $e, \Platform\Recruiting\Zeit\ZeitAkteur $a, string $grund, bool $nurAusgecheckt = false): string
            {
                $this->nurAusgecheckt[] = $nurAusgecheckt;

                // Rennen: zwischen Vorab-Pruefung und Sperre wurde das Gehen geleert.
                return 'nicht_ausgecheckt';
            }
        };
        $d = new DispoZeiten($uhr, $this->schalter, $this->regeln());

        $this->assertSame('nicht_ausgecheckt', $d->erledigt($a, $this->akteur()));
        $this->assertSame(0, $d->alleErledigt([$a], $this->akteur()), 'zaehlt nur, was der Kern wirklich gesperrt hat');
        $this->assertSame([true, true], $uhr->nurAusgecheckt);
    }

    /** K4: Zeilen ohne ausgecheckten Datensatz kosten in der Sammel-Aktion keine Abfrage. */
    public function test_sammel_aktion_fragt_nicht_je_zeile(): void
    {
        $abfragen = function (int $ohne): int {
            foreach (['rec_zeit_eintraege', 'rec_zeit_verlauf', 'rec_dispo_assignments'] as $t) {
                Capsule::table($t)->delete();
            }
            $event = $this->event();
            $zeilen = [$this->einbuchung($event, ['rec_employee_id' => 911])];
            $this->aus($zeilen[0]);
            for ($i = 0; $i < $ohne; $i++) {
                $zeilen[] = $this->einbuchung($event, ['rec_employee_id' => 920 + $i]);
            }
            $offen = $this->einbuchung($event, ['rec_employee_id' => 990]);
            $this->eintrag($offen, ['kommen_at' => '2026-10-01 12:00:00']);
            $zeilen[] = $offen;

            $db = Capsule::connection();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $n = $this->dienst()->alleErledigt($zeilen, $this->akteur());
            $db->disableQueryLog();
            $this->assertSame(1, $n);

            return count($db->getQueryLog());
        };

        $wenig = $abfragen(1);
        $viel = $abfragen(12);
        $this->assertSame($wenig, $viel, 'Abfragen wachsen nicht mit der Zeilenzahl');
    }

    public function test_sammel_aktion_bei_schalter_aus_sperrt_nichts(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $this->schalter->an = false;

        $this->assertSame(0, $this->dienst()->alleErledigt([$a], $this->akteur()));
        $this->assertNull($this->gespeichert($a)->gesperrt_at);
    }

    public function test_sammel_aktion_laesst_den_rest_einer_anderen_person_an_der_zeile_in_ruhe(): void
    {
        $a = $this->einbuchung(null, ['rec_employee_id' => 911]);
        $rest = ZeitEintrag::create([
            'team_id' => self::TEAM, 'person_typ' => 'rec_employee', 'person_id' => 777,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $a->id, 'tag' => '2026-10-01',
            'kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00',
        ]);

        $this->assertSame(0, $this->dienst()->alleErledigt([$a], $this->akteur()));
        $this->assertNull($rest->fresh()->gesperrt_at);
    }

    /** K7: die Schnellknoepfe verwerfen keine ungespeicherten Eingaben. */
    public function test_jetzt_kommen_und_gehen_verweigern_bei_ungespeicherten_eingaben(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $c->zeitBemerkung = 'noch nicht gespeichert';

        $c->zeitJetztKommen();

        $this->assertNull($this->gespeichert($a), 'nichts gestempelt');
        $this->assertSame('Bitte erst speichern oder die Eingaben verwerfen.', $c->zeitMeldung);
        $this->assertSame('noch nicht gespeichert', $c->zeitBemerkung, 'Eingabe bleibt stehen');

        // Verworfen: dann geht es.
        $c->zeitBemerkung = '';
        $c->zeitJetztKommen();
        $this->assertNotNull($this->gespeichert($a)->kommen_at);

        $c->zeitPause = '20';
        $c->zeitJetztGehen();
        $this->assertNull($this->gespeichert($a)->gehen_at, 'auch Gehen stempelt nicht ueber ungespeicherte Eingaben');
        $this->assertSame('Bitte erst speichern oder die Eingaben verwerfen.', $c->zeitMeldung);
        $this->assertSame('20', $c->zeitPause);

        $c->zeitPause = '';
        $c->zeitJetztGehen();
        $this->assertNotNull($this->gespeichert($a)->gehen_at);
    }

    /** Haertung: die Veranstaltung der Seite ist vom Browser nicht umstellbar. */
    public function test_event_id_der_seite_ist_gesperrt(): void
    {
        $attribute = (new \ReflectionProperty(\Platform\Recruiting\Livewire\Dispo\Events\Show::class, 'eventId'))->getAttributes(\Livewire\Attributes\Locked::class);
        $this->assertCount(1, $attribute);
    }

    public function test_oeffnen_nur_mit_listen_eintrag(): void
    {
        $a = $this->einbuchung();
        $this->aus($a, ['gesperrt_at' => '2026-10-01 20:30:00', 'gesperrt_grund' => 'erledigt']);
        $d = $this->dienst();
        $nutzer = $this->auth->nutzer;

        // Der Eventzugang darf nie — auch bei leerer Liste und auch, wenn er selbst auf der Liste steht.
        $this->eventOnlyListe(['sebastian@test.de']);
        $this->assertSame('nicht_berechtigt', $d->oeffnen($a, $this->akteur(), 'Irrtum', $nutzer));
        $this->entsperrListe(['sebastian@test.de']);
        $this->assertSame('nicht_berechtigt', $d->oeffnen($a, $this->akteur(), 'Irrtum', $nutzer));
        $this->eventOnlyListe([]);
        // Gefuellte Liste schraenkt ein; Teilstring zaehlt nicht
        $this->entsperrListe(['sebastian@test.de.evil.com', 'xsebastian@test.de']);
        $this->assertSame('nicht_berechtigt', $d->oeffnen($a, $this->akteur(), 'Irrtum', $nutzer));
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);

        $this->entsperrListe(['Sebastian@Test.de ']);
        $this->assertSame('ungueltig', $d->oeffnen($a, $this->akteur(), '   ', $nutzer), 'ohne Grund');
        $this->assertSame('ungueltig', $d->oeffnen($a, $this->akteur(), str_repeat('x', 256), $nutzer), 'zu lang');
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);

        $this->assertSame('ok', $d->oeffnen($a, $this->akteur(), 'Irrtum bei Teamleiter', $nutzer));
        $this->assertNull($this->gespeichert($a)->gesperrt_at);
        $v = ZeitVerlauf::query()->where('art', 'sperre')->orderBy('id')->get();
        $this->assertCount(1, $v);
        $this->assertSame('Irrtum bei Teamleiter', $v[0]->grund);
        $this->assertNull($v[0]->neu);

        $this->assertSame('nicht_gesperrt', $d->oeffnen($a, $this->akteur(), 'nochmal', $nutzer));
        // danach wieder aenderbar
        $this->assertSame('ok', $d->korrigieren($a, $this->akteur(), ['bemerkung' => 'neu'], 'Nachtrag'));
    }

    public function test_oeffnen_bei_leerer_liste_darf_jedes_konto_mit_eigener_anmeldung(): void
    {
        $d = $this->dienst();
        $nutzer = $this->auth->nutzer;

        // ohne Setting-Zeile
        $a = $this->einbuchung();
        $this->aus($a, ['gesperrt_at' => '2026-10-01 20:30:00', 'gesperrt_grund' => 'erledigt']);
        Capsule::table('rec_applicant_settings')->delete();
        $this->assertSame('ok', $d->oeffnen($a, $this->akteur(), 'Nacharbeit', $nutzer));
        $this->assertNull($this->gespeichert($a)->gesperrt_at);

        // mit leerer Liste
        ZeitEintrag::query()->update(['gesperrt_at' => '2026-10-01 20:30:00', 'gesperrt_grund' => 'erledigt']);
        $this->entsperrListe([]);
        $this->assertSame('ok', $d->oeffnen($a, $this->akteur(), 'Nacharbeit', $nutzer));
        $this->assertNull($this->gespeichert($a)->gesperrt_at);
    }

    public function test_oeffnen_bei_schalter_aus_und_ohne_person(): void
    {
        $a = $this->einbuchung();
        $this->aus($a, ['gesperrt_at' => '2026-10-01 20:30:00']);
        $this->entsperrListe(['sebastian@test.de']);
        $d = $this->dienst();
        $ohne = $this->einbuchung(null, ['rec_employee_id' => null]);
        $this->assertSame('ohne_person', $d->oeffnen($ohne, $this->akteur(), 'x', $this->auth->nutzer));
        $this->schalter->an = false;
        $this->assertSame('aus', $d->oeffnen($a, $this->akteur(), 'x', $this->auth->nutzer));
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);
    }

    // ---- Komponente ----

    public function test_fenster_erledigt_sperrt_und_danach_ist_alles_verweigert(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $c = $this->offen($a);

        $c->zeitErledigt();

        $this->assertSame('erledigt', $this->gespeichert($a)->zustand());
        $this->assertSame('erledigt', $c->zeitFenster['zustand']);
        $this->assertTrue($c->zeitFenster['gesperrt']);
        $this->assertSame('erledigt', $c->zeitEintraege[(int) $a->id]->zustand());
        $this->assertSame(['sperre'], ZeitVerlauf::query()->pluck('art')->all());

        $c->zeitBemerkung = 'neu';
        $c->zeitGrund = 'x';
        $c->zeitSpeichern();
        $this->assertSame('Der Eintrag ist erledigt und gesperrt.', $c->zeitMeldung);
        $c->zeitJetztGehen();
        $c->zeitJetztKommen();
        $this->assertNull($this->gespeichert($a)->bemerkung);
        $this->assertSame(1, ZeitVerlauf::query()->count());
    }

    public function test_fenster_erledigt_nur_wenn_ausgecheckt(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $c = $this->offen($a);

        $c->zeitErledigt();

        $this->assertSame('eingecheckt', $this->gespeichert($a)->zustand());
        $this->assertSame('Erledigt geht nur bei ausgecheckten Einträgen.', $c->zeitMeldung);
    }

    public function test_fenster_erledigt_schalter_aus_und_eventzugang_ohne_namen_verweigern(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->offen($a);
        $c->zeitErledigt();
        $c->zeitAlleErledigt();
        $this->assertSame(1, $c->zeitErledigbar, 'der Eventzugang sieht, was sich erledigen laesst');
        $this->assertNull($this->gespeichert($a)->gesperrt_at, 'ohne gewaehlten Namen wird nichts gesperrt');

        $this->eventOnlyListe([]);
        $c2 = $this->offen($a);
        $this->schalter->an = false;
        $c2->zeitAssignmentId = (int) $a->id;
        $c2->zeitErledigt();
        $c2->zeitAlleErledigt();
        $this->assertNull($this->gespeichert($a)->gesperrt_at);
        $this->assertSame(0, ZeitVerlauf::query()->count());
    }

    public function test_fenster_erledigt_konflikt_mit_schnappschuss_sperrt_nicht(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $c = $this->offen($a);
        // Zwischenzeitlich ändert jemand anderes den Datensatz.
        $this->dienst()->korrigieren($a, $this->akteur('user', 9, 'Anderer'), ['gehen_at' => Carbon::parse('2026-10-01 21:00:00', 'UTC')], 'spaeter');

        $c->zeitErledigt();

        $this->assertNull($this->gespeichert($a)->gesperrt_at);
        $this->assertSame('Der Eintrag wurde inzwischen geändert – bitte prüfen und erneut speichern.', $c->zeitMeldung);
        $this->assertSame('2026-10-01T23:00', $c->zeitGehen, 'Formular neu gefuellt');

        // Nach Neu-Lesen klappt es.
        $c->zeitErledigt();
        $this->assertSame('erledigt', $this->gespeichert($a)->zustand());
    }

    public function test_fenster_erledigt_speichert_ungespeicherte_eingaben_nicht(): void
    {
        $a = $this->einbuchung();
        $this->aus($a);
        $c = $this->offen($a);
        $c->zeitBemerkung = 'noch nicht gespeichert';

        $c->zeitErledigt();

        $this->assertNull($this->gespeichert($a)->gesperrt_at);
        $this->assertNull($this->gespeichert($a)->bemerkung);
        $this->assertSame('Bitte erst speichern, dann als erledigt markieren.', $c->zeitMeldung);

        $c->zeitBemerkung = '';
        $c->zeitPause = '15';
        $c->zeitErledigt();
        $this->assertNull($this->gespeichert($a)->gesperrt_at, 'auch eine geaenderte Pause');
        $this->assertSame('Bitte erst speichern, dann als erledigt markieren.', $c->zeitMeldung);
    }

    public function test_sammel_aktion_wirkt_nur_auf_gefilterte_ausgecheckte_ungesperrte_und_zaehlt_serverseitig(): void
    {
        $event = $this->event();
        $tag1 = $this->einbuchung($event, ['rec_employee_id' => 921, 'datum' => '2026-10-01']);
        $tag1b = $this->einbuchung($event, ['rec_employee_id' => 922, 'datum' => '2026-10-01']);
        $tag1c = $this->einbuchung($event, ['rec_employee_id' => 923, 'datum' => '2026-10-01']);
        $tag2 = $this->einbuchung($event, ['rec_employee_id' => 924, 'datum' => '2026-10-02']);
        $this->aus($tag1);
        $this->aus($tag1b, ['gesperrt_at' => '2026-10-01 20:30:00']);
        $this->eintrag($tag1c, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->aus($tag2);

        $c = $this->dispoComponent((int) $event->id);
        $c->rowDays = ['2026-10-01'];
        $this->assertSame(1, $c->zeitErledigbar);

        // Nach dem Anzeigen kommt serverseitig ein weiterer Ausgecheckter dazu: gezaehlt wird beim Bestaetigen neu.
        $this->dienst()->jetztGehen($tag1c, $this->akteur());
        $this->assertSame(1, $c->zeitErledigbar, 'Cache der Anzeige');

        $c->zeitAlleErledigt();

        $this->assertSame('erledigt', $this->gespeichert($tag1)->zustand());
        $this->assertSame('erledigt', $this->gespeichert($tag1c)->zustand(), 'neu ermittelt');
        $this->assertNull($this->gespeichert($tag2)->gesperrt_at, 'anderer Tag gefiltert');
        $this->assertSame('2 Einträge als erledigt markiert.', $c->zeitHinweis);
        $this->assertSame(0, $c->zeitErledigbar);

        $c->zeitAlleErledigt();
        $this->assertSame('Keine ausgecheckten Einträge zu markieren.', $c->zeitHinweis);
    }

    public function test_wieder_oeffnen_nur_mit_listen_eintrag_und_grund(): void
    {
        $a = $this->einbuchung();
        $this->aus($a, ['gesperrt_at' => '2026-10-01 20:30:00', 'gesperrt_grund' => 'erledigt']);
        // Gefuellte Liste ohne den Nutzer: eingeschraenkt.
        $this->entsperrListe(['chef@test.de']);
        $c = $this->offen($a);
        $c->zeitOeffnenGrund = 'Irrtum';

        $this->assertFalse($c->zeitDarfOeffnen);
        $c->zeitOeffnen();
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at, 'serverseitig verweigert, nicht nur Knopf versteckt');
        $this->assertSame('Dazu fehlt die Berechtigung.', $c->zeitMeldung);

        $this->entsperrListe(['sebastian@test.de']);
        $c2 = $this->offen($a);
        $this->assertTrue($c2->zeitDarfOeffnen);
        $c2->zeitOeffnenGrund = '  ';
        $c2->zeitOeffnen();
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);
        $this->assertTrue($c2->getErrorBag()->has('zeitOeffnenGrund'));

        $c2->zeitOeffnenGrund = 'Irrtum beim Teamleiter';
        $c2->zeitOeffnen();
        $this->assertNull($this->gespeichert($a)->gesperrt_at);
        $this->assertSame('', $c2->zeitOeffnenGrund);
        $this->assertSame('ausgecheckt', $c2->zeitFenster['zustand']);
        $this->assertSame('Irrtum beim Teamleiter', ZeitVerlauf::query()->where('art', 'sperre')->orderByDesc('id')->first()->grund);

        // wieder aenderbar
        $c2->zeitBemerkung = 'korrigiert';
        $c2->zeitGrund = 'Nachtrag';
        $c2->zeitSpeichern();
        $this->assertSame('korrigiert', $this->gespeichert($a)->bemerkung);
    }

    public function test_nur_veranstaltungen_konto_darf_nie_oeffnen_auch_nicht_mit_listen_eintrag(): void
    {
        $a = $this->einbuchung();
        $this->aus($a, ['gesperrt_at' => '2026-10-01 20:30:00']);
        $this->entsperrListe(['sebastian@test.de']);
        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->offen($a);
        $c->zeitOeffnenGrund = 'x';

        $this->assertFalse($c->zeitDarfOeffnen);
        $c->zeitOeffnen();

        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);
        $this->assertSame(0, ZeitVerlauf::query()->count());
    }

    public function test_wieder_oeffnen_bei_schalter_aus_verweigert(): void
    {
        $a = $this->einbuchung();
        $this->aus($a, ['gesperrt_at' => '2026-10-01 20:30:00']);
        $this->entsperrListe(['sebastian@test.de']);
        $c = $this->offen($a);
        $c->zeitOeffnenGrund = 'x';
        $this->schalter->an = false;
        $c->zeitOeffnen();
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);
    }

    public function test_wieder_oeffnen_prueft_den_vollen_riegel_im_quelltext(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');
        $this->assertMatchesRegularExpression(
            '/function zeitOeffnen\(\): void\s*\{\s*if \(!\$this->zeitAktiv \|\| \$this->blockedForEventOnly\(\)\)/',
            $quelle,
            'zeitOeffnen muss mit dem Riegel beginnen'
        );
    }

    public function test_blade_zeigt_schloss_in_beiden_ansichten_ueber_das_gemeinsame_partial(): void
    {
        $show = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/show.blade.php');
        $this->assertSame(2, substr_count($show, "_stempel-zelle'"), 'Tabelle und Karte nutzen dasselbe Partial');
        $partial = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/_stempel-zelle.blade.php');
        $this->assertStringContainsString("\$zelle['gesperrt']", $partial);
    }
}
