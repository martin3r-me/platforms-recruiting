<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Dispo\Jobs\Show;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZahlen;

final class DispoJobZahlenTest extends TestCase
{
    private static function k(int $anchor, string $name, array $o = []): array
    {
        return $o + ['anchor' => $anchor, 'employee_id' => $anchor, 'phone' => '+49170' . $anchor, 'first_name' => $name, 'name' => $name . ' Muster',
            'portal_token' => 't' . $anchor, 'block' => 200, 'portal_locked' => false, 'taetigkeiten' => ['Zapfer']];
    }

    public function testZaehltEmpfaengerUndNenntDieToepfeMitNamen(): void
    {
        $daten = ['kandidaten' => [
            self::k(1, 'Anna'),
            self::k(2, 'Bert', ['phone' => null]),
            self::k(3, 'Cem', ['taetigkeiten' => ['Service']]),
            self::k(4, 'Dora'),
        ], 'einsaetze' => [], 'gebucht' => []];
        $schichten = [['id' => 1, 'datum' => '2026-10-24', 'taetigkeit' => 'Zapfer']];

        $z = DispoJobZahlen::ausDaten($daten, $schichten, [4 => true], ['gruppen' => ['0'], 'bloecke' => [200], 'nur_passend' => true]);

        $this->assertSame(1, $z['empfaenger']);
        $this->assertSame(['Anna Muster'], $z['namen']);
        $this->assertSame(1, $z['mit_passend']);
        $this->assertSame([
            ['key' => 'schon_angeschrieben', 'label' => 'in dieser Anfrage schon angeschrieben', 'anzahl' => 1, 'namen' => ['Dora Muster']],
            ['key' => 'ohne_nummer', 'label' => 'ohne Handynummer', 'anzahl' => 1, 'namen' => ['Bert Muster']],
            ['key' => 'ohne_taetigkeit', 'label' => 'ohne passende Tätigkeit', 'anzahl' => 1, 'namen' => ['Cem Muster']],
        ], $z['toepfe']);

        $ohneHaken = DispoJobZahlen::ausDaten($daten, $schichten, [4 => true], ['gruppen' => ['0'], 'bloecke' => [200], 'nur_passend' => false]);
        $this->assertSame(2, $ohneHaken['empfaenger'], 'ohne Haken zaehlt Cem mit');
        $this->assertSame(1, $ohneHaken['mit_passend'], 'Zahl am Haken bleibt die mit Haken');
    }

    public function testBloeckeUndGruppenAusCheckboxWerten(): void
    {
        $this->assertSame([200, null, 300], DispoJobZahlen::bloecke(['200', 'ohne', '300', '200', 'x', '']));
        $this->assertSame(['0', 'alle'], DispoJobZahlen::gruppen(['0', 'alle', 'quatsch', '0']));
    }

    public function testNaechsteGruppeSchlaegtDasFolgendeBandVor(): void
    {
        $this->assertSame(['1-4'], Show::naechsteGruppe(['0']));
        $this->assertSame(['10-19'], Show::naechsteGruppe(['0', '5-9']));
        $this->assertSame([], Show::naechsteGruppe(['20+']));
        $this->assertSame([], Show::naechsteGruppe(['alle']));
        $this->assertSame(['0'], Show::naechsteGruppe([]));
    }
}
