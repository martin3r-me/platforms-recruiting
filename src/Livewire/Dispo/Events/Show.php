<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\DispoAttachmentStore;
use Platform\Recruiting\Services\Zas\Dispo\DispoChatTemplateSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoConfirmationSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoEscalationConfig;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityGroups;
use Platform\Recruiting\Services\Zas\Dispo\DispoInfoSender;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoRecipientPlanner;
use Platform\Recruiting\Services\Zas\Dispo\DispoContactResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoTeamLeadResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;
use Platform\Recruiting\Services\Zas\Dispo\DispoReplyWindow;
use Platform\Recruiting\Services\Zas\Dispo\DispoTemplateLabels;
use Platform\Recruiting\Services\Zas\Dispo\DispoReplySender;

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

    /**
     * Kunde 03.09. (Nummern-Nachzug): NUR Empfaenger mit Zustellfehler erneut
     * anschreiben — die uebrigen Angeschriebenen ohne Antwort bleiben aussen vor.
     */
    public bool $onlyFailed = false;
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

    // Runde 4 (#1): Chat-Panel auf VA-Ebene. chatEmployeeId = kanonische MA-id der Person.
    // Beide nur ueber openChat()/closeChat()/setChatFilter() serverseitig gesetzt -> gesperrt
    // gegen direkte Frontend-Schreibzugriffe (wire:model/$wire.set).
    #[Locked]
    public ?int $chatEmployeeId = null;
    #[Locked]
    public string $chatFilter = 'seit_versand'; // 'seit_versand' | 'alle'
    public string $chatReply = '';
    // Nur serverseitig gesetzt (Sende-Ergebnis) — gesperrt, damit das Frontend
    // keine fremde Fehlermeldung in die Karte schreiben kann.
    #[Locked]
    public ?string $chatError = null;

    /** Stufe "Nur Veranstaltungen": VA-Seite lesend + Chat — alle Mutationen gesperrt. */
    #[Computed]
    public function eventOnly(): bool
    {
        return \Platform\Recruiting\Services\Zas\Dispo\DispoAccess::currentUserEventOnly();
    }

    /**
     * Server-Riegel fuer mutierende Actions: Blade versteckt die Knoepfe nur —
     * Livewire-Actions blieben sonst per $wire aufrufbar (Muster #[Locked]).
     */
    private function blockedForEventOnly(): bool
    {
        return \Platform\Recruiting\Services\Zas\Dispo\DispoAccess::currentUserEventOnly();
    }

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
        if ($this->blockedForEventOnly()) {
            return;
        }
        $value = trim((string) ($this->notes[$employeeId] ?? ''));
        $this->notes[$employeeId] = $value;

        RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $this->eventId)
            ->where('rec_employee_id', $employeeId)
            ->update([
                'individual_note'            => $value !== '' ? $value : null,
                // Crew-Info (03.09.): Zeitstempel fuer die NEU-Hervorhebung der Einsatz-Seite.
                'individual_note_updated_at' => $value !== '' ? now() : null,
            ]);

        unset($this->event); // Computed-Cache invalidieren
    }

    /** Oeffnet das Hinweis-Modal fuer einen (gematchten) Mitarbeiter. */
    public function openNote(int $employeeId): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->noteEmployeeId = $employeeId;
        $this->noteDraft = (string) ($this->notes[$employeeId] ?? '');

        $employee = $this->event->assignments->firstWhere('rec_employee_id', $employeeId)?->employee;
        $this->noteEmployeeName = $employee ? trim($employee->first_name . ' ' . $employee->last_name) : '';

        $this->showNoteModal = true;
    }

    /** Uebernimmt den Modal-Entwurf und speichert ihn (nur bei gesetzter MA). */
    public function saveNoteFromModal(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
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

    /** @return array<int, list<RecDispoAttachment>> je rec_employee_id (seit Crew-Info mehrere Dateien). */
    #[Computed]
    public function attachmentsByEmployee(): array
    {
        return $this->event->attachments
            ->sortBy('id')
            ->groupBy('rec_employee_id')
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }

    public function openAttachment(int $employeeId): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->attachmentEmployeeId = $employeeId;
        $employee = $this->event->assignments->firstWhere('rec_employee_id', $employeeId)?->employee;
        $this->attachmentEmployeeName = $employee ? trim($employee->first_name . ' ' . $employee->last_name) : '';
        $this->attachmentUpload = null;
        $this->resetErrorBag('attachmentUpload');
        $this->showAttachmentModal = true;
    }

    public function saveAttachment(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
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

        // Modal bleibt offen (Kunde 03.09.: Liste im Modal, mehrere Dateien nacheinander).
        $this->attachmentUpload = null;
        $this->resetErrorBag('attachmentUpload');
        unset($this->event, $this->attachmentsByEmployee);
    }

    public function removeAttachment(int $attachmentId): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        // Scope-Check: nur Anhaenge DIESER VA (id kommt vom Client).
        $attachment = $this->event->attachments->firstWhere('id', $attachmentId);
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
        // "Nicht in der Vergangenheit" darf nur ein NEU gewaehltes Datum treffen:
        // ein bereits gespeichertes (inzwischen vergangenes) Datum wuerde sonst
        // jede unbeteiligte Aenderung an derselben Maske blockieren.
        $stored = $this->event->escalation_date?->format('Y-m-d');
        $dateChanged = $this->escDate !== ($stored ?? '');
        $today = now()->toDateString();
        // Die Schichtbeginn-Regel schuetzt das Vorausplanen (Eskalation am
        // Einsatztag muss vor Schichtbeginn durch sein). Laeuft der gewaehlte
        // Eskalationstag aber BEREITS (Datum == heute), ist der fruehste
        // Schichtbeginn zwangslaeufig Vergangenheit — die Regel wuerde dann
        // jedes Nachsteuern UND (weil der Versand mitspeichert) sogar den
        // Versand selbst blockieren (Befund 04.09., Nummern-Nachzug).
        // Bereits gefeuerte Stufen wiederholen sich ohnehin nicht (Stempel).
        $vonForCheck = ($this->escDay === DispoEscalationConfig::DAY_DATUM && $this->escDate === $today)
            ? null
            : $this->earliestVon();
        $errors = DispoEscalationConfig::validate(
            $this->escDay, $this->escTime1, $this->escTime2, $this->escTime3,
            $vonForCheck, $this->dispoSettings['escalation_defaults'],
            $this->escDate, $dateChanged ? $today : null, $this->eventDays[0] ?? null
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

    /**
     * "Nur Eskalation speichern" im Sende-Modal (Kunde 03.09.): Nachjustieren
     * ohne Neuversand — die frueher eigene Kachel/das eigene Modal sind weg.
     */
    public bool $escSaved = false;

    public function saveEscalation(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->escSaved = $this->persistEscalation();
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

    /**
     * Zeilenfilter der Desktop-Tabelle (Kunde 03.09.): '' (alle) | 'confirmed' |
     * 'read' | 'failed'. Reine Ansicht — die mobile Kartenliste bleibt bewusst
     * ungefiltert (Kunde: nur Desktop).
     */
    public string $rowFilter = '';

    /** @return \Illuminate\Support\Collection<int, RecDispoAssignment> */
    #[Computed]
    public function filteredAssignments()
    {
        return $this->event->assignments->filter(fn ($a) => $this->rowMatchesFilter($a, $this->rowFilter))->values();
    }

    /** @return array{'':int, open:int, confirmed:int, declined:int, read:int, failed:int} */
    #[Computed]
    public function rowFilterCounts(): array
    {
        $counts = [];
        foreach (['', 'open', 'confirmed', 'declined', 'read', 'failed'] as $key) {
            $counts[$key] = $this->event->assignments->filter(fn ($a) => $this->rowMatchesFilter($a, $key))->count();
        }

        return $counts;
    }

    private function rowMatchesFilter(RecDispoAssignment $a, string $filter): bool
    {
        return match ($filter) {
            'open'      => $a->confirmed_at === null && $a->declined_at === null,
            'declined'  => $a->declined_at !== null,
            'confirmed' => $a->confirmed_at !== null,
            // "gelesen" = Chip-Logik der Tabelle: angeschrieben, Nachricht gelesen, noch nicht bestaetigt.
            'read'      => $a->confirmed_at === null && $a->declined_at === null && $a->reminder_sent_at !== null && $a->reminderMessage?->status === 'read',
            // Gleiches Praedikat wie die rote Zeile der Dispo-Karte: irgendeine Stufe failed.
            'failed'    => $a->reminderMessage?->status === 'failed'
                || $a->escalation1Message?->status === 'failed'
                || $a->escalation2Message?->status === 'failed',
            default     => true,
        };
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
        if ($this->blockedForEventOnly()) {
            return;
        }
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
        if ($this->blockedForEventOnly()) {
            return;
        }
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
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->loadContactForm();
        $this->resetErrorBag('ansprechpartner');
        $this->showContactModal = true;
    }

    public function saveContact(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->validate(['ansprechpartner' => 'nullable|string|max:255']);
        $this->persistContact();
        $this->showContactModal = false;
    }

    /**
     * @return array{groups: array<int,list<int>>, canon: array<int,int>, byCanon: array<int,list<int>>}
     *         Identitaet der disponierten MA dieser VA. groupsFor() schluesselt NUR ueber die
     *         angefragten (Einbuchungs-)ids — byCanon schluesselt zusaetzlich ueber die kanonische
     *         id selbst, damit z. B. das Chat-Panel (chatEmployeeId = kanonische id) die Gruppe
     *         auch dann findet, wenn die Person nur unter einem HOEHEREN Datensatz disponiert ist.
     */
    #[Computed]
    public function identity(): array
    {
        $ids = $this->event->assignments->pluck('rec_employee_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $groups = app(DispoIdentityResolver::class)->groupsFor($ids);

        $byCanon = [];
        foreach ($groups as $group) {
            $byCanon[DispoIdentityGroups::canonical($group)] = $group;
        }

        return ['groups' => $groups, 'canon' => DispoIdentityGroups::canonicalMap($groups), 'byCanon' => $byCanon];
    }

    /** @return list<int> */
    #[Computed]
    public function channelIds(): array
    {
        return DispoChannelResolver::dispoChannelIds();
    }

    /** kanonische id => Thread-Info (siehe DispoThreadDirectory::threadsFor) */
    #[Computed]
    public function threadsByEmployee(): array
    {
        $ids = array_keys($this->identity['groups']);

        return $ids === [] ? [] : app(DispoThreadDirectory::class)->threadsFor($this->channelIds, $ids);
    }

    public function openChat(int $employeeId): void
    {
        $canon = $this->identity['canon'][$employeeId] ?? $employeeId;
        $this->chatEmployeeId = $canon;
        $this->chatReply = '';
        $this->chatError = null;
        $this->chatThread?->markAsRead();
        unset($this->threadsByEmployee, $this->chatThread, $this->chat);
        $this->dispatch('sidebar-refresh');
    }

    public function closeChat(): void
    {
        $this->chatEmployeeId = null;
        $this->chatReply = '';
        $this->chatError = null;
        unset($this->chatThread, $this->chat);
    }

    /**
     * Kunde 03.09.: Chat als ungelesen zuruecklegen. Schliesst das Panel mit —
     * solange es offen ist, wuerde chat() den Thread beim naechsten Render
     * sofort wieder als gelesen markieren.
     */
    public function markChatUnread(): void
    {
        $this->chatThread?->markAsUnread();
        $this->closeChat();
        unset($this->threadsByEmployee);
        $this->dispatch('sidebar-refresh');
    }

    // ---- Absage erfassen (Kunde 04.09.): stoppt Eskalation + weitere Sendungen,
    // optional Portalsperre und Uebergabe an den HR-Desk (Clara). Wirkt auf alle
    // kommenden Einbuchungen der Person (Identitaetsgruppe) in DIESER VA.
    public bool $showDeclineModal = false;
    public string $declineReason = 'abgesagt';
    public string $declineNote = '';
    public $declineLock = false;
    public $declineHr = false;

    public function openDeclineModal(): void
    {
        if ($this->blockedForEventOnly() || $this->chatEmployeeId === null) {
            return;
        }
        $this->declineReason = 'abgesagt';
        $this->declineNote = '';
        $this->declineLock = false;
        $this->declineHr = false;
        $this->showDeclineModal = true;
    }

    public function saveDecline(): void
    {
        if ($this->blockedForEventOnly() || $this->chatEmployeeId === null) {
            return;
        }
        $this->validate([
            'declineReason' => 'required|in:krank,abgesagt',
            'declineNote'   => 'nullable|string|max:1000',
        ], [], ['declineNote' => 'Kommentar']);

        $groupIds = $this->identity['byCanon'][$this->chatEmployeeId] ?? [$this->chatEmployeeId];
        $lock = (bool) $this->declineLock;
        $hr = (bool) $this->declineHr;

        $updated = RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $this->eventId)
            ->whereIn('rec_employee_id', $groupIds)
            ->whereDate('datum', '>=', now()->toDateString())
            ->whereNull('declined_at')
            ->update([
                'declined_at'            => now(),
                'declined_reason'        => $this->declineReason,
                'declined_note'          => trim($this->declineNote) !== '' ? trim($this->declineNote) : null,
                'declined_by_user_id'    => auth()->id(),
                'declined_portal_locked' => $lock,
                'declined_hr_at'         => $hr ? now() : null,
            ]);

        if ($updated === 0) {
            $this->addError('declineReason', 'Keine kommende Einbuchung gefunden (bereits abgesagt?).');
            return;
        }
        if ($lock) {
            app(DispoEmployeeGateway::class)->lockPortal($groupIds, 'Dispo-Absage (' . $this->declineReason . ')');
        }

        $this->showDeclineModal = false;
        unset($this->event, $this->sendPreview);
    }

    /** Doku-Haken (Kunde 04.09.): 'in ZAS rausgenommen' — reines Abhaken, kein ZAS-Schreibzugriff. */
    public function toggleZasRemoved(int $assignmentId): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $a = RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $this->eventId)
            ->whereKey($assignmentId)
            ->first();
        if ($a === null) {
            return;
        }
        $a->zas_removed_at = $a->zas_removed_at === null ? now() : null;
        $a->zas_removed_by_user_id = $a->zas_removed_at !== null ? auth()->id() : null;
        $a->save();
        unset($this->event);
    }

    #[Computed]
    public function chatThread(): ?\Platform\Crm\Models\CommsWhatsAppThread
    {
        if ($this->chatEmployeeId === null) {
            return null;
        }
        $info = $this->threadsByEmployee[$this->chatEmployeeId] ?? null;
        if ($info === null || $this->channelIds === []) {
            return null;
        }

        // Sicherheit: nur Threads AUS DEM DISPO-KANAL-SET laden.
        return \Platform\Crm\Models\CommsWhatsAppThread::query()
            ->whereKey($info['thread_id'])
            ->whereIn('comms_channel_id', $this->channelIds)
            ->first();
    }

    /**
     * Kopf + Nachrichten fuer das Panel. Filter 'seit_versand' = ab dem ersten
     * Bestaetigungs-Versand dieser Person fuer DIESE VA (sonst ab Anlage der
     * Einbuchung); 'alle' = kompletter Verlauf.
     */
    #[Computed]
    public function chat(): ?array
    {
        $thread = $this->chatThread;
        if ($thread === null || $this->chatEmployeeId === null) {
            return null;
        }

        // Re-Mark: waehrend das Panel offen ist, kann per wire:poll eine neue
        // eingehende Nachricht eintreffen (Thread wird dabei is_unread=true) —
        // bei jedem Render sofort wieder als gelesen markieren.
        if ($thread->is_unread) {
            $thread->markAsRead();
            $this->dispatch('sidebar-refresh');
        }

        // byCanon statt groups: $this->chatEmployeeId ist die KANONISCHE id, groupsFor()
        // schluesselt aber ueber die angefragten Einbuchungs-ids — bei Disposition nur
        // unter dem hoeheren Doppel-MA-Datensatz waere groups[$chatEmployeeId] leer.
        $groupIds = $this->identity['byCanon'][$this->chatEmployeeId] ?? [$this->chatEmployeeId];
        $contacts = app(DispoEmployeeGateway::class)->contacts($groupIds);
        $name = $contacts[$this->chatEmployeeId]['name'] ?? (string) $thread->remote_phone_number;
        $pnrs = collect($groupIds)->map(fn ($id) => $contacts[$id]['personnel_number'] ?? '')->filter()->values()->all();

        // Portal-Token der kanonischen id, sonst (wie beim Telefon in sendPreview())
        // das erste nicht-leere Token eines anderen Gruppen-Mitglieds.
        $token = (string) ($contacts[$this->chatEmployeeId]['portal_token'] ?? '');
        if ($token === '') {
            foreach ($groupIds as $memberId) {
                $candidate = (string) ($contacts[$memberId]['portal_token'] ?? '');
                if ($candidate !== '') {
                    $token = $candidate;
                    break;
                }
            }
        }

        $own = $this->event->assignments->filter(fn ($a) => in_array((int) $a->rec_employee_id, $groupIds, true));
        $since = null;
        if ($this->chatFilter === 'seit_versand') {
            $sent = $own->pluck('reminder_sent_at')->filter()->min();
            $since = $sent ?? $own->pluck('created_at')->filter()->min();
        }

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
        $labels = DispoTemplateLabels::forTeam($teamId);

        return [
            'name'       => $name,
            'phone'      => (string) $thread->remote_phone_number,
            'pnrs'       => $pnrs,
            'portal_url' => $token !== '' ? route('recruiting.public.employee-assignments', ['token' => $token]) : null,
            'window'     => DispoReplyWindow::info($thread->last_inbound_at, now()),
            'since'      => $since ? \Illuminate\Support\Carbon::instance($since)->format('d.m.Y H:i') : null,
            'messages'   => app(DispoThreadDirectory::class)->messages($thread, $labels, $since),
        ];
    }

    public function setChatFilter(string $filter): void
    {
        $this->chatFilter = $filter === 'alle' ? 'alle' : 'seit_versand';
        unset($this->chat);
    }

    // ------------------------------------------------------------------
    // "Info an Crew" (Kunde 03.09.): Anhang/Hinweis gefiltert nach
    // Qualifikation an viele MA auf einmal + Info-WhatsApp mit Link.
    // ------------------------------------------------------------------
    public bool $showInfoModal = false;
    /** Filter: '' = alle, 't:<Taetigkeit>' (aus den Einbuchungen) oder 'q:<Lookup-value>' (Qualifikation). */
    public string $infoFilter = '';
    /** Abgewaehlte Personen (kanonische ids) — Checkboxen in der Vorschau. @var list<int> */
    public array $infoExcluded = [];
    public string $infoNote = '';
    /** truthy = Info-WhatsApp mitschicken (Standard); leer/false = nur zuweisen ohne Versand (Kunde 03.09.: Erstbefuellung vor dem Bestaetigungs-Versand). Untypisiert — die Checkbox liefert bool (Muster escalationEnabled). */
    public $infoSendWhatsApp = true;
    public $infoUpload = null;
    /** @var ?array{sent:int, failed:list<array{employee_id:int, error:string}>, attached:int, noted:int, no_phone:int} */
    public ?array $infoResult = null;

    public function openInfoModal(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->infoFilter = '';
        $this->infoExcluded = [];
        $this->infoNote = '';
        $this->infoSendWhatsApp = true;
        $this->infoUpload = null;
        $this->infoResult = null;
        $this->resetErrorBag('infoNote');
        $this->showInfoModal = true;
    }

    /** Filterwechsel verwirft die Einzel-Abwahl — sie bezog sich auf die alte Menge. */
    public function updatedInfoFilter(): void
    {
        $this->infoExcluded = [];
        unset($this->infoPreview);
    }

    /** Einzelne Person der Vorschau an-/abwaehlen (Checkbox). */
    public function toggleInfoPerson(int $canonical): void
    {
        if (in_array($canonical, $this->infoExcluded, true)) {
            $this->infoExcluded = array_values(array_diff($this->infoExcluded, [$canonical]));
        } else {
            $this->infoExcluded[] = $canonical;
        }
        unset($this->infoPreview);
    }

    /** In der VA vorkommende Taetigkeiten (aus den kommenden Einbuchungen) fuer den Filter. */
    #[Computed]
    public function infoTaetigkeitOptions(): array
    {
        $today = now()->toDateString();

        return $this->event->assignments
            ->filter(fn ($a) => $a->datum->format('Y-m-d') >= $today && trim((string) $a->taetigkeit) !== '')
            ->pluck('taetigkeit')
            ->map(fn ($t) => trim((string) $t))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** In der VA vertretene Qualifikationen (value => label) fuer den Filter. */
    #[Computed]
    public function infoQualOptions(): array
    {
        $ids = array_keys($this->identity['groups']);
        $all = [];
        foreach ($this->identity['groups'] as $group) {
            foreach ($group as $gid) {
                $all[] = (int) $gid;
            }
        }
        $data = app(DispoEmployeeGateway::class)->qualifications(array_values(array_unique($all)));

        $present = [];
        foreach ($data['byEmployee'] as $values) {
            foreach ($values as $value) {
                $present[$value] = $data['labels'][$value] ?? $value;
            }
        }
        ksort($present);

        return $present;
    }

    /**
     * Zielpersonen der Crew-Info: kommende Auftrags-Einsaetze (nicht missing/
     * geloescht, MA gematcht), Person-dedupliziert; Qualifikations-Filter
     * matcht, wenn IRGENDEIN Datensatz der Person den Wert traegt.
     *
     * @return array{persons: list<array{canonical:int, booked:int, name:string, phone:?string, portal_token:string, first_name:string, assignment_ids:list<int>, has_note:bool}>, no_phone:int}
     */
    #[Computed]
    public function infoPreview(): array
    {
        $today = now()->toDateString();
        $rows = $this->event->assignments->filter(fn ($a) => $a->status_id === RecDispoAssignment::STATUS_AUFTRAG
            && $a->missing_since === null && $a->deletion_marked_at === null
            && $a->rec_employee_id !== null && $a->datum->format('Y-m-d') >= $today);

        $canon = $this->identity['canon'];
        $byCanon = [];
        foreach ($rows as $a) {
            $c = $canon[(int) $a->rec_employee_id] ?? (int) $a->rec_employee_id;
            $byCanon[$c]['booked'] = $byCanon[$c]['booked'] ?? (int) $a->rec_employee_id;
            $byCanon[$c]['assignment_ids'][] = (int) $a->id;
            $byCanon[$c]['has_note'] = ($byCanon[$c]['has_note'] ?? false) || trim((string) $a->individual_note) !== '';
            $byCanon[$c]['taetigkeiten'][trim((string) $a->taetigkeit)] = true;
        }
        if ($byCanon === []) {
            return ['persons' => [], 'no_phone' => 0];
        }

        $groups = $this->identity['byCanon'];
        $allIds = [];
        foreach (array_keys($byCanon) as $c) {
            foreach ($groups[$c] ?? [$c] as $gid) {
                $allIds[] = (int) $gid;
            }
        }
        $allIds = array_values(array_unique($allIds));
        $contacts = app(DispoEmployeeGateway::class)->contacts($allIds);
        $quals = app(DispoEmployeeGateway::class)->qualifications($allIds)['byEmployee'];

        $persons = [];
        $noPhone = 0;
        foreach ($byCanon as $c => $data) {
            $group = $groups[$c] ?? [$c];

            if (str_starts_with($this->infoFilter, 't:')) {
                // Taetigkeit aus den Einbuchungen DIESER VA (immer gefuellt, ZAS liefert sie mit).
                if (!isset($data['taetigkeiten'][substr($this->infoFilter, 2)])) {
                    continue;
                }
            } elseif (str_starts_with($this->infoFilter, 'q:')) {
                $wanted = substr($this->infoFilter, 2);
                $hit = false;
                foreach ($group as $gid) {
                    if (in_array($wanted, $quals[$gid] ?? [], true)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
            }

            $phone = null;
            $token = '';
            $firstName = '';
            $name = '';
            foreach (array_merge([$c], $group) as $gid) {
                $contact = $contacts[$gid] ?? null;
                if ($contact === null) {
                    continue;
                }
                $name = $name !== '' ? $name : $contact['name'];
                $firstName = $firstName !== '' ? $firstName : $contact['first_name'];
                $phone ??= $contact['phone'];
                $token = $token !== '' ? $token : $contact['portal_token'];
            }
            if ($phone === null) {
                $noPhone++;
            }

            $persons[] = [
                'canonical'      => (int) $c,
                'booked'         => (int) $data['booked'],
                'name'           => $name,
                'phone'          => $phone,
                'portal_token'   => $token,
                'first_name'     => $firstName,
                'assignment_ids' => $data['assignment_ids'],
                'has_note'       => (bool) $data['has_note'],
                'selected'       => !in_array((int) $c, $this->infoExcluded, true),
            ];
        }

        return ['persons' => $persons, 'no_phone' => $noPhone];
    }

    /** Anhang speichern + Hinweis setzen + Info-WhatsApp an die gefilterte Auswahl. */
    public function sendCrewInfo(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }

        $note = trim($this->infoNote);
        if ($this->infoUpload === null && $note === '') {
            $this->addError('infoNote', 'Bitte mindestens eine Datei anhängen oder einen Hinweis eingeben.');
            return;
        }
        if ($this->infoUpload !== null) {
            $this->validate(
                ['infoUpload' => 'file|max:10240|mimes:' . implode(',', DispoAttachmentStore::ALLOWED_EXTENSIONS)],
                [], ['infoUpload' => 'Datei']
            );
        }
        $sendWhatsApp = (bool) $this->infoSendWhatsApp;
        $templateId = (int) (RecApplicantSettings::getOrCreateForTeam($this->settingsTeamId())->getSetting('dispo_info_template_id') ?: 0);
        if ($sendWhatsApp && $templateId === 0) {
            $this->addError('infoNote', 'Kein Info-Template konfiguriert (Disposition → Einstellungen) — oder „WhatsApp senden" abwählen.');
            return;
        }

        // Doppelklick-Riegel wie beim Bestaetigungs-Versand.
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock('dispo-info-' . $this->eventId, 180);
            if (!$lock->get()) {
                $this->addError('infoNote', 'Versand läuft bereits — bitte einen Moment warten.');
                return;
            }
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            $preview = $this->infoPreview;
            $persons = array_values(array_filter($preview['persons'], fn ($p) => $p['selected']));
            if ($persons === []) {
                $this->addError('infoNote', 'Die Auswahl trifft keine Mitarbeiter.');
                return;
            }

            $attached = 0;
            $noted = 0;
            $now = now();

            // 1) Anhang: identische Datei je Person (am gebuchten Datensatz — die
            //    Einsatz-Seite liest ueber die ganze Identitaetsgruppe).
            if ($this->infoUpload !== null) {
                $contents = (string) file_get_contents($this->infoUpload->getRealPath());
                $store = DispoAttachmentStore::default();
                foreach ($persons as $person) {
                    $store->putContents(
                        $this->eventId,
                        $person['booked'],
                        $contents,
                        $this->infoUpload->getClientOriginalName(),
                        $this->infoUpload->getClientMimeType(),
                        auth()->id()
                    );
                    $attached++;
                }
            }

            // 2) Hinweis: identischer Text auf allen KOMMENDEN Einbuchungen der
            //    Getroffenen — ERGAENZT einen bestehenden Hinweis als neue Zeile
            //    (User 03.09.: ein Bulk-Treffpunkt darf das individuelle
            //    "15 Min frueher" nicht wegwerfen). Doppeltes Anfuegen desselben
            //    Textes wird uebersprungen (Doppelklick/zweiter Lauf).
            if ($note !== '') {
                $ids = array_merge(...array_map(fn ($p) => $p['assignment_ids'], $persons));
                foreach (RecDispoAssignment::query()->whereIn('id', $ids)->get(['id', 'individual_note']) as $assignment) {
                    $existing = trim((string) $assignment->individual_note);
                    if ($existing === $note || str_contains($existing, $note)) {
                        continue;
                    }
                    RecDispoAssignment::query()->whereKey($assignment->id)->update([
                        'individual_note'            => $existing !== '' ? $existing . "\n" . $note : $note,
                        'individual_note_updated_at' => $now,
                    ]);
                    $noted++;
                }
            }

            // 3) Info-WhatsApp an alle mit Nummer — nur wenn der Schalter an ist
            //    (aus = reine Zuweisung, z. B. Erstbefuellung vor dem Bestaetigungs-
            //    Versand: die Infos haengen dann schon dran, wenn der Link rausgeht).
            if (!$sendWhatsApp) {
                $this->infoResult = [
                    'sent'     => 0,
                    'failed'   => [],
                    'attached' => $attached,
                    'noted'    => (int) $noted,
                    'no_phone' => 0,
                ];
                $this->infoUpload = null;
                unset($this->event, $this->attachmentsByEmployee, $this->infoPreview);
                return;
            }

            $recipients = [];
            foreach ($persons as $person) {
                if ($person['phone'] === null || $person['portal_token'] === '') {
                    continue;
                }
                $recipients[] = [
                    'employee_id'  => $person['canonical'],
                    'phone'        => $person['phone'],
                    'first_name'   => $person['first_name'],
                    'portal_token' => $person['portal_token'],
                ];
            }
            $result = app(DispoInfoSender::class)->send(RecDispoEvent::findOrFail($this->eventId), $recipients, $templateId);
            if (!$result['ok']) {
                $this->addError('infoNote', (string) $result['message']);
                return;
            }

            $this->infoResult = [
                'sent'     => $result['sent'],
                'failed'   => $result['failed'],
                'attached' => $attached,
                'noted'    => (int) $noted,
                'no_phone' => count(array_filter($persons, fn ($p) => $p['phone'] === null)),
            ];
            $this->infoUpload = null;
            unset($this->event, $this->attachmentsByEmployee, $this->infoPreview);
        } finally {
            $lock?->release();
        }
    }

    /** Anker-Team der dispo_*-Settings (gleiche Regel wie dispoSettings()). */
    private function settingsTeamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
    }

    /** Crew-Modal (Kunde 02.09.): abgespecktes Personal-Kaertchen statt Link in die MA-Akte. */
    #[Locked]
    public ?int $crewEmployeeId = null;

    public function openCrew(int $employeeId): void
    {
        // Nur MA, die in DIESER VA disponiert sind — canon ist nach den
        // Assignment-ids dieser VA gekeyt, ein fremdes id-Argument faellt durch.
        $canon = $this->identity['canon'][$employeeId] ?? null;
        if ($canon === null) {
            return;
        }
        $this->crewEmployeeId = $canon;
        unset($this->crewCard);
    }

    public function closeCrew(): void
    {
        $this->crewEmployeeId = null;
        unset($this->crewCard);
    }

    /**
     * Kaertchen-Daten der Person: Selfie, Sterne, Qualifikationen (aus dem
     * Gateway) + Anzahl bestaetigter Einsaetze bisher (vergangene Auftrags-
     * Einsatztage mit Bestaetigung, ueber ALLE Datensaetze der Gruppe).
     */
    #[Computed]
    public function crewCard(): ?array
    {
        if ($this->crewEmployeeId === null) {
            return null;
        }
        $groupIds = $this->identity['byCanon'][$this->crewEmployeeId] ?? [$this->crewEmployeeId];
        $cards = app(DispoEmployeeGateway::class)->profileCards($groupIds);
        if ($cards === []) {
            return null;
        }

        // Kanonischer Datensatz zuerst; Luecken (Selfie/Sterne/Qualis) fuellt die Gruppe.
        $primary = $cards[$this->crewEmployeeId] ?? reset($cards);
        $ratings = $primary['ratings'];
        $selfie = $primary['selfie_url'];
        $selfieFull = $primary['selfie_full_url'];
        $quals = $primary['qualifications'];
        $pnrs = [];
        foreach ($cards as $card) {
            if ($card['personnel_number'] !== '') {
                $pnrs[] = $card['personnel_number'];
            }
            if ($ratings === []) {
                $ratings = $card['ratings'];
            }
            if ($selfie === null) {
                $selfie = $card['selfie_url'];
                $selfieFull = $card['selfie_full_url'];
            }
            if ($quals === []) {
                $quals = $card['qualifications'];
            }
        }

        $confirmedPast = RecDispoAssignment::query()
            ->whereIn('rec_employee_id', $groupIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNotNull('confirmed_at')
            ->whereDate('datum', '<', now()->toDateString())
            ->count();

        return [
            'name'           => $primary['name'],
            'pnrs'           => array_values(array_unique($pnrs)),
            'ratings'        => $ratings,
            'selfie_url'     => $selfie,
            'selfie_full_url' => $selfieFull,
            'qualifications' => array_values(array_unique($quals)),
            'confirmed_past' => $confirmedPast,
        ];
    }

    /** Die drei festen Chat-Vorlagen (config) — Buttons im Panel, wenn kein Fenster offen ist. */
    #[Computed]
    public function chatTemplates(): array
    {
        return DispoChatTemplateSender::options();
    }

    /** Vorlage aus dem VA-Chat senden (Kunde 01.09.) — gleiche Regel wie in der Kommunikation. */
    public function sendChatTemplate(string $key): void
    {
        $thread = $this->chatThread;
        if ($thread === null || $this->chatEmployeeId === null) {
            $this->chatError = 'Kein Thread verfuegbar.';
            return;
        }

        $groupIds = $this->identity['byCanon'][$this->chatEmployeeId] ?? [$this->chatEmployeeId];
        $firstName = '';
        foreach (app(DispoEmployeeGateway::class)->contacts($groupIds) as $contact) {
            if (trim($contact['first_name']) !== '') {
                $firstName = trim($contact['first_name']);
                break;
            }
        }

        $r = app(DispoChatTemplateSender::class)->send($thread, $key, $firstName, auth()->user());
        if (!$r['ok']) {
            $this->chatError = $r['error'];
            return;
        }
        $this->chatError = null;
        unset($this->threadsByEmployee, $this->chatThread, $this->chat);
    }

    public function sendChatReply(): void
    {
        $thread = $this->chatThread;
        if ($thread === null) {
            $this->chatError = 'Kein Thread verfügbar.';
            return;
        }
        $r = app(DispoReplySender::class)->send($thread, $this->chatReply, auth()->user());
        if (!$r['ok']) {
            $this->chatError = $r['error'];
            return; // Text NICHT leeren
        }
        $this->chatReply = '';
        $this->chatError = null;
        unset($this->threadsByEmployee, $this->chatThread, $this->chat);
        $this->dispatch('reply-sent');
    }

    #[Computed]
    public function sendPreview(): array
    {
        $assignments = $this->event->assignments->map(fn ($a) => [
            'id'                 => $a->id,
            'employee_id'        => $a->rec_employee_id,
            'status_id'          => $a->status_id,
            'confirmed_at'       => $a->confirmed_at?->toDateTimeString(),
            'declined_at'        => $a->declined_at?->toDateTimeString(),
            'reminder_sent_at'   => $a->reminder_sent_at?->toDateTimeString(),
            'missing_since'      => $a->missing_since?->toDateTimeString(),
            'deletion_marked_at' => $a->deletion_marked_at?->toDateTimeString(),
            'datum'              => $a->datum->format('Y-m-d'),
            'reconfirm_required_at' => $a->reconfirm_required_at?->toDateTimeString(),
            // Gleiches Praedikat wie Dispo-Karte/Tabellen-Filter: irgendeine Stufe failed.
            'failed'             => $a->reminderMessage?->status === 'failed'
                || $a->escalation1Message?->status === 'failed'
                || $a->escalation2Message?->status === 'failed',
        ])->all();

        // Dispo-Identitaet: Datensaetze derselben Person (gleicher CRM-Kontakt) auf die
        // kanonische id umschreiben -> Dedup im Planner ist damit "pro Person".
        $groups = $this->identity['groups'];
        $canon = $this->identity['canon'];
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

        // Nur-Zustellfehler-Modus: Zielmenge VOR dem Planner eingrenzen; die
        // Aussortierten werden gezaehlt (nichts wird still uebersprungen). Die
        // Betroffenen sind bereits gestempelt, intern also immer Reminder-Modus.
        $notFailed = 0;
        if ($this->onlyFailed) {
            $notFailed = count(array_filter($upcoming, fn ($a) => empty($a['failed'])));
            $upcoming = array_values(array_filter($upcoming, fn ($a) => !empty($a['failed'])));
        }

        $result = (new DispoRecipientPlanner())
            ->plan($upcoming, $phones, $this->onlyFailed ? true : $this->includeReminders);
        if ($notFailed > 0) {
            $result['skipped']['not_failed'] = $notFailed;
        }

        // Runde 4 (#2): wie viele der Empfaenger-Einbuchungen sind Rebestaetigungen (Zeit geaendert)?
        // array_merge([], ...) statt array_merge(..., []): "Cannot use positional
        // argument after argument unpacking" bei leerem Spread (PHP 8.1+).
        $recipientIds = array_merge([], ...array_map(fn ($r) => $r['assignment_ids'], $result['recipients']));
        $result['reconfirm'] = count(array_filter($upcoming, fn ($a) => in_array((int) $a['id'], $recipientIds, true) && !empty($a['reconfirm_required_at'])));

        if ($pastCount > 0) {
            $result['skipped']['past'] = $pastCount;
        }

        return $result;
    }

    public function openSendModal(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        $this->vorlaufMinuten = (string) ($this->event->vorlauf_minuten ?? '');
        $this->includeReminders = false;
        $this->onlyFailed = false;
        $this->sendDay = '';
        $this->sendResult = null;
        $this->loadEscalationForm();
        $this->resetErrorBag('escTime1');
        $this->escSaved = false;

        // Ansprechpartner: Teamleitung ist Standard, gespeicherte manuelle Eingabe gewinnt.
        $this->loadContactForm();

        $this->showSendModal = true;
    }

    public function sendConfirmations(): void
    {
        if ($this->blockedForEventOnly()) {
            return;
        }
        // Doppelklick-Riegel (Kunde 01.09.): ein Versand je VA zur Zeit. Der zweite
        // Klick landet hier, waehrend der erste noch in der Meta-Schleife steckt —
        // ohne Lock wuerde er dieselben Empfaenger erneut planen (die
        // reminder_sent_at-Stempel sind dann noch nicht gesetzt).
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock('dispo-send-' . $this->eventId, 180);
            if (!$lock->get()) {
                $this->addError('vorlaufMinuten', 'Versand läuft bereits — bitte einen Moment warten.');
                return;
            }
        } catch (\Throwable $e) {
            // Cache-Store ohne Lock-Faehigkeit: Versand nicht blockieren — der
            // Button-Riegel (wire:loading) bleibt als erste Verteidigung.
            \Illuminate\Support\Facades\Log::warning('dispo_send_lock_unavailable', ['error' => $e->getMessage()]);
            $this->doSendConfirmations();
            return;
        }

        try {
            $this->doSendConfirmations();
        } finally {
            $lock->release();
        }
    }

    private function doSendConfirmations(): void
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
