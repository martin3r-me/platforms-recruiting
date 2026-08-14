<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoConfirmationSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoRecipientPlanner;

/**
 * Disposition → Veranstaltung → Detail: VA-Kopf + Einbuchungen mit
 * Zuordnungs-Status. Hier kommt in Step 2 (Bestaetigungs-Flow) der
 * Sende-Button hin.
 */
class Show extends Component
{
    public int $eventId;

    public bool $showSendModal = false;
    public string $vorlaufMinuten = '';
    public bool $includeReminders = false;
    /** @var array{sent:int, failed:list<array{employee_id:int, error:string}>}|null */
    public ?array $sendResult = null;

    public function mount(int $eventId): void
    {
        $this->eventId = $eventId;
    }

    #[Computed]
    public function event(): RecDispoEvent
    {
        return RecDispoEvent::query()
            ->with(['assignments' => fn ($q) => $q->with(['employee', 'reminderMessage'])->orderBy('datum')->orderBy('von')])
            ->findOrFail($this->eventId);
    }

    #[Computed]
    public function dispoSettings(): array
    {
        $settings = RecApplicantSettings::getOrCreateForTeam(auth()->user()->currentTeam->id);

        return [
            'template_id'    => $settings->getSetting('dispo_confirmation_template_id') ? (int) $settings->getSetting('dispo_confirmation_template_id') : null,
            'deadline_hours' => (int) ($settings->getSetting('dispo_deadline_hours') ?? 4),
        ];
    }

    #[Computed]
    public function sendPreview(): array
    {
        $assignments = $this->event->assignments->map(fn ($a) => [
            'id'                 => $a->id,
            'employee_id'        => $a->rec_employee_id,
            'status_id'          => $a->status_id,
            'confirmed_at'       => $a->confirmed_at?->toDateTimeString(),
            'reminder_sent_at'   => $a->reminder_sent_at?->toDateTimeString(),
            'missing_since'      => $a->missing_since?->toDateTimeString(),
            'deletion_marked_at' => $a->deletion_marked_at?->toDateTimeString(),
            'datum'              => $a->datum->format('Y-m-d'),
        ])->all();

        $employeeIds = array_values(array_unique(array_filter(array_column($assignments, 'employee_id'))));
        $phones = app(DispoEmployeeGateway::class)->phones($employeeIds);

        return (new DispoRecipientPlanner())
            ->plan($assignments, $phones, $this->includeReminders);
    }

    public function openSendModal(): void
    {
        $this->vorlaufMinuten = (string) ($this->event->vorlauf_minuten ?? '');
        $this->includeReminders = false;
        $this->sendResult = null;
        $this->showSendModal = true;
    }

    public function sendConfirmations(): void
    {
        $this->validate(['vorlaufMinuten' => 'required|integer|min:0|max:480'], [], ['vorlaufMinuten' => 'Vorlaufzeit']);

        $templateId = $this->dispoSettings['template_id'];
        if ($templateId === null) {
            $this->addError('vorlaufMinuten', 'Kein Bestätigungs-Template konfiguriert (Disposition → Einstellungen).');
            return;
        }

        $event = RecDispoEvent::findOrFail($this->eventId);
        $event->update(['vorlauf_minuten' => (int) $this->vorlaufMinuten]);

        $preview = $this->sendPreview;
        $result = app(DispoConfirmationSender::class)
            ->send($event, $preview['recipients'], $templateId);

        if (!$result['ok']) {
            $this->addError('vorlaufMinuten', (string) $result['message']);
            return;
        }

        $this->sendResult = ['sent' => $result['sent'], 'failed' => $result['failed']];
        unset($this->event, $this->sendPreview); // Computed-Caches invalidieren
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.events.show')
            ->layout('platform::layouts.app');
    }
}
