<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\HrDesk\Index;
use Platform\Recruiting\Livewire\HrDesk\Mitarbeiter;
use Platform\Recruiting\Services\HrKontoAktionen;
use Platform\Recruiting\Services\HrMitarbeiterListen;
use Platform\Recruiting\Support\HrAktionErgebnis;

final class HrDeskMitarbeiterTest extends TestCase
{
    use HrBereichHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hrWeltAufbauen();
        $c = Container::getInstance();
        $c->instance(HrKontoAktionen::class, new HrKontoAktionen($this->portalAuth()));
        $c->instance(HrMitarbeiterListen::class, new HrMitarbeiterListen($this->portalAuth()));
    }

    protected function tearDown(): void
    {
        $c = Container::getInstance();
        $c->forgetInstance(HrKontoAktionen::class);
        $c->forgetInstance(HrMitarbeiterListen::class);
        $this->hrWeltAbbauen();
        parent::tearDown();
    }

    private function mitAntrag(int $team = 7): int
    {
        $akte = $this->akte(['team_id' => $team, 'phone' => '+4915111111111']);

        return $this->person(['team_id' => $team, 'wechsel_neue_nummer' => '+4917098765432', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall'], $akte);
    }

    /** Review Focus 2: $wire.set('aktionPersonId', …) wirft — weil die Properties #[Locked] sind. */
    public function test_aktionskontext_ist_gegen_wire_set_gesperrt(): void
    {
        foreach (['aktion', 'aktionPersonId', 'aktionEmployeeId', 'aktionBezug', 'erwartetBeantragtAt', 'aktionWarnung', 'modalFehler', 'meldung', 'meldungStufe'] as $name) {
            $attribute = (new \ReflectionProperty(Mitarbeiter::class, $name))->getAttributes(Locked::class);
            $this->assertCount(1, $attribute, "{$name} ist nicht #[Locked]");
        }
        $this->assertCount(0, (new \ReflectionProperty(Mitarbeiter::class, 'notiz'))->getAttributes(Locked::class), 'die Notiz tippt HR');
    }

    /** Review Focus 2: Methoden-Parameter sind Eingabe — die Scope-Pruefung greift. */
    public function test_oeffne_mit_person_aus_fremdem_team_meldet_nicht_gefunden(): void
    {
        $fremd = $this->mitAntrag(8);
        $c = new Mitarbeiter();

        $c->oeffne('stoppen', $fremd);

        $this->assertFalse($c->modalOffen);
        $this->assertNull($c->aktionPersonId);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $c->meldung);
        $this->assertSame(HrAktionErgebnis::FEHLER, $c->meldungStufe);
    }

    public function test_unbekannte_aktion_oeffnet_nichts_und_bestaetige_ohne_modal_tut_nichts(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();

        $c->oeffne('loeschen', $id);
        $this->assertFalse($c->modalOffen);

        $c->notiz = 'Eine ausreichend lange Notiz';
        $c->bestaetige();
        $this->assertCount(0, $this->vermerke());
    }

    public function test_freigeben_merkt_das_gesehene_beantragt_at_und_wirkt(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();

        $c->oeffne('freigeben', $id);

        $this->assertTrue($c->modalOffen);
        $this->assertSame('2026-10-12 09:00:00', $c->erwartetBeantragtAt);
        $this->assertStringContainsString('…5432', $c->aktionWarnung);

        $c->notiz = 'Telefonat mit Ausweis-Abgleich.';
        $c->bestaetige();

        $this->assertFalse($c->modalOffen);
        $this->assertSame(HrAktionErgebnis::OK, $c->meldungStufe);
        $this->assertSame('+4917098765432', $this->personZeile($id)->phone);
        $this->assertSame('', $c->notiz, 'Notiz nach Erfolg geleert');
    }

    public function test_zwischenzeitlich_neu_gestellter_antrag_wird_nicht_freigegeben(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();
        $c->oeffne('freigeben', $id);
        DB::table('rec_persons')->where('id', $id)->update(['wechsel_beantragt_at' => '2026-10-12 09:30:00', 'wechsel_neue_nummer' => '+4917011111111']);

        $c->notiz = 'Telefonat mit Ausweis-Abgleich.';
        $c->bestaetige();

        $this->assertSame(HrAktionErgebnis::HINWEIS, $c->meldungStufe);
        $this->assertSame('+4915111111111', $this->personZeile($id)->phone);
    }

    public function test_fehler_haelt_das_modal_offen(): void
    {
        $id = $this->mitAntrag();
        $c = new Mitarbeiter();
        $c->oeffne('stoppen', $id);

        $c->notiz = 'kurz';
        $c->bestaetige();

        $this->assertTrue($c->modalOffen);
        $this->assertStringContainsString('mindestens 5 Zeichen', $c->modalFehler);
        $this->assertCount(0, $this->vermerke());
    }

    public function test_portal_entsperren_ueber_die_akte_ohne_person(): void
    {
        Container::getInstance()->instance(\Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver::class, new class extends \Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver {
            public function __construct() {}
            public function groupFor(int $employeeId): array { return [$employeeId]; }
        });
        $akte = $this->akte(['portal_locked_at' => '2026-10-10 16:00:00']);
        $c = new Mitarbeiter();

        $c->oeffne('portal_entsperren', null, $akte->id);
        $c->notiz = 'Dispo hat Rückmeldung bekommen.';
        $c->bestaetige();

        $this->assertSame(HrAktionErgebnis::OK, $c->meldungStufe);
        $this->assertNull(DB::table('rec_employees')->where('id', $akte->id)->value('portal_locked_at'));
        Container::getInstance()->forgetInstance(\Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver::class);
    }

    public function test_seiten_zu_je_50_und_zahlen_je_unterreiter(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->person(['locked_at' => now()], $this->akte());
        }
        $c = new Mitarbeiter();
        $c->zeigeListe('gesperrt');

        $zeilen = $c->gesperrt();
        $this->assertCount(60, $zeilen);
        $this->assertCount(50, $c->seitenZeilen($zeilen));
        $this->assertSame(2, $c->seitenAnzahl($zeilen));
        $c->blaettern(2);
        $this->assertCount(10, $c->seitenZeilen($zeilen));
        $this->assertSame(60, $c->zahlen()['gesperrt']);

        $c->zeigeListe('quatsch');
        $this->assertSame('antraege', $c->liste);
        $this->assertSame(1, $c->seite);
    }

    public function test_reiter_und_abzeichen_am_schreibtisch(): void
    {
        $this->mitAntrag();
        $this->person(['locked_at' => now()], $this->akte());
        $index = new Index();

        $this->assertSame('faelle', $index->tab);
        $this->assertSame(2, $index->mitarbeiterAbzeichen());

        $index->tab = 'quatsch';
        $index->updatedTab();
        $this->assertSame('faelle', $index->tab);
    }

    /** Controller-Ruling: HR aus Team A stoppt ueber die Komponente keinen Antrag aus Team B. */
    public function test_hr_aus_team_a_kann_antrag_aus_team_b_nicht_stoppen(): void
    {
        $fremd = $this->mitAntrag(8);
        $c = new Mitarbeiter();

        $c->oeffne('stoppen', $fremd);
        $c->notiz = 'Eine ausreichend lange Notiz';
        $c->bestaetige();

        $this->assertFalse($c->modalOffen);
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $c->meldung);
        $zeile = $this->personZeile($fremd);
        $this->assertSame('2026-10-12 09:00:00', $zeile->wechsel_beantragt_at, 'Antrag in Team B bleibt offen');
        $this->assertSame('+4917098765432', $zeile->wechsel_neue_nummer);
        $this->assertNull($zeile->notfall_gesperrt_bis);
        $this->assertCount(0, $this->vermerke());
    }

    /** Ruling Aufgabe 6 (M2): ein Antrag ohne Akte im Team ist sichtbar UND stoppbar — aber nicht freigebbar. */
    public function test_antrag_ohne_akte_im_team_laesst_sich_stoppen_aber_nicht_freigeben(): void
    {
        $id = $this->person(['phone' => '+4915122222222', 'wechsel_neue_nummer' => '+4917098765432', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall']);
        $c = new Mitarbeiter();

        $c->oeffne('freigeben', $id);
        $this->assertFalse($c->modalOffen, 'Freigeben braucht eine Akte im Team');
        $this->assertSame(HrKontoAktionen::NICHT_GEFUNDEN, $c->meldung);

        $c->oeffne('stoppen', $id);
        $this->assertTrue($c->modalOffen);
        $c->notiz = 'Unbekannter Antrag, gestoppt.';
        $c->bestaetige();

        $this->assertSame(HrAktionErgebnis::OK, $c->meldungStufe);
        $this->assertNull($this->personZeile($id)->wechsel_beantragt_at);
        $this->assertSame('+4915122222222', $this->personZeile($id)->phone);
    }

    /** Ruling: Antrags-Zeilen ohne Akte bauen keinen Akten-Link, sondern zeigen "Person #id". */
    public function test_mensch_partial_ohne_akte_zeigt_person_ohne_link(): void
    {
        $id = $this->person(['wechsel_neue_nummer' => '+4917098765432', 'wechsel_beantragt_at' => '2026-10-12 09:00:00',
            'wechsel_wirksam_ab' => '2026-10-13 09:00:00', 'wechsel_quelle' => 'notfall']);
        $mitAkte = $this->mitAntrag();
        $zeilen = [];
        foreach ((new Mitarbeiter())->antraege() as $z) {
            $zeilen[$z['person_id']] = $z;
        }
        $this->assertNull($zeilen[$id]['employee_id']);

        $ohne = $this->rendereMensch($zeilen[$id]);
        $this->assertStringContainsString('Person #' . $id, $ohne);
        $this->assertStringNotContainsString('<a ', $ohne);

        $mit = $this->rendereMensch($zeilen[$mitAkte]);
        $this->assertStringContainsString('recruiting.employees.show', $mit);
    }

    /** #[Url]-Werte sind Eingabe; eine gemerkte Seite hinter dem Listenende zeigt die letzte Seite. */
    public function test_url_werte_werden_normalisiert_und_seite_geklemmt(): void
    {
        $c = new Mitarbeiter();
        $c->liste = 'quatsch';
        $c->pilotFilter = '<script>';
        $c->mount();
        $this->assertSame('antraege', $c->liste);
        $this->assertSame('alle', $c->pilotFilter);

        $c->blaettern(5);
        $this->assertCount(3, $c->seitenZeilen([1, 2, 3]));
    }

    private function rendereMensch(array $z): string
    {
        $dir = sys_get_temp_dir() . '/hr-mensch-' . uniqid('', true);
        @mkdir($dir);
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), $dir);
        $datei = $dir . '/mensch.php';
        file_put_contents($datei, $compiler->compileString((string) file_get_contents(__DIR__ . '/../../resources/views/livewire/hr-desk/partials/mensch.blade.php')));
        $__env = new class {
            use \Illuminate\View\Concerns\ManagesLoops;
        };
        ob_start();
        include $datei;
        $html = (string) ob_get_clean();
        @unlink($datei);
        @rmdir($dir);

        return $html;
    }
}
