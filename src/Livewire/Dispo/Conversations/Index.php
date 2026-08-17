<?php

namespace Platform\Recruiting\Livewire\Dispo\Conversations;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;

/**
 * Disposition → Kommunikation: ALLE Threads des Dispo-Kanals.
 *
 * LUECKENLOSIGKEITS-REGEL (Spec): Query filtert AUSSCHLIESSLICH auf
 * comms_channel_id — nie auf Kontext/Zuordnung. Threads ohne MA-Match
 * erscheinen mit Rohnummer. Zuordnung nur zur ANZEIGE via Telefonnummer
 * (DispoPhoneMatcher, Ambiguitaet -> keine Zuordnung).
 */
class Index extends Component
{
    public ?int $selectedThreadId = null;
    public string $replyText = '';
    public string $filter = 'alle'; // alle | ungelesen
    public ?string $sendError = null;

    #[Computed]
    public function channelId(): ?int
    {
        return DispoChannelResolver::resolve()?->id;
    }

    #[Computed]
    public function matcher(): DispoPhoneMatcher
    {
        return new DispoPhoneMatcher(app(DispoEmployeeGateway::class)->phoneDirectory());
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function threads(): array
    {
        if ($this->channelId === null) {
            return [];
        }

        $query = CommsWhatsAppThread::query()
            ->where('comms_channel_id', $this->channelId) // NUR Kanal-Filter (Spec-Regel!)
            ->orderByDesc('is_unread')
            ->orderByDesc('updated_at');

        if ($this->filter === 'ungelesen') {
            $query->where('is_unread', true);
        }

        $rows = $query->limit(200)->get();

        $matchedIds = $rows
            ->map(fn ($t) => $this->matcher->match($t->remote_phone_number))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $names = $matchedIds === [] ? [] : collect(app(DispoEmployeeGateway::class)->contacts($matchedIds))
            ->map(fn ($c) => $c['name'])
            ->all();

        return $rows->map(function ($t) use ($names) {
            $employeeId = $this->matcher->match($t->remote_phone_number);

            return [
                'id'          => (int) $t->id,
                'label'       => ($employeeId !== null ? ($names[$employeeId] ?? null) : null) ?? (string) $t->remote_phone_number,
                'employee_id' => $employeeId,
                'preview'     => (string) ($t->last_message_preview ?? ''),
                'is_unread'   => (bool) $t->is_unread,
                'last_at'     => optional($t->last_inbound_at ?? $t->last_outbound_at)->format('d.m.Y H:i'),
            ];
        })->all();
    }

    #[Computed]
    public function selected(): ?CommsWhatsAppThread
    {
        if ($this->selectedThreadId === null || $this->channelId === null) {
            return null;
        }

        // Sicherheit: nur Threads DES Dispo-Kanals laden, nie fremde IDs.
        return CommsWhatsAppThread::query()
            ->whereKey($this->selectedThreadId)
            ->where('comms_channel_id', $this->channelId)
            ->first();
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function messages(): array
    {
        $thread = $this->selected;
        if ($thread === null) {
            return [];
        }

        return $thread->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'direction' => (string) $m->direction,
                'body'      => (string) ($m->body ?? ($m->template_name ? 'Template: ' . $m->template_name : '')),
                'status'    => $m->status,
                'at'        => optional($m->sent_at ?? $m->created_at)->format('d.m.Y H:i'),
            ])->all();
    }

    /** @return list<array<string, mixed>> kommende Einsaetze des zugeordneten MA */
    #[Computed]
    public function employeePanel(): array
    {
        $thread = $this->selected;
        $employeeId = $thread ? $this->matcher->match($thread->remote_phone_number) : null;
        if ($employeeId === null) {
            return [];
        }

        return RecDispoAssignment::query()
            ->with('event')
            ->where('rec_employee_id', $employeeId)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereDate('datum', '>=', now()->toDateString())
            ->whereNull('missing_since')
            ->orderBy('datum')->orderBy('von')
            ->limit(10)
            ->get()
            ->map(fn ($a) => [
                'datum'   => $a->datum->format('d.m.Y'),
                'zeit'    => $a->von ? ($a->von . ($a->bis ? '–' . $a->bis : '')) : null,
                'event'   => (string) ($a->event->name ?? $a->event->einsatz_ref),
                'status'  => $a->deletion_marked_at ? 'geloescht_gemeldet'
                    : ($a->confirmed_at ? 'bestaetigt'
                    : ($a->reminder_sent_at ? 'angeschrieben' : 'offen')),
            ])->all();
    }

    public function select(int $threadId): void
    {
        $this->selectedThreadId = $threadId;
        $this->replyText = '';
        $this->sendError = null;
        $this->selected?->markAsRead();
        unset($this->threads, $this->selected, $this->messages, $this->employeePanel);
        $this->dispatch('sidebar-refresh');
    }

    public function toggleUnread(int $threadId): void
    {
        if ($this->channelId === null) {
            return;
        }

        $thread = CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->where('comms_channel_id', $this->channelId)
            ->first();
        if ($thread) {
            $thread->is_unread ? $thread->markAsRead() : $thread->markAsUnread();
        }
        unset($this->threads);
        $this->dispatch('sidebar-refresh');
    }

    public function sendReply(): void
    {
        $this->sendError = null;
        $text = trim($this->replyText);
        if ($text === '') {
            $this->sendError = 'Bitte eine Nachricht eingeben.';
            return;
        }

        $thread = $this->selected;
        $channel = DispoChannelResolver::resolve();
        if ($thread === null || $channel === null) {
            $this->sendError = 'Kein Thread/Kanal verfuegbar.';
            return;
        }

        if (!DispoTimeCalculator::isReplyWindowOpen($thread->last_inbound_at, now())) {
            $this->sendError = '24h-Fenster abgelaufen — Erinnerungen laufen ueber die VA-Seite.';
            return;
        }

        try {
            $message = app(\Platform\Crm\Services\Comms\WhatsAppMetaService::class)->sendText(
                channel: $channel,
                to:      (string) $thread->remote_phone_number,
                message: $text,
                sender:  auth()->user(),
            );

            if (($message->status ?? null) === 'failed') {
                $this->sendError = 'Meta hat den Versand abgelehnt: '
                    . (string) ($message->meta_payload['error']['message'] ?? 'unbekannter Grund');
                return; // replyText NICHT leeren
            }

            $this->replyText = '';
            unset($this->threads, $this->messages, $this->selected);
        } catch (\Throwable $e) {
            $this->sendError = 'Senden fehlgeschlagen: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.conversations.index')
            ->layout('platform::layouts.app');
    }
}
