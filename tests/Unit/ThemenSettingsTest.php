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

    public function test_pilot_sperre_vorgabe_an_checkbox_und_normalisierung(): void
    {
        $this->assertTrue(RecApplicantSettings::DEFAULT_SETTINGS['comms_topic_nur_pilot']);
        $this->assertMatchesRegularExpression('~<input type="checkbox"[^>]*wire:model="settings\.comms_topic_nur_pilot"~s', $this->blade());
        $this->assertStringContainsString('Nur für die Pilotgruppe (Mitarbeiter mit neuem Portal)', $this->blade());
        $this->assertStringContainsString('Aus = alle aktiven Mitarbeiter des Teams', $this->blade());

        $this->assertFalse(ApplicantSettingsModal::themenEinstellungen(['comms_topic_nur_pilot' => 'false'])['settings']['comms_topic_nur_pilot']);
        $this->assertFalse(ApplicantSettingsModal::themenEinstellungen(['comms_topic_nur_pilot' => false])['settings']['comms_topic_nur_pilot']);
        $this->assertTrue(ApplicantSettingsModal::themenEinstellungen(['comms_topic_nur_pilot' => '1'])['settings']['comms_topic_nur_pilot']);
        // Fehlt der Schluessel, bleibt die Sperre an — Speichern oeffnet nichts.
        $this->assertTrue(ApplicantSettingsModal::themenEinstellungen([])['settings']['comms_topic_nur_pilot']);
        // Fail-closed (Review Fix-Runde 1): Unlesbares bleibt an.
        foreach (['abc', '', '  ', 'ja?', [], 2] as $unlesbar) {
            $this->assertTrue(ApplicantSettingsModal::themenEinstellungen(['comms_topic_nur_pilot' => $unlesbar])['settings']['comms_topic_nur_pilot'], var_export($unlesbar, true));
        }
    }

    public function test_pilot_schalter_lesart_ist_fail_closed(): void
    {
        foreach ([false, 0, '0', 'false', 'FALSE', ' off ', 'no'] as $aus) {
            $this->assertFalse(\Platform\Recruiting\Support\PilotKreis::sperreAn($aus), var_export($aus, true));
        }
        foreach ([true, 1, '1', 'true', 'on', null, '', 'abc', [], 2] as $an) {
            $this->assertTrue(\Platform\Recruiting\Support\PilotKreis::sperreAn($an), var_export($an, true));
        }
        // Fenster und Einsatz-Pruefung lesen mit DERSELBEN Stelle.
        $quelle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/EinsatzPruefung.php');
        $this->assertStringContainsString('PilotKreis::sperreAn($settings[PortalVorlagen::EINSATZ_PRUEFUNG_NUR_PILOT] ?? null)', $quelle);
    }

    public function test_vorlagen_editor_bietet_die_lohn_quellen_an(): void
    {
        $blade = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/contract-templates/index.blade.php');
        $this->assertMatchesRegularExpression("~'Lohn' => \\[\\s*'lohn\\.eg1' => 'Grundlohn MA – Entgeltgruppe 1 zum Vertragsbeginn',\\s*'contract\\.extra_field\\.zuschlag' => 'Zuschlag aus dem Vertrag',~u", $blade);
    }
}
