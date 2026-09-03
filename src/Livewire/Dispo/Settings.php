<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Services\Zas\ZasContactLinkReport;
use Platform\Recruiting\Support\Filialen;

/**
 * Disposition → Einstellungen (getrennt von den Bewerber-Einstellungen).
 *
 * PITFALL-AUFLAGE (Spec): schlichte Selects/Inputs mit wire:model +
 * explizitem Speichern-Button — NICHT x-ui-input-select + @entangle
 * (bekannter Speicher-Bug). Gilt auch fuer die Filial-Kanal-Selects
 * unten (eigene Tabelle rec_dispo_filiale_settings, Zeilen-Speichern).
 */
class Settings extends Component
{
    public string $templateId = '';
    public bool $saved = false;

    // Eskalation (teamweit) — bewusst als String-Properties gebunden
    // (Livewire-Falle: typed bool/int-Properties an wire:model crashen).
    public string $escalationEnabled = '';
    public string $escalationTime1 = '14:00';
    public string $escalationTime2 = '15:00';
    public string $escalationTime3 = '16:00';

    /** Schonfrist vor Stufe 3: Rausnahme erst, wenn die letzte Ansprache so viele Stunden her ist (0 = aus). */
    public string $escalationGraceHours = '6';
    public string $escalationTemplate1Id = '';
    public string $escalationTemplate2Id = '';
    public string $alarmTemplateId = '';
    public string $infoTemplateId = '';

    // Kill-Switch fuer den stuendlichen CRM-Abgleich (Runde 4 Final-Review).
    // Fehlende Einstellung = AN, deshalb Default '1'.
    public string $contactBackfillEnabled = '1';

    /** Stufe "Nur Veranstaltungen" (Gate Stufe 1): eine E-Mail pro Zeile (Textarea, String-Prop). */
    public string $eventOnlyEmails = '';

    // Pro-Filiale-Konfiguration — Arrays von Strings, Key = Filialnummer.
    /** @var array<int, string> */
    public array $filialeChannelId = [];
    /** @var array<int, string> */
    public array $filialeDutyPhone = [];
    public ?int $savedFilialNr = null;

    public function mount(): void
    {
        // dispo_*-Settings haengen am ZAS-Anker-Team, damit Public-Seite/Scheduler
        // dieselben Werte lesen; Fallback currentTeam wenn unkonfiguriert.
        $settings = RecApplicantSettings::getOrCreateForTeam($this->teamId());
        $this->templateId    = (string) ($settings->getSetting('dispo_confirmation_template_id') ?? '');
        $this->eventOnlyEmails = implode("\n", (array) ($settings->getSetting('dispo_event_only_emails') ?? []));

        $this->escalationEnabled     = $settings->getSetting('dispo_escalation_enabled') ? '1' : '';
        $this->escalationTime1       = (string) ($settings->getSetting('dispo_escalation_time_1') ?: '14:00');
        $this->escalationTime2       = (string) ($settings->getSetting('dispo_escalation_time_2') ?: '15:00');
        $this->escalationTime3       = (string) ($settings->getSetting('dispo_escalation_time_3') ?: '16:00');
        $graceRaw = $settings->getSetting('dispo_escalation_grace_hours');
        $this->escalationGraceHours  = ($graceRaw === null || $graceRaw === '') ? '6' : (string) (int) $graceRaw;
        $this->escalationTemplate1Id = (string) ($settings->getSetting('dispo_escalation_template_1_id') ?? '');
        $this->escalationTemplate2Id = (string) ($settings->getSetting('dispo_escalation_template_2_id') ?? '');
        $this->alarmTemplateId       = (string) ($settings->getSetting('dispo_alarm_template_id') ?? '');
        $this->infoTemplateId        = (string) ($settings->getSetting('dispo_info_template_id') ?? '');

        // Nur ein ausdrueckliches false schaltet ab — fehlender/null-Wert = AN.
        $this->contactBackfillEnabled = $settings->getSetting('dispo_contact_backfill_enabled') === false ? '' : '1';

        foreach (Filialen::options() as $nr => $code) {
            $row = $this->filialeSettings->get($nr);
            $this->filialeChannelId[$nr] = $row ? (string) $row->comms_channel_id : '';
            $this->filialeDutyPhone[$nr] = $row ? (string) $row->duty_phone : '';
        }
    }

    private function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
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

    /** Bekannte Filialen (Nummer => Code), fuer die Pro-Filiale-Zeilen. */
    #[Computed]
    public function filialen(): array
    {
        return Filialen::options();
    }

