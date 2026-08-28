<?php

namespace Platform\Recruiting\Livewire\Dispo\Conversations;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoFilialeSettings;
use Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityGroups;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoPhoneMatcher;
use Platform\Recruiting\Services\Zas\Dispo\DispoReplySender;
use Platform\Recruiting\Services\Zas\Dispo\DispoReplyWindow;
use Platform\Recruiting\Services\Zas\Dispo\DispoTemplateLabels;
use Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory;
use Platform\Recruiting\Support\Filialen;

/**
 * Disposition → Kommunikation: ALLE Threads ALLER Dispo-Kanaele (Multi-Nummer
 * je WABA-Account), kategorisiert nach Filiale.
 *
 * LUECKENLOSIGKEITS-REGEL (Spec): Query filtert AUSSCHLIESSLICH auf
 * comms_channel_id IN <Set aller Dispo-Kanaele> — nie auf Kontext/Zuordnung.
 * Kanaele ohne Filial-Zuordnung landen im Tab "Sonstige", nie unsichtbar.
 * Zuordnung zur Filiale nur zur ANZEIGE via rec_dispo_filiale_settings;
 * Zuordnung zum MA nur zur ANZEIGE — zuerst ueber den am Thread verlinkten
 * CRM-Kontakt (resolveEmployee, sicher), sonst ueber die Telefonnummer
 * (DispoPhoneMatcher, Ambiguitaet -> keine Zuordnung). Mehrere Mitarbeiter-
 * Datensaetze derselben Person (Dispo-Identitaet, siehe DispoIdentityResolver)
 * werden auf die kanonische id zusammengefasst — EINE Person, EIN Thread-Eintrag.
 * Antworten gehen ueber den Kanal DES jeweiligen Threads, nicht mehr ueber
 * einen einzelnen Default-Kanal.
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

    /** @var array<int, string>|null In-Request-Cache: employee_id -> Roh-Telefonnummer (aktive MA). */
    private ?array $phoneDirectoryCache = null;

    /** @return array<int, string> employee_id -> Roh-Telefonnummer */
    private function phoneDirectory(): array
    {
        return $this->phoneDirectoryCache ??= app(DispoEmployeeGateway::class)->phoneDirectory();
    }

    /** @return list<int> IDs aller Kanaele des Dispo-WABA-Accounts (Lueckenlosigkeit). */
    #[Computed]
    public function channelIds(): array
    {
        return DispoChannelResolver::dispoChannelIds();
    }

    /**
     * Dispo-Identitaet (Spec 2026-08-28): mehrere Mitarbeiter-Datensaetze
     * desselben CRM-Kontakts sind EINE Person. Einmal pro Request — matcher(),
     * resolveEmployee() und die PNr-Chips nutzen dieselbe Zuordnung.
     *
     * @return array{groups: array<int,list<int>>, canon: array<int,int>}
     */
    #[Computed]
    public function identity(): array
    {
        $groups = app(DispoIdentityResolver::class)->groupsFor(array_keys($this->phoneDirectory()));

        return ['groups' => $groups, 'canon' => DispoIdentityGroups::canonicalMap($groups)];
    }

    #[Computed]
    public function matcher(): DispoPhoneMatcher
    {
        $directory = $this->phoneDirectory(); // id => phone
        $canon = $this->identity['canon'];

        // Datensaetze derselben Person -> eine kanonische id mit allen ihren Nummern.
        $byCanonical = [];
        foreach ($directory as $id => $phone) {
            $byCanonical[$canon[(int) $id] ?? (int) $id][] = $phone;
        }

        return new DispoPhoneMatcher($byCanonical);
    }

    /**
     * Kontakt-Zuordnung fuer die aktuell im Kanal-Set liegenden Threads:
     * contact_id (des verlinkten CRM-Kontakts) -> kanonische Mitarbeiter-id.
     * Nur Threads mit contact_type = CrmContact zaehlen (Entkopplungs-Regel).
     * Kontakt -> erste aktive id -> kanonisch (mehrere aktive Treffer je
     * Kontakt sind heute nicht vorgesehen, die Gruppe fasst sie ohnehin zusammen).
     *
     * @return array<int, int> contact_id -> kanonische employee_id
     */
    #[Computed]
    public function employeeByContact(): array
    {
        $channelIds = $this->channelIds;
        if ($channelIds === []) {
            return [];
        }

        $crmTypes = [\Platform\Crm\Models\CrmContact::class, (new \Platform\Crm\Models\CrmContact())->getMorphClass()];

        $contactIds = CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $channelIds)
            ->whereNotNull('contact_id')
            ->whereIn('contact_type', $crmTypes)
            ->distinct()
            ->pluck('contact_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if ($contactIds === []) {
            return [];
        }

        $byContact = app(DispoIdentityResolver::class)->employeeIdsByContact($contactIds);
        $canon = $this->identity['canon'];

        $map = [];
        foreach ($byContact as $contactId => $ids) {
            if ($ids === []) {
                continue;
            }
            $firstActive = $ids[0];
            $map[(int) $contactId] = $canon[$firstActive] ?? $firstActive;
        }

        return $map;
    }

    /**
     * Normalisierte Nummern, die (nach Kanonisierung) zu >=2 verschiedenen
     * Personen gehoeren — echte Mehrdeutigkeit (mehrere Datensaetze derselben
     * Person auf derselben Nummer sind KEINE). Blade zeigt statt "kein MA"
     * "Nummer von N MA genutzt".
     *
     * @return array<string, int> normalisierte Nummer -> Anzahl verschiedener Personen
     */
    #[Computed]
    public function sharedPhones(): array
    {
        $canon = $this->identity['canon'];

        $idsByPhone = [];
        foreach ($this->phoneDirectory() as $id => $phone) {
            $normalized = DispoPhoneMatcher::normalize($phone);
            if ($normalized === null) {
                continue;
            }
            $canonId = $canon[(int) $id] ?? (int) $id;
            $idsByPhone[$normalized][$canonId] = true;
        }

        $shared = [];
        foreach ($idsByPhone as $phone => $ids) {
            if (count($ids) >= 2) {
                $shared[$phone] = count($ids);
            }
        }

        return $shared;
    }

    /**
     * MA-Zuordnung eines Threads: zuerst der verlinkte CRM-Kontakt (sicher,
     * kein Raten), sonst Fallback auf das Telefon-Matching. Beide Wege liefern
     * bereits die kanonische id der Dispo-Identitaetsgruppe.
     */
    private function resolveEmployee(CommsWhatsAppThread $t): ?int
    {
        $byContact = $this->employeeByContact; // contact_id => kanonische id
        $crmTypes = [\Platform\Crm\Models\CrmContact::class, (new \Platform\Crm\Models\CrmContact())->getMorphClass()];
        if ($t->contact_id && in_array((string) $t->contact_type, $crmTypes, true) && isset($byContact[(int) $t->contact_id])) {
            return $byContact[(int) $t->contact_id];
        }

        return $this->matcher->match($t->remote_phone_number);
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
            ->map(fn ($t) => $this->resolveEmployee($t))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $groups = $this->identity['groups'];

        // Alle Datensaetze der betroffenen Gruppen fuer Namen + PNr-Chips in EINER Abfrage.
        $groupIds = [];
        foreach ($matchedIds as $id) {
            foreach ($groups[$id] ?? [$id] as $gid) {
                $groupIds[$gid] = true;
            }
        }
        $groupContacts = $groupIds === [] ? [] : app(DispoEmployeeGateway::class)->contacts(array_keys($groupIds));

        $names = [];
        $pnrsByEmployee = [];
        foreach ($matchedIds as $id) {
            $names[$id] = $groupContacts[$id]['name'] ?? null;
            $pnrsByEmployee[$id] = collect($groups[$id] ?? [$id])
                ->map(fn ($gid) => $groupContacts[$gid]['personnel_number'] ?? '')
                ->filter(fn ($pnr) => $pnr !== '')
                ->values()
                ->all();
        }

        $map = $this->channelFilialeMap();
        $sharedPhones = $this->sharedPhones;
        $search = mb_strtolower(trim($this->search));

        return $rows->map(function ($t) use ($names, $pnrsByEmployee, $map, $sharedPhones) {
            $employeeId = $this->resolveEmployee($t);
            $filialNr = $map[(int) $t->comms_channel_id] ?? null;
            $name = $employeeId !== null ? ($names[$employeeId] ?? null) : null;
            $label = $name ?? (string) $t->remote_phone_number;
            $lastAt = $t->last_inbound_at ?? $t->last_outbound_at;
            $window = $this->windowInfo($t->last_inbound_at);
            $normalizedPhone = DispoPhoneMatcher::normalize($t->remote_phone_number);
            $sharedCount = $normalizedPhone !== null ? ($sharedPhones[$normalizedPhone] ?? 0) : 0;

            return [
                'id'          => (int) $t->id,
                'label'       => $label,
                'phone'       => (string) $t->remote_phone_number,
                'initials'    => self::initials($name),
                'employee_id' => $employeeId,
                'pnrs'        => $employeeId !== null ? ($pnrsByEmployee[$employeeId] ?? []) : [],
                'shared_count' => $sharedCount, // >=2: Nummer wird von mehreren Personen genutzt (echte Mehrdeutigkeit)
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
        $teamId = (int) (config('recruiting.zas.inbound_team_id') ?: (auth()->user()?->currentTeam?->id ?? 0));

        return DispoTemplateLabels::forTeam($teamId);
    }

    /** Label fuer einen Template-Namen (Einstellungen zuerst, dann Namens-Heuristik, sonst der Name). */
    public function templateLabel(string $name): string
    {
        return DispoTemplateLabels::label($name, $this->templateLabels);
    }

    /** "Template: xyz" in der Vorschau -> "Bestätigungsanfrage gesendet". */
    private function humanPreview(string $preview): string
    {
        return DispoTemplateLabels::humanPreview($preview, $this->templateLabels);
    }

    /**
     * Antwortfenster-Status fuer die Anzeige. state: open (mit Restzeit) | closed | none
     * (der MA hat noch nie geschrieben — es gibt kein Fenster, nur Vorlagen).
     *
     * @return array{state: string, left: ?string}
     */
    private function windowInfo(?\DateTimeInterface $lastInboundAt): array
    {
        return DispoReplyWindow::info($lastInboundAt, now());
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

    /** Kopfdaten des gewaehlten Threads (Name, Nummer, Filiale, Fenster, PNr der Gruppe). */
    #[Computed]
    public function selectedInfo(): ?array
    {
        $thread = $this->selected;
        if ($thread === null) {
            return null;
        }
        $employeeId = $this->resolveEmployee($thread);
        $groupIds = $employeeId !== null ? ($this->identity['groups'][$employeeId] ?? [$employeeId]) : [];
        $groupContacts = $groupIds === [] ? [] : app(DispoEmployeeGateway::class)->contacts($groupIds);
        $name = $employeeId !== null ? ($groupContacts[$employeeId]['name'] ?? null) : null;
        $pnrs = collect($groupIds)
            ->map(fn ($gid) => $groupContacts[$gid]['personnel_number'] ?? '')
            ->filter(fn ($pnr) => $pnr !== '')
            ->values()
            ->all();
        $token = $employeeId !== null ? (string) ($groupContacts[$employeeId]['portal_token'] ?? '') : '';
        $filialNr = $this->channelFilialeMap()[(int) $thread->comms_channel_id] ?? null;

        return [
            'label'    => $name ?? (string) $thread->remote_phone_number,
            'phone'    => (string) $thread->remote_phone_number,
            'initials' => self::initials($name),
            'matched'  => $employeeId !== null,
            'pnrs'     => $pnrs,
            // Runde 4 (#3): genau die Ansicht, die der MA sieht (kanonischer Datensatz; die
            // Seite zeigt ohnehin alle Datensaetze der Person).
            'portal_url' => $token !== '' ? route('recruiting.public.employee-assignments', ['token' => $token]) : null,
            'filiale'  => $filialNr !== null ? (Filialen::code($filialNr) ?? ('#' . $filialNr)) : 'Sonstige',
            'filial_nr' => $filialNr,
            'window'   => $this->windowInfo($thread->last_inbound_at),
            'is_unread' => (bool) $thread->is_unread,
        ];
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

        return app(DispoThreadDirectory::class)->messages($thread, $this->templateLabels);
    }

    /** @return list<array<string, mixed>> kommende Einsaetze des zugeordneten MA (ALLER Datensaetze der Gruppe) */
    #[Computed]
    public function employeePanel(): array
    {
        $thread = $this->selected;
        $employeeId = $thread ? $this->resolveEmployee($thread) : null;
        if ($employeeId === null) {
            return [];
        }
        $groupIds = $this->identity['groups'][$employeeId] ?? [$employeeId];

        return RecDispoAssignment::query()
            ->with('event')
            ->whereIn('rec_employee_id', $groupIds)
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
                'url'     => route('recruiting.dispo.events.show', ['eventId' => (int) $a->rec_dispo_event_id]),
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

        $thread = $this->selected;
        if ($thread === null) {
            $this->sendError = 'Kein Thread verfuegbar.';
            return;
        }

        $r = app(DispoReplySender::class)->send($thread, $this->replyText, auth()->user());
        if (!$r['ok']) {
            $this->sendError = $r['error'];
            return; // replyText NICHT leeren
        }

        $this->replyText = '';
        unset($this->threads, $this->messages, $this->selected, $this->filialeTabs, $this->selectedInfo);
        $this->dispatch('reply-sent'); // Browser-Event: Textfeld-Hoehe zuruecksetzen
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
