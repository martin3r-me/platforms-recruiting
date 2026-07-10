<?php

namespace Platform\Recruiting\Livewire\Conversations;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Comms\ConversationEscalation;
use Platform\Recruiting\Services\Comms\ConversationInboxReport;
use Platform\Recruiting\Services\Comms\ConversationInboxService;
use Platform\Recruiting\Services\Comms\HoldingTemplateSender;
use Platform\Recruiting\Services\Comms\OooAutoReplyHandler;
use Platform\Recruiting\Services\Comms\OooMode;
use Platform\Recruiting\Services\Comms\TeamClock;

/**
 * Kommunikations-Übersicht: zeigt unbeantwortete/ungelesene WhatsApp-Konversationen
 * mit Ampel nach dem EINEN 24h-Fenster (grün/gelb/rot/verpasst), filterbar. Erlaubt
 * gelesen-markieren sowie das Markieren mehrerer Konversationen und das Versenden
 * einer Eingangsbestätigung („wir melden uns") an alle Markierten in einem Schritt.
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

    /** Markierte Thread-IDs (für Bulk-Template-Versand). */
    public array $selected = [];

    /** OOO-Aktivierungsformular (Y-m-d-Strings aus <input type="date">). */
    public array $oooForm = ['from' => '', 'until' => '', 'back_at' => ''];
    public bool $showOooForm = false;

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

    /** Markiert alle aktuell gefilterten Zeilen. */
    public function selectAllVisible(): void
    {
        $this->selected = array_map(fn ($r) => (string) $r->threadId, $this->rows);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /** Name des konfigurierten Eingangsbestätigungs-Templates (oder null). */
    #[Computed]
    public function holdingTemplateName(): ?string
    {
        return app(HoldingTemplateSender::class)->configuredTemplateName($this->teamId());
    }

    /**
     * Sendet die konfigurierte Eingangsbestätigung („wir melden uns") an alle
     * markierten Konversationen — unabhängig vom 24h-Fenster (Template-Versand).
     */
    public function sendTemplateToSelected(): void
    {
        $selectedIds = array_map('strval', $this->selected);
        if ($selectedIds === []) {
            session()->flash('error', 'Bitte zuerst Konversationen markieren.');
            return;
        }

        // Über den vollen Report matchen, damit die Auswahl Filterwechsel übersteht.
        $recipients = [];
        foreach ($this->report->rows as $row) {
            if (in_array((string) $row->threadId, $selectedIds, true)) {
                $recipients[] = ['phone' => $row->phone, 'first_name' => $row->firstName];
            }
        }

        $result = app(HoldingTemplateSender::class)->sendToMany($this->teamId(), $recipients);

        if ($result['error'] !== null) {
            session()->flash('error', $result['error']);
        } else {
            $msg = '„Wir melden uns" an ' . $result['sent'] . ' Kontakt(e) gesendet.';
            if ($result['failed'] > 0) {
                $msg .= " {$result['failed']} fehlgeschlagen.";
            }
            if ($result['skipped'] > 0) {
                $msg .= " {$result['skipped']} ohne Nummer übersprungen.";
            }
            session()->flash('message', $msg);
        }

        $this->selected = [];
        unset($this->report, $this->rows);
        $this->dispatch('sidebar-refresh');
    }

    private function oooSettings(): RecApplicantSettings
    {
        return RecApplicantSettings::getOrCreateForTeam($this->teamId());
    }

    /** Heutiges Datum in der Team-Timezone — einzige "heute"-Quelle der Komponente. */
    private function teamToday(): string
    {
        return TeamClock::today($this->oooSettings()->getSetting('comms_timezone'));
    }

    /** off | pending | active — alleinige Quelle: OooMode (nie das rohe Flag). */
    #[Computed]
    public function oooState(): string
    {
        $s = $this->oooSettings();

        return OooMode::state(
            (bool) $s->getSetting('comms_ooo_enabled', false),
            $s->getSetting('comms_ooo_from'),
            $s->getSetting('comms_ooo_back_at'),
            $this->teamToday(),
        );
    }

    /** Anzeige-Daten fuer Banner (d.m.Y) + Template-Konfig-Status. */
    #[Computed]
    public function oooView(): array
    {
        $s = $this->oooSettings();
        $fmt = static fn (?string $ymd): ?string => $ymd ? \Carbon\Carbon::parse($ymd)->format('d.m.Y') : null;

        return [
            'from' => $fmt($s->getSetting('comms_ooo_from')),
            'back_at' => $fmt($s->getSetting('comms_ooo_back_at')),
            'template_configured' => app(\Platform\Recruiting\Services\Comms\HoldingTemplateSender::class)
                ->configuredTemplateName($this->teamId(), OooAutoReplyHandler::SETTINGS_KEY) !== null,
        ];
    }

    public function openOooForm(): void
    {
        if (!$this->oooView['template_configured']) {
            session()->flash('error', 'Kein Abwesenheits-Template konfiguriert (Einstellungen → Kommunikation).');
            return;
        }
        $this->oooForm = ['from' => $this->teamToday(), 'until' => '', 'back_at' => ''];
        $this->showOooForm = true;
    }

    /** Bis-Datum gesetzt → wieder_da mit bis+1 vorbefuellen (editierbar). */
    public function updated($property): void
    {
        if ($property === 'oooForm.until' && $this->oooForm['until'] !== '' && $this->oooForm['back_at'] === '') {
            $this->oooForm['back_at'] = \Carbon\Carbon::parse($this->oooForm['until'])->addDay()->format('Y-m-d');
        }
    }

    public function activateOoo(): void
    {
        if (!$this->oooView['template_configured']) {
            session()->flash('error', 'Kein Abwesenheits-Template konfiguriert (Einstellungen → Kommunikation).');
            return;
        }

        $from = $this->oooForm['from'];
        $until = $this->oooForm['until'];
        $backAt = $this->oooForm['back_at'];

        if ($from === '' || $until === '' || $backAt === '') {
            session()->flash('error', 'Bitte alle drei Daten angeben.');
            return;
        }
        // Y-m-d: String-Vergleich == chronologischer Vergleich
        if (!($from <= $until && $until < $backAt)) {
            session()->flash('error', 'Es muss gelten: von ≤ bis < wieder da.');
            return;
        }
        if ($backAt <= $this->teamToday()) {
            session()->flash('error', 'Das Wieder-da-Datum muss in der Zukunft liegen.');
            return;
        }

        $s = $this->oooSettings();
        $s->setSetting('comms_ooo_from', $from);
        $s->setSetting('comms_ooo_until', $until);
        $s->setSetting('comms_ooo_back_at', $backAt);
        $s->setSetting('comms_ooo_enabled', true);
        $s->save();

        $this->showOooForm = false;
        unset($this->oooState, $this->oooView);
        session()->flash('message', 'Abwesenheitsmodus gespeichert.');
    }

    public function deactivateOoo(): void
    {
        $s = $this->oooSettings();
        $s->setSetting('comms_ooo_enabled', false);
        $s->save();
        unset($this->oooState, $this->oooView);
        session()->flash('message', 'Abwesenheitsmodus deaktiviert.');
    }

    public function render()
    {
        return view('recruiting::livewire.conversations.index')
            ->layout('platform::layouts.app');
    }
}
