<?php

namespace Platform\Recruiting\Livewire\Posting;

use Livewire\Component;
use Livewire\Attributes\Computed;
use Platform\Crm\Models\CommsChannel;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Models\RecPostingExternalRef;
use Platform\Recruiting\Services\PostingRefCodeService;

class Show extends Component
{
    public RecPosting $posting;
    public string $description = '';

    // Datums-Felder als Y-m-d-Strings statt Model-Binding: das datetime-Cast
    // serialisiert Carbon als ISO-Timestamp, den <input type="date"> verwirft
    // (Feld springt sonst nach jedem Livewire-Roundtrip auf leer zurück).
    public ?string $publishedAt = null;
    public ?string $closesAt = null;

    public string $newRefSourceId = '';
    public string $newRefValue = '';

    public function mount(RecPosting $posting)
    {
        $this->posting = $posting->load(['position', 'applicants.crmContactLinks.contact', 'commsChannels', 'externalRefs.sourcePlatform']);
        $this->description = $posting->description ?? '';
        $this->publishedAt = $posting->published_at?->format('Y-m-d');
        $this->closesAt = $posting->closes_at?->format('Y-m-d');
    }

    public function rules(): array
    {
        return [
            'posting.title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'posting.status' => 'required|in:draft,published,closed',
            'posting.is_active' => 'boolean',
            'publishedAt' => 'nullable|date_format:Y-m-d',
            'closesAt' => 'nullable|date_format:Y-m-d',
            'posting.bedarf' => 'nullable|integer|min:0|max:10000',
            'posting.bewerbungs_faktor' => 'nullable|numeric|min:0.1|max:99.9',
        ];
    }

    public function save(): void
    {
        $this->validate();
        $this->posting->description = $this->description;
        $this->posting->published_at = $this->publishedAt ?: null;
        $this->posting->closes_at = $this->closesAt ?: null;
        $this->posting->save();
        session()->flash('message', 'Ausschreibung erfolgreich aktualisiert.');
    }

    public function publish(): void
    {
        $this->posting->status = 'published';
        $this->posting->published_at = now();
        $this->posting->save();
        $this->publishedAt = $this->posting->published_at->format('Y-m-d');
        session()->flash('message', 'Ausschreibung veröffentlicht.');
    }

    public function close(): void
    {
        $this->posting->status = 'closed';
        $this->posting->save();
        session()->flash('message', 'Ausschreibung geschlossen.');
    }

    public function deletePosting(): void
    {
        $this->posting->delete();
        session()->flash('message', 'Ausschreibung gelöscht.');
        $this->redirect(route('recruiting.postings.index'), navigate: true);
    }

    public function linkChannel(int $channelId): void
    {
        $channel = CommsChannel::where('is_active', true)->find($channelId);
        if (!$channel) {
            return;
        }

        $this->posting->commsChannels()->syncWithoutDetaching([$channelId]);
        $this->posting->load('commsChannels');
    }

    public function unlinkChannel(int $channelId): void
    {
        $this->posting->commsChannels()->detach($channelId);
        $this->posting->load('commsChannels');
    }

    #[Computed]
    public function availableChannels()
    {
        $team = auth()->user()->currentTeam;
        $rootTeam = method_exists($team, 'getRootTeam') ? $team->getRootTeam() : $team;

        $linkedIds = $this->posting->commsChannels->pluck('id')->toArray();

        return CommsChannel::query()
            ->where('team_id', $rootTeam->id)
            ->where('is_active', true)
            ->whereNotIn('id', $linkedIds)
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function isDirty()
    {
        return $this->posting->isDirty()
            || $this->description !== ($this->posting->getOriginal('description') ?? '')
            || ($this->publishedAt ?: null) !== $this->posting->getOriginal('published_at')?->format('Y-m-d')
            || ($this->closesAt ?: null) !== $this->posting->getOriginal('closes_at')?->format('Y-m-d');
    }

    #[Computed]
    public function availableSourcePlatforms()
    {
        return RecSourcePlatform::query()
            ->where('team_id', $this->posting->team_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function refCode(): ?string
    {
        return app(PostingRefCodeService::class)->codeFor($this->posting);
    }

    public function addExternalRef(): void
    {
        $this->validate([
            'newRefSourceId' => 'required|integer',
            'newRefValue' => 'required|string|max:255',
        ]);

        $sourceBelongsToTeam = RecSourcePlatform::query()
            ->whereKey((int) $this->newRefSourceId)
            ->where('team_id', $this->posting->team_id)
            ->exists();
        if (!$sourceBelongsToTeam) {
            $this->addError('newRefSourceId', 'Ungültige Quelle.');
            return;
        }

        $ref = RecPostingExternalRef::firstOrCreate(
            [
                'rec_source_platform_id' => (int) $this->newRefSourceId,
                'external_ref' => trim($this->newRefValue),
            ],
            [
                'rec_posting_id' => $this->posting->id,
                'team_id' => $this->posting->team_id,
            ],
        );

        if ($ref->rec_posting_id !== $this->posting->id) {
            $this->addError('newRefValue', 'Diese Referenz ist bereits einer anderen Ausschreibung zugeordnet.');
            return;
        }

        $this->newRefSourceId = '';
        $this->newRefValue = '';
        $this->posting->load('externalRefs.sourcePlatform');
    }

    public function removeExternalRef(int $refId): void
    {
        RecPostingExternalRef::query()
            ->where('rec_posting_id', $this->posting->id)
            ->whereKey($refId)
            ->delete();

        $this->posting->load('externalRefs.sourcePlatform');
    }

    public function render()
    {
        return view('recruiting::livewire.posting.show')
            ->layout('platform::layouts.app');
    }
}
