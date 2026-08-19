<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;

/**
 * Disposition → Einstellungen (getrennt von den Bewerber-Einstellungen).
 *
 * PITFALL-AUFLAGE (Spec): schlichte Selects/Inputs mit wire:model +
 * explizitem Speichern-Button — NICHT x-ui-input-select + @entangle
 * (bekannter Speicher-Bug).
 */
class Settings extends Component
{
    public string $templateId = '';
    public string $deadlineHours = '4';
    public bool $saved = false;

    public function mount(): void
    {
        // dispo_*-Settings haengen am ZAS-Anker-Team, damit Public-Seite/Scheduler
        // dieselben Werte lesen; Fallback currentTeam wenn unkonfiguriert.
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $this->templateId    = (string) ($settings->getSetting('dispo_confirmation_template_id') ?? '');
        $this->deadlineHours = (string) ((int) ($settings->getSetting('dispo_deadline_hours') ?? 4));
    }

    #[Computed]
    public function templates(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['id', 'name', 'whatsapp_account_id'])
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name])
            ->all();
    }

    public function save(): void
    {
        $this->validate([
            'templateId'    => 'nullable|string|max:20',
            'deadlineHours' => 'required|integer|min:1|max:72',
        ]);

        // dispo_*-Settings haengen am ZAS-Anker-Team, damit Public-Seite/Scheduler
        // dieselben Werte lesen; Fallback currentTeam wenn unkonfiguriert.
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        // Konvertiere templateId-String zu Int oder null
        $templateId = ($this->templateId !== '' && ctype_digit($this->templateId)) ? (int) $this->templateId : null;
        $settings->setSetting('dispo_confirmation_template_id', $templateId);

        $settings->setSetting('dispo_deadline_hours', (int) $this->deadlineHours);
        $settings->save();

        $this->saved = true;
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.settings')
            ->layout('platform::layouts.app');
    }
}
