<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentStore;
use Platform\Recruiting\Services\Zas\Dispo\DispoConfirmationSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationConfig;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityGroups;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoRecipientPlanner;
use Platform\Recruiting\Services\Zas\Dispo\DispoContactResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoTeamLeadResolver;

/**
 * Disposition → Veranstaltung → Detail: VA-Kopf + Einbuchungen mit
 * Zuordnungs-Status. Hier kommt in Step 2 (Bestaetigungs-Flow) der
 * Sende-Button hin.
 */
class Show extends Component
{
    use WithFileUploads;

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
    /** Herkunft des aktuellen Feldwerts: 'auto' (Teamleitung = Standard), 'manual' oder '' (nichts). */
    public string $contactSource = '';
    /** Anpassen-Dialog fuer den Ansprechpartner (ohne Senden). */
    public bool $showContactModal = false;

    /** Individueller Hinweis pro Mitarbeiter, keyed by rec_employee_id → Text. */
    public array $notes = [];

    // Hinweis-Modal (komfortables Bearbeiten statt engem Inline-Feld).
    public bool $showNoteModal = false;
    public ?int $noteEmployeeId = null;
    public string $noteEmployeeName = '';
    public string $noteDraft = '';

    // Anhang-Modal (Runde 3, #8): eine Datei pro MA fuer diese VA.
    public bool $showAttachmentModal = false;
    public ?int $attachmentEmployeeId = null;
    public string $attachmentEmployeeName = '';
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $attachmentUpload = null;

    // Eskalation pro VA (Runde 3, #5) — Strings (Livewire-Typed-Property-Falle).
    public string $escDay = DispoEscalationConfig::DAY_VORTAG;
    public string $escTime1 = '';
    public string $escTime2 = '';
    public string $escTime3 = '';
    /** Eskalationsdatum (Modus datum), Y-m-d oder '' — String-Prop (Livewire-Typed-Property-Falle). */
    public string $escDate = '';
    public bool $showEscalationModal = false;

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

    /** @return array<int, RecDispoAttachment> keyed by rec_employee_id */
    #[Computed]
    public function attachmentsByEmployee(): array
    {
        return $this->event->attachments->keyBy('rec_employee_id')->all();
    }

    public function openAttachment(int $employeeId): void
    {
        $this->attachmentEmployeeId = $employeeId;
        $employee = $this->event->assignments->firstWhere('rec_employee_id', $employeeId)?->employee;
        $this->attachmentEmployeeName = $employee ? trim($employee->first_name . ' ' . $employee->last_name) : '';
        $this->attachmentUpload = null;
        $this->resetErrorBag('attachmentUpload');
        $this->showAttachmentModal = true;
    }

