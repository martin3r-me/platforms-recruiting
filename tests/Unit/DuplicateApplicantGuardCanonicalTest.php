<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DuplicateApplicantGuard;

class DuplicateApplicantGuardCanonicalTest extends TestCase
{
    public function test_alle_schreibweisen_derselben_mobilnummer_kanonisieren_gleich(): void
    {
        $expected = '491637899743';

        foreach ([
            '+491637899743',
            '491637899743',          // nackte wa_id (WhatsApp-Inbound)
            '01637899743',           // nationale 0-Notation (Legacy #1023-Klasse)
            '1637899743',            // nackt ohne 0 (Legacy #1012/#97-Klasse)
            '00491637899743',        // Doppelnull
            '+49 163 789-97 43',     // Spaces + Bindestrich
            '0163/7899743',          // Slash (ContactIndex-Fallback-Klasse)
            '(0163) 789.9743',       // Klammern + Punkt
            "+49\u{00A0}163 7899743", // Non-breaking Space
        ] as $input) {
            $this->assertSame(
                $expected,
                DuplicateApplicantGuard::canonicalDigits($input),
                "Eingabe '{$input}' muss kanonisch {$expected} ergeben"
            );
        }
    }

    public function test_ortsnetz_0491_leer_wird_nicht_als_laendercode_fehlgeparst(): void
    {
        // 0491 = Ortsnetz Leer. Führende 0 disambiguiert: NSN bleibt 491234567.
        $this->assertSame('49491234567', DuplicateApplicantGuard::canonicalDigits('0491234567'));
        $this->assertSame('49491234567', DuplicateApplicantGuard::canonicalDigits('+49 491 234567'));
        $this->assertSame('49491234567', DuplicateApplicantGuard::canonicalDigits('0049 491 234567'));
    }

    public function test_zwei_personen_mit_49_praefix_ambiguitaet_kollidieren_nicht(): void
    {
        // Person X: Festnetz Leer (0491-234567). Person Y: (hypothetische) +49 1234567.
        // Fehlerhaftes 49-Stripping würde beide auf dieselbe Form mappen — darf nicht.
        $x = DuplicateApplicantGuard::canonicalDigits('0491234567');
        $y = DuplicateApplicantGuard::canonicalDigits('+491234567');

        $this->assertSame('49491234567', $x);
        $this->assertSame('491234567', $y);
        $this->assertNotSame($x, $y);
    }

    public function test_nackte_49_ziffern_werden_als_laendercodiert_interpretiert(): void
    {
        // Dokumentierte Ambiguität (Regel 5): nackt + führende 49 = wa_id-Schreibweise.
        $this->assertSame('491234567', DuplicateApplicantGuard::canonicalDigits('491234567'));
    }

    public function test_auslandsnummern_mit_plus_bleiben_erhalten(): void
    {
        $this->assertSame('436641234567', DuplicateApplicantGuard::canonicalDigits('+43 664 1234567'));
    }

    public function test_leere_und_ziffernlose_eingaben_ergeben_null(): void
    {
        $this->assertNull(DuplicateApplicantGuard::canonicalDigits(null));
        $this->assertNull(DuplicateApplicantGuard::canonicalDigits(''));
        $this->assertNull(DuplicateApplicantGuard::canonicalDigits('   '));
        $this->assertNull(DuplicateApplicantGuard::canonicalDigits('+-/()'));
    }
}
