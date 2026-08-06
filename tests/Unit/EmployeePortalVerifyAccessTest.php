<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;

/**
 * verifyPortalAccess() — Login-Faktor 2 sind die letzten 4 STELLEN der
 * Ausweisnummer, nicht Ziffern: deutsche Ausweisnummern enthalten
 * Buchstaben (z.B. L01X00T47). Das Frontend darf deshalb weder eine
 * reine Zahlentastatur erzwingen noch auf Ziffern validieren.
 */
class EmployeePortalVerifyAccessTest extends TestCase
{
    private function employee(
        string $idCard = 'L01X00T47',
        string $birth = '1990-04-12',
        bool $active = true,
    ): RecEmployee {
        $e = new RecEmployee();
        // setRawAttributes statt fill: der Date-Cast-SETTER braucht eine
        // DB-Connection (getDateFormat), der Getter fuer Y-m-d-Strings nicht.
        $e->setRawAttributes([
            'is_active'            => $active,
            'birth_date'           => $birth,
            'identity_card_number' => $idCard,
        ]);

        return $e;
    }

    public function test_accepts_letters_in_last4(): void
    {
        $this->assertTrue($this->employee()->verifyPortalAccess('1990-04-12', '0T47'));
    }

    public function test_last4_match_is_case_insensitive(): void
    {
        $this->assertTrue($this->employee()->verifyPortalAccess('1990-04-12', '0t47'));
    }

    public function test_accepts_purely_numeric_last4(): void
    {
        $this->assertTrue(
            $this->employee(idCard: 'T22001234')->verifyPortalAccess('1990-04-12', '1234')
        );
    }

    public function test_whitespace_in_stored_number_is_ignored(): void
    {
        // Erfassung mit Leerzeichen (z.B. "L01X 00T 47") darf den Login nicht brechen.
        $this->assertTrue(
            $this->employee(idCard: 'L01X 00T 47')->verifyPortalAccess('1990-04-12', '0T47')
        );
    }

    public function test_rejects_wrong_last4(): void
    {
        $this->assertFalse($this->employee()->verifyPortalAccess('1990-04-12', '0T48'));
    }

    public function test_rejects_wrong_birth_date(): void
    {
        $this->assertFalse($this->employee()->verifyPortalAccess('1990-04-13', '0T47'));
    }

    public function test_rejects_inactive_employee(): void
    {
        $this->assertFalse($this->employee(active: false)->verifyPortalAccess('1990-04-12', '0T47'));
    }

    public function test_rejects_when_id_card_number_missing(): void
    {
        $this->assertFalse($this->employee(idCard: '')->verifyPortalAccess('1990-04-12', '0T47'));
    }
}
