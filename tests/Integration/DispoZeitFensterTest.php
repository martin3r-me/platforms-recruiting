<?php

namespace Platform\Recruiting\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zas\Dispo\DispoAccess;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;
use Platform\Recruiting\Zeit\ZeitEintrag;
use Platform\Recruiting\Zeit\ZeitVerlauf;

/**
 * Fenster „Zeit“ in der Veranstaltungs-Seite (Spec 2026-11-16 §4): die echte
 * Show-Komponente ohne Livewire-Mount (Muster DispoInfoImSendefensterTest),
 * Zeitdatensaetze echt in SQLite. Einsatz 01.10.2026 14:00–22:00 Berliner Zeit.
 */
class DispoZeitFensterTest extends ZeitTestCase
{
    private object $schalter;
    private object $auth;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // addError() braucht View-Schicht und gemeinsamen DataStore (Muster DispoDressSendFormTest).
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
        Carbon::setTestNow('2026-10-01 11:00:00');
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

    public function test_oeffnen_fuellt_formular_in_ortszeit(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:37', 'bemerkung' => 'Stau']);

        $c = $this->offen($a);

        $this->assertTrue($c->showZeitModal);
        $this->assertSame((int) $a->id, $c->zeitAssignmentId);
        $this->assertSame('2026-10-01T14:00', $c->zeitKommen);
        $this->assertSame('', $c->zeitGehen);
        $this->assertSame('', $c->zeitPause);
        $this->assertSame('Stau', $c->zeitBemerkung);
        $this->assertSame('14:00–22:00', $c->zeitFenster['soll']);
        $c->closeZeit();
        $this->assertFalse($c->showZeitModal);
        $this->assertNull($c->zeitFenster);
    }

