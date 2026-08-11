<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Support\HrDeskRejectionStatus;

final class HrDeskRejectionStatusTest extends TestCase
{
    public function test_jugendschutz_ablehnung_stempelt_konfigurierten_status(): void
    {
        $this->assertSame(1, HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_MINOR, null, 1));
    }

    public function test_andere_fall_typen_bleiben_ungestempelt(): void
    {
        $this->assertNull(HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_NON_EU_CITIZEN, null, 1));
        $this->assertNull(HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE, null, 1));
        $this->assertNull(HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING, null, 1));
    }

    public function test_handauswahl_gewinnt(): void
    {
        $this->assertNull(HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_MINOR, 7, 1));
    }

    public function test_ohne_konfiguration_kein_stempel(): void
    {
        $this->assertNull(HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_MINOR, null, null));
        $this->assertNull(HrDeskRejectionStatus::resolve(RecHrDeskCase::REASON_MINOR, null, 0));
    }
}
