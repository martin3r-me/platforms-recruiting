<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecServiceHours;
use Illuminate\Support\Facades\Auth;

class ApplicantSettingsModal extends Component
{
    public $modalShow = false;
    public $activeTab = 'general';

    public ?RecApplicantSettings $settingsModel = null;
    public array $settings = [];

    public $serviceHours = [];
    public $showServiceHoursForm = false;
    public array $teamUsers = [];
    public array $newServiceZeit = [
        'name' => '',
        'description' => '',
        'is_active' => true,
        'use_auto_messages' => false,
        'auto_message_inside' => '',
        'auto_message_outside' => '',
        'service_hours' => []
    ];

    #[On('open-applicant-settings')]
    public function openSettings(): void
    {
        $teamId = Auth::user()->currentTeam->id;
        $this->settingsModel = RecApplicantSettings::getOrCreateForTeam($teamId);
        $this->settings = $this->settingsModel->settings ?? RecApplicantSettings::DEFAULT_SETTINGS;

        $this->teamUsers = Auth::user()->currentTeam->users()->orderBy('name')->get()->toArray();

        $this->serviceHours = $this->settingsModel->serviceHours()->orderBy('order')->get();
        $this->newServiceZeit['service_hours'] = RecServiceHours::getDefaultServiceHours();

        $this->activeTab = 'general';
        $this->modalShow = true;
    }

    public function save(): void
    {
        // Validate: auto_start requires both WA templates
        if (!empty($this->settings['auto_start_auto_pilot'])) {
            if (empty($this->settings['auto_pilot_wa_initial_template_id']) || empty($this->settings['auto_pilot_wa_reminder_template_id'])) {
                $this->settings['auto_start_auto_pilot'] = false;
                $this->addError('settings.auto_start_auto_pilot', 'Beide WhatsApp-Templates müssen konfiguriert sein, um Auto-Start zu aktivieren.');
                return;
            }
        }

        $this->settingsModel->settings = $this->settings;
        $this->settingsModel->save();
        $this->modalShow = false;
    }

    public function addServiceHours(): void
    {
        $serviceHours = new RecServiceHours();
        $serviceHours->rec_applicant_settings_id = $this->settingsModel->id;
        $serviceHours->name = $this->newServiceZeit['name'];
        $serviceHours->description = $this->newServiceZeit['description'];
        $serviceHours->is_active = $this->newServiceZeit['is_active'];
        $serviceHours->use_auto_messages = $this->newServiceZeit['use_auto_messages'];
        $serviceHours->auto_message_inside = $this->newServiceZeit['auto_message_inside'];
        $serviceHours->auto_message_outside = $this->newServiceZeit['auto_message_outside'];
        $serviceHours->service_hours = $this->newServiceZeit['service_hours'];
        $serviceHours->order = $this->settingsModel->serviceHours()->count();
        $serviceHours->save();

        $this->serviceHours = $this->settingsModel->serviceHours()->orderBy('order')->get();
        $this->newServiceZeit = [
            'name' => '',
            'description' => '',
            'is_active' => true,
            'use_auto_messages' => false,
            'auto_message_inside' => '',
            'auto_message_outside' => '',
            'service_hours' => RecServiceHours::getDefaultServiceHours(),
        ];
        $this->showServiceHoursForm = false;
    }

    public function deleteServiceHours($serviceHoursId): void
    {
        $serviceHours = RecServiceHours::find($serviceHoursId);
        if ($serviceHours && $serviceHours->rec_applicant_settings_id == $this->settingsModel->id) {
            $serviceHours->delete();
            $this->serviceHours = $this->settingsModel->serviceHours()->orderBy('order')->get();
        }
    }

    public function toggleServiceHoursForm(): void
    {
        $this->showServiceHoursForm = !$this->showServiceHoursForm;
    }

    #[Computed]
    public function availableStatuses()
    {
        return RecApplicantStatus::where('team_id', Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function availableWhatsAppTemplates(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }

        $query = \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->with('whatsappAccount:id,title,phone_number')
            ->where('status', 'APPROVED');

        $accountId = $this->settings['auto_pilot_wa_account_id'] ?? null;
        if ($accountId) {
            $query->where('whatsapp_account_id', (int) $accountId);
        }

        return $query->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "{$t->name} ({$t->language})" . (!$accountId && $t->whatsappAccount ? " — {$t->whatsappAccount->title}" : ''),
            ])
            ->toArray();
    }

    #[Computed]
    public function availableWhatsAppAccounts(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppAccount::class)) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppAccount::query()
            ->withCount('templates')
            ->orderBy('title')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'label' => "{$a->title} ({$a->phone_number})",
                'active' => $a->active,
                'templates_count' => $a->templates_count,
            ])
            ->toArray();
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.applicant-settings-modal');
    }
}