    public function test_speichern_ortszeit_wird_utc_und_schreibt_verlauf_mit_nutzer(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $c->zeitKommen = '2026-10-01T13:55';
        $c->zeitGehen = '2026-10-01T22:30';
        $c->zeitGrund = 'Nachtrag';

        $c->zeitSpeichern();

        $e = $this->gespeichert($a);
        $this->assertSame('2026-10-01 11:55:00', $e->kommen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 20:30:00', $e->gehen_at->format('Y-m-d H:i:s'));
        $v = ZeitVerlauf::query()->orderBy('id')->get();
        $this->assertCount(2, $v);
        $this->assertSame(['user', 7, 'Sebastian Test', 'korrektur', 'Nachtrag'], [$v[0]->akteur_typ, (int) $v[0]->akteur_id, $v[0]->akteur_name, $v[0]->art, $v[0]->grund]);
        $this->assertSame('', $c->zeitGrund, 'Grund wird nach dem Speichern geleert');
        $this->assertSame('2026-10-01T22:30', $c->zeitGehen, 'Formular aus dem Datensatz neu gefuellt');
    }

    public function test_speichern_ohne_grund_meldet_und_schreibt_nichts(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $c->zeitKommen = '2026-10-01T13:55';
        $c->zeitGrund = '   ';

        $c->zeitSpeichern();

        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertSame(0, ZeitVerlauf::query()->count());
        $this->assertSame('Bitte Grund angeben und Zeiten prüfen (Gehen nicht vor Kommen).', $c->zeitMeldung);
    }

    public function test_ungueltige_eingabe_speichert_nichts_und_meldet_am_feld(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $c->zeitKommen = 'gestern abend';
        $c->zeitGrund = 'x';

        $c->zeitSpeichern();

        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertTrue($c->getErrorBag()->has('zeitKommen'));

        $c->resetErrorBag();
        $c->zeitKommen = '2026-10-01T13:55';
        $c->zeitPause = 'abc';
        $c->zeitSpeichern();
        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertTrue($c->getErrorBag()->has('zeitPause'));
    }

    public function test_gehen_vor_kommen_wird_abgelehnt(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $c->zeitKommen = '2026-10-01T15:00';
        $c->zeitGehen = '2026-10-01T14:00';
        $c->zeitGrund = 'x';

        $c->zeitSpeichern();

        $this->assertSame(0, ZeitEintrag::query()->count());
        $this->assertStringContainsString('Gehen nicht vor Kommen', (string) $c->zeitMeldung);
    }

    public function test_unveraenderte_zeiten_werden_nicht_neu_geschrieben(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:37']);
        $c = $this->offen($a);
        $c->zeitBemerkung = 'Stau';
        $c->zeitGrund = 'Notiz';

        $c->zeitSpeichern();

        $this->assertSame('2026-10-01 12:00:37', $this->gespeichert($a)->kommen_at->format('Y-m-d H:i:s'), 'Sekunden bleiben');
        $this->assertSame(['bemerkung'], ZeitVerlauf::query()->pluck('feld')->all());
    }

    public function test_nichts_geaendert_meldet(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $c = $this->offen($a);
        $c->zeitGrund = 'x';

        $c->zeitSpeichern();

        $this->assertSame('Nichts geändert.', $c->zeitMeldung);
    }

    public function test_pause_wird_als_zahl_gespeichert_leer_heisst_nach_regel(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00', 'pause_minuten' => 15]);
        $c = $this->offen($a);
        $this->assertSame('15', $c->zeitPause);
        $c->zeitPause = '';
        $c->zeitGrund = 'zurueck auf Regel';

        $c->zeitSpeichern();

        $this->assertNull($this->gespeichert($a)->pause_minuten);
    }

    public function test_jetzt_kommen_und_gehen_stellvertretend_und_cache_wird_verworfen(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $this->assertSame('offen', $c->zeitFenster['zustand']);
        $this->assertSame([], $c->zeitEintraege);

        $c->zeitJetztKommen();

        $this->assertSame('eingecheckt', $c->zeitFenster['zustand']);
        $this->assertSame('eingecheckt', $c->zeitEintraege[(int) $a->id]->zustand());
        $this->assertNotSame('', $c->zeitKommen, 'Formular gefuellt');
        Carbon::setTestNow('2026-10-01 18:00:00');
        $c->zeitJetztGehen();
        $this->assertSame('ausgecheckt', $c->zeitEintraege[(int) $a->id]->zustand());
        $this->assertSame(['stellvertretend', 'stellvertretend'], ZeitVerlauf::query()->pluck('art')->all());
        $c->zeitJetztGehen();
        $this->assertSame('Schon ausgecheckt.', $c->zeitMeldung);
    }

    public function test_gesperrter_datensatz_meldet_beim_speichern(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00', 'gesperrt_at' => '2026-10-01 21:00:00']);
        $c = $this->offen($a);
        $this->assertTrue($c->zeitFenster['gesperrt']);
        $c->zeitBemerkung = 'neu';
        $c->zeitGrund = 'x';

        $c->zeitSpeichern();

        $this->assertSame('Der Eintrag ist erledigt und gesperrt.', $c->zeitMeldung);
        $this->assertNull($this->gespeichert($a)->bemerkung);
    }

    public function test_einbuchung_einer_anderen_veranstaltung_wird_ignoriert(): void
    {
        $meine = $this->einbuchung();
        $fremde = $this->einbuchung(null, ['rec_employee_id' => 902]);
        $c = $this->dispoComponent((int) $meine->rec_dispo_event_id);

        $c->openZeit((int) $fremde->id);
        $this->assertFalse($c->showZeitModal);
        $this->assertNull($c->zeitAssignmentId);

        // Manipulierte Property: Aktionen laden die Zeile neu und finden sie nicht.
        $c->zeitAssignmentId = (int) $fremde->id;
        $c->zeitKommen = '2026-10-01T13:55';
        $c->zeitGrund = 'x';
        $c->zeitSpeichern();
        $c->zeitJetztKommen();
        $c->zeitJetztGehen();

        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_zeile_ohne_person_oeffnet_nicht(): void
    {
        $a = $this->einbuchung(null, ['rec_employee_id' => null]);
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);

        $c->openZeit((int) $a->id);
        $c->zeitAssignmentId = (int) $a->id;
        $c->zeitJetztKommen();

        $this->assertFalse($c->showZeitModal);
        $this->assertSame(0, ZeitEintrag::query()->count());
    }

    public function test_schalter_aus_verweigert_alles(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->schalter->an = false;
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);

        $this->assertFalse($c->zeitAktiv);
        $this->assertSame([], $c->zeitEintraege);
        $c->openZeit((int) $a->id);
        $this->assertFalse($c->showZeitModal);
        $c->zeitAssignmentId = (int) $a->id;
        $c->zeitKommen = '2026-10-01T13:00';
        $c->zeitBemerkung = 'x';
        $c->zeitGrund = 'x';
        $c->zeitSpeichern();
        $c->zeitJetztGehen();
        $c->zeitJetztKommen();

        $this->assertSame(0, ZeitVerlauf::query()->count());
        $this->assertNull($this->gespeichert($a)->bemerkung);
        $this->assertNull($c->zeitFenster);
    }

