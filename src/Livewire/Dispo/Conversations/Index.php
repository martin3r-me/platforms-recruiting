<?php

namespace Platform\Recruiting\Livewire\Dispo\Conversations;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;
use Platform\Recruiting\Support\Filialen;

/**
 * Disposition → Kommunikation: ALLE Threads ALLER Dispo-Kanaele (Multi-Nummer
 * je WABA-Account), kategorisiert nach Filiale.
 *
 * LUECKENLOSIGKEITS-REGEL (Spec): Query filtert AUSSCHLIESSLICH auf
 * comms_channel_id IN <Set aller Dispo-Kanaele> — nie auf Kontext/Zuordnung.
 * Kanaele ohne Filial-Zuordnung landen im Tab "Sonstige", nie unsichtbar.
 * Zuordnung zur Filiale nur zur ANZEIGE via rec_dispo_filiale_settings;
 * Zuordnung zum MA nur zur ANZEIGE via Telefonnummer (DispoPhoneMatcher,
 * Ambiguitaet -> keine Zuordnung). Antworten gehen ueber den Kanal DES
 * jeweiligen Threads, nicht mehr ueber einen einzelnen Default-Kanal.
 */
class Index extends Component
{
    public ?int $selectedThreadId = null;
    public string $replyText = '';
    public string $filter = 'alle'; // alle | ungelesen
    public string $tabFilial = ''; // '' = Alle, 'sonstige' = unzugeordnet, sonst filial_nr als String
    /** Suche in Name/Nummer der Thread-Liste (nur Anzeige-Filter, aendert die Kanal-Regel nicht). */
    public string $search = '';
    public ?string $sendError = null;

    /** @var array<int, int|null>|null In-Request-Cache: comms_channel_id -> filial_nr (oder null-Eintrag existiert nicht, nur vorhandene Zuordnungen). */
    private ?array $channelFilialeMapCache = null;

    /** @return list<int> IDs aller Kanaele des Dispo-WABA-Accounts (Lueckenlosigkeit). */
    #[Computed]
    public function channelIds(): array
    {
        return DispoChannelResolver::dispoChannelIds();
    }

    #[Computed]
    public function matcher(): DispoPhoneMatcher
    {
        return new DispoPhoneMatcher(app(DispoEmployeeGateway::class)->phoneDirectory());
    }

    /**
     * Filial-Tabs inkl. "Alle" (erster) und ggf. "Sonstige" (letzter, nur
     * wenn unzugeordnete Kanaele Threads haben). Ungelesen-Zaehler ueber
     * EINE Aggregat-Query (kein N+1).
     *
     * @return list<array{key: string, label: string, unread: int}>
     */
    #[Computed]
    public function filialeTabs(): array
    {
        $channelIds = $this->channelIds;
        if ($channelIds === []) {
            return [];
        }

        $map = $this->channelFilialeMap();

        // EINE Aggregat-Query: Gesamt + Ungelesen je Kanal, gruppiert.
        $stats = CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $channelIds)
            ->selectRaw('comms_channel_id, COUNT(*) as total, SUM(CASE WHEN is_unread = 1 THEN 1 ELSE 0 END) as unread')
            ->groupBy('comms_channel_id')
            ->get()
            ->keyBy('comms_channel_id');

        $totalUnread = 0;
        $byFiliale = []; // filial_nr => unread-Summe
        $sonstigeUnread = 0;
        $sonstigeHatThreads = false;

        foreach ($channelIds as $cid) {
            $row = $stats->get($cid);
            $unread = $row ? (int) $row->unread : 0;
            $total = $row ? (int) $row->total : 0;
            $totalUnread += $unread;

            if (array_key_exists($cid, $map)) {
                $nr = $map[$cid];
                $byFiliale[$nr] = ($byFiliale[$nr] ?? 0) + $unread;
            } else {
                $sonstigeUnread += $unread;
                if ($total > 0) {
                    $sonstigeHatThreads = true;
                }
            }
        }

        ksort($byFiliale);

        $tabs = [['key' => '', 'label' => 'Alle', 'unread' => $totalUnread]];

        foreach ($byFiliale as $nr => $unread) {
            $tabs[] = [
                'key'    => (string) $nr,
                'label'  => Filialen::code($nr) ?? ('#' . $nr),
                'unread' => $unread,
            ];
        }

