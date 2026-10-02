<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecConversationForward;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\Forward\ForwardFirstContact;
use Platform\Recruiting\Services\Comms\Forward\ForwardTargets;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;

/**
 * Reiter "Weitergeleitet" der HR-Kommunikation (Spec 02.10.2026): offene
 * Weiterleitungen aus der Dispo, Erstnachricht, Erledigt.
 *
 * Jeder Zugriff laeuft ueber forwardForTeam() — Livewire-Methoden sind mit
 * beliebigen IDs aufrufbar.
 */
class Forwards extends Component
{
    public ?int $selectedId = null;
    public ?string $actionError = null;

    private function teamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    private function forwardForTeam(int $id): ?RecConversationForward
    {
        return RecConversationForward::query()->forTeam($this->teamId())
            ->where('target', ForwardTargets::HR)->whereKey($id)->first();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function rows(): array
    {
        $rows = RecConversationForward::query()->openForTeam($this->teamId(), ForwardTargets::HR)
            ->orderByDesc('forwarded_at')->orderByDesc('id')->get();
        $pnrs = RecEmployee::query()->whereIn('id', $rows->pluck('rec_employee_id')->filter()->all())
            ->pluck('personnel_number', 'id');

        return $rows->map(fn (RecConversationForward $f) => [
            'id' => (int) $f->id,
            'name' => (string) $f->display_name,
            'phone' => (string) $f->phone,
            'pnr' => $f->rec_employee_id ? (string) ($pnrs[$f->rec_employee_id] ?? '') : '',
            'preview' => (string) ($f->messages[count($f->messages) - 1]['body'] ?? ''),
            'forwarded_at' => $f->forwarded_at->format('d.m. H:i'),
            'by' => (string) ($f->forwarded_by_name ?? ''),
            'first_contact' => $f->first_contact_at !== null,
        ])->all();
    }

    #[Computed]
    public function selected(): ?array
    {
        $f = $this->selectedId !== null ? $this->forwardForTeam($this->selectedId) : null;
        if ($f === null) {
            return null;
        }
        $service = app(ForwardFirstContact::class);
        $openThread = $f->first_contact_at === null ? $service->windowOpen($f) : null;
        $target = $f->target_thread_id ? CommsWhatsAppThread::find($f->target_thread_id) : null;

        return [
            'id' => (int) $f->id,
            'name' => (string) $f->display_name,
            'phone' => (string) $f->phone,
            'messages' => (array) $f->messages,
            'comment' => $f->comment,
            'by' => (string) ($f->forwarded_by_name ?? ''),
            'forwarded_at' => $f->forwarded_at->format('d.m.Y H:i'),
            'first_contact_at' => $f->first_contact_at?->format('d.m.Y H:i'),
            'last_error' => $f->last_error,
            'can_send' => $service->firstNameFor($f) !== '',
            'open_thread_id' => $openThread?->id ? (int) $openThread->id : null,
            'hr_messages' => $target ? app(DispoThreadDirectory::class)->messages($target, []) : [],
            'target_listed' => $target !== null && $target->last_inbound_at !== null,
            'target_thread_id' => $target?->id ? (int) $target->id : null,
        ];
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->actionError = null;
        unset($this->selected);
    }

    public function back(): void
    {
        $this->selectedId = null;
        $this->actionError = null;
    }

    public function sendFirstContact(int $id): void
    {
        $this->actionError = null;
        $f = $this->forwardForTeam($id);
        if ($f === null) {
            return;
        }
        $r = app(ForwardFirstContact::class)->send($f, Auth::user());
        if (!$r['ok']) {
            $this->actionError = $r['error'];
        }
        unset($this->rows, $this->selected);
    }

    /** HR-Fenster offen: Chat uebernehmen statt Vorlage. */
    public function openChat(int $id): void
    {
        $f = $this->forwardForTeam($id);
        $thread = $f ? app(ForwardFirstContact::class)->windowOpen($f) : null;
        $threadId = $thread?->id ?? ($f?->target_thread_id);
        if ($f === null || $threadId === null) {
            $this->actionError = 'Kein offener HR-Chat gefunden.';
            return;
        }
        if ($f->target_thread_id === null) {
            $f->update(['target_thread_id' => (int) $threadId]);
        }
        $this->dispatch('forward-open-thread', threadId: (int) $threadId);
    }

    public function markDone(int $id): void
    {
        $f = $this->forwardForTeam($id);
        if ($f !== null && $f->isOpen()) {
            $f->update(['done_at' => now(), 'done_by_user_id' => (int) Auth::id()]);
        }
        $this->selectedId = null;
        unset($this->rows, $this->selected);
        $this->dispatch('sidebar-refresh');
        $this->dispatch('forwards-changed');
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.forwards');
    }
}
