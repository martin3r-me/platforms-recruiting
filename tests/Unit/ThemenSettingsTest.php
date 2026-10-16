<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Applicant\ApplicantSettingsModal;
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
        // Ausgefuehrt statt Quelltext-Suche (Schlussreview m5); das Speichern
        // an der echten Komponente prueft SettingsModalThemenTest.
        $r = ApplicantSettingsModal::themenEinstellungen([
            'comms_topic_enabled' => 'false',
            'comms_topic_quiet_hours' => 'abc',
            'comms_topic_lohn_url' => '',
        ]);
        $this->assertFalse($r['settings']['comms_topic_enabled']);
        $this->assertSame(8, $r['settings']['comms_topic_quiet_hours']);
        $this->assertNull($r['settings']['comms_topic_lohn_url']);
        $this->assertSame([], $r['fehler']);

        $r = ApplicantSettingsModal::themenEinstellungen(['comms_topic_enabled' => '1', 'comms_topic_quiet_hours' => 99]);
        $this->assertTrue($r['settings']['comms_topic_enabled']);
        $this->assertSame(48, $r['settings']['comms_topic_quiet_hours']);
    }
}