        if ($sonstigeHatThreads) {
            $tabs[] = ['key' => 'sonstige', 'label' => 'Sonstige', 'unread' => $sonstigeUnread];
        }

        return $tabs;
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function threads(): array
    {
        $channelIds = $this->channelIds;
        if ($channelIds === []) {
            return [];
        }

        $filterIds = $this->channelIdsForTab($this->tabFilial, $channelIds);
        if ($filterIds === []) {
            return [];
        }

        $query = CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $filterIds) // NUR Kanal-Filter (Spec-Regel!)
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

        $map = $this->channelFilialeMap();
        $search = mb_strtolower(trim($this->search));

        return $rows->map(function ($t) use ($names, $map) {
            $employeeId = $this->matcher->match($t->remote_phone_number);
            $filialNr = $map[(int) $t->comms_channel_id] ?? null;
            $name = $employeeId !== null ? ($names[$employeeId] ?? null) : null;
            $label = $name ?? (string) $t->remote_phone_number;
            $lastAt = $t->last_inbound_at ?? $t->last_outbound_at;
            $window = $this->windowInfo($t->last_inbound_at);

            return [
                'id'          => (int) $t->id,
                'label'       => $label,
                'phone'       => (string) $t->remote_phone_number,
                'initials'    => self::initials($name),
                'employee_id' => $employeeId,
                'preview'     => $this->humanPreview((string) ($t->last_message_preview ?? '')),
                'preview_is_template' => str_starts_with((string) ($t->last_message_preview ?? ''), 'Template:'),
                'is_unread'   => (bool) $t->is_unread,
                'last_at'     => optional($lastAt)->format('d.m.Y H:i'),
                'last_short'  => self::shortTime($lastAt),
                'filiale'     => $filialNr !== null ? (Filialen::code($filialNr) ?? ('#' . $filialNr)) : 'Sonstige',
                'filial_nr'   => $filialNr,
                'window'      => $window, // state: open | closed | none, left: "22 h" | "45 min" | null
            ];
        })->filter(function (array $row) use ($search) {
            if ($search === '') {
                return true;
            }
            $digits = preg_replace('/\D+/', '', $search) ?? '';
            return str_contains(mb_strtolower($row['label']), $search)
                || ($digits !== '' && str_contains(preg_replace('/\D+/', '', $row['phone']) ?? '', $digits));
        })->values()->all();
    }

    /**
     * Lesbare Labels der Dispo-Vorlagen: zuerst die in den Einstellungen gewaehlten
     * Templates (Name -> Rolle), sonst Heuristik ueber den Template-Namen. Nur Anzeige.
     *
     * @return array<string, string> template_name => Label
     */
    #[Computed]
    public function templateLabels(): array
    {
        if (!class_exists(\Platform\Integrations\Models\IntegrationsWhatsAppTemplate::class)) {
            return [];
        }
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));
        if ($teamId === 0) {
            return [];
        }
        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $roles = [
            'dispo_confirmation_template_id'  => 'Bestätigungsanfrage',
            'dispo_escalation_template_1_id'  => 'Erinnerung',
            'dispo_escalation_template_2_id'  => 'Letzte Erinnerung',
            'dispo_alarm_template_id'         => 'Dispo-Alarm',
        ];
        $byId = [];
        foreach ($roles as $key => $label) {
            $id = (int) ($settings->getSetting($key) ?: 0);
            if ($id > 0) {
                $byId[$id] = $label;
            }
        }
        if ($byId === []) {
            return [];
        }

        return \Platform\Integrations\Models\IntegrationsWhatsAppTemplate::query()
            ->whereIn('id', array_keys($byId))
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($t) => [(string) $t->name => $byId[(int) $t->id]])
            ->all();
    }

    /** Label fuer einen Template-Namen (Einstellungen zuerst, dann Namens-Heuristik, sonst der Name). */
    public function templateLabel(string $name): string
    {
        $labels = $this->templateLabels;
        if (isset($labels[$name])) {
            return $labels[$name];
        }
        $n = mb_strtolower($name);
        if (str_contains($n, 'reminder2') || str_contains($n, 'final')) {
            return 'Letzte Erinnerung';
        }
        if (str_contains($n, 'reminder') || str_contains($n, 'erinner')) {
            return 'Erinnerung';
        }
        if (str_contains($n, 'alarm')) {
            return 'Dispo-Alarm';
        }
        if (str_contains($n, 'bestaetig') || str_contains($n, 'confirm')) {
            return 'Bestätigungsanfrage';
        }

        return $name;
    }

    /** "Template: xyz" in der Vorschau -> "Bestätigungsanfrage gesendet". */
    private function humanPreview(string $preview): string
    {
        if (preg_match('/^Template:\s*(\S+)/u', $preview, $m) === 1) {
            return $this->templateLabel($m[1]) . ' gesendet';
        }

        return $preview;
    }

    /**
     * Antwortfenster-Status fuer die Anzeige. state: open (mit Restzeit) | closed | none
     * (der MA hat noch nie geschrieben — es gibt kein Fenster, nur Vorlagen).
     *
     * @return array{state: string, left: ?string}
     */
    private function windowInfo(?\DateTimeInterface $lastInboundAt): array
    {
        if ($lastInboundAt === null) {
            return ['state' => 'none', 'left' => null];
        }
        $now = now();
        if (!DispoTimeCalculator::isReplyWindowOpen($lastInboundAt, $now)) {
            return ['state' => 'closed', 'left' => null];
        }
        $deadline = \Illuminate\Support\Carbon::instance($lastInboundAt)->addHours(24);
        $mins = max(0, (int) $now->diffInMinutes($deadline, false));

        return ['state' => 'open', 'left' => $mins >= 60 ? intdiv($mins, 60) . ' h' : $mins . ' min'];
    }

    private static function initials(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '?';
        }
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first . $last);
    }

    /** "14:19" heute, "Gestern", sonst "Di" (innerhalb 7 Tage) bzw. "26.08." */
    private static function shortTime(?\DateTimeInterface $at): string
    {
        if ($at === null) {
            return '';
        }
        $c = \Illuminate\Support\Carbon::instance($at);
        if ($c->isToday()) {
            return $c->format('H:i');
        }
        if ($c->isYesterday()) {
            return 'Gestern';
        }
        if ($c->greaterThan(now()->subDays(6))) {
            return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$c->dayOfWeek];
        }

        return $c->format('d.m.');
    }

    /** Kopfdaten des gewaehlten Threads (Name, Nummer, Filiale, Fenster). */
    #[Computed]
    public function selectedInfo(): ?array
    {
        $thread = $this->selected;
        if ($thread === null) {
            return null;
        }
        $employeeId = $this->matcher->match($thread->remote_phone_number);
        $name = $employeeId !== null ? (app(DispoEmployeeGateway::class)->contacts([$employeeId])[$employeeId]['name'] ?? null) : null;
        $filialNr = $this->channelFilialeMap()[(int) $thread->comms_channel_id] ?? null;

        return [
            'label'    => $name ?? (string) $thread->remote_phone_number,
            'phone'    => (string) $thread->remote_phone_number,
            'initials' => self::initials($name),
            'matched'  => $employeeId !== null,
            'filiale'  => $filialNr !== null ? (Filialen::code($filialNr) ?? ('#' . $filialNr)) : 'Sonstige',
            'filial_nr' => $filialNr,
            'window'   => $this->windowInfo($thread->last_inbound_at),
            'is_unread' => (bool) $thread->is_unread,
        ];
    }

    /** "Heute", "Gestern", sonst "Mittwoch, 27. August". */
    private static function dayLabel(\Illuminate\Support\Carbon $c): string
    {
        if ($c->isToday()) {
            return 'Heute';
        }
        if ($c->isYesterday()) {
            return 'Gestern';
        }
        $wd = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'][$c->dayOfWeek];
        $mo = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'][(int) $c->format('n')];

        return $wd . ', ' . $c->format('j') . '. ' . $mo;
    }

    /** Mobil: zurueck zur Liste. */
    public function back(): void
    {
        $this->selectedThreadId = null;
        $this->replyText = '';
        $this->sendError = null;
        unset($this->selected, $this->messages, $this->employeePanel, $this->selectedInfo);
    }

    #[Computed]
    public function selected(): ?CommsWhatsAppThread
    {
        if ($this->selectedThreadId === null || $this->channelIds === []) {
            return null;
        }

        // Sicherheit: nur Threads AUS DEM DISPO-KANAL-SET laden, nie fremde IDs.
        return CommsWhatsAppThread::query()
            ->whereKey($this->selectedThreadId)
            ->whereIn('comms_channel_id', $this->channelIds)
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
            ->map(function ($m) {
                $at = $m->sent_at ?? $m->created_at;
                $isTemplate = ($m->body === null || $m->body === '') && !empty($m->template_name);

                return [
                    'direction'      => (string) $m->direction,
                    'kind'           => $isTemplate ? 'template' : 'text',
                    'body'           => (string) ($m->body ?? ''),
                    'template_label' => $isTemplate ? $this->templateLabel((string) $m->template_name) : null,
                    'status'         => $m->status,
                    'at'             => optional($at)->format('d.m.Y H:i'),
                    'time'           => optional($at)->format('H:i'),
                    'day'            => optional($at)->format('Y-m-d'),
                    'day_label'      => $at ? self::dayLabel(\Illuminate\Support\Carbon::instance($at)) : '',
                ];
            })->all();
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
        unset($this->threads, $this->selected, $this->messages, $this->employeePanel, $this->filialeTabs, $this->selectedInfo);
        $this->dispatch('sidebar-refresh');
    }

    public function toggleUnread(int $threadId): void
    {
        if ($this->channelIds === []) {
            return;
        }

        $thread = CommsWhatsAppThread::query()
            ->whereKey($threadId)
            ->whereIn('comms_channel_id', $this->channelIds)
            ->first();
        if ($thread) {
            $thread->is_unread ? $thread->markAsRead() : $thread->markAsUnread();
        }
        unset($this->threads, $this->filialeTabs);
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
        if ($thread === null) {
            $this->sendError = 'Kein Thread verfuegbar.';
            return;
        }

        // Antwort geht ueber den Kanal DES Threads (die Filial-Nummer, auf der er liegt) —
        // nicht mehr ueber einen einzelnen Default-Kanal.
        $channel = \Platform\Crm\Models\CommsChannel::find($thread->comms_channel_id);
        if ($channel === null) {
            $this->sendError = 'Kanal des Threads nicht gefunden.';
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
            unset($this->threads, $this->messages, $this->selected, $this->filialeTabs, $this->selectedInfo);
            $this->dispatch('reply-sent'); // Browser-Event: Textfeld-Hoehe zuruecksetzen
        } catch (\Throwable $e) {
            $this->sendError = 'Senden fehlgeschlagen: ' . $e->getMessage();
        }
    }

    /**
     * Kanal-IDs des Sets, die zum gewaehlten Tab gehoeren.
     * '' = alle; 'sonstige' = Kanaele ohne Filial-Zuordnung; sonst die
     * Kanaele der jeweiligen filial_nr.
     *
     * @param list<int> $channelIds
     * @return list<int>
     */
    private function channelIdsForTab(string $tab, array $channelIds): array
    {
        if ($tab === '') {
            return $channelIds;
        }

        $map = $this->channelFilialeMap();

        if ($tab === 'sonstige') {
            return array_values(array_filter($channelIds, fn ($cid) => !array_key_exists($cid, $map)));
        }

        $nr = (int) $tab;

        return array_values(array_filter($channelIds, fn ($cid) => ($map[$cid] ?? null) === $nr));
    }

    /**
     * Kanal -> Filial-Nr fuer die Kanaele des Sets (team-scoped), aus
     * rec_dispo_filiale_settings. In-Request gecacht (keine wiederholte
     * Query innerhalb desselben Requests).
     *
     * @return array<int, int>
     */
    private function channelFilialeMap(): array
    {
        if ($this->channelFilialeMapCache !== null) {
            return $this->channelFilialeMapCache;
        }

        $channelIds = $this->channelIds;
        if ($channelIds === []) {
            return $this->channelFilialeMapCache = [];
        }

        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));

        return $this->channelFilialeMapCache = RecDispoFilialeSettings::query()
            ->where('team_id', $teamId)
            ->whereIn('comms_channel_id', $channelIds)
            ->whereNotNull('comms_channel_id')
            ->pluck('filial_nr', 'comms_channel_id')
            ->map(fn ($nr) => (int) $nr)
            ->all();
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.conversations.index')
            ->layout('platform::layouts.app');
    }
}
