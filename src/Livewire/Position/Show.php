<?php

namespace Platform\Recruiting\Livewire\Position;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Platform\Hcm\Models\HcmJobTitle;
use Platform\Recruiting\Models\RecPhase;
use Platform\Recruiting\Models\RecPosition;

class Show extends Component
{
    public RecPosition $position;
    public array $autoPilotSettings = [];
    public array $autoPilotSettingsOriginal = [];
    public array $phaseAutoPilotSettings = [];
    public array $phaseAutoPilotSettingsOriginal = [];

    public function mount(RecPosition $position)
    {
        $this->position = $position->load(['postings', 'ownedByUser', 'createdByUser', 'jobTitle', 'phases']);
        $this->autoPilotSettings = ($this->position->auto_pilot_settings ?? []) + $this->positionSettingsDefaults();
        $this->autoPilotSettingsOriginal = $this->autoPilotSettings;

        // Load phase-level AutoPilot settings
        foreach ($this->position->phases as $phase) {
            $this->phaseAutoPilotSettings[$phase->id] = ($phase->auto_pilot_settings ?? []) + $this->phaseSettingsDefaults();
        }
        $this->phaseAutoPilotSettingsOriginal = $this->phaseAutoPilotSettings;
    }

    /**
     * Default-Schlüssel für position-level autoPilotSettings — alle Felder
     * die im UI per wire:model gebunden sind müssen mindestens als null
     * existieren, damit Livewire-Entangle nicht meckert wenn die Position
     * (oder eine ihrer Phasen) noch keine Settings hat.
     *
     * Existierende Werte werden NICHT überschrieben — der Array-Union-Operator
     * bevorzugt linke Seite. Beim Speichern werden null-Werte ohnehin
     * rausgefiltert (siehe save()), also kein Effekt auf die DB.
     */
    private function positionSettingsDefaults(): array
    {
        return [
            'auto_pilot_enabled' => null,
            'auto_pilot_channel_priority' => null,
            'auto_pilot_wa_account_id' => null,
            'auto_pilot_wa_initial_template_id' => null,
            'auto_pilot_wa_reminder_template_id' => null,
            'auto_pilot_reminder_interval_hours' => null,
            'auto_pilot_max_reminders' => null,
            'auto_start_auto_pilot' => null,
            'interview_booking_wa_template_id' => null,
        ];
    }

    private function phaseSettingsDefaults(): array
    {
        return [
            'auto_pilot_wa_initial_template_id' => null,
            'auto_pilot_wa_reminder_template_id' => null,
        ];
    }

    public function rules(): array
    {
        return [
            'position.title' => 'required|string|max:255',
            'position.description' => 'nullable|string',
            'position.department' => 'nullable|string|max:255',
            'position.location' => 'nullable|string|max:255',
            'position.hcm_job_title_id' => 'nullable|exists:hcm_job_titles,id',
            'position.is_active' => 'boolean',
            'position.owned_by_user_id' => 'nullable|exists:users,id',
            'position.cost_center' => 'nullable|integer|min:0',
            'autoPilotSettings.auto_pilot_enabled' => 'nullable|boolean',
            'autoPilotSettings.auto_pilot_channel_priority' => 'nullable|string|in:whatsapp_first,email_first,whatsapp_only,email_only',
            'autoPilotSettings.auto_pilot_wa_account_id' => 'nullable|integer',
            'autoPilotSettings.auto_pilot_wa_initial_template_id' => 'nullable|integer',
            'autoPilotSettings.auto_pilot_wa_reminder_template_id' => 'nullable|integer',
            'autoPilotSettings.auto_pilot_reminder_interval_hours' => 'nullable|integer|min:1|max:168',
            'autoPilotSettings.auto_pilot_max_reminders' => 'nullable|integer|min:1|max:10',
            'autoPilotSettings.auto_start_auto_pilot' => 'nullable|boolean',
            'autoPilotSettings.interview_booking_wa_template_id' => 'nullable|integer',
            'phaseAutoPilotSettings.*.auto_pilot_wa_initial_template_id' => 'nullable|integer',
            'phaseAutoPilotSettings.*.auto_pilot_wa_reminder_template_id' => 'nullable|integer',
        ];
    }

    public function updatedPosition($value, $key): void
    {
        // Convert empty strings to null for nullable foreign key fields
        // (HTML selects send "" for null options, but validation needs null)
        if ($value === '' && in_array($key, ['hcm_job_title_id', 'owned_by_user_id'])) {
            $this->position->{$key} = null;
        }
    }

