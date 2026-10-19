<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sebastian 19.10.2026: Lohn an EINER Stelle sehen — Mindestlohn RG
 * (editierbar) und die EG-1-Stufen (nur lesend, der Tarif steht im Code).
 */
final class SettingsModalLohnBladeTest extends TestCase
{
    private function blade(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/applicant/applicant-settings-modal.blade.php');
    }

    private function reiter(string $name): string
    {
        $b = $this->blade();
        $start = strpos($b, "\$activeTab === '{$name}')");
        $this->assertNotFalse($start, "Reiter {$name} fehlt");
        $ende = strpos($b, '@elseif($activeTab', $start + 1);

        return substr($b, $start, ($ende === false ? strlen($b) : $ende) - $start);
    }

    public function test_mindestlohn_steht_im_reiter_lohnbuchhaltung_und_nicht_mehr_in_allgemein(): void
    {
        $this->assertStringContainsString('wire:model="settings.minimum_wage_hourly"', $this->reiter('payroll'));
        $this->assertStringNotContainsString('minimum_wage_hourly', $this->reiter('general'));
        $this->assertSame(1, substr_count($this->blade(), 'wire:model="settings.minimum_wage_hourly"'), 'genau ein Eingabefeld');
    }

    public function test_eg1_stufen_werden_nur_lesend_gezeigt(): void
    {
        $payroll = $this->reiter('payroll');
        $this->assertStringContainsString('EntgeltgruppeEins::STUFEN', $payroll);
        $this->assertStringContainsString('EntgeltgruppeEins::gueltigAb', $payroll);
        $this->assertStringContainsString('Entgeltgruppe 1', $payroll);
        $this->assertStringNotContainsString('wire:model="settings.eg1', $payroll, 'kein editierbarer EG-1-Wert');
    }
}
