<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Platform\Crm\Models\CommsWhatsAppThread;
use Platform\Recruiting\Models\RecDispoAssignment;

/**
 * Rueckwaerts-Aufloesung Person -> Thread fuer die VA-Seite (Runde 4, #1):
 * "welcher Thread gehoert zu diesem disponierten MA?" — (1) ueber den CRM-
 * Kontakt-Link (sicher), (2) ueber das Telefon der Identitaetsgruppe.
 * Ergebnis ist IMMER kanonisch (DispoIdentityGroups). Die Kommunikations-
 * Komponente loest weiterhin vorwaerts (Thread -> Person) auf; geteilt sind
 * Fenster-Status, Vorlagen-Labels, Nachrichten-Mapping und Antwort-Versand.
 * CRM wird hier NICHT direkt gelesen — nur ueber DispoIdentityResolver.
 *
 * Volumen-Annahme: die Thread-Query filtert auf eine (Kontakt-ID- oder
 * Telefon-Ziffernfolge-)Obermenge moeglicher Treffer statt auf ALLE Threads
 * des Kanal-Sets — bei wenigen hundert offenen Threads je Dispo-WABA-Account
 * (heutige Groessenordnung) ist das unauffaellig; bei absehbar mehr Threads
 * waere ein Index auf remote_phone_number (Ziffern-Ausdruck) der naechste
 * Schritt.
 */
class DispoThreadDirectory
{
    /** @var list<string>|null Cache: CrmContact::class + Morph-Alias (Model-Boot nur einmal). */
    private static ?array $crmTypes = null;

    /** Cache je Instanz: Matcher ueber das VOLLE aktive Telefonverzeichnis. */
    private ?DispoPhoneMatcher $fullMatcher = null;

    public function __construct(
        private DispoIdentityResolver $identity,
        private DispoEmployeeGateway $gateway,
    ) {}

    /**
     * @param list<int> $channelIds Dispo-Kanal-Set
     * @param list<int> $employeeIds beliebige Datensaetze (auch nicht-kanonische)
     * @return array<int, array{thread_id:int, is_unread:bool, last_inbound_at:?string, last_at:?string}> kanonische id => neuester Thread
     */
    public function threadsFor(array $channelIds, array $employeeIds): array
    {
        return $this->resolveThreads($channelIds, $employeeIds)['threads'];
    }