    public function save(): void
    {
        $this->validate();

        // Clean autoPilotSettings: remove null/empty values, cast numeric strings to int
        $cleaned = collect($this->autoPilotSettings)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => is_numeric($value) ? (int) $value : $value)
            ->all();

        // Validate: if auto_start is set to true, effective templates must exist
        if (!empty($cleaned['auto_start_auto_pilot'])) {
            $teamSettings = \Platform\Recruiting\Models\RecApplicantSettings::getOrCreateForTeam($this->position->team_id);
            $initialTemplate = $cleaned['auto_pilot_wa_initial_template_id']
                ?? $teamSettings->getSetting('auto_pilot_wa_initial_template_id');
            $reminderTemplate = $cleaned['auto_pilot_wa_reminder_template_id']
                ?? $teamSettings->getSetting('auto_pilot_wa_reminder_template_id');

            if (empty($initialTemplate) || empty($reminderTemplate)) {
                $cleaned['auto_start_auto_pilot'] = false;
                $this->autoPilotSettings['auto_start_auto_pilot'] = false;
                $this->addError('autoPilotSettings.auto_start_auto_pilot', 'Beide WhatsApp-Templates müssen konfiguriert sein (Team oder Position), um Auto-Start zu aktivieren.');
                return;
            }
        }

        $this->position->auto_pilot_settings = !empty($cleaned) ? $cleaned : null;
        $this->position->save();
        $this->autoPilotSettingsOriginal = $this->autoPilotSettings;

        // Save phase-level AutoPilot settings
        foreach ($this->phaseAutoPilotSettings as $phaseId => $settings) {
            $phase = RecPhase::find($phaseId);
            if (!$phase || (int)$phase->rec_position_id !== (int)$this->position->id) {
                continue;
            }
            $cleanedPhase = collect($settings)
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->map(fn ($value) => is_numeric($value) ? (int) $value : $value)
                ->all();
            $phase->auto_pilot_settings = !empty($cleanedPhase) ? $cleanedPhase : null;
            $phase->save();
        }
        $this->phaseAutoPilotSettingsOriginal = $this->phaseAutoPilotSettings;

        session()->flash('message', 'Stelle erfolgreich aktualisiert.');
    }

    public function clearAutoPilotSetting(string $key): void
    {
        unset($this->autoPilotSettings[$key]);
    }

    public function clearPhaseAutoPilotSetting(int $phaseId, string $key): void
    {
        unset($this->phaseAutoPilotSettings[$phaseId][$key]);
    }

    public function deletePosition(): void
    {
        $this->position->delete();
        session()->flash('message', 'Stelle erfolgreich gelöscht.');
        $this->redirect(route('recruiting.positions.index'), navigate: true);
    }

    #[Computed]
    public function teamUsers()
    {
        return Auth::user()
            ->currentTeam
            ->users()
            ->orderBy('name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->fullname ?? $user->name,
            ]);
    }

    #[Computed]
    public function jobTitles()
    {
        return HcmJobTitle::where('team_id', Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn ($jt) => [
                'id' => $jt->id,
                'name' => $jt->name,
            ]);
    }

    #[Computed]
    public function availableWhatsAppAccounts()
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return collect();
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppAccount::query()
            ->withCount('templates')
            ->orderBy('title')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'label' => "{$a->title} ({$a->phone_number})",
            ]);
    }

    #[Computed]
    public function availableWhatsAppTemplates()
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return collect();
        }

        $query = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->with('whatsappAccount:id,title,phone_number')
            ->where('status', 'APPROVED');

        $accountId = $this->autoPilotSettings['auto_pilot_wa_account_id'] ?? null;
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "{$t->name} ({$t->language})" . (!$accountId && $t->whatsappAccount ? " — {$t->whatsappAccount->title}" : ''),
            ]);
    }

    public function checkIsDirty(): bool
    {
        return $this->position->isDirty() || $this->autoPilotSettings !== $this->autoPilotSettingsOriginal || $this->phaseAutoPilotSettings !== $this->phaseAutoPilotSettingsOriginal;
    }

    #[Computed]
    public function phases()
    {
        return $this->position->phases()->active()->orderBy('order')->get();
    }

    public function render()
    {
        return view('recruiting::livewire.position.show')
            ->layout('platform::layouts.app');
    }
}
