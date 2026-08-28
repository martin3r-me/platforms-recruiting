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
 */
class DispoThreadDirectory
{
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
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($channelIds === [] || $employeeIds === []) {
            return [];
        }

        $groups = $this->identity->groupsFor($employeeIds);
        $canon  = DispoIdentityGroups::canonicalMap($groups);
        $allIds = array_values(array_unique(array_merge(...array_values($groups))));

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
        $matcher = new DispoPhoneMatcher($byCanonical);
        $wanted  = array_fill_keys(array_map(fn ($id) => $canon[$id] ?? $id, $employeeIds), true);

        $crmTypes = [\Platform\Crm\Models\CrmContact::class, (new \Platform\Crm\Models\CrmContact())->getMorphClass()];
        $rows = CommsWhatsAppThread::query()
            ->whereIn('comms_channel_id', $channelIds)
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
                $cid = $matcher->match((string) $t->remote_phone_number);
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

        return $result;
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
        $threads = $this->threadsFor($channelIds, $ids);
        $canon = DispoIdentityGroups::canonicalMap($this->identity->groupsFor($ids));

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
     * hierher gezogen). $since filtert auf Nachrichten ab diesem Zeitpunkt.
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

            return [
                'direction'      => (string) $m->direction,
                'kind'           => $isTemplate ? 'template' : 'text',
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
}
