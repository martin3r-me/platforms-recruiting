<?php

namespace Platform\Recruiting\Livewire\DirectHire;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecIntakeChannel;
use Platform\Recruiting\Services\DirectHireSetupService;

/**
 * Wizard zum Anlegen einer Direkteinstellung in einem Rutsch.
 *
 * Eingangsweg-Logik (kein Radio): EIN optionales Feld "Eigene Bewerbungs-Mail".
 *  - Prefix leer  → Referenz-Code wird erzeugt (intake_mode='code').
 *  - Prefix gesetzt → dedizierte Mail-Adresse (intake_mode='mail').
 *
 * Die LOCKED_FIELDS (Geburtsdatum + Ausweisnummer) sind für den MA-Portal-Login
 * zwingend und werden serverseitig immer in $fields gemerged sowie im View als
 * checked+disabled gerendert.
 */
class Create extends Component
{
    /** Felder, die für den MA-Portal-Login zwingend sind und nicht abgewählt werden dürfen. */
    public const LOCKED_FIELDS = ['geburtsdatum', 'ausweisnummer'];

    public string $title = '';

    public string $mailPrefix = '';

    public ?int $ownerUserId = null;

    /** @var array<int, string> ausgewählte Feld-`name`s aus DirectHireSetupService::STANDARD_FIELDS */
    public array $fields = [];

    // Ergebnis-Props (steuern die Success-View)
    public ?string $createdRefCode = null;

    public ?string $createdMailAddress = null;

    public ?int $createdPositionId = null;

    public function mount(): void
    {
        $this->fields = array_map(fn (array $f) => $f['name'], DirectHireSetupService::STANDARD_FIELDS);
        $this->ownerUserId = Auth::id();
    }

    #[Computed]
    public function teamUsers()
    {
        return Auth::user()->currentTeam->users()->orderBy('name')->get();
    }

    /**
     * Aktive E-Mail-Eingangsadresse des Teams (für mailto-Link in der Success-View).
     * Null, wenn kein registrierter, aktiver Intake-Kanal mit Absender existiert.
     */
    #[Computed]
    public function teamIntakeAddress(): ?string
    {
        $teamId = (int) Auth::user()->currentTeam->id;

        $channelIds = RecIntakeChannel::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->pluck('comms_channel_id');

        if ($channelIds->isEmpty()) {
            return null;
        }

        $channel = CommsChannel::query()
            ->whereIn('id', $channelIds)
            ->where('team_id', $teamId)
            ->where('type', 'email')
            ->where('is_active', true)
            ->whereNotNull('sender_identifier')
            ->first();

        return $channel?->sender_identifier;
    }

    public function create(DirectHireSetupService $service): void
    {
        $teamUserIds = $this->teamUsers->pluck('id')->all();

        $this->validate([
            'title' => 'required|string|max:255',
            'mailPrefix' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9.\-]*$/i'],
            'ownerUserId' => ['required', 'integer', 'in:' . implode(',', $teamUserIds)],
        ], [
            'ownerUserId.in' => 'Der gewählte Verantwortliche gehört nicht zum Team.',
            'mailPrefix.regex' => 'Der Mail-Präfix darf nur Buchstaben, Ziffern, Punkt und Bindestrich enthalten.',
        ]);

        $intakeMode = trim($this->mailPrefix) !== '' ? 'mail' : 'code';

        // LOCKED_FIELDS immer erzwingen (auch falls aus dem Formular manipuliert).
        $fields = array_values(array_unique(array_merge($this->fields, self::LOCKED_FIELDS)));

        $input = [
            'title' => $this->title,
            'team_id' => (int) Auth::user()->currentTeam->id,
            'created_by_user_id' => (int) Auth::id(),
            'owner_user_id' => (int) $this->ownerUserId,
            'fields' => $fields,
            'intake_mode' => $intakeMode,
        ];

        if ($intakeMode === 'mail') {
            $input['mail_prefix'] = trim($this->mailPrefix);
        }

        try {
            $result = $service->create($input);
        } catch (\RuntimeException $e) {
            // Mail-Probleme: Eingaben bleiben durch Livewire erhalten, kein Datenverlust.
            $this->addError('mailPrefix', $e->getMessage());

            return;
        }

        $this->createdPositionId = $result['position']->id;
        $this->createdRefCode = $result['ref_code'] ?? null;
        $this->createdMailAddress = $result['channel']?->sender_identifier;
    }

    public function render()
    {
        return view('recruiting::livewire.direct-hire.create')
            ->layout('platform::layouts.app');
    }
}
