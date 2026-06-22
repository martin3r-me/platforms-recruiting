<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Services\Comms\ConversationEscalation;
use Platform\Recruiting\Services\Comms\ConversationInboxReport;
use Platform\Recruiting\Services\Comms\ConversationInboxService;

/**
 * Kommunikations-Übersicht: zeigt unbeantwortete/ungelesene WhatsApp-Konversationen
 * mit Ampel nach dem EINEN 24h-Fenster (grün/gelb/rot/verpasst), filterbar, und
 * erlaubt gelesen-markieren sowie das Re-Open-Template zu senden.
 */
class Index extends Component
{
    /** unread | escalation | all */
    public string $view = 'all';

    /** all | green | yellow | red | missed */
    public string $level = 'all';

    /** all | mine | <userId> */
    public string $owner = 'all';

    public string $search = '';

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    #[Computed]
    public function report(): ConversationInboxReport
    {
        return app(ConversationInboxService::class)->build($this->teamId());
    }

    /**
     * Wendet die UI-Filter auf die (vollständige) Report-Zeilenliste an.
     * Kennzahlen/Badges nutzen weiterhin den vollen Report.
     */
    #[Computed]
    public function rows(): array
    {
        $rows = $this->report->rows;
        $uid = (int) Auth::id();

        return array_values(array_filter($rows, function ($row) use ($uid) {
            $level = $row->escalation->level;

            // Ansicht
            if ($this->view === 'unread' && !$row->isUnread) {
                return false;
            }
            if ($this->view === 'escalation' && !in_array($level, [
                ConversationEscalation::LEVEL_MISSED,
                ConversationEscalation::LEVEL_RED,
                ConversationEscalation::LEVEL_YELLOW,
            ], true)) {
                return false;
            }

            // Level-Filter
            if ($this->level !== 'all' && $level !== $this->level) {
                return false;
            }

            // Owner-Filter
            if ($this->owner === 'mine' && (int) $row->ownerUserId !== $uid) {
                return false;
            }
            if ($this->owner !== 'all' && $this->owner !== 'mine'
                && (int) $row->ownerUserId !== (int) $this->owner) {
                return false;
            }

            // Suche
            if (trim($this->search) !== '') {
                $needle = mb_strtolower(trim($this->search));
                $haystack = mb_strtolower(($row->contactName ?? '') . ' ' . ($row->phone ?? ''));
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    #[Computed]
    public function teamUsers(): array
    {
        return Auth::user()->currentTeam->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    /** Lädt einen Thread sicher im Team-Kontext. */
    private function threadForTeam(int $threadId): ?CommsWhatsAppThread
    {
        return CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('team_id', $this->teamId())
            ->first();
    }

    public function markRead(int $threadId): void
    {
        $this->threadForTeam($threadId)?->markAsRead();
        unset($this->report, $this->rows);
        $this->dispatch('sidebar-refresh');
    }

    public function markUnread(int $threadId): void
    {
        $this->threadForTeam($threadId)?->markAsUnread();
        unset($this->report, $this->rows);
        $this->dispatch('sidebar-refresh');
    }

    public function markAllReadFiltered(): void
    {
        foreach ($this->rows as $row) {
            if ($row->isUnread) {
                $this->threadForTeam((int) $row->threadId)?->markAsRead();
            }
        }
        unset($this->report, $this->rows);
        $this->dispatch('sidebar-refresh');
    }

    public function sendHolding(int $applicantId): void
    {
        $applicant = RecApplicant::forTeam($this->teamId())->whereKey($applicantId)->first();
        if (!$applicant) {
            session()->flash('error', 'Bewerber nicht gefunden.');
            return;
        }

        if ($applicant->sendHoldingWhatsApp()) {
            session()->flash('message', 'Template gesendet — sobald geantwortet wird, ist das Fenster wieder offen.');
        } else {
            session()->flash('error', 'Versand fehlgeschlagen. Ist ein Re-Open-Template in den Einstellungen hinterlegt?');
        }
        unset($this->report, $this->rows);
        $this->dispatch('sidebar-refresh');
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.index')
            ->layout('platform::layouts.app');
    }
}