    /** Bestehende Filial-Konfiguration, keyed by filial_nr. */
    #[Computed]
    public function filialeSettings(): \Illuminate\Support\Collection
    {
        return RecDispoFilialeSettings::where('team_id', $this->teamId())->get()->keyBy('filial_nr');
    }

    /** WhatsApp-Kanaele fuer die Filial-Selects (gleiches Muster wie DispoConfirmationSender). */
    #[Computed]
    public function whatsappChannels(): array
    {
        return \Platform\Crm\Models\CommsChannel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])
            ->all();
    }

    /**
     * Offene CRM-Zuordnungen (Runde 4, #0). Dokumentierte Ausnahme der Dispo-
     * Leitplanke (Recruiting-Service statt Gateway): der Report gehoert zum
     * Linker/Backfill in Services/Zas und zieht beim Staffing-Auszug NICHT mit.
     */
    #[Computed]
    public function contactLinkReport(): array
    {
        return app(ZasContactLinkReport::class)->openCases($this->teamId());
    }

    public function save(): void
    {
        $this->validate([
            'templateId'            => 'nullable|string|max:20',
            'escalationTime1'       => 'required|date_format:H:i',
            'escalationTime2'       => 'required|date_format:H:i',
            'escalationTime3'       => 'required|date_format:H:i',
            'escalationGraceHours'  => 'required|integer|min:0|max:24',
            'escalationTemplate1Id' => 'nullable|string|max:20',
            'escalationTemplate2Id' => 'nullable|string|max:20',
            'alarmTemplateId'       => 'nullable|string|max:20',
            'infoTemplateId'        => 'nullable|string|max:20',
        ]);

        // dispo_*-Settings haengen am ZAS-Anker-Team, damit Public-Seite/Scheduler
        // dieselben Werte lesen; Fallback currentTeam wenn unkonfiguriert.
        $settings = RecApplicantSettings::getOrCreateForTeam($this->teamId());

        // Konvertiere templateId-String zu Int oder null
        $templateId = ($this->templateId !== '' && ctype_digit($this->templateId)) ? (int) $this->templateId : null;
        $settings->setSetting('dispo_confirmation_template_id', $templateId);

        // "Nur Veranstaltungen"-Zugaenge: eine E-Mail pro Zeile, kleingeschrieben, dedupliziert.
        $emails = array_values(array_unique(array_filter(array_map(
            fn ($line) => mb_strtolower(trim($line)),
            preg_split('/\r?\n/', $this->eventOnlyEmails) ?: []
        ))));
        $settings->setSetting('dispo_event_only_emails', $emails);
        $this->eventOnlyEmails = implode("\n", $emails);
        \Platform\Recruiting\Services\Zas\Dispo\DispoAccess::flush();

        $settings->setSetting('dispo_escalation_enabled', $this->escalationEnabled !== '');
        $settings->setSetting('dispo_escalation_time_1', $this->escalationTime1);
        $settings->setSetting('dispo_escalation_time_2', $this->escalationTime2);
        $settings->setSetting('dispo_escalation_time_3', $this->escalationTime3);
        $settings->setSetting('dispo_escalation_grace_hours', (int) $this->escalationGraceHours);
        $settings->setSetting('dispo_escalation_template_1_id', $this->toNullableId($this->escalationTemplate1Id));
        $settings->setSetting('dispo_escalation_template_2_id', $this->toNullableId($this->escalationTemplate2Id));
        $settings->setSetting('dispo_alarm_template_id', $this->toNullableId($this->alarmTemplateId));
        $settings->setSetting('dispo_info_template_id', $this->toNullableId($this->infoTemplateId));
        $settings->setSetting('dispo_contact_backfill_enabled', $this->contactBackfillEnabled !== '');

        $settings->save();

        $this->saved = true;
    }

    private function toNullableId(string $value): ?int
    {
        return ($value !== '' && ctype_digit($value)) ? (int) $value : null;
    }

    /** Speichert Kanal + Diensthandy fuer genau eine Filiale (Zeilen-Speichern, kein @entangle). */
    public function saveFiliale(int $filialNr): void
    {
        $channelRaw = $this->filialeChannelId[$filialNr] ?? '';
        $channelId  = ($channelRaw !== '' && ctype_digit($channelRaw)) ? (int) $channelRaw : null;
        $dutyPhone  = trim((string) ($this->filialeDutyPhone[$filialNr] ?? ''));

        RecDispoFilialeSettings::updateOrCreate(
            ['team_id' => $this->teamId(), 'filial_nr' => $filialNr],
            ['comms_channel_id' => $channelId, 'duty_phone' => $dutyPhone !== '' ? $dutyPhone : null]
        );

        $this->savedFilialNr = $filialNr;
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.settings')
            ->layout('platform::layouts.app');
    }
}
