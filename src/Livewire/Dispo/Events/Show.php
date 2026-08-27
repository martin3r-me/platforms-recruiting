<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoConfirmationSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoRecipientPlanner;
use Platform\Recruiting\Services\Zas\Dispo\DispoTeamLeadResolver;

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
    public string $ansprechpartner = '';
    public bool $includeReminders = false;
    /** Auswahl bei Mehrtages-VA: leer = alle Tage, sonst Y-m-d. */
    public string $sendDay = '';
    /** @var array{sent:int, failed:list<array{employee_id:int, error:string}>}|null */
    public ?array $sendResult = null;
    /** Auswahl bei mehreren Teamleitungen (employee_id als String — Livewire-Typed-Property-Falle). */
    public string $leadChoice = '';
    /** true, wenn das Ansprechpartner-Feld beim Oeffnen automatisch aus der Teamleitung befuellt wurde. */
    public bool $leadAutoFilled = false;

    /** Individueller Hinweis pro Mitarbeiter, keyed by rec_employee_id → Text. */
    public array $notes = [];

    // Hinweis-Modal (komfortables Bearbeiten statt engem Inline-Feld).
    public bool $showNoteModal = false;
    public ?int $noteEmployeeId = null;
    public string $noteEmployeeName = '';
    public string $noteDraft = '';

    public function mount(int $eventId): void
    {
        $this->eventId = $eventId;
        $this->loadNotes();
    }

    /** Vorbelegung von $notes aus dem ersten nicht-leeren individual_note pro MA. */
    private function loadNotes(): void
    {
        foreach ($this->event->assignments as $assignment) {
            $employeeId = $assignment->rec_employee_id;
            if ($employeeId === null) {
                continue;
            }
            $this->notes[$employeeId] ??= '';
            if ($this->notes[$employeeId] === '' && !empty($assignment->individual_note)) {
                $this->notes[$employeeId] = $assignment->individual_note;
            }
        }
    }

    /** Schreibt den Hinweis auf ALLE Einbuchungen dieses MA fuer diese VA. */
    public function saveNote(int $employeeId): void
    {
        $value = trim((string) ($this->notes[$employeeId] ?? ''));
        $this->notes[$employeeId] = $value;

        RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $this->eventId)
            ->where('rec_employee_id', $employeeId)
            ->update(['individual_note' => $value !== '' ? $value : null]);

        unset($this->event); // Computed-Cache invalidieren
    }

    /** Oeffnet das Hinweis-Modal fuer einen (gematchten) Mitarbeiter. */
    public function openNote(int $employeeId): void
    {
        $this->noteEmployeeId = $employeeId;
        $this->noteDraft = (string) ($this->notes[$employeeId] ?? '');

        $employee = $this->event->assignments->firstWhere('rec_employee_id', $employeeId)?->employee;
        $this->noteEmployeeName = $employee ? trim($employee->first_name . ' ' . $employee->last_name) : '';

        $this->showNoteModal = true;
    }

    /** Uebernimmt den Modal-Entwurf und speichert ihn (nur bei gesetzter MA). */
    public function saveNoteFromModal(): void
    {
        if ($this->noteEmployeeId === null) {
            return;
        }

        $this->notes[$this->noteEmployeeId] = $this->noteDraft;
        $this->saveNote($this->noteEmployeeId);
        $this->closeNoteModal();
    }

    public function closeNoteModal(): void
    {
        $this->showNoteModal = false;
        $this->noteEmployeeId = null;
        $this->noteEmployeeName = '';
        $this->noteDraft = '';
    }

    #[Computed]
    public function event(): RecDispoEvent
    {
        return RecDispoEvent::query()
            ->with([
                'alarmMessage',
                'assignments' => fn ($q) => $q->with(['employee', 'reminderMessage', 'escalation1Message', 'escalation2Message'])->orderBy('datum')->orderBy('von'),
            ])
            ->findOrFail($this->eventId);
    }

    #[Computed]
    public function dispoSettings(): array
    {
        // dispo_*-Settings haengen am ZAS-Anker-Team, damit Public-Seite/Scheduler
        // dieselben Werte lesen; Fallback currentTeam wenn unkonfiguriert.
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);

        return [
            'template_id' => $settings->getSetting('dispo_confirmation_template_id') ? (int) $settings->getSetting('dispo_confirmation_template_id') : null,
        ];
    }

    /**
     * Distinkte kommende Einsatztage dieser VA (Y-m-d, sortiert). Nur bei
     * Mehrtages-VA (> 1) im Sende-Modal relevant — siehe Blade.
     *
     * @return list<string>
     */
    #[Computed]
    public function eventDays(): array
    {
        $today = now()->toDateString();

        return $this->event->assignments
            ->map(fn ($a) => $a->datum->format('Y-m-d'))
            ->filter(fn ($d) => $d >= $today)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Teamleitungen dieser VA (Kunden-Feedback 2): Kandidaten fuer die automatische
     * Ansprechpartner-Vorbelegung. Respektiert die Tages-Auswahl des Sende-Modals.
     *
     * @return list<array{employee_id:int, name:string, phone:?string, label:string}>
     */
    #[Computed]
    public function teamLeads(): array
    {
        $rows = $this->event->assignments->map(fn ($a) => [
            'employee_id' => $a->rec_employee_id,
            'taetigkeit'  => $a->taetigkeit,
            'datum'       => $a->datum->format('Y-m-d'),
        ])->all();
        $ids = array_values(array_unique(array_filter(array_column($rows, 'employee_id'))));
        $contacts = $ids === [] ? [] : app(DispoEmployeeGateway::class)->contacts($ids);

        return (new DispoTeamLeadResolver())->resolve(
            $rows,
            $contacts,
            (array) config('recruiting.zas.dispo_lead_taetigkeiten', ['Teamleitung']),
            $this->sendDay
        );
    }

    /** Uebernimmt eine Teamleitung (Button/Select) ins Ansprechpartner-Feld. */
    public function applyLead(?int $employeeId = null): void
    {
        $employeeId ??= ($this->leadChoice !== '' ? (int) $this->leadChoice : null);
        foreach ($this->teamLeads as $lead) {
            if ($employeeId !== null && $lead['employee_id'] === $employeeId) {
                $this->ansprechpartner = $lead['label'];
                $this->leadChoice = (string) $employeeId;
                $this->leadAutoFilled = false;
                return;
            }
        }
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

        // Tages-Auswahl (Mehrtages-VA): VOR der Vergangenheits-Filterung
        // anwenden, damit "past"-Zaehlung nur den gewaehlten Tag betrifft.
        if ($this->sendDay !== '') {
            $assignments = array_values(array_filter(
                $assignments,
                fn ($a) => $a['datum'] === $this->sendDay
            ));
        }

        // Vergangene Einsatztage nie anschreiben (Public-Seite/confirm() filtern
        // ebenfalls datum >= heute) — nichts wird still uebersprungen, daher zaehlen.
        $today = now()->toDateString();
        $pastCount = 0;
        $upcoming = [];
        foreach ($assignments as $assignment) {
            if ($assignment['datum'] < $today) {
                $pastCount++;
                continue;
            }
            $upcoming[] = $assignment;
        }

        $employeeIds = array_values(array_unique(array_filter(array_column($upcoming, 'employee_id'))));
        $phones = app(DispoEmployeeGateway::class)->phones($employeeIds);

        $result = (new DispoRecipientPlanner())
            ->plan($upcoming, $phones, $this->includeReminders);

        if ($pastCount > 0) {
            $result['skipped']['past'] = $pastCount;
        }

        return $result;
    }

    public function openSendModal(): void
    {
        $this->vorlaufMinuten = (string) ($this->event->vorlauf_minuten ?? '');
        $this->ansprechpartner = (string) ($this->event->ansprechpartner ?? '');
        $this->includeReminders = false;
        $this->sendDay = '';
        $this->sendResult = null;

        // Teamleitung als Ansprechpartner vorbelegen — NUR wenn das Feld leer ist
        // (gespeicherte manuelle Eingabe wird nie ueberschrieben).
        $this->leadChoice = '';
        $this->leadAutoFilled = false;
        $leads = $this->teamLeads;
        if ($this->ansprechpartner === '' && $leads !== []) {
            $this->leadChoice = (string) $leads[0]['employee_id'];
            $this->ansprechpartner = $leads[0]['label'];
            $this->leadAutoFilled = true;
        }

        $this->showSendModal = true;
    }

    public function sendConfirmations(): void
    {
        $this->validate([
            'vorlaufMinuten'  => 'required|integer|min:0|max:480',
            'ansprechpartner' => 'nullable|string|max:255',
        ], [], ['vorlaufMinuten' => 'Vorlaufzeit']);

        $templateId = $this->dispoSettings['template_id'];
        if ($templateId === null) {
            $this->addError('vorlaufMinuten', 'Kein Bestätigungs-Template konfiguriert (Disposition → Einstellungen).');
            return;
        }

        $event = RecDispoEvent::findOrFail($this->eventId);
        $event->update([
            'vorlauf_minuten' => (int) $this->vorlaufMinuten,
            'ansprechpartner' => trim($this->ansprechpartner) !== '' ? trim($this->ansprechpartner) : null,
        ]);

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
