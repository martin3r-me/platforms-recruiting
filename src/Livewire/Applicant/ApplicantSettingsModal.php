<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecServiceHours;
use Platform\Crm\Models\CommsChannel;
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
        $teamId = Auth::user()->currentTeam->id;

        $waChannel = CommsChannel::where('team_id', $teamId)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if (!$waChannel) {
            return [];
        }

        $accountId = $waChannel->meta['integrations_whatsapp_account_id'] ?? null;
        if (!$accountId) {
            return [];
        }

        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->where('whatsapp_account_id', $accountId)
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "{$t->name} ({$t->language})",
            ])
            ->toArray();
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.applicant-settings-modal');
    }
}