    public function saveAttachment(): void
    {
        if ($this->attachmentEmployeeId === null) {
            return;
        }
        $this->validate(
            ['attachmentUpload' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'],
            [],
            ['attachmentUpload' => 'Datei']
        );

        DispoAttachmentStore::default()->putUpload(
            $this->eventId,
            $this->attachmentEmployeeId,
            $this->attachmentUpload,
            auth()->id()
        );

        $this->closeAttachmentModal();
        unset($this->event, $this->attachmentsByEmployee);
    }

    public function removeAttachment(int $employeeId): void
    {
        $attachment = $this->attachmentsByEmployee[$employeeId] ?? null;
        if ($attachment !== null) {
            DispoAttachmentStore::default()->remove($attachment);
        }
        unset($this->event, $this->attachmentsByEmployee);
    }

    public function closeAttachmentModal(): void
    {
        $this->showAttachmentModal = false;
        $this->attachmentEmployeeId = null;
        $this->attachmentEmployeeName = '';
        $this->attachmentUpload = null;
    }

    /** Effektive Eskalation dieser VA (Override oder Default) fuer die Kachel. */
    #[Computed]
    public function escalationEffective(): array
    {
        $e = $this->event;

        return DispoEscalationConfig::effective(
            $e->escalation_day, $e->escalation_time_1, $e->escalation_time_2, $e->escalation_time_3,
            $this->dispoSettings['escalation_defaults'],
            $e->escalation_date?->format('Y-m-d')
        );
    }

    /** Formular aus der VA vorbelegen (leere Zeiten = Standard). */
    private function loadEscalationForm(): void
    {
        $e = $this->event;
        $this->escDay   = in_array($e->escalation_day, [DispoEscalationConfig::DAY_EINSATZTAG, DispoEscalationConfig::DAY_DATUM], true)
            ? (string) $e->escalation_day : DispoEscalationConfig::DAY_VORTAG;
        $this->escTime1 = (string) ($e->escalation_time_1 ?? '');
        $this->escTime2 = (string) ($e->escalation_time_2 ?? '');
        $this->escTime3 = (string) ($e->escalation_time_3 ?? '');
        $this->escDate  = $e->escalation_date?->format('Y-m-d') ?? '';
    }

    /**
     * Fruehester Schichtbeginn ALLER kommenden Tage — fuer die Einsatztag-Pruefung.
     * Bewusst unabhaengig von $sendDay: der Override gilt fuer jeden Einsatztag
     * der VA, also muss die Schichtpruefung das auch (sonst validiert der
     * Anpassen-Dialog gegen einen veralteten Einzeltag aus dem Sende-Modal).
     */
    private function earliestVon(): ?string
    {
        $today = now()->toDateString();
        $von = $this->event->assignments
            ->filter(fn ($a) => $a->datum->format('Y-m-d') >= $today)
            ->pluck('von')
            ->filter(fn ($v) => DispoEscalationConfig::isTime($v))
            ->min();

        return $von !== null ? (string) $von : null;
    }

    /**
     * Validiert und speichert Modus/Zeiten an der VA. Fehler landen unter 'escTime1'.
     * @return bool true = gespeichert
     */
    private function persistEscalation(): bool
    {
        $errors = DispoEscalationConfig::validate(
            $this->escDay, $this->escTime1, $this->escTime2, $this->escTime3,
            $this->earliestVon(), $this->dispoSettings['escalation_defaults'],
            $this->escDate, now()->toDateString(), $this->eventDays[0] ?? null
        );
        if ($errors !== []) {
            $this->addError('escTime1', $errors[0]);
            return false;
        }

        $hasTimes = $this->escTime1 !== '';
        $dayStored = in_array($this->escDay, [DispoEscalationConfig::DAY_EINSATZTAG, DispoEscalationConfig::DAY_DATUM], true) ? $this->escDay : null;
        RecDispoEvent::query()->whereKey($this->eventId)->update([
            'escalation_day'    => $dayStored,
            'escalation_time_1' => $hasTimes ? $this->escTime1 : null,
            'escalation_time_2' => $hasTimes ? $this->escTime2 : null,
            'escalation_time_3' => $hasTimes ? $this->escTime3 : null,
            'escalation_date'   => $dayStored === DispoEscalationConfig::DAY_DATUM ? $this->escDate : null,
        ]);
        unset($this->event, $this->escalationEffective);

        return true;
    }

    public function openEscalationModal(): void
    {
        $this->loadEscalationForm();
        $this->resetErrorBag('escTime1');
        $this->showEscalationModal = true;
    }

    public function saveEscalation(): void
    {
        if ($this->persistEscalation()) {
            $this->showEscalationModal = false;
        }
    }

    /** Zurueck auf Team-Standard (Vortag, Default-Zeiten). */
    public function resetEscalation(): void
    {
        $this->escDay = DispoEscalationConfig::DAY_VORTAG;
        $this->escTime1 = $this->escTime2 = $this->escTime3 = '';
        $this->escDate = '';
        $this->resetErrorBag('escTime1');
    }

    #[Computed]
    public function event(): RecDispoEvent
    {
        return RecDispoEvent::query()
            ->with([
                'alarmMessage',
                'attachments',
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
            'template_id'        => $settings->getSetting('dispo_confirmation_template_id') ? (int) $settings->getSetting('dispo_confirmation_template_id') : null,
            'escalation_enabled' => (bool) $settings->getSetting('dispo_escalation_enabled'),
            'escalation_defaults' => [
                1 => (string) ($settings->getSetting('dispo_escalation_time_1') ?: '14:00'),
                2 => (string) ($settings->getSetting('dispo_escalation_time_2') ?: '15:00'),
                3 => (string) ($settings->getSetting('dispo_escalation_time_3') ?: '16:00'),
            ],
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
                $this->refreshContactSource();
                return;
            }
        }
    }

    /** Effektiver Ansprechpartner der VA (Standard = Teamleitung, manuell ueberschreibbar) — fuer die Kachel. */
    #[Computed]
    public function contactEffective(): array
    {
        return DispoContactResolver::effective($this->event->ansprechpartner, $this->teamLeads);
    }

    /** Feld aus der VA vorbelegen: manuelle Ueberschreibung oder Standard-Teamleitung. */
    private function loadContactForm(): void
    {
        $leads = $this->teamLeads;
        $eff = DispoContactResolver::effective($this->event->ansprechpartner, $leads);
        $this->ansprechpartner = (string) ($eff['label'] ?? '');
        $this->contactSource = (string) ($eff['source'] ?? '');
        $this->leadChoice = $leads !== [] ? (string) $leads[0]['employee_id'] : '';
    }

    /** Herkunft nach jeder Eingabe neu bestimmen (Hinweistext im Formular). */
    private function refreshContactSource(): void
    {
        $eff = DispoContactResolver::effective($this->ansprechpartner, $this->teamLeads);
        $this->contactSource = (string) ($eff['source'] ?? '');
    }

    public function updatedAnsprechpartner(): void
    {
        $this->refreshContactSource();
    }

    /** "Standard verwenden": zurueck auf die Teamleitung (leert die manuelle Ueberschreibung beim Speichern). */
    public function useLeadDefault(): void
    {
        $leads = $this->teamLeads;
        $this->ansprechpartner = $leads !== [] ? $leads[0]['label'] : '';
        $this->leadChoice = $leads !== [] ? (string) $leads[0]['employee_id'] : '';
        $this->refreshContactSource();
    }

    /** Speichert NUR manuelle Ueberschreibungen; Standard/leer -> null (Vertrag DispoContactResolver). */
    private function persistContact(): void
    {
        RecDispoEvent::query()->whereKey($this->eventId)->update([
            'ansprechpartner' => DispoContactResolver::toStore($this->ansprechpartner, $this->teamLeads),
        ]);
        unset($this->event, $this->contactEffective);
    }

    public function openContactModal(): void
    {
        $this->loadContactForm();
        $this->resetErrorBag('ansprechpartner');
        $this->showContactModal = true;
    }

    public function saveContact(): void
    {
        $this->validate(['ansprechpartner' => 'nullable|string|max:255']);
        $this->persistContact();
        $this->showContactModal = false;
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

        // Dispo-Identitaet: Datensaetze derselben Person (gleicher CRM-Kontakt) auf die
        // kanonische id umschreiben -> Dedup im Planner ist damit "pro Person".
        $ids = array_values(array_unique(array_filter(array_column($assignments, 'employee_id'))));
        $groups = app(DispoIdentityResolver::class)->groupsFor($ids);
        $canon = DispoIdentityGroups::canonicalMap($groups);
        $assignments = DispoIdentityGroups::canonicalize($assignments, $canon);

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

        // Telefon fuer die kanonische id: alle Gruppen-Mitglieder abfragen, damit die
        // Nummer eines anderen Datensatzes derselben Person einspringt, falls der
        // kanonische Datensatz keine hat.
        $employeeIds = $groups === [] ? [] : array_values(array_unique(array_merge(...array_values($groups))));
        $phones = app(DispoEmployeeGateway::class)->phones($employeeIds);
        foreach ($groups as $id => $group) {
            $c = $canon[$id];
            if (empty($phones[$c])) {
                foreach ($group as $m) {
                    if (!empty($phones[$m])) {
                        $phones[$c] = $phones[$m];
                        break;
                    }
                }
            }
        }

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
        $this->includeReminders = false;
        $this->sendDay = '';
        $this->sendResult = null;
        $this->loadEscalationForm();
        $this->resetErrorBag('escTime1');

        // Ansprechpartner: Teamleitung ist Standard, gespeicherte manuelle Eingabe gewinnt.
        $this->loadContactForm();

        $this->showSendModal = true;
    }

    public function sendConfirmations(): void
    {
        $this->validate([
            'vorlaufMinuten'  => 'required|integer|min:0|max:480',
            'ansprechpartner' => 'nullable|string|max:255',
        ], [], ['vorlaufMinuten' => 'Vorlaufzeit']);

        if (!$this->persistEscalation()) {
            return;
        }

        $templateId = $this->dispoSettings['template_id'];
        if ($templateId === null) {
            $this->addError('vorlaufMinuten', 'Kein Bestätigungs-Template konfiguriert (Disposition → Einstellungen).');
            return;
        }

        $event = RecDispoEvent::findOrFail($this->eventId);
        $event->update([
            'vorlauf_minuten' => (int) $this->vorlaufMinuten,
            // Nur manuelle Ueberschreibung speichern; Standard-Teamleitung -> null (zieht live mit).
            'ansprechpartner' => DispoContactResolver::toStore($this->ansprechpartner, $this->teamLeads),
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
