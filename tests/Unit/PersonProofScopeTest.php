<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonProofScope;

/**
 * Die Regel entscheidet, wessen Ausweis jemand im Portal zu sehen bekommt.
 * Deshalb ist sie streng: gleicher Marker UND gleiche Nummer.
 */
final class PersonProofScopeTest extends TestCase
{
    private function ma(int $id, ?string $key, ?string $phone): array
    {
        return ['id' => $id, 'person_key' => $key, 'phone' => $phone];
    }

    public function test_ohne_geschwister_nur_man_selbst(): void
    {
        $r = PersonProofScope::resolve($this->ma(1, 'p-1', '+4915112345678'), []);

        $this->assertSame([1], $r['ids']);
        $this->assertSame([], $r['abweichend']);
    }

    public function test_gleicher_marker_und_gleiche_nummer_zaehlt_zusammen(): void
    {
        $r = PersonProofScope::resolve(
            $this->ma(1, 'p-1', '+49 151 12345678'),
            [$this->ma(2, 'p-1', '015112345678')],
        );

        $this->assertEqualsCanonicalizing([1, 2], $r['ids'], 'dieselbe Nummer in anderer Schreibweise');
        $this->assertSame([], $r['abweichend']);
    }

    public function test_gleicher_marker_andere_nummer_geht_auf_die_hr_liste(): void
    {
        $r = PersonProofScope::resolve(
            $this->ma(1, 'p-1', '+4915112345678'),
            [$this->ma(2, 'p-1', '+4917699998888')],
        );

        $this->assertSame([1], $r['ids'], 'im Zweifel weniger zeigen');
        $this->assertSame([2], $r['abweichend']);
    }

    public function test_zwei_leere_nummern_bestaetigen_sich_nicht_gegenseitig(): void
    {
        $r = PersonProofScope::resolve(
            $this->ma(1, 'p-1', null),
            [$this->ma(2, 'p-1', '')],
        );

        $this->assertSame([1], $r['ids']);
        $this->assertSame([2], $r['abweichend']);
    }

    public function test_zu_kurze_nummer_gilt_nicht_als_uebereinstimmung(): void
    {
        $r = PersonProofScope::resolve(
            $this->ma(1, 'p-1', '1234'),
            [$this->ma(2, 'p-1', '1234')],
        );

        $this->assertSame([1], $r['ids'], 'vier Ziffern sind kein Nachweis');
        $this->assertSame([2], $r['abweichend']);
    }

    public function test_ohne_marker_keine_zusammenfuehrung_und_kein_zweifelsfall(): void
    {
        $r = PersonProofScope::resolve(
            $this->ma(1, null, '+4915112345678'),
            [$this->ma(2, null, '+4915112345678')],
        );

        $this->assertSame([1], $r['ids'], 'gleiche Nummer allein reicht nicht');
        $this->assertSame([], $r['abweichend'], 'ohne Marker ist es gar keine Geschwisterzeile');
    }

    public function test_mehrere_geschwister_werden_einzeln_bewertet(): void
    {
        $r = PersonProofScope::resolve(
            $this->ma(1, 'p-1', '+4915112345678'),
            [
                $this->ma(2, 'p-1', '015112345678'),
                $this->ma(3, 'p-1', '+4917600000000'),
            ],
        );

        $this->assertEqualsCanonicalizing([1, 2], $r['ids']);
        $this->assertSame([3], $r['abweichend']);
    }
}
