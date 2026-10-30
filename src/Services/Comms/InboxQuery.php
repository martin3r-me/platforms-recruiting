<?php

namespace Platform\Recruiting\Services\Comms;

use Platform\Crm\Models\CommsWhatsAppMessage;
use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Models\RecConversationHandled;
use Platform\Recruiting\Models\RecConversationTopic;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\Forward\DispoForwardChannels;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\KostenstellenBloecke;

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
    /** Themen der Themen-Auswahl in Pillen-Reihenfolge. */
    public const TOPICS = [RecConversationTopic::TOPIC_DISPO, RecConversationTopic::TOPIC_LOHN, RecConversationTopic::TOPIC_HR];

    /**
     * @param ?DispoForwardChannels $dispoKanaele Beschriftung „Dispo → Köln“;
     *        Override fuer Tests, sonst lazy erzeugt — und nur, wenn eine
     *        sichtbare Zeile ein Dispo-Thema mit Kanal traegt.
     */
    public function __construct(private ?DispoForwardChannels $dispoKanaele = null) {}

    /** @return array{unread:int, green:int, yellow:int, red:int, missed:int, followup:int, parked:int, total:int, applicants:int, employees:int, topics: array{dispo:int, lohn:int, hr:int}} */
    public function counts(int $teamId, ?int $now = null): array
    {
        // Ohne Filial-Aufloesung und ohne „Offen lassen“-Marker: die Navigation
        // links (Sidebar, 30s-Poll auf JEDER Recruiting-Seite) braucht nur die
        // Gesamtzahlen und zeigt „Offen“ nicht — 'followup' ist hier daher
        // immer 0 (Review Task 6, M2).
        return $this->snapshot($teamId, new InboxFilter(), 0, 0, $now, false, false)['counts'];
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
     * seine Kopfzeile behalten, auch wenn er z.B. unter "Verpasst" gerade
     * nicht mehr auftaucht, weil das Oeffnen ihn gelesen hat).
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
        $themen = $this->aktuelleThemen($teamId, [$id]);
        $offen = $this->offeneMarker($teamId, [$id]);

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);
        // Themen-Auswahl aus → nichts geparkt, alles normal bei HR.
        $parkenAn = (bool) $settings->getSetting('comms_topic_enabled', false);
        $hrAntwort = $parkenAn ? $this->hrAntwortZeitpunkte(self::geparkteIds($themen)) : [];

        $row = [
            'thread_id' => $id,
            'channel_id' => (int) $thread->comms_channel_id,
            'phone' => $thread->remote_phone_number,
            'context_model' => (string) ($thread->context_model ?? ''),
            'context_model_id' => $thread->context_model_id ? (int) $thread->context_model_id : null,
            'preview' => $thread->last_message_preview,
            ...self::openState(
                (bool) $thread->is_unread,
                $handledAt[$id] ?? null,
                $inboundAt,
                $humanOutbound[$id] ?? null,
                $now,
                $yellow,
                $red,
            ),
            'siblings' => 0,
            'last_message_at' => self::lastMessageAt($inboundAt, $thread->last_outbound_at?->getTimestamp()),
            ...self::themaFelder($themen[$id] ?? null),
            'parked' => $parkenAn && self::geparkt($themen[$id] ?? null, $hrAntwort[$id] ?? null),
            'followup' => $offen[$id] ?? null,
        ];

        return $this->hydrate([$row], $teamId)[0] ?? null;
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
     * 'counts.topics' = Chats je Thema — wie Alle/Bewerber/Mitarbeiter ueber
     * die Filial-Basis, aber unabhaengig von der gewaehlten Thema-Pille
     * (jede Pille zeigt ihre eigene Zahl).
     *
     * @return array{counts: array{unread:int, green:int, yellow:int, red:int, missed:int, followup:int, parked:int, total:int, applicants:int, employees:int, topics: array{dispo:int, lohn:int, hr:int}}, rows: list<InboxRow>, total: int, fallback: bool, filialen: array<string, int>}
     */
    public function snapshot(
        int $teamId,
        InboxFilter $filter,
        int $limit,
        int $offset,
        ?int $now = null,
        bool $withFiliale = true,
        bool $withFollowup = true,
    ): array {
        $now ??= time();

        $scored = $this->scored(
            $teamId,
            $now,
            $withFiliale || $filter->filiale !== 'all',
            $withFollowup || $filter->level === 'followup',
        );
        // Geparkt (Lohn, Akte unvollstaendig): fuer HR unsichtbar — keine Liste,
        // kein Zaehler, auch nicht unter „Alle“ (Markus 28.10.2026: „gar nicht
        // sichtbar fuer HR, erst wenn die Akte vollstaendig ist“). Erst die
        // Freigabe (LohnParken) bringt den Chat als ungelesen zurueck.
        $geparkt = 0;
        $scored['rows'] = array_values(array_filter($scored['rows'], static function (array $row) use (&$geparkt): bool {
            if ($row['parked'] ?? false) {
                $geparkt++;
                return false;
            }
            return true;
        }));
        $filialen = self::filialeCounts($scored['rows']);

        // Filiale schneidet VOR dem Zaehlen (Kunde 16.10.2026): bei "MGL" zeigen
        // Pillen und Alle/Bewerber/Mitarbeiter nur MGL. Owner/Suche bleiben
        // bewusst ausserhalb der Zaehler (siehe oben).
        $basis = $filter->filiale === 'all'
            ? $scored['rows']
            : array_values(array_filter($scored['rows'], fn (array $row) => self::matchesFiliale($row, $filter->filiale)));
        $counts = $this->countsFromScored($basis);
        $counts['parked'] = $geparkt;   // nur Diagnose, die Oberflaeche zeigt ihn nicht

        // Owner und Namenssuche brauchen Bewerber-Daten, die die duenne Stufe
        // nicht hat — deshalb EINMAL vorab die erlaubten Bewerber-IDs holen
        // statt pro Zeile zu fragen.
        $allowed = $this->allowedSubjectIds($teamId, $filter, array_column($basis, 'thread_id'));

        $rows = array_values(array_filter(
            $basis,
            fn (array $row) => $this->matches($row, $filter, $allowed),
        ));

        // NEUESTE ZUERST, wie in jedem Messenger (Kundenwunsch 16.09.2026).
        // Vorher stand die Eskalationsstufe vorn — dadurch klebten Chats, die
        // seit 175 Tagen verpasst sind, dauerhaft ganz oben, waehrend die
        // Nachricht von heute Morgen weit unten stand. Die Dringlichkeit geht
        // dadurch nicht verloren: die Farbkante zeigt sie an jeder Zeile, und
        // ein Klick auf eine Ampel-Pille filtert die Liste darauf.
        usort($rows, static function (array $a, array $b): int {
            $letzteA = $a['last_message_at'] ?? 0;
            $letzteB = $b['last_message_at'] ?? 0;
            if ($letzteA !== $letzteB) {
                return $letzteB <=> $letzteA;
            }

            // Letzter Tiebreaker: thread_id. Ohne ihn haengt die Reihenfolge
            // bei Gleichstand (gleiche letzte Nachricht) an
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
            'rows' => $this->hydrate($slice, $teamId),
            'total' => $total,
            'fallback' => $scored['fallback'],
            'filialen' => $filialen,
        ];
    }

    /** Ein Chat gehoert zum Filter-Wert ('ohne' = keine Kostenstelle ableitbar). */
    private static function matchesFiliale(array $row, string $filiale): bool
    {
        return $filiale === 'ohne'
            ? $row['filiale'] === null
            : $row['filiale'] === (int) $filiale;
    }

    /**
     * Anzahl Chats je Filial-Block fuer die Auswahl — ueber ALLE Chats, damit
     * jede Option ihre eigene Zahl zeigt, egal was gerade gewaehlt ist.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int> Block als String ('100', …) bzw. 'ohne' => Anzahl
     */
    private static function filialeCounts(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row['filiale'] === null ? 'ohne' : (string) $row['filiale'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        uksort($out, static fn ($a, $b) => [$a === 'ohne', (int) $a] <=> [$b === 'ohne', (int) $b]);

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{unread:int, green:int, yellow:int, red:int, missed:int, followup:int, parked:int, total:int, applicants:int, employees:int, topics: array{dispo:int, lohn:int, hr:int}}
     */
    private function countsFromScored(array $rows): array
    {
        $counts = ['unread' => 0, 'green' => 0, 'yellow' => 0, 'red' => 0,
                   'missed' => 0, 'followup' => 0, 'parked' => 0, 'total' => 0,
                   'applicants' => 0, 'employees' => 0,
                   'topics' => array_fill_keys(self::TOPICS, 0)];

        // Alle | Bewerber | Mitarbeiter zaehlen jeden Chat (die Liste zeigt
        // gelesene mit, wie WhatsApp). Ungelesen und Ampel zaehlen nur offene
        // — gelesen = erledigt (Markus 15.10.2026).
        foreach ($rows as $row) {
            $counts['total']++;
            if ($row['kind'] === 'applicant') {
                $counts['applicants']++;
            } elseif ($row['kind'] === 'employee') {
                $counts['employees']++;
            }
            // Thema zaehlt wie Alle/Bewerber/Mitarbeiter jeden Chat, auch gelesene.
            if (isset($counts['topics'][$row['topic'] ?? ''])) {
                $counts['topics'][$row['topic']]++;
            }
            // „Offen“ zaehlt markierte Chats unabhaengig von gelesen (Spec §3) —
            // deshalb VOR der is_unread-Pruefung.
            if (($row['followup'] ?? null) !== null) {
                $counts['followup']++;
            }
            if (!$row['is_unread']) {
                continue;
            }
            $counts['unread']++;
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
    private function scored(int $teamId, int $now, bool $withFiliale = true, bool $withFollowup = true): array
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
        $themen = $this->aktuelleThemen($teamId, $threadIds);
        $offen = $withFollowup ? $this->offeneMarker($teamId, $threadIds) : [];

        $settings = RecApplicantSettings::getOrCreateForTeam($teamId);
        $yellow = (float) $settings->getSetting('comms_window_yellow_hours_left', 12);
        $red = (float) $settings->getSetting('comms_window_red_hours_left', 3);
        // Themen-Auswahl aus → nichts geparkt, alles normal bei HR.
        $parkenAn = (bool) $settings->getSetting('comms_topic_enabled', false);
        $hrAntwort = $parkenAn ? $this->hrAntwortZeitpunkte(self::geparkteIds($themen)) : [];

        // Bewerbungen, aus denen ein aktiver Mitarbeiter wurde: deren Chats
        // haengen weiter an der Bewerbung, gehoeren im Filter aber zu
        // "Mitarbeiter". Eine Query fuer die ganze Grundmenge.
        $aktiveAnstellungen = RecEmployee::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('rec_applicant_id')
            ->orderBy('id')
            ->get(['id', 'rec_applicant_id', 'cost_center', 'rec_position_id']);
        $employeeApplicantIds = array_fill_keys(
            $aktiveAnstellungen->map(fn ($e) => (int) $e->rec_applicant_id)->all(),
            true,
        );

        $filialeOf = $withFiliale
            ? $this->filialeResolver($teamId, $threads, $aktiveAnstellungen)
            : static fn (string $contextModel, ?int $contextId): ?int => null;

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
                ...self::openState(
                    (bool) $thread->is_unread,
                    $handledAt[$id] ?? null,
                    $inboundAt,
                    $humanOutbound[$id] ?? null,
                    $now,
                    $yellow,
                    $red,
                ),
                'siblings' => max(0, count($byDigits[$digits] ?? []) - 1),
                'last_message_at' => self::lastMessageAt($inboundAt, $thread->last_outbound_at?->getTimestamp()),
                'kind' => $this->kindOf(
                    (string) ($thread->context_model ?? ''),
                    $thread->context_model_id ? (int) $thread->context_model_id : null,
                    $employeeApplicantIds,
                ),
                'filiale' => $filialeOf(
                    (string) ($thread->context_model ?? ''),
                    $thread->context_model_id ? (int) $thread->context_model_id : null,
                ),
                ...self::themaFelder($themen[$id] ?? null),
                'parked' => $parkenAn && self::geparkt($themen[$id] ?? null, $hrAntwort[$id] ?? null),
                'followup' => $offen[$id] ?? null,
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
        if ($filter->kind === 'applicants' && $row['kind'] !== 'applicant') {
            return false;
        }
        if ($filter->kind === 'employees' && $row['kind'] !== 'employee') {
            return false;
        }
        if ($filter->topic !== 'all' && ($row['topic'] ?? null) !== $filter->topic) {
            return false;
        }

        $level = $row['escalation']->level;
        if ($filter->level === 'followup') {
            // „Offen“ (Spec §3): nur markierte Chats, aber UNABHAENGIG von
            // gelesen/ungelesen — deshalb vor der is_unread-Pruefung. Owner und
            // Suche greifen weiter unten trotzdem.
            if (($row['followup'] ?? null) === null) {
                return false;
            }
        } else {
            // Jede Pille ausser "Alle" zeigt nur offene Chats. Eine Ampelstufe
            // haben ohnehin nur offene (openState()), die Pruefung auf is_unread
            // steht trotzdem fuer alle Stufen da, damit beides nie auseinanderlaeuft.
            if ($filter->level !== 'all' && !$row['is_unread']) {
                return false;
            }
            if (!in_array($filter->level, ['all', 'unread'], true) && $level !== $filter->level) {
                return false;
            }
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

            $textTrifft = isset($allowed['searchText'][(int) $row['thread_id']]);

            if (!$phoneTrifft && !$nameTrifft && !$textTrifft) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filiale je Chat = Hunderterblock der Kostenstelle (KostenstellenBloecke,
     * wie Dispo „Jobs anbieten“ und die Vorbelegung der Weiterleitung):
     *  - Mitarbeiter-Thread: Kostenstelle am MA, sonst an seiner Stelle;
     *  - Bewerber-Thread mit aktiver Anstellung: die der Anstellung
     *    (dieselbe Zuordnung wie kindOf() → "Mitarbeiter");
     *  - sonst: Kostenstelle der Stelle an der Bewerbung;
     *  - nicht zugeordnet / nichts ableitbar: null ("ohne Filiale").
     * Drei gebuendelte Abfragen fuer die ganze Grundmenge, nicht je Chat.
     *
     * @param iterable<CommsWhatsAppThread> $threads
     * @return \Closure(string, ?int): ?int
     */
    private function filialeResolver(int $teamId, iterable $threads, \Illuminate\Support\Collection $aktiveAnstellungen): \Closure
    {
        $applicantIds = [];
        $employeeIds = [];
        foreach ($threads as $thread) {
            $id = $thread->context_model_id ? (int) $thread->context_model_id : null;
            if ($id === null) {
                continue;
            }
            if ($this->isEmployeeContext((string) $thread->context_model)) {
                $employeeIds[] = $id;
            } elseif ($this->isApplicantContext((string) $thread->context_model)) {
                $applicantIds[] = $id;
            }
        }

        $mitarbeiter = $employeeIds === [] ? collect() : RecEmployee::query()
            ->where('team_id', $teamId)
            ->whereIn('id', array_values(array_unique($employeeIds)))
            ->get(['id', 'cost_center', 'rec_position_id'])
            ->keyBy('id');
        // Neueste aktive Anstellung je Bewerbung (orderBy id oben -> letzte gewinnt).
        $anstellungJeBewerbung = $aktiveAnstellungen->keyBy(fn ($e) => (int) $e->rec_applicant_id);
        $bewerbungStelle = $applicantIds === [] ? [] : RecApplicant::query()
            ->where('team_id', $teamId)
            ->whereIn('id', array_values(array_unique($applicantIds)))
            ->pluck('rec_position_id', 'id')
            ->all();

        $stellenIds = array_filter(array_merge(
            $mitarbeiter->pluck('rec_position_id')->all(),
            $aktiveAnstellungen->pluck('rec_position_id')->all(),
            array_values($bewerbungStelle),
        ));
        $stelleKst = $stellenIds === [] ? [] : \Platform\Recruiting\Models\RecPosition::query()
            ->where('team_id', $teamId)
            ->whereIn('id', array_values(array_unique(array_map('intval', $stellenIds))))
            ->pluck('cost_center', 'id')
            ->all();

        $block = static fn (?object $ma) => $ma === null ? null : KostenstellenBloecke::block(
            $ma->cost_center !== null ? (string) $ma->cost_center : null,
            isset($stelleKst[(int) $ma->rec_position_id]) ? (string) $stelleKst[(int) $ma->rec_position_id] : null,
        );

        return function (string $contextModel, ?int $contextId) use ($mitarbeiter, $anstellungJeBewerbung, $bewerbungStelle, $stelleKst, $block): ?int {
            if ($contextId === null) {
                return null;
            }
            if ($this->isEmployeeContext($contextModel)) {
                return $block($mitarbeiter->get($contextId));
            }
            if ($this->isApplicantContext($contextModel)) {
                if ($anstellungJeBewerbung->has($contextId)) {
                    return $block($anstellungJeBewerbung->get($contextId));
                }
                $stelle = $bewerbungStelle[$contextId] ?? null;

                return KostenstellenBloecke::block(null, isset($stelleKst[(int) $stelle]) ? (string) $stelleKst[(int) $stelle] : null);
            }

            return null;
        };
    }

    /**
     * Zuordnung fuer den Filter Alle | Bewerber | Mitarbeiter:
     * 'employee' = Mitarbeiter-Thread ODER Bewerbung, aus der ein aktiver
     * Mitarbeiter wurde; 'applicant' = uebrige Bewerber-Threads; 'other' =
     * nicht zugeordnet (nur unter "Alle").
     *
     * @param array<int, true> $employeeApplicantIds
     */
    private function kindOf(string $contextModel, ?int $contextId, array $employeeApplicantIds): string
    {
        if ($contextId === null) {
            return 'other';
        }
        if ($this->isEmployeeContext($contextModel)) {
            return 'employee';
        }
        if ($this->isApplicantContext($contextModel)) {
            return isset($employeeApplicantIds[$contextId]) ? 'employee' : 'applicant';
        }

        return 'other';
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
     * @param  list<int> $threadIds Grundmenge fuer die Textsuche
     * @return ?array{owner: ?list<int>, searchApplicant: ?list<int>, searchEmployee: ?list<int>, searchText: array<int, true>}
     */
    private function allowedSubjectIds(int $teamId, InboxFilter $filter, array $threadIds = []): ?array
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
        $searchText = [];
        if ($sucheAktiv) {
            // Bewusst OHNE CONCAT(first_name, ' ', last_name) — anders als im
            // Suchzweig von Livewire\Applicant\Index: CONCAT ist MySQL-Syntax
            // und dieses Modul testet den Lesepfad gegen SQLite (Capsule,
            // kein voller App-Boot). Stattdessen Token-fuer-Token gegen
            // first_name/last_name, das bleibt auf beiden Treibern lauffaehig.
            //
            // Fix (Re-Review nach Abschluss-Durchsicht): die Eingabe wird an
            // Leerzeichen zerlegt, und JEDES Token muss treffen (first_name
            // ODER last_name je Token, die Token-Gruppen UND-verknuepft) —
            // sonst fand "Mara Keller" (der volle Name, die naheliegendste
            // Suche ueberhaupt) nichts, weil kein einzelnes Feld die
            // Gesamteingabe enthaelt. "Mara Keller" UND "Keller Mara" treffen
            // damit denselben Kontakt; eine Einwort-Suche verhaelt sich wie
            // zuvor (nur eine Token-Gruppe).
            $tokens = array_values(array_filter(
                preg_split('/\s+/', trim($filter->search)) ?: [],
                fn ($token) => $token !== '',
            ));

            $applicantQuery = RecApplicant::query()->where('team_id', $teamId);
            $employeeQuery = RecEmployee::query()->where('team_id', $teamId);

            foreach ($tokens as $token) {
                $like = '%' . $token . '%';

                $applicantQuery->whereHas('crmContactLinks.contact', function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });

                $employeeQuery->where(function ($q) use ($like) {
                    $q->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            }

            $searchApplicantIds = $applicantQuery->pluck('id')->map(fn ($id) => (int) $id)->all();
            $searchEmployeeIds = $employeeQuery->pluck('id')->map(fn ($id) => (int) $id)->all();

            // Nachrichtentext (30.10.2026): jede Nachricht des Chats, die Eingabe
            // als Ganzes ("pilot-feedback", "das neue Portal") — anders als beim
            // Namen, sonst trifft jedes Wort irgendwo irgendeinen Chat.
            // % und _ aus der Eingabe gelten woertlich.
            // Escape-Zeichen ! statt Backslash: '\\' liest MySQL als
            // maskiertes Anfuehrungszeichen, SQLite nicht — ! ist auf beiden gleich.
            $phrase = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filter->search)) . '%';
            foreach (array_chunk($threadIds, 1000) as $chunk) {
                $treffer = CommsWhatsAppMessage::query()
                    ->whereIn('comms_whatsapp_thread_id', $chunk)
                    ->whereRaw("LOWER(body) LIKE LOWER(?) ESCAPE '!'", [$phrase])
                    ->distinct()
                    ->pluck('comms_whatsapp_thread_id');
                foreach ($treffer as $tid) {
                    $searchText[(int) $tid] = true;
                }
            }
        }

        return ['owner' => $ownerIds, 'searchApplicant' => $searchApplicantIds, 'searchEmployee' => $searchEmployeeIds, 'searchText' => $searchText];
    }

    /**
     * Namen, Owner und Links — nur fuer die sichtbaren Zeilen.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<InboxRow>
     */
    private function hydrate(array $rows, int $teamId): array
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
            $urlLabel = null;
            $secondaryUrl = null;
            $secondaryLabel = null;
            $subjectId = $row['context_model_id'];
            $contextLabel = null;

            // Zeigt die Verknuepfung auf einen Geloeschten, bleibt der Chat
            // "nicht zugeordnet" — sonst kann HR ihn nie von Hand zuordnen
            // ("bereits zugeordnet"), und er zeigt nur die Nummer (Review 30.10.).
            if ($row['context_model'] === RecEmployee::class && $subjectId !== null && $employees->has($subjectId)) {
                $type = 'employee';
                $employee = $employees->get($subjectId);
                $name = $employee ? trim(($employee->first_name ?? '') . ' ' . ($employee->last_name ?? '')) : null;
                $firstName = $employee?->first_name;
                // Mitarbeiter fuehren in die MITARBEITER-Akte. Der alte Dienst
                // sprang bei Mitarbeitern mit Bewerbung bewusst auf die
                // BEWERBER-Seite, weil dort der Chat lebte ("Deep-Link
                // bevorzugt auf die Bewerber-Detailseite (dort lebt der
                // Chat)"). Der Chat lebt jetzt hier — damit ist der Grund weg
                // und der Sprung nur noch verwirrend.
                $url = $employee ? $this->safeRoute('recruiting.employees.show', ['employee' => $subjectId]) : null;
                $urlLabel = 'MA-Akte';

                // Wer beides hat, bekommt beides: die urspruengliche Bewerbung
                // als zweiten Link, statt sie zu verstecken.
                if ($employee && $employee->rec_applicant_id) {
                    $secondaryUrl = $this->safeRoute('recruiting.applicants.show', ['applicant' => $employee->rec_applicant_id]);
                    $secondaryLabel = 'Bewerbung';
                }
            } elseif (in_array($row['context_model'], [$applicantMorph, RecApplicant::class], true) && $subjectId !== null && $applicants->has($subjectId)) {
                $type = 'applicant';
                $applicant = $applicants->get($subjectId);
                $contact = $applicant?->crmContactLinks->first()?->contact;
                $name = $contact?->full_name;
                $firstName = $contact?->first_name;
                $owner = $applicant?->owned_by_user_id;
                $url = $applicant ? $this->safeRoute('recruiting.applicants.show', ['applicant' => $subjectId]) : null;
                $urlLabel = 'Bewerberakte';
            } elseif ($row['context_model'] !== '' && !self::isBareContact($row['context_model'])
                && $row['context_model'] !== RecEmployee::class
                && !in_array($row['context_model'], [$applicantMorph, RecApplicant::class], true)) {
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
                window: $row['window'] ?? null,
                contextLabel: $contextLabel,
                siblingCount: (int) $row['siblings'],
                lastMessageAt: $row['last_message_at'] ?? null,
                urlLabel: $urlLabel,
                secondaryUrl: $secondaryUrl,
                secondaryLabel: $secondaryLabel,
                topic: $row['topic'] ?? null,
                topicLabel: $this->themaLabel($teamId, $row['topic'] ?? null, $row['topic_dispo_channel_id'] ?? null),
                topicNote: self::themaVermerk($row['topic_state'] ?? null),
                followup: $row['followup'] ?? null,
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
     * Letzte Antwort einer PERSON je Thread (Absender gesetzt, kein Auto-Reply)
     * — nur fuer das Parken. Einmalcode und Nachweis-Erinnerung gehen ohne
     * auto-Flag, aber auch ohne Absender raus; sie duerfen einen geparkten
     * Lohn-Chat nicht sichtbar machen (Review 28.10. I1). Gleiche Regel wie
     * LohnParken::hrHatGeantwortet. Nur fuer geparkte Threads abgefragt.
     *
     * @param list<int> $threadIds
     * @return array<int, int>
     */
    private function hrAntwortZeitpunkte(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        return CommsWhatsAppMessage::query()
            ->whereIn('comms_whatsapp_thread_id', $threadIds)
            ->where('direction', 'outbound')
            ->where('is_auto_reply', false)
            ->whereNotNull('sent_by_user_id')
            ->groupBy('comms_whatsapp_thread_id')
            ->selectRaw('comms_whatsapp_thread_id, MAX(created_at) AS last_hr_at')
            ->pluck('last_hr_at', 'comms_whatsapp_thread_id')
            ->map(fn ($value) => \Carbon\Carbon::parse((string) $value)->getTimestamp())
            ->all();
    }

    /**
     * @param array<int, array{state: string}> $themen
     * @return list<int>
     */
    private static function geparkteIds(array $themen): array
    {
        return array_keys(array_filter($themen, static fn (array $t) => in_array($t['state'], RecConversationTopic::VERSTECKT, true)));
    }

    /**
     * Thema je Thread fuer Chip, Filter und Zaehler (Themen-Auswahl, Spec 2.7
     * Nachtrag / 2.8). Die Regel „aktuelles Anliegen“ steht an EINER Stelle:
     * RecConversationTopic::aktuelleJeThread() — juengste Zeile je Thread;
     * hat sie kein Thema (neue, unbeantwortete Frage), gibt es keinen Chip.
     *
     * Fehlt die Tabelle (Deploy ohne migrate), degradiert der Lesepfad:
     * keine Chips, Themen-Zaehler 0. Sidebar (30s-Poll auf jeder
     * Recruiting-Seite) und Kommunikation duerfen daran nicht sterben. Der
     * Fehler wird gemeldet, nicht verschluckt.
     *
     * @param list<int> $threadIds
     * @return array<int, array{topic: string, state: string, dispo_channel_id: ?int, chosen_at: ?int}>
     */
    private function aktuelleThemen(int $teamId, array $threadIds): array
    {
        try {
            $aktuelle = RecConversationTopic::aktuelleJeThread($teamId, $threadIds);
        } catch (\Illuminate\Database\QueryException $e) {
            self::melde($e);

            return [];
        }

        $out = [];
        foreach ($aktuelle as $threadId => $row) {
            if ($row->topic === null) {
                continue;
            }
            $out[$threadId] = [
                'topic' => (string) $row->topic,
                'state' => (string) $row->state,
                'dispo_channel_id' => $row->dispo_channel_id !== null ? (int) $row->dispo_channel_id : null,
                'chosen_at' => $row->chosen_at?->getTimestamp(),
            ];
        }

        return $out;
    }

    /**
     * Offene „Offen lassen“-Marker je Thread (FollowupMarker). Fehlt die
     * Tabelle (Deploy ohne migrate), degradiert der Lesepfad wie bei den
     * Themen: keine Marker, Zaehler 0 — gemeldet, nicht verschluckt.
     *
     * @param list<int> $threadIds
     * @return array<int, array{by: ?string, at: int}>
     */
    private function offeneMarker(int $teamId, array $threadIds): array
    {
        try {
            return (new FollowupMarker())->offeneFuer($teamId, $threadIds);
        } catch (\Illuminate\Database\QueryException $e) {
            self::melde($e);

            return [];
        }
    }

    /**
     * report() ohne gebundenen ExceptionHandler (Capsule-Tests booten kein
     * Laravel) waere selbst ein Absturz — dann nur nicht melden.
     */
    private static function melde(\Throwable $e): void
    {
        $container = \Illuminate\Container\Container::getInstance();
        if ($container->bound(\Illuminate\Contracts\Debug\ExceptionHandler::class)) {
            report($e);
        }
    }

    /**
     * Thema-Spalten einer duennen Zeile. Unbekannte Werte (kein dispo/lohn/hr)
     * gelten als kein Thema — sonst stuende ein roher Code als Chip da.
     *
     * @param ?array{topic: string, state: string, dispo_channel_id: ?int} $thema
     * @return array{topic: ?string, topic_state: ?string, topic_dispo_channel_id: ?int}
     */
    private static function themaFelder(?array $thema): array
    {
        if ($thema === null || !in_array($thema['topic'], self::TOPICS, true)) {
            return ['topic' => null, 'topic_state' => null, 'topic_dispo_channel_id' => null];
        }

        return [
            'topic' => $thema['topic'],
            'topic_state' => $thema['state'],
            'topic_dispo_channel_id' => $thema['dispo_channel_id'],
        ];
    }

    /**
     * Geparkt (Lohn, Akte unvollstaendig — LohnParken): Zustand lohn_parked
     * UND keine menschliche HR-Antwort seit der Wahl. Antwortet HR, ist der
     * Chat in Arbeit und gehoert nicht mehr in „Wartet auf Akte“ (Review 26.10. I2).
     *
     * @param ?array{state: string, chosen_at: ?int} $thema
     */
    private static function geparkt(?array $thema, ?int $humanOutboundAt): bool
    {
        if ($thema === null || !in_array($thema['state'], RecConversationTopic::VERSTECKT, true)) {
            return false;
        }

        return $humanOutboundAt === null || ($thema['chosen_at'] ?? null) === null || $humanOutboundAt <= $thema['chosen_at'];
    }

    /**
     * Chip-Text. Dispo mit Kanal → „Dispo → Köln“ (Beschriftung aus
     * DispoForwardChannels, „Dispo “ davor abgeschnitten); Kanal unbekannt
     * oder nicht mehr in der Auswahl → nur „Dispo“. Die Kanal-Aufloesung
     * laeuft nur hier, also nur fuer sichtbare Zeilen — und je Instanz einmal
     * (DispoForwardChannels merkt sich die Optionen).
     */
    private function themaLabel(int $teamId, ?string $topic, ?int $dispoChannelId): ?string
    {
        return match ($topic) {
            RecConversationTopic::TOPIC_LOHN => 'Lohn',
            RecConversationTopic::TOPIC_HR => 'HR',
            RecConversationTopic::TOPIC_DISPO => $this->dispoLabel($teamId, $dispoChannelId),
            default => null,
        };
    }

    private function dispoLabel(int $teamId, ?int $channelId): string
    {
        if ($channelId === null) {
            return 'Dispo';
        }
        $this->dispoKanaele ??= new DispoForwardChannels();
        $label = $this->dispoKanaele->labelFor($teamId, $channelId);
        if ($label === null) {
            return 'Dispo';
        }
        $ziel = str_starts_with($label, 'Dispo ') ? substr($label, strlen('Dispo ')) : $label;

        return 'Dispo → ' . $ziel;
    }

    /** Vermerk zum Weg nach der Wahl — nur wo ein Hinweis an den MA ging. */
    private static function themaVermerk(?string $state): ?string
    {
        return match ($state) {
            RecConversationTopic::STATE_AKTE_HINT => 'Akte unvollständig · Hinweis gesendet',
            RecConversationTopic::STATE_GEPARKT => 'Wartet auf Akte',
            RecConversationTopic::STATE_FREIGEGEBEN => 'Akte nachgereicht',
            RecConversationTopic::STATE_LOHN_INFO => 'Login-Hinweis gesendet',
            RecConversationTopic::STATE_AGENDA => 'Agenda-Zugang angefordert',
            RecConversationTopic::STATE_LOHN_FRAGE => 'Lohnfrage nach Prüfung',
            default => null,
        };
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

    /**
     * Offen = ungelesen UND kein gueltiger Stempel (Spec 16.10.2026,
     * Markus: „ungelesen = muss noch bearbeitet werden, gelesen = erledigt“).
     * Der Stempel kommt aus der Mehrfachauswahl, dem Archiv-Lauf oder einem
     * Button-Klick; ein spaeterer Eingang hebt ihn auf.
     *
     * Gelesene Chats bekommen KEINE Eskalation: sie stehen unter "Alle"
     * weiter in der Liste, duerfen dort aber weder Farbkante noch
     * „verpasst seit“ tragen — sonst sieht ein Dritter wieder rot.
     *
     * 'is_unread' traegt danach den abgeleiteten Zustand, nicht mehr die
     * rohe Core-Spalte — Liste, Pillen und Sidebar lesen nur diesen.
     *
     * 'window' traegt die rohe Fenster-Rechnung auch fuer gelesene Chats —
     * nur fuer den Chat-Kopf („noch 5 h“): wer einen Chat geoeffnet hat,
     * muss weiter sehen, wie lange er noch frei antworten kann.
     *
     * @return array{is_unread: bool, escalation: ConversationEscalation, window: ConversationEscalation}
     */
    private static function openState(
        bool $isUnread,
        ?int $handledAt,
        ?int $inboundAt,
        ?int $humanOutboundAt,
        int $now,
        float $yellow,
        float $red,
    ): array {
        $open = $isUnread && !ConversationHandledState::isHandled($handledAt, $inboundAt);
        $window = ConversationEscalation::compute($inboundAt, $humanOutboundAt, $now, $yellow, $red);

        return [
            'is_unread' => $open,
            'escalation' => $open ? $window : ConversationEscalation::compute(null, null, $now),
            'window' => $window,
        ];
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
