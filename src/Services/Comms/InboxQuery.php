<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Lesepfad der neuen Kommunikation.
 *
 * Zwei Unterschiede zum alten ConversationInboxService, beide bewusst:
 *
 * 1. GRUNDMENGE UEBER DEN KANAL. Kein Filter auf context_model, kein
 *    Zusammenfassen pro Person. Was auf der Recruiting-Nummer eingeht, steht
 *    in der Liste — auch ohne Bewerber (Fall 2474) und auch als zweiter
 *    Thread derselben Person (Fall #307).
 *
 * 2. ERST SCHNEIDEN, DANN ANREICHERN. Namen, Kontakte und Owner werden nur
 *    fuer die sichtbaren Zeilen geladen. Der alte Dienst laedt alle ~1000
 *    Threads samt Bewerbern bei jedem Render; fuer eine Seite mit
 *    20-Sekunden-Poll ist das zu teuer.
 *
 * @see docs/superpowers/specs/2026-09-15-kommunikation-chat-design.md
 */
final class InboxQuery
{
    /** @return array{unread:int, green:int, yellow:int, red:int, missed:int, handled:int, total:int} */
    public function counts(int $teamId, ?int $now = null): array
    {
        return $this->snapshot($teamId, new InboxFilter(), 0, 0, $now)['counts'];
    }

    /**
     * Loest EINEN Thread zu seiner InboxRow auf — ohne die volle Grundmenge
     * (scored() liest sonst ALLE Threads des Kanals) und ohne die
     * Bewerber-Volltabelle (allowedSubjectIds() laedt sonst jeden Bewerber
     * samt CRM-Kontakt, nur um einen Owner-/Namensfilter aufzuloesen, den es
     * hier gar nicht gibt). Fuer den Fall, dass ein bereits offener Chat
     * durch einen Filterwechsel aus der sichtbaren Seite gefallen ist: die
     * UI braucht dann nur noch Titel/Ampel/Owner dieser EINEN Zeile, kein
     * Snapshot des ganzen Kanals — und das bei jedem 20s-Poll erneut, solange
     * der Chat aus dem Filter draussen bleibt.
     *
     * Bewusst OHNE InboxFilter-Parameter: die Zeile wird unabhaengig vom
     * aktuellen Filterzustand aufgeloest (ein bereits geoeffneter Chat soll
     * seine Kopfzeile behalten, auch wenn er z.B. gerade nicht "erledigt"
     * ist, waehrend die Erledigt-Ansicht aktiv ist).
     *
     * `siblingCount` bleibt 0 — die Kopfzeile zeigt sie nicht an, und ihre
     * echte Berechnung braucht wieder einen Scan ueber alle Threads
     * derselben Nummer (genau die Kosten, die diese Methode vermeiden soll).
     */
    public function rowForThread(CommsWhatsAppThread $thread, int $teamId, ?int $now = null): ?InboxRow
    {
        if ($thread->last_inbound_at === null) {
            return null;
        }

        $now ??= time();
        $id = (int) $thread->id;
        $inboundAt = $thread->last_inbound_at->getTimestamp();

        $humanOutbound = $this->humanOutboundTimestamps([$id]);
        $handledAt = $this->handledTimestamps($teamId, [$id]);

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);

        $row = [
            'thread_id' => $id,
            'channel_id' => (int) $thread->comms_channel_id,
            'phone' => $thread->remote_phone_number,
            'context_model' => (string) ($thread->context_model ?? ''),
            'context_model_id' => $thread->context_model_id ? (int) $thread->context_model_id : null,
            'preview' => $thread->last_message_preview,
            'is_unread' => (bool) $thread->is_unread,
            'escalation' => ConversationEscalation::compute(
                $inboundAt,
                $humanOutbound[$id] ?? null,
                $now,
                $yellow,
                $red,
            ),
            'handled' => ConversationHandledState::isHandled($handledAt[$id] ?? null, $inboundAt),
            'siblings' => 0,
            'last_message_at' => self::lastMessageAt($inboundAt, $thread->last_outbound_at?->getTimestamp()),
        ];

        return $this->hydrate([$row])[0] ?? null;
    }

    /** @return array{rows: list<InboxRow>, total: int} */
    public function page(
        int $teamId,
        InboxFilter $filter,
        int $limit,
        int $offset,
        ?int $now = null,
    ): array {
        $snapshot = $this->snapshot($teamId, $filter, $limit, $offset, $now);

        return ['rows' => $snapshot['rows'], 'total' => $snapshot['total']];
    }

    /**
     * Ein Tick der Seite: Zaehler (Pillen) UND die aktuell sichtbare Seite in
     * EINEM Durchlauf. `scored()` — die teure Kanal-/Eskalations-Aufloesung —
     * laeuft dabei genau EINMAL; counts() und page() waren bis hierhin zwei
     * getrennte volle Durchlaeufe pro Render, obwohl jede Seite immer beide
     * braucht (Pillen + Liste).
     *
     * 'counts' bleibt bewusst OHNE Owner-/Suchfilter berechnet — die Pillen
     * zeigen den vollen Stand, nicht die eigene Filterung. 'rows'/'total'
     * sind MIT Filter.
     *
     * 'fallback' = true, wenn die Grundmenge ueber die engere Altmenge lief
     * (kein konfigurierter Kanal). Ohne dieses Flag muesste die UI
     * isConfigured() separat fragen — eine dritte Aufloesung, und eine, die
     * "nicht konfiguriert" nicht von "Exception beim Aufloesen geschluckt"
     * unterscheiden kann. Ein transienter DB-Fehler saehe damit aus wie eine
     * saubere Fehlkonfiguration und wuerde still den Verlustpfad
     * wiederherstellen, den dieses Feature beseitigt.
     *
     * @return array{counts: array{unread:int, green:int, yellow:int, red:int, missed:int, handled:int, total:int}, rows: list<InboxRow>, total: int, fallback: bool}
     */
    public function snapshot(
        int $teamId,
        InboxFilter $filter,
        int $limit,
        int $offset,
        ?int $now = null,
    ): array {
        $now ??= time();

        $scored = $this->scored($teamId, $now);
        $counts = $this->countsFromScored($scored['rows']);

        // Owner und Namenssuche brauchen Bewerber-Daten, die die duenne Stufe
        // nicht hat — deshalb EINMAL vorab die erlaubten Bewerber-IDs holen
        // statt pro Zeile zu fragen.
        $allowed = $this->allowedSubjectIds($teamId, $filter);

        $rows = array_values(array_filter(
            $scored['rows'],
            fn (array $row) => $this->matches($row, $filter, $allowed),
        ));

        usort($rows, static function (array $a, array $b): int {
            $orderA = ConversationInboxReport::levelOrder($a['escalation']->level);
            $orderB = ConversationInboxReport::levelOrder($b['escalation']->level);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            $expA = $a['escalation']->windowExpiresAt ?? PHP_INT_MAX;
            $expB = $b['escalation']->windowExpiresAt ?? PHP_INT_MAX;
            if ($expA !== $expB) {
                return $expA <=> $expB;
            }

            // Letzter Tiebreaker: thread_id. Ohne ihn haengt die Reihenfolge
            // bei Gleichstand (gleiches Level, gleiches windowExpiresAt) an
            // der zufaelligen DB-Rueckgabereihenfolge — kein ORDER BY in der
            // SQL, und PHPs Sort-Stabilitaet garantiert nur, dass die
            // urspruengliche ARRAY-Reihenfolge erhalten bleibt, nicht dass
            // die DB diese Reihenfolge zwischen zwei Aufrufen wiederholt.
            // Zwischen zwei "mehr laden"-Klicks koennte so eine Zeile
            // uebersprungen werden oder doppelt erscheinen — genau der
            // Verlustpfad, den dieses Feature beseitigen soll.
            return $a['thread_id'] <=> $b['thread_id'];
        });

        $total = count($rows);
        $slice = array_slice($rows, $offset, $limit);

        return [
            'counts' => $counts,
            'rows' => $this->hydrate($slice),
            'total' => $total,
            'fallback' => $scored['fallback'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{unread:int, green:int, yellow:int, red:int, missed:int, handled:int, total:int}
     */
    private function countsFromScored(array $rows): array
    {
        $counts = ['unread' => 0, 'green' => 0, 'yellow' => 0, 'red' => 0,
                   'missed' => 0, 'handled' => 0, 'total' => 0];

        foreach ($rows as $row) {
            if ($row['handled']) {
                $counts['handled']++;
                continue;
            }
            $counts['total']++;
            if ($row['is_unread']) {
                $counts['unread']++;
            }
            $level = $row['escalation']->level;
            if (isset($counts[$level])) {
                $counts[$level]++;
            }
        }

        return $counts;
    }

    /**
     * Duenne Zeilen des Kanal-Sets mit Eskalation und Erledigt-Zustand.
     * Noch OHNE Namen — die kosten Joins und werden erst fuer die sichtbare
     * Seite geholt.
     *
     * @return array{rows: list<array<string, mixed>>, fallback: bool}
     */
    private function scored(int $teamId, int $now): array
    {
        $channelIds = RecruitingChannelResolver::channelIds($teamId);
        $fallback = $channelIds === [];

        $query = CommsWhatsAppThread::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_inbound_at');

        if (!$fallback) {
            $query->whereIn('comms_channel_id', $channelIds);
        } else {
            // Rueckfall ohne konfiguriertes Konto: die alte, engere Menge.
            // Die UI sagt das an, damit niemand die Luecke fuer Vollstaendigkeit haelt.
            $query->whereIn('context_model', self::legacyContextModels())
                ->whereNotNull('context_model_id');
        }

        $threads = $query->get([
            'id', 'comms_channel_id', 'remote_phone_number', 'context_model',
            'context_model_id', 'is_unread', 'last_inbound_at', 'last_message_preview',
            'last_outbound_at',
        ]);

        $threadIds = $threads->map(fn ($t) => (int) $t->id)->all();
        $humanOutbound = $this->humanOutboundTimestamps($threadIds);
        $handledAt = $this->handledTimestamps($teamId, $threadIds);

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);

        // Geschwister je Nummer (letzte 10 Ziffern) zaehlen — nur fuer den Hinweis-Chip.
        $byDigits = [];
        foreach ($threads as $thread) {
            $byDigits[self::digits((string) $thread->remote_phone_number)][] = (int) $thread->id;
        }

        $rows = [];
        foreach ($threads as $thread) {
            $inboundAt = $thread->last_inbound_at?->getTimestamp();
            $id = (int) $thread->id;
            $digits = self::digits((string) $thread->remote_phone_number);

            $rows[] = [
                'thread_id' => $id,
                'channel_id' => (int) $thread->comms_channel_id,
                'phone' => $thread->remote_phone_number,
                'context_model' => (string) ($thread->context_model ?? ''),
                'context_model_id' => $thread->context_model_id ? (int) $thread->context_model_id : null,
                'preview' => $thread->last_message_preview,
                'is_unread' => (bool) $thread->is_unread,
                'escalation' => ConversationEscalation::compute(
                    $inboundAt,
                    $humanOutbound[$id] ?? null,
                    $now,
                    $yellow,
                    $red,
                ),
                'handled' => ConversationHandledState::isHandled($handledAt[$id] ?? null, $inboundAt),
                'siblings' => max(0, count($byDigits[$digits] ?? []) - 1),
                'last_message_at' => self::lastMessageAt($inboundAt, $thread->last_outbound_at?->getTimestamp()),
            ];
        }

        return ['rows' => $rows, 'fallback' => $fallback];
    }

    /**
     * @param ?array{owner: ?list<int>, searchApplicant: ?list<int>, searchEmployee: ?list<int>} $allowed
     *        null = keine Einschraenkung. 'owner' und 'searchApplicant' enthalten
     *        Bewerber-IDs, 'searchEmployee' Mitarbeiter-IDs — zwei getrennte
     *        ID-Raeume (Befund 2 der Abschluss-Durchsicht).
     */
    private function matches(array $row, InboxFilter $filter, ?array $allowed): bool
    {
        if ($row['handled'] !== $filter->handled) {
            return false;
        }

        $level = $row['escalation']->level;
        if ($filter->level === 'unread' && !$row['is_unread']) {
            return false;
        }
        if (!in_array($filter->level, ['all', 'unread'], true) && $level !== $filter->level) {
            return false;
        }

        // Zustaendigkeit haengt am Bewerber. Mitarbeiter-Chats und nicht
        // zugeordnete Chats haben keine — sie fallen bei aktivem Owner-Filter
        // heraus (wie in der alten Seite, wo owner dort null ist).
        //
        // Fix (Abschluss-Durchsicht, Befund 2, IMPORTANT): $allowed['owner']
        // enthaelt Bewerber-IDs, waehrend $row['context_model_id'] JEDER
        // Kontext-Typ sein kann (Mitarbeiter, blosser CrmContact, Fremd-
        // Kontext). Beide ID-Raeume sind unabhaengig voneinander vergeben —
        // bei ~1000 Threads sind Zahlenkollisionen real. Ohne die
        // isApplicantContext()-Pruefung matchte ein Mitarbeiter-Thread mit
        // derselben Zahl als context_model_id faelschlich den Owner-Filter
        // eines Bewerbers.
        if ($allowed !== null && $allowed['owner'] !== null) {
            if ($row['context_model_id'] === null
                || !$this->isApplicantContext((string) $row['context_model'])
                || !in_array((int) $row['context_model_id'], $allowed['owner'], true)) {
                return false;
            }
        }

        if ($filter->search !== '') {
            $needle = mb_strtolower(trim($filter->search));
            $phoneTrifft = str_contains(mb_strtolower((string) $row['phone']), $needle);

            // Gleiche Trennung wie beim Owner-Filter: eine gefundene
            // Bewerber-ID darf nur Bewerber-Zeilen treffen, eine gefundene
            // Mitarbeiter-ID nur Mitarbeiter-Zeilen — sonst matcht wieder
            // eine zufaellige ID-Kollision zwischen den beiden Raeumen.
            $nameTrifft = $allowed !== null
                && $row['context_model_id'] !== null
                && (
                    ($allowed['searchApplicant'] !== null
                        && $this->isApplicantContext((string) $row['context_model'])
                        && in_array((int) $row['context_model_id'], $allowed['searchApplicant'], true))
                    || ($allowed['searchEmployee'] !== null
                        && $this->isEmployeeContext((string) $row['context_model'])
                        && in_array((int) $row['context_model_id'], $allowed['searchEmployee'], true))
                );

            if (!$phoneTrifft && !$nameTrifft) {
                return false;
            }
        }

        return true;
    }

    /** @see hydrate() — dieselbe Zwei-Alias-Pruefung (Morph-Map ODER volle Klasse). */
    private function isApplicantContext(string $contextModel): bool
    {
        static $morph;
        $morph ??= (new RecApplicant)->getMorphClass();

        return in_array($contextModel, [$morph, RecApplicant::class], true);
    }

    /** @see hydrate() — RecEmployee steht bewusst NICHT in der Morph-Map (siehe dort). */
    private function isEmployeeContext(string $contextModel): bool
    {
        return $contextModel === RecEmployee::class;
    }

    /**
     * Loest Owner- und Namensfilter EINMAL in IDs auf.
     * Gibt null zurueck, wenn keiner der beiden Filter aktiv ist.
     *
     * Fix (Abschluss-Durchsicht, Befund 3, IMPORTANT): der Suchzweig lud
     * bisher ALLE Bewerber des Teams samt CRM-Kontakten (get()) und filterte
     * in PHP — bei jedem Tastendruck UND bei jedem 20-Sekunden-Poll, solange
     * das Suchfeld gefuellt ist. Die Namenssuche laeuft jetzt ueber whereHas()
     * mit LIKE in der DB, und Mitarbeiter werden zusaetzlich aufgeloest
     * (searchEmployee) — vorher war ein Mitarbeiter-Chat per Namenssuche gar
     * nicht auffindbar, weil nur RecApplicant-Namen aufgeloest wurden.
     *
     * @return ?array{owner: ?list<int>, searchApplicant: ?list<int>, searchEmployee: ?list<int>}
     */
    private function allowedSubjectIds(int $teamId, InboxFilter $filter): ?array
    {
        $ownerAktiv = $filter->owner !== 'all';
        $sucheAktiv = trim($filter->search) !== '';

        if (!$ownerAktiv && !$sucheAktiv) {
            return null;
        }

        $ownerIds = null;
        if ($ownerAktiv) {
            $userId = $filter->owner === 'mine'
                ? (int) $filter->currentUserId
                : (int) $filter->owner;

            $ownerIds = RecApplicant::query()
                ->where('team_id', $teamId)
                ->where('owned_by_user_id', $userId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $searchApplicantIds = null;
        $searchEmployeeIds = null;
        if ($sucheAktiv) {
            // Bewusst OHNE CONCAT(first_name, ' ', last_name) — anders als im
            // Suchzweig von Livewire\Applicant\Index: CONCAT ist MySQL-Syntax
            // und dieses Modul testet den Lesepfad gegen SQLite (Capsule,
            // kein voller App-Boot). first_name/last_name je einzeln per LIKE
            // deckt die in diesem Feature verlangte Namenssuche ab, bleibt
            // aber auf beiden Treibern lauffaehig.
            $like = '%' . trim($filter->search) . '%';

            $searchApplicantIds = RecApplicant::query()
                ->where('team_id', $teamId)
                ->whereHas('crmContactLinks.contact', function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $searchEmployeeIds = RecEmployee::query()
                ->where('team_id', $teamId)
                ->where(function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return ['owner' => $ownerIds, 'searchApplicant' => $searchApplicantIds, 'searchEmployee' => $searchEmployeeIds];
    }

    /**
     * Namen, Owner und Links — nur fuer die sichtbaren Zeilen.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<InboxRow>
     */
    private function hydrate(array $rows): array
    {
        $applicantMorph = (new RecApplicant)->getMorphClass();
        $applicantIds = [];
        $employeeIds = [];

        foreach ($rows as $row) {
            if ($row['context_model_id'] === null) {
                continue;
            }
            if ($row['context_model'] === RecEmployee::class) {
                $employeeIds[] = $row['context_model_id'];
            } elseif (in_array($row['context_model'], [$applicantMorph, RecApplicant::class], true)) {
                $applicantIds[] = $row['context_model_id'];
            }
        }

        $applicants = RecApplicant::query()
            ->with(['crmContactLinks.contact'])
            ->whereIn('id', $applicantIds)
            ->get()
            ->keyBy('id');

        $employees = RecEmployee::query()
            ->whereIn('id', $employeeIds)
            ->get(['id', 'first_name', 'last_name', 'rec_applicant_id'])
            ->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $type = 'unassigned';
            $name = null;
            $firstName = null;
            $owner = null;
            $url = null;
            $subjectId = $row['context_model_id'];
            $contextLabel = null;

            if ($row['context_model'] === RecEmployee::class && $subjectId !== null) {
                $type = 'employee';
                $employee = $employees->get($subjectId);
                $name = $employee ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : null;
                $firstName = $employee?->first_name;
                $url = $employee && $employee->rec_applicant_id
                    ? $this->safeRoute('recruiting.applicants.show', ['applicant' => $employee->rec_applicant_id])
                    : ($employee ? $this->safeRoute('recruiting.employees.show', ['employee' => $subjectId]) : null);
            } elseif (in_array($row['context_model'], [$applicantMorph, RecApplicant::class], true) && $subjectId !== null) {
                $type = 'applicant';
                $applicant = $applicants->get($subjectId);
                $contact = $applicant?->crmContactLinks->first()?->contact;
                $name = $contact?->full_name;
                $firstName = $contact?->first_name;
                $owner = $applicant?->owned_by_user_id;
                $url = $applicant ? $this->safeRoute('recruiting.applicants.show', ['applicant' => $subjectId]) : null;
            } elseif ($row['context_model'] !== '' && !self::isBareContact($row['context_model'])) {
                $contextLabel = $row['context_model'];
            }

            $out[] = new InboxRow(
                threadId: $row['thread_id'],
                subjectType: $type,
                subjectId: $type === 'unassigned' ? null : $subjectId,
                url: $url,
                title: $name ?: ((string) $row['phone'] ?: 'Unbekannt'),
                firstName: $firstName,
                preview: $row['preview'],
                phone: $row['phone'],
                ownerUserId: $owner,
                isUnread: $row['is_unread'],
                escalation: $row['escalation'],
                contextLabel: $contextLabel,
                siblingCount: (int) $row['siblings'],
                lastMessageAt: $row['last_message_at'] ?? null,
            );
        }

        return $out;
    }

    /**
     * Letzter MENSCHLICHER Ausgang je Thread. Auto-Quittungen (OOO, Voice)
     * zaehlen NICHT — sonst gilt ein Chat als beantwortet, den nie jemand
     * gelesen hat. Kein Rueckfall auf thread.last_outbound_at: die Spalte
     * wird von der Auto-Antwort mitgezogen.
     *
     * @param list<int> $threadIds
     * @return array<int, int>
     */
    private function humanOutboundTimestamps(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', $threadIds)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            ->groupBy('comms_whatsapp_thread_id')
            ->selectRaw('comms_whatsapp_thread_id, MAX(created_at) AS last_human_outbound_at')
            ->pluck('last_human_outbound_at', 'comms_whatsapp_thread_id')
            ->map(fn ($value) => \Carbon\Carbon::parse((string) $value)->getTimestamp())
            ->all();
    }

    /**
     * @param list<int> $threadIds
     * @return array<int, int>
     */
    private function handledTimestamps(int $teamId, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return RecConversationHandled::query()
            ->where('team_id', $teamId)
            ->whereIn('comms_whatsapp_thread_id', $threadIds)
            ->get(['comms_whatsapp_thread_id', 'handled_at'])
            ->mapWithKeys(fn ($row) => [
                (int) $row->comms_whatsapp_thread_id => $row->handled_at->getTimestamp(),
            ])
            ->all();
    }

    /** @return list<string> */
    private static function legacyContextModels(): array
    {
        return [(new RecApplicant)->getMorphClass(), RecApplicant::class, RecEmployee::class];
    }

    private static function isBareContact(string $contextModel): bool
    {
        return ThreadContextGate::isBareContactContext($contextModel);
    }

    private static function digits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return substr($digits, -10);
    }

    /**
     * Zeitstempel der letzten Nachricht (Eingang ODER Ausgang, je spaeter) —
     * fuer die Uhrzeit-Anzeige rechts in der Listenzeile (Befund 6 der
     * Abschluss-Durchsicht). Anders als humanOutboundTimestamps() (nur
     * menschliche Antworten, fuer die Eskalation) zaehlt hier JEDE
     * Nachricht inkl. Auto-Antwort — die Uhrzeitanzeige urteilt nicht,
     * wer geantwortet hat, sie zeigt nur, wann zuletzt etwas geschrieben wurde.
     */
    private static function lastMessageAt(?int $inboundAt, ?int $outboundAt): int
    {
        return max($inboundAt ?? 0, $outboundAt ?? 0);
    }

    /**
     * Link bauen, ohne an einem fehlenden Router zu sterben.
     *
     * Der alte ConversationInboxService ruft route() direkt — und ist genau
     * deshalb ohne einen einzigen Integrationstest geblieben (die Capsule-
     * Tests dieses Moduls booten kein Laravel und haben keinen Router). Der
     * Link ist Beiwerk; die Sichtbarkeit einer Zeile darf nicht daran haengen.
     */
    private function safeRoute(string $name, array $params): ?string
    {
        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