    /**
     * Gemeinsamer Kern von threadsFor() und unreadByEvent() (letzteres braucht
     * zusaetzlich die kanonische Zuordnung selbst) — vermeidet eine doppelte
     * Identitaets-Aufloesung fuer dieselben ids.
     *
     * @param list<int> $channelIds
     * @param list<int> $employeeIds
     * @return array{threads: array<int, array{thread_id:int, is_unread:bool, last_inbound_at:?string, last_at:?string}>, canon: array<int,int>}
     */
    private function resolveThreads(array $channelIds, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($channelIds === [] || $employeeIds === []) {
            return ['threads' => [], 'canon' => []];
        }

        $groups = $this->identity->groupsFor($employeeIds);
        $canon = self::canonByMember($groups);
        $allIds = $groups === [] ? [] : array_values(array_unique(array_merge(...array_values($groups))));

        // (1) Kontakt-Links: contact_id -> kanonische id
        $canonByContact = [];
        foreach ($this->identity->contactIdsByEmployee($allIds) as $eid => $contactIds) {
            foreach ($contactIds as $cid) {
                $canonByContact[(int) $cid] = $canon[(int) $eid] ?? (int) $eid;
            }
        }

        // (2) Telefon: kanonische id -> alle Nummern der Gruppe
        $byCanonical = [];
        foreach ($this->gateway->phones($allIds) as $eid => $phone) {
            if ($phone !== null && $phone !== '') {
                $byCanonical[$canon[(int) $eid] ?? (int) $eid][] = $phone;
            }
        }

        if ($canonByContact === [] && $byCanonical === []) {
            // Weder Kontakt-Link noch Telefon fuer irgendein Gruppenmitglied
            // -> es kann keinen Treffer geben, keine Query noetig.
            return ['threads' => [], 'canon' => $canon];
        }

        // Der Matcher entscheidet gegen das VOLLE aktive Telefonverzeichnis
        // (siehe fullMatcher()); ohne Telefon in der Gruppe kann es ohnehin
        // keinen Telefon-Treffer geben, dann sparen wir uns das Verzeichnis.
        $matcher = $byCanonical === [] ? null : $this->fullMatcher();
        $wanted  = array_fill_keys(array_map(fn ($id) => $canon[$id] ?? $id, $employeeIds), true);

        // Ziffern-Suffixe (letzte 9 Ziffern je normalisierter Nummer) grenzen
        // die Query auf eine OBERMENGE moeglicher Telefon-Treffer ein — die
        // exakte Entscheidung (inkl. Mehrdeutigkeit) faellt weiterhin unten
        // im DispoPhoneMatcher. Mirror von ZasEmployeeContactLinker::phoneCandidates().
        $phoneSuffixes = [];
        foreach ($byCanonical as $phones) {
            foreach ($phones as $phone) {
                $normalized = DispoPhoneMatcher::normalize($phone);
                if ($normalized !== null) {
                    $phoneSuffixes[substr($normalized, -9)] = true;
                }
            }
        }
        $phoneSuffixes = array_keys($phoneSuffixes);

        $crmTypes = self::crmTypes();
        $rows = CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $channelIds)
            ->where(function ($q) use ($canonByContact, $phoneSuffixes) {
                if ($canonByContact !== []) {
                    $q->orWhereIn('contact_id', array_keys($canonByContact));
                }
                foreach ($phoneSuffixes as $suffix) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(remote_phone_number, ' ', ''), '-', ''), '+', '') LIKE ?",
                        ['%' . $suffix]
                    );
                }
            })
            ->orderByDesc('updated_at')
            ->get(['id', 'remote_phone_number', 'contact_id', 'contact_type', 'is_unread', 'last_inbound_at', 'last_outbound_at', 'updated_at']);

        $result = [];   // kanonische id => row
        $viaContact = []; // kanonische id => true, wenn ueber Kontakt gefunden (schlaegt Telefon)
        foreach ($rows as $t) {
            $cid = null;
            $byContact = false;
            if ($t->contact_id && in_array((string) $t->contact_type, $crmTypes, true) && isset($canonByContact[(int) $t->contact_id])) {
                $cid = $canonByContact[(int) $t->contact_id];
                $byContact = true;
            } else {
                // Telefon-Treffer werden unten gegen $wanted geprueft — der
                // Matcher kennt ALLE aktiven MA, liefert also fuer eine
                // geteilte Nummer null (mehrdeutig) statt eines Fremden.
                $cid = $matcher?->match((string) $t->remote_phone_number);
            }
            if ($cid === null || !isset($wanted[$cid])) {
                continue;
            }
            if (isset($result[$cid]) && ($viaContact[$cid] || !$byContact)) {
                continue; // aelter (Sortierung) oder schwaecher (Telefon nach Kontakt)
            }
            $lastAt = $t->last_inbound_at ?? $t->last_outbound_at;
            $result[$cid] = [
                'thread_id'       => (int) $t->id,
                'is_unread'       => (bool) $t->is_unread,
                'last_inbound_at' => $t->last_inbound_at?->format('Y-m-d H:i:s'),
                'last_at'         => $lastAt?->format('Y-m-d H:i:s'),
            ];
            $viaContact[$cid] = $byContact;
        }

        return ['threads' => $result, 'canon' => $canon];
    }

    /**
     * Ungelesene Threads je VA = Anzahl PERSONEN (kanonisch) mit ungelesenem Thread
     * unter den disponierten, gematchten Auftrags-Einbuchungen ab $today.
     * Wenige Aggregat-Queries fuer alle VAs zusammen — kein N+1.
     *
     * @param list<int> $eventIds
     * @return array<int,int> event_id => Anzahl
     */
    public function unreadByEvent(array $channelIds, array $eventIds, string $today): array
    {
        if ($channelIds === [] || $eventIds === []) {
            return [];
        }
        $rows = RecDispoAssignment::query()
            ->whereIn('rec_dispo_event_id', $eventIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNotNull('rec_employee_id')
            ->whereDate('datum', '>=', $today)
            ->get(['rec_dispo_event_id', 'rec_employee_id']);
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('rec_employee_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        ['threads' => $threads, 'canon' => $canon] = $this->resolveThreads($channelIds, $ids);

        $out = [];
        foreach ($rows->groupBy('rec_dispo_event_id') as $eventId => $eventRows) {
            $persons = [];
            foreach ($eventRows as $r) {
                $c = $canon[(int) $r->rec_employee_id] ?? (int) $r->rec_employee_id;
                if (!empty($threads[$c]['is_unread'])) {
                    $persons[$c] = true;
                }
            }
            if ($persons !== []) {
                $out[(int) $eventId] = count($persons);
            }
        }

        return $out;
    }

    /**
     * Nachrichten eines Threads fuer die Anzeige (Mapping aus der Kommunikation
     * hierher gezogen). $since filtert auf created_at (Insert-Zeitpunkt); die
     * Anzeige-Zeit je Nachricht ('at'/'time'/'day'/'day_label') nutzt dagegen
     * sent_at, wenn vorhanden, sonst created_at — beide Zeitpunkte koennen
     * bei verzoegertem Versand auseinanderfallen.
     *
     * @return list<array<string, mixed>>
     */
    public function messages(CommsWhatsAppThread $thread, array $labels, ?\DateTimeInterface $since = null): array
    {
        $q = $thread->messages()->orderBy('created_at');
        if ($since !== null) {
            $q->where('created_at', '>=', $since);
        }

        return $q->get()->map(function ($m) use ($labels) {
            $at = $m->sent_at ?? $m->created_at;
            $isTemplate = ($m->body === null || $m->body === '') && !empty($m->template_name);

            // Medien (Kunde 04.09.): Bilder/Dokumente/Sprachnachrichten des MA
            // wurden bisher als leere Blase gerendert — Typ + Anhaenge (URL/
            // Thumbnail/Titel via ContextFileReferences) jetzt durchreichen.
            $hasMedia = method_exists($m, 'hasMedia') && $m->hasMedia();

            return [
                'direction'      => (string) $m->direction,
                'kind'           => $isTemplate ? 'template' : 'text',
                'media_type'     => $hasMedia ? (string) $m->media_display_type : null,
                'attachments'    => $hasMedia ? (array) ($m->attachments ?? []) : [],
                'body'           => (string) ($m->body ?? ''),
                'template_label' => $isTemplate ? DispoTemplateLabels::label((string) $m->template_name, $labels) : null,
                'status'         => $m->status,
                'at'             => optional($at)->format('d.m.Y H:i'),
                'time'           => optional($at)->format('H:i'),
                'day'            => optional($at)->format('Y-m-d'),
                'day_label'      => $at ? self::dayLabel(\Illuminate\Support\Carbon::instance($at)) : '',
            ];
        })->all();
    }

    /** "Heute", "Gestern", sonst "Mittwoch, 27. August" (aus der Kommunikation hierher gezogen). */
    public static function dayLabel(\Illuminate\Support\Carbon $c): string
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

    /**
     * Kanonische id JE GRUPPEN-MITGLIED. groupsFor() liefert nur fuer die
     * ANGEFRAGTEN ids einen Schluessel, aber jeder Wert ist die VOLLSTAENDIGE
     * Gruppe — kanonisieren muss deshalb ueber alle Gruppen-MITGLIEDER laufen,
     * nicht nur ueber die Schluessel. Sonst bleiben Mitglieder, die nicht
     * selbst angefragt wurden (z. B. der RG-Datensatz bei einer Anfrage nach
     * der MA-id), unkanonisiert -> Treffer landen unter der falschen id oder
     * zwei Datensaetze derselben Person erscheinen als zwei verschiedene
     * "kanonische" ids (Telefon-Matcher haelt das faelschlich fuer
     * Mehrdeutigkeit und liefert dann gar nichts mehr).
     *
     * @param array<int, list<int>> $groups
     * @return array<int,int> mitglied_id => kanonische id
     */
    private static function canonByMember(array $groups): array
    {
        $canon = [];
        foreach ($groups as $group) {
            $c = DispoIdentityGroups::canonical($group);
            foreach ($group as $m) {
                $canon[(int) $m] = $c;
            }
        }

        return $canon;
    }

    /**
     * Telefon-Matcher ueber das VOLLE aktive Telefonverzeichnis — NICHT nur
     * ueber die angefragten MA. Sonst sieht eine Nummer, die sich ein
     * angefragter MA mit einem NICHT angefragten teilt, eindeutig aus und der
     * Thread eines Fremden wird ihm zugeschrieben; erst gegen das volle
     * Verzeichnis meldet der Matcher korrekt "mehrdeutig" (null). Aufbau
     * identisch zu Livewire/Dispo/Conversations/Index::matcher(): kanonisiert
     * ueber alle Gruppen-MITGLIEDER, damit zwei Datensaetze derselben Person
     * KEINE Mehrdeutigkeit ausloesen. Ein Treffer wird danach nur akzeptiert,
     * wenn seine kanonische id angefragt war ($wanted).
     */
    private function fullMatcher(): DispoPhoneMatcher
    {
        if ($this->fullMatcher !== null) {
            return $this->fullMatcher;
        }

        $directory = $this->gateway->phoneDirectory(); // id => phone (aktiv, Team-Anker)
        $canon = self::canonByMember($this->identity->groupsFor(array_keys($directory)));

        $byCanonical = [];
        foreach ($directory as $id => $phone) {
            $byCanonical[$canon[(int) $id] ?? (int) $id][] = $phone;
        }

        return $this->fullMatcher = new DispoPhoneMatcher($byCanonical);
    }

    /** @return list<string> */
    private static function crmTypes(): array
    {
        return self::$crmTypes ??= [\Platform\Crm\Models\CrmContact::class, (new \Platform\Crm\Models\CrmContact())->getMorphClass()];
    }
}
