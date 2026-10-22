<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PersonDatensatz;

/** Spec 2026-10-22 §3: eigener Wert, sonst juengste aktive Schwester. */
final class PersonDatensatzTest extends TestCase
{
    public function test_eigener_wert_gewinnt(): void
    {
        $this->assertSame('deutsch', PersonDatensatz::wert('nationality', 'deutsch', ['polnisch']));
    }

    public function test_leer_nimmt_die_erste_gefuellte_schwester(): void
    {
        $this->assertSame('polnisch', PersonDatensatz::wert('nationality', null, [null, '', 'polnisch', 'italienisch']));
    }

    public function test_false_ist_gefuellt(): void
    {
        $this->assertFalse(PersonDatensatz::wert('is_eu_citizen', null, [false, true]));
        $this->assertFalse(PersonDatensatz::wert('is_first_aider', false, [true]));
    }

    public function test_je_gesellschaft_wird_nie_ergaenzt(): void
    {
        $this->assertNull(PersonDatensatz::wert('is_main_employer', null, [true]));
        $this->assertNull(PersonDatensatz::wert('tax_class', null, ['1']));
    }

    public function test_feld_ausserhalb_der_personenfelder_wird_nie_ergaenzt(): void
    {
        $this->assertNull(PersonDatensatz::wert('phone', null, ['+49151']));
        $this->assertNull(PersonDatensatz::wert('first_aider_certificate_file_id', null, [55]));
    }

    public function test_keine_schwester_laesst_den_eigenen_wert(): void
    {
        $this->assertSame('', PersonDatensatz::wert('nationality', '', []));
    }
}
