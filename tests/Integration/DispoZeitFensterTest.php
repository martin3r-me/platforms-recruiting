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
        if (!\Illuminate\Database\Capsule\Manager::schema()->hasTable('rec_employees')) {
            \Illuminate\Database\Capsule\Manager::schema()->create('rec_employees', function ($t) {
                $t->id();
                $t->string('first_name')->nullable();
                $t->string('last_name')->nullable();
                $t->softDeletes();
                $t->timestamps();
            });
        }
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
        \Illuminate\Database\Capsule\Manager::table('rec_employees')->delete();
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
        $this->assertSame('Gehen liegt vor Kommen.', $c->zeitMeldung);
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

    public function test_eventzugang_ohne_gewaehlten_namen_aendert_nichts(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->eventOnlyListe(['sebastian@test.de']);
        Carbon::setTestNow('2026-10-01 18:00:00'); // nach dem Kommen: ohne Riegel waere Gehen gueltig
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);

        $this->assertTrue($c->zeitAktiv);
        $this->assertCount(1, $c->zeitEintraege, 'die Spalte ist sichtbar');
        $c->openZeit((int) $a->id);
        $this->assertTrue($c->showZeitModal);

        $c->zeitBemerkung = 'x';
        $c->zeitGrund = 'x';
        $c->zeitSpeichern();
        $this->assertSame(\Platform\Recruiting\Livewire\Dispo\Events\Show::ZEIT_NAME_FEHLT, $c->zeitMeldung);
        $this->assertTrue($c->getErrorBag()->has('zeitTeamleiter'));
        $c->zeitJetztGehen();
        $c->zeitJetztKommen();
        $c->zeitErledigt();
        // Nur Leerraum und Steuerzeichen sind kein Name.
        $c->zeitTeamleiter = " \t\u{200B} ";
        $c->zeitJetztGehen();

        $this->assertSame(0, ZeitVerlauf::query()->count());
        $this->assertNull($this->gespeichert($a)->gehen_at);
        $this->assertNull($this->gespeichert($a)->bemerkung);

        $b = $this->einbuchung(null, ['rec_employee_id' => 905]);
        $c2 = $this->dispoComponent((int) $b->rec_dispo_event_id);
        $c2->openZeit((int) $b->id);
        $c2->zeitJetztKommen();
        $this->assertNull($this->gespeichert($b), 'auch Jetzt kommen verweigert');
    }

    /**
     * Schreibende Aktionen: Schalter-Riegel zuerst; danach muss feststehen, WER bucht — der Eventzugang
     * ohne gewaehlten Namen (zeitAkteur() === null) kommt nicht bis zum Dienst.
     */
    public function test_alle_schreibenden_aktionen_pruefen_schalter_und_akteur_im_quelltext(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');
        foreach (['zeitSpeichern', 'zeitJetztKommen', 'zeitJetztGehen', 'zeitErledigt', 'zeitAlleErledigt'] as $methode) {
            $this->assertMatchesRegularExpression(
                '/function ' . $methode . '\(\): void\s*\{\s*if \(!\$this->zeitAktiv\) \{\s*return;/',
                $quelle,
                "{$methode} muss mit dem Schalter-Riegel beginnen"
            );
            $start = strpos($quelle, 'function ' . $methode . '(): void');
            $rumpf = substr($quelle, $start, strpos($quelle, "\n    }\n", $start) - $start);
            $wache = strpos($rumpf, 'if ($akteur === null) {');
            $this->assertNotFalse($wache, "{$methode} prueft den Akteur");
            foreach (['->korrigieren(', '->jetztKommen(', '->jetztGehen(', '->erledigt(', '->alleErledigt('] as $aufruf) {
                $stelle = strpos($rumpf, $aufruf);
                if ($stelle !== false) {
                    $this->assertGreaterThan($wache, $stelle, "{$methode}: Dienst-Aufruf erst nach der Akteur-Pruefung");
                }
            }
            $this->assertStringNotContainsString('$this->zeitAkteur())', $rumpf, "{$methode}: kein ungeprüfter Akteur im Aufruf");
        }
        // Nur mit eigener Anmeldung: Wieder oeffnen und der Haken an der Veranstaltung.
        foreach (['zeitOeffnen\(\)', 'zeitNurTeamleiterSetzen\(bool \$an\)'] as $methode) {
            $this->assertMatchesRegularExpression(
                '/function ' . $methode . ': void\s*\{\s*if \(!\$this->zeitAktiv \|\| \$this->blockedForEventOnly\(\)\)/',
                $quelle,
                "{$methode} muss mit dem vollen Riegel beginnen"
            );
        }
    }

    public function test_kommt_spaeter_auch_mit_dem_eventzugang(): void
    {
        // Markus 20.11.2026: der Teamleiter markiert vor Ort, wer sich gemeldet hat, dass er später kommt.
        $a = $this->einbuchung();
        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->offen($a);

        $c->toggleLate((int) $a->id);
        $this->assertNotNull(RecDispoAssignment::query()->find($a->id)->late_marked_at);

        $c->toggleLate((int) $a->id);
        $this->assertNull(RecDispoAssignment::query()->find($a->id)->late_marked_at);
    }

    public function test_fehlt_noch_zaehlt_wer_spaeter_kommt(): void
    {
        $event = $this->event();
        $spaet = $this->einbuchung($event, ['late_marked_at' => '2026-10-01 07:00:00']);
        $this->einbuchung($event, ['rec_employee_id' => 902]);
        $schonDa = $this->einbuchung($event, ['rec_employee_id' => 903, 'late_marked_at' => '2026-10-01 07:00:00']);
        $this->eintrag($schonDa, ['kommen_at' => '2026-10-01 06:30:00']);

        $c = $this->offen($spaet);
        $offen = collect($c->rowStempelOptions)->firstWhere('value', 'offen');
        $this->assertSame(2, $offen['count']);
        $this->assertSame('fehlt noch · davon 1 kommt später', $offen['label'], 'wer schon da ist, zählt nicht mehr');
    }

    public function test_waesche_im_fenster_ausgeben_rueckgabe_und_sperre(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);

        $c->waescheArtikel = 'weste';
        $c->waescheAnzahl = '2';
        $c->waescheAusgeben();
        $c->waescheArtikel = 'sonstiges';
        $c->waescheName = 'Hose';
        $c->waescheEuro = '19,90';
        $c->waescheAusgeben();
        $this->assertNull($c->zeitMeldung);
        $this->assertSame('', $c->waescheName, 'Eingabe nach dem Ausgeben geleert');

        $w = $c->zeitWaesche;
        $this->assertSame(['Weste', 'Hose'], array_column($w['posten'], 'name'));
        $this->assertSame('79,90', $w['abzug']);

        $c->waescheZurueck($w['posten'][0]['id'], '1'); // kommt als Text aus der Auswahl
        $this->assertSame('49,90', $c->zeitWaesche['abzug']);

        $c->waescheEuro = 'abc';
        $c->waescheAusgeben();
        $this->assertNotNull($c->zeitMeldung, 'falscher Preis wird gemeldet');
        $c->waescheEuro = '1000,01';
        $c->waescheAusgeben();
        $this->assertSame('Bitte einen Preis bis 1.000 € angeben, z. B. 19,90.', $c->zeitMeldung);
        $this->assertCount(2, $c->zeitWaesche['posten']);

        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00', 'gesperrt_at' => '2026-10-01 21:00:00', 'gesperrt_grund' => 'erledigt']);
        $c->waescheEntfernen($w['posten'][1]['id']);
        $this->assertSame('Die Zeit ist erledigt – die Wäsche ist damit gesperrt.', $c->zeitMeldung);
        $this->assertCount(2, $c->zeitWaesche['posten']);
    }

    public function test_waesche_am_eventzugang_nur_mit_teamleiter_namen(): void
    {
        $a = $this->einbuchung();
        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->offen($a);
        $c->zeitTeamleiter = '';
        $c->zeitTeamleiterFrei = true;

        $stand = $c->waescheStand;
        $c->waescheAusgeben();
        $this->assertSame(\Platform\Recruiting\Livewire\Dispo\Events\Show::ZEIT_NAME_FEHLT, $c->zeitMeldung);
        $this->assertGreaterThan($stand, $c->waescheStand, 'auch ein abgelehnter Versuch baut die Auswahl neu');
        $this->assertSame([], $c->zeitWaesche['posten']);

        $c->zeitTeamleiter = 'Anna Leiter';
        $c->waescheAusgeben();
        $this->assertCount(1, $c->zeitWaesche['posten']);
        $this->assertSame('Anna Leiter (Eventzugang)', \Platform\Recruiting\Models\RecZeitWaesche::query()->value('ausgegeben_von'));
    }

    // ---- Fix-Runde 1 ----

    public function test_portal_stempelt_gehen_waehrend_das_fenster_offen_ist(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $c = $this->offen($a);
        // Der Mitarbeiter stempelt im Portal „Gehen“.
        ZeitEintrag::query()->update(['gehen_at' => '2026-10-01 20:00:00']);
        $c->zeitBemerkung = 'Stau';
        $c->zeitGrund = 'Notiz';

        $c->zeitSpeichern();

        $e = $this->gespeichert($a);
        $this->assertSame('2026-10-01 20:00:00', $e->gehen_at->format('Y-m-d H:i:s'), 'Gehen bleibt');
        $this->assertNull($e->bemerkung, 'nichts geschrieben');
        $this->assertSame('Der Eintrag wurde inzwischen geändert – bitte prüfen und erneut speichern.', $c->zeitMeldung);
        $this->assertSame('Notiz', $c->zeitGrund, 'Grund bleibt stehen');
        $this->assertSame('2026-10-01T22:00', $c->zeitGehen, 'Formular neu gefuellt');
    }

    public function test_zwei_disponenten_der_zweite_ueberschreibt_nicht_still(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00']);
        $ersterA = $this->offen($a);
        $zweiterB = $this->offen($a);

        $ersterA->zeitGehen = '2026-10-01T22:30';
        $ersterA->zeitGrund = 'A';
        $ersterA->zeitSpeichern();
        $this->assertSame('2026-10-01 20:30:00', $this->gespeichert($a)->gehen_at->format('Y-m-d H:i:s'));

        $zweiterB->zeitBemerkung = 'Stau';
        $zweiterB->zeitGrund = 'B';
        $zweiterB->zeitSpeichern();

        $this->assertSame('2026-10-01 20:30:00', $this->gespeichert($a)->gehen_at->format('Y-m-d H:i:s'), 'A bleibt');
        $this->assertNull($this->gespeichert($a)->bemerkung);
        $this->assertStringContainsString('inzwischen geändert', (string) $zweiterB->zeitMeldung);

        // Nach dem Neu-Fuellen geht auch ein bewusster Schritt zurueck auf den alten Wert.
        $zweiterB->zeitGehen = '2026-10-01T22:00';
        $zweiterB->zeitSpeichern();
        $this->assertSame('2026-10-01 20:00:00', $this->gespeichert($a)->gehen_at->format('Y-m-d H:i:s'));
    }

    public function test_nur_bemerkung_geaendert_schreibt_nur_bemerkung(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:37', 'gehen_at' => '2026-10-01 20:00:00', 'pause_minuten' => 15]);
        $c = $this->offen($a);
        $c->zeitBemerkung = 'Stau';
        $c->zeitGrund = 'Notiz';

        $c->zeitSpeichern();

        $this->assertSame(['bemerkung'], ZeitVerlauf::query()->pluck('feld')->all());
        $this->assertSame(15, (int) $this->gespeichert($a)->pause_minuten);
    }

    public function test_personenwechsel_bei_offenem_fenster_schreibt_nichts_auf_die_neue_person(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $c = $this->offen($a);
        RecDispoAssignment::query()->whereKey($a->id)->update(['rec_employee_id' => 955]);
        $c->zeitGehen = '2026-10-01T22:30';
        $c->zeitGrund = 'x';

        $c->zeitSpeichern();

        $this->assertNull(ZeitEintrag::finde('rec_employee', 955, 'rec_dispo_assignment', (int) $a->id));
        $this->assertSame(1, ZeitEintrag::query()->count());
        $this->assertFalse($c->showZeitModal);
        $this->assertSame('Die Einbuchung hat sich geändert – bitte neu öffnen.', $c->zeitHinweis);
        $c->zeitAssignmentId = (int) $a->id;
        $c->zeitJetztKommen();
        $this->assertNull(ZeitEintrag::finde('rec_employee', 955, 'rec_dispo_assignment', (int) $a->id));
    }

    public function test_weggefallene_einbuchung_schliesst_das_fenster_beim_rendern(): void
    {
        foreach ([
            fn (RecDispoAssignment $a) => RecDispoAssignment::query()->whereKey($a->id)->delete(),
            fn (RecDispoAssignment $a) => RecDispoAssignment::query()->whereKey($a->id)->update(['rec_employee_id' => null]),
        ] as $weg) {
            $a = $this->einbuchung();
            $c = $this->offen($a);
            $this->callPrivate($c, 'zeitPruefeFenster');
            $this->assertTrue($c->showZeitModal, 'unveraendert: bleibt offen');

            $weg($a);
            $this->callPrivate($c, 'zeitPruefeFenster');

            $this->assertFalse($c->showZeitModal, 'sonst bleibt der Poll dauerhaft aus');
            $this->assertSame('Die Einbuchung hat sich geändert – bitte neu öffnen.', $c->zeitHinweis);
        }
    }

    public function test_render_ruft_die_pruefung_auf(): void
    {
        $quelle = file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Dispo/Events/Show.php');
        $this->assertMatchesRegularExpression('/public function render\(\)\s*\{\s*\$this->zeitPruefeFenster\(\);/', $quelle);
    }

    public function test_fenster_kopf_traegt_name_und_tag(): void
    {
        \Illuminate\Database\Capsule\Manager::table('rec_employees')->insert(['id' => 901, 'first_name' => 'Anna', 'last_name' => 'Beispiel']);
        $a = $this->einbuchung();
        $c = $this->offen($a);

        $this->assertSame('Beispiel, Anna', $c->zeitFenster['name']);
        $this->assertSame('01.10.2026', $c->zeitFenster['tag']);
    }

    public function test_k2_meldungen_sind_getrennt(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00']);
        $c = $this->offen($a);
        $c->zeitGrund = 'x';

        foreach (['abc', '601', '-5'] as $pause) {
            $c->resetErrorBag();
            $c->zeitPause = $pause;
            $c->zeitSpeichern();
            $this->assertSame('Bitte eine ganze Zahl zwischen 0 und 600 eingeben.', $c->zeitMeldung, "Pause {$pause}");
            $this->assertTrue($c->getErrorBag()->has('zeitPause'));
        }
        $c->zeitPause = '';

        $c->resetErrorBag();
        $c->zeitGrund = str_repeat('a', 256);
        $c->zeitBemerkung = 'neu';
        $c->zeitSpeichern();
        $this->assertSame('Bitte höchstens 255 Zeichen.', $c->zeitMeldung);
        $this->assertTrue($c->getErrorBag()->has('zeitGrund'));
        $c->zeitGrund = 'x';
        $c->zeitBemerkung = '';

        $c->zeitKommen = '';
        $c->zeitSpeichern();
        $this->assertSame('Kommen kann nur zusammen mit Gehen geleert werden.', $c->zeitMeldung);

        $c->zeitKommen = '2026-10-01T23:00';
        $c->zeitSpeichern();
        $this->assertSame('Gehen liegt vor Kommen.', $c->zeitMeldung);
        $this->assertSame(0, ZeitVerlauf::query()->count());
    }

    public function test_jetzt_gehen_vor_dem_kommen_hat_eigene_meldung(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']); // jetzt = 11:00 UTC
        $c = $this->offen($a);

        $c->zeitJetztGehen();

        $this->assertSame('Gehen liegt vor dem eingetragenen Kommen – bitte die Zeiten prüfen.', $c->zeitMeldung);
    }

    public function test_eventzugang_sieht_den_verlauf(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']);
        $normal = $this->offen($a);
        $normal->zeitBemerkung = 'Stau';
        $normal->zeitGrund = 'gemeldet';
        $normal->zeitSpeichern();

        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->offen($a);

        $this->assertCount(1, $c->zeitFenster['verlauf'], 'der Teamleiter sieht die Bearbeitungshistorie (Folie 9)');
        $this->assertSame('Sebastian Test', $c->zeitFenster['verlauf'][0]['wer']);
    }

    // ---- Eventzugang bucht mit Namen, Haken an der Veranstaltung, Stempel-Filter (Markus 17./18.11.2026) ----

    private function mensch(int $id, string $vor, string $nach): void
    {
        \Illuminate\Database\Capsule\Manager::table('rec_employees')->where('id', $id)->delete();
        \Illuminate\Database\Capsule\Manager::table('rec_employees')->insert(['id' => $id, 'first_name' => $vor, 'last_name' => $nach]);
    }

    public function test_eventzugang_bucht_mit_gewaehltem_namen_bis_erledigt_und_darf_nicht_wieder_oeffnen(): void
    {
        $a = $this->einbuchung();
        $this->eventOnlyListe(['sebastian@test.de']);
        Carbon::setTestNow('2026-10-01 12:00:00');
        $c = $this->offen($a);
        $c->zeitTeamleiter = "  Tina \n Teamleiterin ";

        $c->zeitJetztKommen();
        $this->assertNull($c->zeitMeldung);
        $this->assertNotNull($this->gespeichert($a)->kommen_at);
        $v = ZeitVerlauf::query()->orderBy('id')->get();
        $this->assertSame('Tina Teamleiterin (Eventzugang)', $v[0]->akteur_name, 'bereinigt, mit Zusatz');
        $this->assertSame('stellvertretend', $v[0]->art);
        $this->assertSame(7, (int) $v[0]->akteur_id);

        $c->zeitBemerkung = 'kam mit dem Bus';
        $c->zeitGrund = 'Nachtrag';
        $c->zeitSpeichern();
        $this->assertSame('kam mit dem Bus', $this->gespeichert($a)->bemerkung);

        Carbon::setTestNow('2026-10-01 20:00:00');
        $c->zeitJetztGehen();
        $this->assertSame('ausgecheckt', $this->gespeichert($a)->zustand());
        $this->assertSame(1, $c->zeitErledigbar);
        $c->zeitErledigt();
        $this->assertSame('erledigt', $this->gespeichert($a)->zustand());
        $this->assertSame(
            ['Tina Teamleiterin (Eventzugang)'],
            ZeitVerlauf::query()->distinct()->pluck('akteur_name')->all(),
            'jede Zeile nennt den Teamleiter'
        );

        // Erledigt ist auch fuer ihn zu — und wieder oeffnen darf der Eventzugang nie.
        $this->assertFalse($c->zeitDarfOeffnen);
        $c->zeitOeffnenGrund = 'doch nicht';
        $c->zeitOeffnen();
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);
        $c->zeitBemerkung = 'nachtraeglich';
        $c->zeitGrund = 'x';
        $c->zeitSpeichern();
        $this->assertSame('kam mit dem Bus', $this->gespeichert($a)->bemerkung);
    }

    public function test_eigene_anmeldung_bucht_weiter_unter_dem_kontonamen_auch_mit_gefuelltem_namensfeld(): void
    {
        $a = $this->einbuchung();
        $c = $this->offen($a);
        $c->zeitTeamleiter = 'Jemand Anderes';
        $c->zeitJetztKommen();

        $this->assertSame('Sebastian Test', ZeitVerlauf::query()->firstOrFail()->akteur_name);
    }

    public function test_sammel_erledigt_mit_eventzugang_braucht_den_namen(): void
    {
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00']);
        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);

        $c->zeitAlleErledigt();
        $this->assertSame(\Platform\Recruiting\Livewire\Dispo\Events\Show::ZEIT_NAME_FEHLT, $c->zeitHinweis);
        $this->assertNull($this->gespeichert($a)->gesperrt_at);

        $c->zeitTeamleiter = 'Tina Teamleiterin';
        $c->zeitAlleErledigt();
        $this->assertNotNull($this->gespeichert($a)->gesperrt_at);
        $this->assertSame('Tina Teamleiterin (Eventzugang)', ZeitVerlauf::query()->firstOrFail()->akteur_name);
    }

    public function test_namensvorschlaege_sind_die_eingebuchten_teamleiter_und_ein_einzelner_ist_vorbelegt(): void
    {
        $event = $this->event();
        $this->mensch(901, 'Erika', 'Muster');
        $this->mensch(902, 'Tina', 'Teamleiterin');
        $this->mensch(903, 'Tom', 'Zweiter');
        $this->mensch(904, 'Abgesagt', 'Leiter');
        $this->einbuchung($event, ['rec_employee_id' => 901, 'taetigkeit' => 'Service']);
        $this->einbuchung($event, ['rec_employee_id' => 902, 'taetigkeit' => 'Teamleitung Service']);
        $this->einbuchung($event, ['rec_employee_id' => 902, 'taetigkeit' => 'TL', 'datum' => '2026-10-02']);
        $this->einbuchung($event, ['rec_employee_id' => 904, 'taetigkeit' => 'Teamleiter', 'declined_at' => '2026-09-30 10:00:00']);
        Carbon::setTestNow('2026-10-01 12:00:00');

        // Eigene Anmeldung: keine Vorbelegung, kein Filter.
        $c = $this->dispoComponent((int) $event->id);
        $this->callPrivate($c, 'zeitStartEventzugang');
        $this->assertSame('', $c->zeitTeamleiter);
        $this->assertSame([], $c->rowStempel);

        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->dispoComponent((int) $event->id);
        $this->assertSame(['Tina Teamleiterin'], $c->zeitTeamleiterVorschlaege);
        $this->callPrivate($c, 'zeitStartEventzugang');
        $this->assertSame('Tina Teamleiterin', $c->zeitTeamleiter);
        $this->assertSame(['offen'], $c->rowStempel, 'am Veranstaltungstag zuerst nur, wer noch fehlt');

        // Zwei Teamleiter: nichts vorbelegt, sonst stuende still der falsche im Verlauf.
        $this->einbuchung($event, ['rec_employee_id' => 903, 'taetigkeit' => 'Teamleiter']);
        $c = $this->dispoComponent((int) $event->id);
        $this->assertSame(['Tina Teamleiterin', 'Tom Zweiter'], $c->zeitTeamleiterVorschlaege);
        $this->callPrivate($c, 'zeitStartEventzugang');
        $this->assertSame('', $c->zeitTeamleiter);

        // Anderer Tag: kein voreingestellter Filter. Schalter aus: gar nichts.
        Carbon::setTestNow('2026-10-05 12:00:00');
        $c = $this->dispoComponent((int) $event->id);
        $this->callPrivate($c, 'zeitStartEventzugang');
        $this->assertSame([], $c->rowStempel);
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->schalter->an = false;
        $c = $this->dispoComponent((int) $event->id);
        $this->callPrivate($c, 'zeitStartEventzugang');
        $this->assertSame([], $c->rowStempel);
    }

    public function test_freies_namensfeld_umschalten_verwirft_einen_namen_ausserhalb_der_liste(): void
    {
        $event = $this->event();
        $this->mensch(902, 'Tina', 'Teamleiterin');
        $this->einbuchung($event, ['rec_employee_id' => 902, 'taetigkeit' => 'Teamleiter']);
        $c = $this->dispoComponent((int) $event->id);

        $c->zeitTeamleiterFreiUmschalten();
        $this->assertTrue($c->zeitTeamleiterFrei);
        $c->zeitTeamleiter = 'Kunde Vor Ort';
        $c->zeitTeamleiterFreiUmschalten();
        $this->assertFalse($c->zeitTeamleiterFrei);
        $this->assertSame('', $c->zeitTeamleiter);

        $c->zeitTeamleiter = 'Tina Teamleiterin';
        $c->zeitTeamleiterFreiUmschalten();
        $c->zeitTeamleiterFreiUmschalten();
        $this->assertSame('Tina Teamleiterin', $c->zeitTeamleiter, 'ein Listen-Name bleibt');
    }

    public function test_stempel_filter_zeigt_wer_fehlt_und_wer_da_ist_kombiniert_mit_den_anderen_filtern(): void
    {
        $event = $this->event();
        $fehlt = $this->einbuchung($event, ['rec_employee_id' => 901, 'taetigkeit' => 'Service']);
        $da = $this->einbuchung($event, ['rec_employee_id' => 902, 'taetigkeit' => 'Service']);
        $fertig = $this->einbuchung($event, ['rec_employee_id' => 903, 'taetigkeit' => 'Bar']);
        $weg = $this->einbuchung($event, ['rec_employee_id' => 904, 'taetigkeit' => 'Service', 'missing_since' => '2026-09-30 10:00:00']);
        $this->eintrag($da, ['kommen_at' => '2026-10-01 12:00:00']);
        $this->eintrag($fertig, ['kommen_at' => '2026-10-01 12:00:00', 'gehen_at' => '2026-10-01 20:00:00']);

        $ids = fn ($c) => $c->filteredAssignments->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();

        $c = $this->dispoComponent((int) $event->id);
        $this->assertCount(4, $c->filteredAssignments, 'ohne Filter alles, auch die verschwundene Zeile');
        $this->assertSame(
            ['offen' => 1, 'eingecheckt' => 1, 'ausgecheckt' => 1, 'erledigt' => 0],
            array_column($c->rowStempelOptions, 'count', 'value'),
            'die verschwundene Zeile zaehlt nicht als „fehlt noch“'
        );
        $this->assertSame(['fehlt noch', 'ist da', 'ausgecheckt', 'erledigt'], array_column($c->rowStempelOptions, 'label'));

        $c = $this->dispoComponent((int) $event->id);
        $c->rowStempel = ['offen'];
        $this->assertSame([(int) $fehlt->id], $ids($c));
        $this->assertSame([['prop' => 'rowStempel', 'value' => 'offen', 'label' => 'Stempel: fehlt noch']], $c->activeRowFilters);
        $this->assertCount(1, $this->callPrivate($c, 'columnScope'), 'die Status-Pills zaehlen im selben Ausschnitt');

        $c = $this->dispoComponent((int) $event->id);
        $c->rowStempel = ['eingecheckt', 'ausgecheckt', 'quatsch'];
        $this->assertSame([(int) $da->id, (int) $fertig->id], $ids($c));

        // Doppelter Filter: Taetigkeit UND Stempel.
        $c = $this->dispoComponent((int) $event->id);
        $c->rowStempel = ['eingecheckt', 'ausgecheckt'];
        $c->rowTaetigkeiten = ['Service'];
        $this->assertSame([(int) $da->id], $ids($c));
        $this->assertSame(['offen' => 1, 'eingecheckt' => 1, 'ausgecheckt' => 0, 'erledigt' => 0], array_column($c->rowStempelOptions, 'count', 'value'));

        // Chip entfernen und alles zuruecksetzen.
        $c->removeRowFilter('rowStempel', 'eingecheckt');
        $this->assertSame(['ausgecheckt'], $c->rowStempel);
        $c->resetRowFilters();
        $this->assertSame([], $c->rowStempel);

        // Schalter aus: der Filter wirkt nicht (die Spalte gibt es dann nicht).
        $this->schalter->an = false;
        $c = $this->dispoComponent((int) $event->id);
        $c->rowStempel = ['offen'];
        $this->assertCount(4, $c->filteredAssignments);
        $this->assertSame([], $c->activeRowFilters);
        $this->assertSame([], $c->rowStempelOptions);
    }

    public function test_nach_dem_einchecken_verschwindet_die_zeile_aus_dem_filter_wer_fehlt(): void
    {
        $a = $this->einbuchung();
        Carbon::setTestNow('2026-10-01 12:00:00');
        $c = $this->dispoComponent((int) $a->rec_dispo_event_id);
        $c->rowStempel = ['offen'];
        $this->assertCount(1, $c->filteredAssignments);

        $c->openZeit((int) $a->id);
        $c->zeitJetztKommen();

        $this->assertCount(0, $c->filteredAssignments, 'im selben Aufruf, nicht erst beim naechsten Nachladen');
        $this->assertSame(1, array_column($c->rowStempelOptions, 'count', 'value')['eingecheckt']);
    }

    public function test_haken_nur_teamleiter_setzt_nur_die_eigene_anmeldung(): void
    {
        $a = $this->einbuchung();
        $eventId = (int) $a->rec_dispo_event_id;
        $wert = fn () => (bool) \Platform\Recruiting\Models\RecDispoEvent::query()->findOrFail($eventId)->zeit_nur_teamleiter;

        $c = $this->dispoComponent($eventId);
        $c->zeitNurTeamleiterSetzen(true);
        $this->assertTrue($wert());
        $this->assertStringContainsString('nur noch der Teamleiter', (string) $c->zeitHinweis);
        $this->assertTrue((bool) $c->event->zeit_nur_teamleiter, 'die Seite zeigt den neuen Stand');
        // Setzen, nicht umschalten: derselbe Klick eines zweiten Disponenten laesst den Haken stehen.
        $this->dispoComponent($eventId)->zeitNurTeamleiterSetzen(true);
        $this->assertTrue($wert());
        $c->zeitNurTeamleiterSetzen(false);
        $this->assertFalse($wert());

        $this->eventOnlyListe(['sebastian@test.de']);
        $c = $this->dispoComponent($eventId);
        $c->zeitNurTeamleiterSetzen(true);
        $this->assertFalse($wert(), 'der Eventzugang setzt den Haken nicht');

        $this->eventOnlyListe([]);
        $this->schalter->an = false;
        $c = $this->dispoComponent($eventId);
        $c->zeitNurTeamleiterSetzen(true);
        $this->assertFalse($wert());
    }

    public function test_abgesagte_und_zeilen_ohne_person_zaehlen_im_stempel_filter_nicht(): void
    {
        $event = $this->event();
        $fehlt = $this->einbuchung($event, ['rec_employee_id' => 901]);
        $this->einbuchung($event, ['rec_employee_id' => 902, 'declined_at' => '2026-09-30 10:00:00']);
        $this->einbuchung($event, ['rec_employee_id' => null]);

        $c = $this->dispoComponent((int) $event->id);
        $this->assertSame(1, array_column($c->rowStempelOptions, 'count', 'value')['offen']);
        $c->rowStempel = ['offen'];
        $this->assertSame([(int) $fehlt->id], $c->filteredAssignments->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_name_der_aus_der_vorschlagsliste_gefallen_ist_gilt_nicht_mehr(): void
    {
        $event = $this->event();
        $this->mensch(902, 'Tina', 'Teamleiterin');
        $this->mensch(903, 'Tom', 'Zweiter');
        $a = $this->einbuchung($event, ['rec_employee_id' => 901]);
        $this->einbuchung($event, ['rec_employee_id' => 902, 'taetigkeit' => 'Teamleiter']);
        $tom = $this->einbuchung($event, ['rec_employee_id' => 903, 'taetigkeit' => 'Teamleiter']);
        $this->eventOnlyListe(['sebastian@test.de']);
        Carbon::setTestNow('2026-10-01 12:00:00');

        // Tom wurde gewaehlt, sagt dann ab: im Auswahlfeld stuende „Bitte waehlen“, gebucht wuerde unter Tom.
        $tom->forceFill(['declined_at' => '2026-10-01 09:00:00'])->save();
        $c = $this->offen($a);
        $c->zeitTeamleiter = 'Tom Zweiter';
        $c->zeitJetztKommen();
        $this->assertSame(0, ZeitVerlauf::query()->count());
        $this->assertSame('', $c->zeitTeamleiter);
        $this->assertSame(\Platform\Recruiting\Livewire\Dispo\Events\Show::ZEIT_NAME_FEHLT, $c->zeitMeldung);

        // Im freien Feld darf jeder Name stehen (Kunde vor Ort).
        $c->zeitTeamleiterFrei = true;
        $c->zeitTeamleiter = 'Kunde Vor Ort';
        $c->zeitJetztKommen();
        $this->assertSame('Kunde Vor Ort (Eventzugang)', ZeitVerlauf::query()->firstOrFail()->akteur_name);
    }

    public function test_korrektur_die_auf_den_gespeicherten_wert_rundet_blockiert_das_fenster_nicht(): void
    {
        // Mit den ECHTEN Regeln (Rundung), nicht mit der Attrappe.
        $regeln = new \Platform\Recruiting\Services\Zeit\RheingedeckZeitRegeln();
        Container::getInstance()->instance(\Platform\Recruiting\Zeit\ZeitRegeln::class, $regeln);
        $a = $this->einbuchung();
        $this->eintrag($a, ['kommen_at' => '2026-10-01 12:00:00']); // 14:00 Ortszeit
        Carbon::setTestNow('2026-10-01 18:07:00');
        $c = $this->offen($a);

        $c->zeitKommen = '2026-10-01T14:05';
        $c->zeitGrund = 'Teamleiter';
        $c->zeitSpeichern();
        $this->assertStringContainsString('Nichts geändert', (string) $c->zeitMeldung);
        $this->assertSame('2026-10-01T14:00', $c->zeitKommen, 'Feld steht wieder auf dem gespeicherten Wert');
        $this->assertSame('Teamleiter', $c->zeitGrund, 'der Grund bleibt stehen');

        $c->zeitJetztGehen();
        $this->assertNull($c->zeitMeldung);
        $this->assertSame('2026-10-01 18:00:00', $this->gespeichert($a)->gehen_at->format('Y-m-d H:i:s'), '20:07 Ortszeit -> 20:00');
    }

    public function test_beide_ansichten_nutzen_dieselbe_stempel_zelle(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/show.blade.php');
        $this->assertSame(2, substr_count($blade, "livewire.dispo.events._stempel-zelle"), 'Tabelle und Karte');
        $this->assertStringContainsString('openZeit(', file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/_stempel-zelle.blade.php'));
    }
}
