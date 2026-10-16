<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Themen-Auswahl (WhatsApp): sechs Team-Einstellungen mit Vorgaben, im
 * Einstellungs-Fenster gebunden (rohe Checkbox/Textareas, kein x-ui-Select).
 */
final class ThemenSettingsTest extends TestCase
{
    private const KEYS = [
        'comms_topic_enabled', 'comms_topic_quiet_hours', 'comms_topic_lohn_url',
        'comms_topic_text_ask', 'comms_topic_text_lohn_offen', 'comms_topic_text_lohn_ok',
    ];

    private function blade(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
    }

    public function test_default_settings_haben_die_sechs_keys_mit_vorgaben(): void
    {
        $d = RecApplicantSettings::DEFAULT_SETTINGS;
        foreach (self::KEYS as $k) {
            $this->assertArrayHasKey($k, $d);
        }
        $this->assertFalse($d['comms_topic_enabled']);
        $this->assertSame(8, $d['comms_topic_quiet_hours']);
        $this->assertNull($d['comms_topic_lohn_url']);
    }

    public function test_blade_bindet_alle_sechs_keys(): void
    {
        $blade = $this->blade();
        foreach (self::KEYS as $k) {
            $this->assertStringContainsString('wire:model="settings.' . $k . '"', $blade, $k);
        }
        $this->assertMatchesRegularExpression('~<input type="checkbox"[^>]*wire:model="settings\.comms_topic_enabled"~s', $blade);
        $this->assertSame(3, preg_match_all('~<textarea[^>]*wire:model="settings\.comms_topic_text_~s', $blade));
        $this->assertStringContainsString('{PUNKTE}', $blade);
    }

    public function test_save_begrenzt_ruhezeit_und_normalisiert_den_schalter(): void
    {
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Livewire/Applicant/ApplicantSettingsModal.php');
        $this->assertStringContainsString("begrenze(\$this->settings['comms_topic_quiet_hours'] ?? null, 1, 48, 8)", $quelle);
        $this->assertStringContainsString("FILTER_VALIDATE_BOOLEAN", $quelle);
    }
}