    public function test_nur_veranstaltungen_konto_sieht_aber_aendert_nichts(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->eventOnlyListe(['sebastian@test.de']);
        Carbon::setTestNow('2026-10-01 18:00:00'); // nach dem Kommen: ohne Riegel waere Gehen gueltig
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);

        $this->assertTrue($c->zeitAktiv);
        $this->assertCount(1, $c->zeitEintraege, 'die Spalte ist sichtbar');
        $c->openZeit((int) $a->id);
        $this->assertTrue($c->showZeitModal, 'das Fenster oeffnet lesend');

        $c->zeitBemerkung = 'x';
        $c->zeitGrund = 'x';
        $c->zeitSpeichern();
        $c->zeitJetztGehen();
        $c->zeitJetztKommen();

        $this->assertSame(0, ZeitVerlauf::query()->count());
        $this->assertNull($this->gespeichert($a)->gehen_at);
        $this->assertNull($this->gespeichert($a)->bemerkung);

        $b = $this->einbuchung(null, ['rec_employee_id' => 905]);
        $c2 = $this->dispoComponent((int) $b->rec_dispo_event_id);
        $c2->openZeit((int) $b->id);
        $c2->zeitJetztKommen();
        $this->assertNull($this->gespeichert($b), 'auch Jetzt kommen verweigert');
    }

    public function test_alle_schreibenden_aktionen_pruefen_den_riegel_im_quelltext(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');
        foreach (['zeitSpeichern', 'zeitJetztKommen', 'zeitJetztGehen'] as $methode) {
            $this->assertMatchesRegularExpression(
                '/function ' . $methode . '\(\): void\s*\{\s*if \(!\$this->zeitAktiv \|\| \$this->blockedForEventOnly\(\)\)/',
                $quelle,
                "{$methode} muss mit dem Riegel beginnen"
            );
        }
    }

    public function test_toggle_late_unberuehrt(): void
    {
        $wurzel = dirname(__DIR__, 2);
        $basis = @shell_exec('git -C ' . escapeshellarg($wurzel) . ' show origin/main:src/Livewire/Dispo/Events/Show.php 2>/dev/null');
        if (!is_string($basis) || $basis === '') {
            $this->markTestSkipped('origin/main nicht lesbar');
        }
        $methode = fn (string $q) => preg_match('/    public function toggleLate\(.*?\n    }\n/s', $q, $m) ? $m[0] : null;

        $jetzt = $methode(file_get_contents($wurzel . '/src/Livewire/Dispo/Events/Show.php'));
        $this->assertNotNull($jetzt);
        $this->assertSame($methode($basis), $jetzt);
    }
}
