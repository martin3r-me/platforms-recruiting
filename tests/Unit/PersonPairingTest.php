<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonPairing;

/**
 * Personen-Paarung (Chaieb-Befund 10.09.2026): ZAS bedient zwei Firmen, eine
 * Person kann zwei rec_employees-Datensaetze mit zwei Personalnummern haben.
 * Die Paar-Regel entscheidet, wann zwei Datensaetze OHNE Menschen sicher
 * derselbe Mensch sind: voller Name UND Geburtsdatum exakt gleich — die
 * Warnung vor Namens-AUTOMATIK galt Namens-VARIANTEN, die hier gerade nicht
 * matchen. Alles Unscharfe bleibt Handarbeit (Audit-Report).
 */
final class PersonPairingTest extends TestCase
{
    public function test_namensschluessel_normalisiert_nur_gross_klein_und_rand(): void
    {
        $this->assertSame('wannes|chaieb', PersonPairing::nameKey(' Wannes ', 'CHAIEB'));
        $this->assertNull(PersonPairing::nameKey('', 'Chaieb'), 'ohne Vornamen kein Schluessel');
        $this->assertNull(PersonPairing::nameKey(null, null));
    }

    public function test_exaktes_paar_braucht_namen_und_geburtsdatum(): void
    {
        $a = ['first_name' => 'Wannes', 'last_name' => 'Chaieb', 'birth_date' => '1998-08-03'];
        $b = ['first_name' => 'wannes', 'last_name' => 'chaieb', 'birth_date' => '1998-08-03'];
        $this->assertTrue(PersonPairing::isExactMatch($a, $b));

        // Namens-VARIANTE matcht bewusst NICHT — das ist der Fall, der einem
        // Menschen gehoert (Leni Runtenberg vs. Leni Emily Runtenberg).
        $c = ['first_name' => 'Wannes Karim', 'last_name' => 'Chaieb', 'birth_date' => '1998-08-03'];
        $this->assertFalse(PersonPairing::isExactMatch($a, $c));

        $d = ['first_name' => 'Wannes', 'last_name' => 'Chaieb', 'birth_date' => '1998-08-04'];
        $this->assertFalse(PersonPairing::isExactMatch($a, $d), 'anderes Geburtsdatum');

        $e = ['first_name' => 'Wannes', 'last_name' => 'Chaieb', 'birth_date' => null];
        $this->assertFalse(PersonPairing::isExactMatch($a, $e), 'ohne Geburtsdatum keine Automatik');
    }
}
