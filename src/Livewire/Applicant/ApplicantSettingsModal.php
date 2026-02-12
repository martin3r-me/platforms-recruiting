<?php

namespace Platform\Recruiting\Livewire\Applicant;

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecApplicantStatus;
use Platform\Recruiting\Models\RecServiceHours;
use Platform\Core\Models\CommsChannel;
use Platform\Core\Models\CommsChannelContext;
use Illuminate\Support\Facades\Auth;

class ApplicantSettingsModal extends Component
{
    public $modalShow = false;
    public $activeTab = 'general';

    public ?RecApplicantSettings $settingsModel = null;
    public array $settings = [];

    public array $availableChannels = [];
    public array $linkedChannelIds = [];

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

        $this->loadAvailableChannels();
        $this->loadLinkedChannels();

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

    public function loadAvailableChannels(): void
    {
        $team = Auth::user()->currentTeam;
        if (!$team) {
            $this->availableChannels = [];
            return;
        }

        $rootTeam = method_exists($team, 'getRootTeam') ? $team->getRootTeam() : $team;

        $this->availableChannels = CommsChannel::query()
            ->where('team_id', $rootTeam->id)
            ->where('type', 'email')
            ->where('is_active', true)
            ->orderBy('sender_identifier')
            ->get()
            ->toArray();
    }

    public function loadLinkedChannels(): void
    {
        if (!$this->settingsModel) {
            $this->linkedChannelIds = [];
            return;
        }

        $this->linkedChannelIds = CommsChannelContext::query()
            ->where('context_model', RecApplicantSettings::class)
            ->where('context_model_id', $this->settingsModel->id)
            ->pluck('comms_channel_id')
            ->toArray();
    }

    public function toggleChannel(int $channelId): void
    {
        if (!$this->settingsModel) {
            return;
        }

        $existing = CommsChannelContext::query()
            ->where('comms_channel_id', $channelId)
            ->where('context_model', RecApplicantSettings::class)
            ->where('context_model_id', $this->settingsModel->id)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            CommsChannelContext::create([
                'comms_channel_id' => $channelId,
                'context_model' => RecApplicantSettings::class,
                'context_model_id' => $this->settingsModel->id,
            ]);
        }

        $this->loadLinkedChannels();
    }

    #[Computed]
    public function availableStatuses()
    {
        return RecApplicantStatus::where('team_id', Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function render()
    {
        return view('recruiting::livewire.applicant.applicant-settings-modal');
    }
}
