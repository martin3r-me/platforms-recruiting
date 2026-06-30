<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\LegalStatusGate;

class LegalStatusGateTest extends TestCase
{
    public function test_no_legal_status_record_is_not_blocked(): void
    {
        // Bestands-Bewerber ohne legalStatus-Record → eu_burger nie beantwortet.
        // Nicht blockieren, sonst Versand-/Reminder-Regression.
        $this->assertFalse(LegalStatusGate::isUnchecked(false, null, false));
        $this->assertFalse(LegalStatusGate::isUnchecked(false, true, false));
    }

    public function test_eu_citizen_is_never_blocked(): void
    {
        // EU-Buerger brauchen keine HR-Pruefung — egal ob geprueft-Flag gesetzt.
        $this->assertFalse(LegalStatusGate::isUnchecked(true, true, false));
        $this->assertFalse(LegalStatusGate::isUnchecked(true, true, true));
    }

    public function test_non_eu_unchecked_is_blocked(): void
    {
        // Nicht-EU + noch nicht von HR geprueft → blockieren.
        $this->assertTrue(LegalStatusGate::isUnchecked(true, false, false));
    }

    public function test_non_eu_checked_is_not_blocked(): void
    {
        // Nicht-EU, aber HR hat geprueft → freigegeben.
        $this->assertFalse(LegalStatusGate::isUnchecked(true, false, true));
    }

    public function test_unanswered_eu_question_is_blocked_until_checked(): void
    {
        // is_eu_citizen=null (Frage offen) → wie nicht-EU behandeln: blockieren
        // solange ungeprueft, freigeben sobald HR geprueft hat.
        $this->assertTrue(LegalStatusGate::isUnchecked(true, null, false));
        $this->assertFalse(LegalStatusGate::isUnchecked(true, null, true));
    }
}
