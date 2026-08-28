<?php
// tests/Unit/CampaignSettingsKeysTest.php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Die beiden Kampagnen-Templates haben Default-Keys, damit getSetting() sie
 * kennt und das Einstellungs-Modal sie anbietet. Ohne Default-Eintrag liefert
 * getSetting() zwar auch null — aber der Key waere nirgends dokumentiert.
 */
final class CampaignSettingsKeysTest extends TestCase
{
    public function testKeysSindInDenDefaults(): void
    {
        $this->assertArrayHasKey('campaign_form_wa_template_id', RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertArrayHasKey('campaign_booking_wa_template_id', RecApplicantSettings::DEFAULT_SETTINGS);
        $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS['campaign_form_wa_template_id']);
        $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS['campaign_booking_wa_template_id']);
    }

    public function testModalBietetBeideSelects(): void
    {
        $blade = file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
        $this->assertStringContainsString('settings.campaign_form_wa_template_id', $blade);
        $this->assertStringContainsString('settings.campaign_booking_wa_template_id', $blade);
    }
}
