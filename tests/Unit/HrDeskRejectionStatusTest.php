<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecHrDeskCase;
use Platform\Recruiting\Support\HrDeskRejectionStatus;
use Platform\Recruiting\Support\MinorAgeGate;

final class HrDeskRejectionStatusTest extends TestCase
{
    public function test_jugendschutz_ablehnung_stempelt_konfigurierten_status(): void
    {
        $this->assertSame(1, HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_REVIEW, 1
        ));
        $this->assertSame(1, HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_REJECT, 1
        ));
    }

    public function test_unbekanntes_geburtsdatum_wird_nicht_als_minderjaehrig_gestempelt(): void
    {
        // REASON_MINOR trägt auch "Geburtsdatum fehlt/unplausibel" — ein
        // Erwachsener darf keinen Jugendschutz-Absagestatus bekommen.
        $this->assertNull(HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_UNKNOWN, 1
        ));
        $this->assertNull(HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_PASS, 1
        ));
    }

    public function test_bereits_gesetzter_status_verhindert_den_stempel_nicht(): void
    {
        // Kein "Handauswahl gewinnt"-Guard: der Intake vergibt automatisch den
        // Standard-Status, sonst wäre der Stempel praktisch nie wirksam.
        $this->assertSame(4, HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_REVIEW, 4
        ));
    }

    public function test_andere_fall_typen_bleiben_ungestempelt(): void
    {
        foreach ([
            RecHrDeskCase::REASON_NON_EU_CITIZEN,
            RecHrDeskCase::REASON_NO_GERMAN_KNOWLEDGE,
            RecHrDeskCase::REASON_APPLICANT_CANCELLED_TRAINING,
        ] as $reason) {
            $this->assertNull(HrDeskRejectionStatus::resolve($reason, MinorAgeGate::VERDICT_REVIEW, 1));
        }
    }

    public function test_ohne_konfiguration_kein_stempel(): void
    {
        $this->assertNull(HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_REVIEW, null
        ));
        $this->assertNull(HrDeskRejectionStatus::resolve(
            RecHrDeskCase::REASON_MINOR, MinorAgeGate::VERDICT_REVIEW, 0
        ));
    }
}
