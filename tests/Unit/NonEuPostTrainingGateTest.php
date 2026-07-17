<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\NonEuPostTrainingGate;

class NonEuPostTrainingGateTest extends TestCase
{
    public function test_nicht_eu_ungeprueft_bei_transition_zu_attended_routet(): void
    {
        $this->assertTrue(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, false, false));
    }

    public function test_null_zu_attended_routet(): void
    {
        // Frisch angelegte Buchung direkt als attended (Signatur lässt es zu).
        $this->assertTrue(NonEuPostTrainingGate::shouldRoute(null, 'attended', true, false, false));
    }

    public function test_eu_status_unbeantwortet_mit_datensatz_routet(): void
    {
        $this->assertTrue(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, null, false));
    }

    public function test_attended_zu_attended_feuert_nicht_erneut(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('attended', 'attended', true, false, false));
    }

    public function test_eu_buerger_routet_nie(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, true, false));
    }

    public function test_geprueft_routet_nicht(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', true, false, true));
    }

    public function test_ohne_legalstatus_datensatz_routet_nicht(): void
    {
        // Bestandsbewerber ohne Phase-3-Antwort — Konvention wie LegalStatusGate.
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'attended', false, null, false));
    }

    public function test_andere_zielstatus_routen_nicht(): void
    {
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('confirmed', 'no_show', true, false, false));
        $this->assertFalse(NonEuPostTrainingGate::shouldRoute('attended', 'cancelled', true, false, false));
    }
}
