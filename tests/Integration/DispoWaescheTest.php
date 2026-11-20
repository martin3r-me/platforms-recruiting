<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Platform\Recruiting\Models\RecZeitWaesche;
use Platform\Recruiting\Services\Zeit\DispoWaesche;
use Platform\Recruiting\Services\Zeit\ZeitSchalter;

/** Wäsche an der Einbuchung: ausgeben, Rückgabe, Sperre durch „erledigt“, Rechnung je Einbuchung. */
class DispoWaescheTest extends ZeitTestCase
{
    private object $schalter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schalter = new class extends ZeitSchalter {
            public bool $an = true;
            public function aktiv(): bool { return $this->an; }
            public function teamId(): int { return 1101; }
        };
    }

    private function dienst(): DispoWaesche
    {
        return new DispoWaesche($this->schalter);
    }

    private function erledigt(int $assignmentId, int $personId): void
    {
        Capsule::table('rec_zeit_eintraege')->insert([
            'uuid' => uniqid('z', true), 'team_id' => self::TEAM, 'person_typ' => 'rec_employee', 'person_id' => $personId,
            'bezug_typ' => 'rec_dispo_assignment', 'bezug_id' => $assignmentId, 'tag' => '2026-10-01',
            'kommen_at' => '2026-10-01 06:00:00', 'gehen_at' => '2026-10-01 14:00:00',
            'gesperrt_at' => '2026-10-01 15:00:00', 'gesperrt_grund' => 'erledigt', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_ausgeben_rueckgabe_und_rechnung_im_fenster(): void
    {
        $a = $this->assignment($this->event());
        $d = $this->dienst();
        $tl = $this->akteur('user', 7, 'Anna Leiter (Eventzugang)');

        $this->assertSame('ok', $d->ausgeben($a, 'hemd', 1, $tl));
        $this->assertSame('ok', $d->ausgeben($a, 'weste', 1, $tl));
        $this->assertSame('ok', $d->ausgeben($a, 'sonstiges', 1, $tl, '  Hose  schwarz ', 1999));

        $liste = $d->liste($a);
        $this->assertSame(['Hemd', 'Weste', 'Hose schwarz'], array_column($liste['posten'], 'name'));
        $this->assertSame('74,99', $liste['abzug'], 'nichts zurück: 25 + 30 + 19,99');

        [$hemd, $weste, $hose] = array_column($liste['posten'], 'id');
        $this->assertSame('ok', $d->zurueck($a, $hemd, 1, $tl));
        $this->assertSame('ok', $d->zurueck($a, $hose, 1, $tl));
        $liste = $d->liste($a);
        $this->assertSame('34,00', $liste['abzug'], 'Hemd zurück 4 €, Weste fehlt 30 €, Hose zurück 0');
        $this->assertSame(['Weste'], $liste['fehlt']);
        $this->assertSame(['weste' => 1], $liste['kunde']);
        $this->assertSame('Anna Leiter (Eventzugang)', RecZeitWaesche::find($hemd)->geaendert_von);

        $this->assertSame('ok', $d->entfernen($a, $hose));
        $this->assertCount(2, $d->liste($a)['posten']);
    }

    public function test_preis_wird_bei_der_ausgabe_festgehalten(): void
    {
        $a = $this->assignment($this->event());
        $this->dienst()->ausgeben($a, 'hemd', 2, $this->akteur());
        $w = RecZeitWaesche::query()->first();
        $this->assertSame([2500, 400, false, 2], [$w->preis_cent, $w->reinigung_lohn_cent, $w->leihe_kunde, $w->anzahl]);
    }

    public function test_ungueltige_eingaben(): void
    {
        $a = $this->assignment($this->event());
        $d = $this->dienst();
        $x = $this->akteur();
        $this->assertSame('ungueltig', $d->ausgeben($a, 'hemd', 0, $x));
        $this->assertSame('ungueltig', $d->ausgeben($a, 'hemd', DispoWaesche::MAX_ANZAHL + 1, $x));
        $this->assertSame('ungueltig', $d->ausgeben($a, 'socke', 1, $x), 'unbekannter Artikel');
        $this->assertSame('ungueltig', $d->ausgeben($a, 'sonstiges', 1, $x, '   ', 500), 'Sonstiges ohne Namen');
        $this->assertSame('ungueltig', $d->ausgeben($a, 'sonstiges', 1, $x, 'Hose', -1));
        $this->assertSame('ungueltig', $d->ausgeben($a, 'sonstiges', 1, $x, 'Hose', DispoWaesche::MAX_PREIS_CENT + 1));
        $this->assertSame(0, RecZeitWaesche::query()->count());

        $d->ausgeben($a, 'hemd', 2, $x);
        $id = (int) RecZeitWaesche::query()->value('id');
        $this->assertSame('ungueltig', $d->zurueck($a, $id, 3, $x), 'mehr zurück als ausgegeben');
        $this->assertSame('ungueltig', $d->zurueck($a, $id, -1, $x));
    }

    public function test_erledigt_sperrt_die_waesche(): void
    {
        $a = $this->assignment($this->event());
        $d = $this->dienst();
        $d->ausgeben($a, 'hemd', 1, $this->akteur());
        $id = (int) RecZeitWaesche::query()->value('id');
        $this->erledigt((int) $a->id, (int) $a->rec_employee_id);

        $this->assertSame('gesperrt', $d->ausgeben($a, 'hemd', 1, $this->akteur()));
        $this->assertSame('gesperrt', $d->zurueck($a, $id, 1, $this->akteur()));
        $this->assertSame('gesperrt', $d->entfernen($a, $id));
        $this->assertSame(0, (int) RecZeitWaesche::query()->value('zurueck'));
    }

    public function test_schalter_aus_ohne_person_und_fremde_posten(): void
    {
        $event = $this->event();
        $a = $this->assignment($event);
        $b = $this->assignment($event);
        $d = $this->dienst();
        $d->ausgeben($b, 'hemd', 1, $this->akteur());
        $fremd = (int) RecZeitWaesche::query()->value('id');

        $this->assertSame('weg', $d->zurueck($a, $fremd, 1, $this->akteur()), 'Posten einer anderen Einbuchung');
        $this->assertSame('weg', $d->entfernen($a, $fremd));

        $ohne = $this->assignment($event, ['rec_employee_id' => null]);
        $this->assertSame('ohne_person', $d->ausgeben($ohne, 'hemd', 1, $this->akteur()));

        $this->schalter->an = false;
        $this->assertSame('aus', $d->ausgeben($a, 'hemd', 1, $this->akteur()));
    }

    public function test_rechnungen_nur_fuer_die_eingebuchte_person(): void
    {
        $event = $this->event();
        $a = $this->assignment($event);
        $d = $this->dienst();
        $d->ausgeben($a, 'weste', 1, $this->akteur());
        // Person an der Zeile gewechselt (ZAS): die Wäsche der alten Person zählt hier nicht mehr
        RecZeitWaesche::query()->update(['rec_employee_id' => 4711]);

        $this->assertSame([], $d->rechnungen([(int) $a->id => (int) $a->rec_employee_id]));
        $this->assertSame(3000, $d->rechnungen([(int) $a->id => 4711])[(int) $a->id]['abzugCent']);
    }
}
