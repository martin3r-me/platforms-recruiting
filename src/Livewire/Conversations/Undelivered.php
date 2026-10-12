<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\TeamClock;
use Platform\Recruiting\Services\Comms\UndeliveredQuery;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;

/**
 * Reiter "Nicht zugestellt" der HR-Kommunikation: Personen, bei denen unsere
 * letzte WhatsApp nicht ankam und die seitdem nichts geschrieben haben —
 * vor allem frische Bewerber, deren erstes Template scheiterte und die in
 * der Chat-Liste (nur Threads mit Eingang) nie auftauchen.
 *
 * Jeder Zugriff laeuft ueber UndeliveredQuery::threadForTeam() — Livewire-
 * Methoden sind mit beliebigen IDs aufrufbar.
 */
class Undelivered extends Component
{
    public ?int $selectedThreadId = null;

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function rows(): array
    {
        $tz = TeamClock::resolveTimezone(
            RecApplicantSettings::getOrCreateForTeam($this->teamId())->getSetting('comms_timezone'),
        );
        $owners = $this->ownerNames();

        return array_map(function (array $row) use ($tz, $owners) {
            $at = \Carbon\Carbon::createFromTimestamp($row['failed_at'], $tz);
            $row['failed_label'] = $at->isToday() ? 'heute ' . $at->format('H:i')
                : ($at->isYesterday() ? 'gestern ' . $at->format('H:i') : $at->format('d.m.y H:i'));
            $row['owner_name'] = $row['owner_user_id'] !== null ? ($owners[$row['owner_user_id']] ?? null) : null;

            return $row;
        }, app(UndeliveredQuery::class)->rows($this->teamId()));
    }

    /** @return array<int, string> */
    private function ownerNames(): array
    {
        return Auth::user()->currentTeam->users()
            ->get(['users.id', 'users.name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => (string) $u->name])
            ->all();
    }

    #[Computed]
    public function selected(): ?array
    {
        if ($this->selectedThreadId === null) {
            return null;
        }
        foreach ($this->rows as $row) {
            if ($row['thread_id'] === $this->selectedThreadId) {
                return $row;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function messages(): array
    {
        $thread = $this->selectedThreadId !== null
            ? app(UndeliveredQuery::class)->threadForTeam($this->teamId(), $this->selectedThreadId)
            : null;

        return $thread ? app(DispoThreadDirectory::class)->messages($thread, []) : [];
    }

    public function select(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        unset($this->selected, $this->messages);
    }

    public function back(): void
    {
        $this->selectedThreadId = null;
    }

    public function acknowledge(int $threadId): void
    {
        if (app(UndeliveredQuery::class)->threadForTeam($this->teamId(), $threadId) === null) {
            return;
        }
        app(UndeliveredQuery::class)->acknowledge($this->teamId(), $threadId, Auth::id() !== null ? (int) Auth::id() : null);

        if ($this->selectedThreadId === $threadId) {
            $this->selectedThreadId = null;
        }
        unset($this->rows, $this->selected, $this->messages);
        $this->dispatch('sidebar-refresh');
        $this->dispatch('undelivered-changed');
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.undelivered');
    }
}
