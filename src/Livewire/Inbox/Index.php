<?php

namespace Platform\Recruiting\Livewire\Inbox;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosting;
use Platform\Recruiting\Models\RecSourcePlatform;
use Platform\Recruiting\Services\IncomingApplicationService;
use Platform\Recruiting\Services\MatchResult;

/**
 * Eingangs-Inbox: lists applicants whose inbound mail did NOT match any
 * RecSourcePlatform pattern. HR can:
 *  - assign a source (→ moves applicant into normal flow + triggers enrichment)
 *  - discard as spam (soft-delete via $applicant->delete() — falls SoftDeletes
 *    Trait genutzt; ansonsten setzen wir is_active=false als Soft-Mark)
 */
class Index extends Component
{
    public string $search = '';

    #[Computed]
    public function unroutedApplicants()
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $query = RecApplicant::with([
            'crmContactLinks.contact.emailAddresses',
            'crmContactLinks.contact.phoneNumbers',
            'suggestedPosting.position',
        ])
            ->forTeam($teamId)
            ->unrouted()
            ->where('is_active', true)
            ->orderByDesc('created_at');

        if (!empty($this->search)) {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('notes', 'like', $term)
                    ->orWhereHas('crmContactLinks.contact', function ($c) use ($term) {
                        $c->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term);
                    });
            });
        }

        return $query->get();
    }

    #[Computed]
    public function availableSourcePlatforms()
    {
        return RecSourcePlatform::where('team_id', Auth::user()->currentTeam->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function openPostings()
    {
        return RecPosting::query()
            ->forTeam((int) Auth::user()->currentTeam->id)
            ->open()
            ->with('position')
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function totalCount(): int
    {
        return RecApplicant::forTeam(Auth::user()->currentTeam->id)
            ->unrouted()
            ->where('is_active', true)
            ->count();
    }

    public function assignSource(int $applicantId, int $sourceId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $applicant = RecApplicant::forTeam($teamId)->unrouted()->find($applicantId);
        if (!$applicant) {
            return;
        }

        $source = RecSourcePlatform::where('team_id', $teamId)->find($sourceId);
        if (!$source) {
            return;
        }

        $applicant->source_platform_id = $source->id;
        $applicant->is_unrouted = false;
        // Reset enrichment_status so the cronjob picks it up on next tick
        $applicant->enrichment_status = null;
        $applicant->save();

        unset($this->unroutedApplicants);
        unset($this->totalCount);

        session()->flash('message', "Bewerber wurde Quelle '{$source->name}' zugewiesen — wird jetzt normal verarbeitet.");
    }

    public function discardApplicant(int $applicantId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $applicant = RecApplicant::forTeam($teamId)->unrouted()->find($applicantId);
        if (!$applicant) {
            return;
        }

        // Soft-discard: deaktivieren statt hart löschen — Datensatz bleibt
        // für den Fall einer späteren Wieder-Sichtung in der DB.
        $applicant->is_active = false;
        $applicant->save();

        unset($this->unroutedApplicants);
        unset($this->totalCount);

        session()->flash('message', 'Eingang verworfen.');
    }

    public function confirmSuggestedPosting(int $applicantId): void
    {
        $teamId = (int) Auth::user()->currentTeam->id;
        $applicant = RecApplicant::forTeam($teamId)->unrouted()->find($applicantId);
        if (!$applicant) {
            return;
        }

        if (!$applicant->suggested_posting_id || !$applicant->suggestedPosting) {
            return;
        }

        app(IncomingApplicationService::class)->assignPosting(
            $applicant,
            new MatchResult(
                $applicant->suggestedPosting,
                MatchResult::VIA_MANUAL,
                reason: 'Inbox-Vorschlag bestätigt',
            ),
        );

        unset($this->unroutedApplicants);
        unset($this->totalCount);

        session()->flash('message', 'Bewerber wurde der vorgeschlagenen Ausschreibung zugeordnet.');
    }

    public function assignPosting(int $applicantId, string $postingId): void
    {
        if ($postingId === '' || !is_numeric($postingId)) {
            return;
        }

        $teamId = (int) Auth::user()->currentTeam->id;
        $applicant = RecApplicant::forTeam($teamId)->unrouted()->find($applicantId);
        if (!$applicant) {
            return;
        }

        $posting = RecPosting::forTeam($teamId)->find((int) $postingId);
        if (!$posting) {
            return;
        }

        app(IncomingApplicationService::class)->assignPosting(
            $applicant,
            new MatchResult($posting, MatchResult::VIA_MANUAL),
        );

        unset($this->unroutedApplicants);
        unset($this->totalCount);

        session()->flash('message', 'Bewerber wurde der Ausschreibung zugeordnet.');
    }

    public function render()
    {
        return view('recruiting::livewire.inbox.index')
            ->layout('platform::layouts.app');
    }
}
