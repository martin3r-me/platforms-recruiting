<?php

namespace Platform\Recruiting\Livewire\Public;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoAttachment;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Zas\Dispo\DispoContactResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoDressResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoEinsatzAktuell;
use Platform\Recruiting\Services\Zas\Dispo\DispoEmployeeGateway;
use Platform\Recruiting\Services\Zas\Dispo\DispoIdentityResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoTeamLeadResolver;
use Platform\Recruiting\Services\Zas\Dispo\DispoTimeCalculator;
use Platform\Recruiting\Support\DressPanels;

/**
 * Oeffentliche Einsatz-Seite (Dispo-Bestaetigung), token-only.
 *
 * BEWUSST ohne die Verifikations-Schranke des EmployeePortal (Spec):
 * unerratbarer UUIDv7-Token, Link kommt privat per WhatsApp, Seite zeigt
 * nur Einsatz-Infos und kennt nur die positive Aktion Bestaetigen.
 * Nirgends im MA-Portal verlinkt. Token am URL-Ende (Meta-URL-Button).
 *
 * Sichtbarkeits-Regel (Kunde 01.09.): angezeigt und bestaetigbar ist NUR, was
 * angeschrieben wurde (reminder_sent_at) oder schon bestaetigt ist — nie der
 * komplette Dispo-Bestand der Person.
 */
class EmployeeAssignments extends Component
{
    #[Locked]
    public string $token = '';
    #[Locked]
    public ?int $employeeId = null;
    /**
     * Dispo-Identitaets-Gruppe (Spec 2026-08-28): alle aktiven Mitarbeiter-
     * Datensaetze derselben Person im selben Team, inkl. $employeeId selbst.
     * #[Locked] + ausschliesslich serverseitig in mount() gesetzt.
     *
     * @var list<int>
     */
    #[Locked]
    public array $employeeIds = [];
    #[Locked]
    public string $firstName = '';
    #[Locked]
    public bool $tokenInvalid = false;

    /**
     * Server-only-Sperrflag (Eskalations-Stufe 3, DispoEmployeeGateway::
     * lockPortal), analog EmployeePortal::$portalLocked. #[Locked] verhindert
     * Client-Ueberschreiben; wird ausschliesslich aus rec_employees.
     * portal_locked_at gesetzt. Zeigt bei true den Sperr-Screen statt der
     * Einsatz-Liste und gated confirm().
     */
    #[Locked]
    public bool $portalLocked = false;

    /** Vorheriger Besuch der Seite (Y-m-d H:i:s) — Basis der NEU-Badges; null = erster Besuch. */
    #[Locked]
    public ?string $lastSeenBefore = null;

    public bool $showPast = false;

    /**
     * Im Portal-Reiter „Einsätze“ eingebettet (Pilot-Review 28.10.2026):
     * ohne eigenen Kopf (Logo, „Hey Name“) — den hat das Portal schon. Nur
     * vom Server beim Einbetten gesetzt.
     */
    #[Locked]
    public bool $eingebettet = false;

    public function mount(string $token, bool $eingebettet = false): void
    {
        $this->token = $token;
        $this->eingebettet = $eingebettet;

        $employee = RecEmployee::query()->where('portal_token', $token)->first();
        if (!$employee) {
            $this->tokenInvalid = true;
            return;
        }

        $this->employeeId  = (int) $employee->id;
        $this->employeeIds = app(DispoIdentityResolver::class)->groupFor((int) $employee->id);
        $this->firstName   = (string) $employee->first_name;

        // Gesperrt, wenn irgendein Datensatz der Gruppe gesperrt ist.
        if (RecEmployee::query()->whereIn('id', $this->employeeIds)->whereNotNull('portal_locked_at')->exists()) {
            $this->portalLocked = true;
        }

        // Crew-Info (03.09.): NEU-Hervorhebung = neuer als der VORHERIGE Besuch.
        // Erst lesen, dann stempeln — Direkt-Update (kein Eloquent), damit weder
        // updated_at noch der ZAS-Export-Marker angefasst werden.
        $this->lastSeenBefore = RecEmployee::query()->whereIn('id', $this->employeeIds)->max('portal_last_seen_at');
        \Illuminate\Support\Facades\DB::table('rec_employees')
            ->whereIn('id', $this->employeeIds)
            ->update(['portal_last_seen_at' => now()]);
    }

    /**
     * Kommende Auftrags-Einsaetze des MA, gruppiert pro VA — EINE Karte pro VA
     * mit allen Tagen und Sammel-Bestaetigen (Spec). #[Computed]: nie im State.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function eventGroups(): array
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return [];
        }

        $assignments = self::sichtbareEinsaetze($this->employeeIds, now());

        $attachments = $this->attachmentsByEvent($assignments->pluck('rec_dispo_event_id')->unique()->all());

        $leadsByEvent = $this->teamLeadsByEvent($assignments->pluck('rec_dispo_event_id')->unique()->values()->all());

        // Waeschepakete je Einbuchung — ein Aufruf fuer alle Tage, nicht je Zeile.
        $dressByAssignment = app(DispoDressResolver::class)->forAssignments($assignments);

        $groups = [];
        foreach ($assignments as $assignment) {
            $event = $assignment->event;
            $key = $event->id;

            $groups[$key] ??= [
                'event_id'     => $event->id,
                'name'         => $event->name ?? $event->einsatz_ref,
                'adresse'      => $event->venue_text,
                'zusatz_ort'   => $event->ort,
                'kleidung'     => $event->dresscode,
                'hinweis'       => $event->hinweis,
                'dress_group'   => null,
                'dress_hinweis' => null,
                // Standard = disponierte Teamleitung (live), manuelle Eingabe gewinnt.
                'contact_line' => DispoContactResolver::effective($event->ansprechpartner, $leadsByEvent[$event->id] ?? [])['label'],
                'vorlauf_minuten' => (int) ($event->vorlauf_minuten ?? 0),
                'attachments'  => $attachments[$event->id] ?? [],
                'all_confirmed' => true,
                'has_reconfirm' => false,
                'days'         => [],
            ];

            $arrival = DispoTimeCalculator::arrivalTime($assignment->von, $event->vorlauf_minuten);
            $groups[$key]['days'][] = [
                'datum'           => $assignment->datum->format('d.m.Y'),
                'von'             => $assignment->von,
                'bis'             => $assignment->bis,
                'taetigkeit'      => $assignment->taetigkeit,
                'assignment_id'  => $assignment->id,
                'dress'          => null,
                // Festgeschriebene Textkopie schlaegt den lebenden Paket-Text:
                // was der Mitarbeiter beim Versand bekommen hat, darf sich
                // durch spaetere Pflege am Paket nicht rueckwirkend aendern
                // (DispoDressResolver::freeze()).
                'dress_text'     => trim((string) $assignment->dress_items_text) !== ''
                    ? (string) $assignment->dress_items_text
                    : $dressByAssignment[$assignment->id]?->items_text,
                'arrival'         => $arrival,
                'confirmed'       => $assignment->confirmed_at !== null,
                'individual_note' => $assignment->individual_note,
                'note_new'        => trim((string) $assignment->individual_note) !== '' && $this->isNewSince($assignment->individual_note_updated_at),
                'reconfirm'       => $assignment->reconfirm_required_at !== null,
                'previous_label'  => self::previousLabel($assignment->reconfirm_previous),
            ];
            if ($assignment->confirmed_at === null) {
                $groups[$key]['all_confirmed'] = false;
            }
            if ($assignment->reconfirm_required_at !== null) {
                $groups[$key]['has_reconfirm'] = true;
            }
        }

        foreach ($groups as $key => $group) {
            $days = [];
            foreach ($group['days'] as $day) {
                $days[$day['assignment_id']] = $day['dress_text'];
            }

            $panels = DressPanels::build($days, $group['kleidung'], $group['hinweis']);

            $groups[$key]['dress_group']   = $panels['group'];
            $groups[$key]['dress_hinweis'] = $panels['hinweis'];
            foreach ($groups[$key]['days'] as $i => $day) {
                $groups[$key]['days'][$i]['dress'] = $panels['perDay'][$day['assignment_id']] ?? null;
            }
        }

        return array_values($groups);
    }

    /**
     * Teamleitungen je VA (Standard-Ansprechpartner), ueber alle aktiven Auftrags-
     * Einbuchungen der VA — nicht nur die des eingeloggten MA. Der MA selbst wird
     * ausgelassen ("Dein Ansprechpartner ist <du selbst>" waere Unsinn). Zwei Queries
     * fuer alle VAs zusammen, kein N+1.
     *
     * @param list<int> $eventIds
     * @return array<int, list<array{employee_id:int, name:string, phone:?string, label:string}>>
     */
    private function teamLeadsByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = RecDispoAssignment::query()
            ->whereIn('rec_dispo_event_id', $eventIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            ->whereNotNull('rec_employee_id')
            ->whereNotIn('rec_employee_id', $this->employeeIds)
            ->orderBy('datum')->orderBy('von')
            ->get(['rec_dispo_event_id', 'rec_employee_id', 'taetigkeit', 'datum']);

        if ($rows->isEmpty()) {
            return [];
        }

        $contacts = app(DispoEmployeeGateway::class)->contacts($rows->pluck('rec_employee_id')->unique()->map(fn ($id) => (int) $id)->values()->all());
        $wanted = (array) config('recruiting.zas.dispo_lead_taetigkeiten', ['Teamleitung']);
        $resolver = new DispoTeamLeadResolver();

        $byEvent = [];
        foreach ($rows->groupBy('rec_dispo_event_id') as $eventId => $eventRows) {
            $byEvent[(int) $eventId] = $resolver->resolve(
                $eventRows->map(fn ($a) => [
                    'employee_id' => $a->rec_employee_id,
                    'taetigkeit'  => $a->taetigkeit,
                    'datum'       => $a->datum->format('Y-m-d'),
                ])->all(),
                $contacts,
                $wanted
            );
        }

        return $byEvent;
    }

    /**
     * Neuer als der vorherige Besuch? Erster Besuch (lastSeenBefore null) oder
     * fehlender Zeitstempel -> false: die Bestaetigungs-WhatsApp fuehrt beim
     * Erstbesuch ohnehin hierher, ein "NEU" an allem waere nur Rauschen.
     */
    private function isNewSince(mixed $timestamp): bool
    {
        if ($this->lastSeenBefore === null || $timestamp === null) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($timestamp)->greaterThan(\Illuminate\Support\Carbon::parse($this->lastSeenBefore));
    }

    /** "Sa 29.08. 10:00–18:00" aus reconfirm_previous, sonst null. */
    private static function previousLabel(?array $prev): ?string
    {
        if (empty($prev['datum'])) {
            return null;
        }
        $c = \Illuminate\Support\Carbon::parse($prev['datum']);
        $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$c->dayOfWeek];
        $time = !empty($prev['von']) ? (' ' . $prev['von'] . (!empty($prev['bis']) ? '–' . $prev['bis'] : '')) : '';

        return $wd . ' ' . $c->format('d.m.') . $time;
    }

    /**
     * Vergangene Auftrags-Einsaetze des MA, kompakt gruppiert pro VA (nur Anzeige,
     * eingeklappt per Default). Kein Filter auf missing/deletion_marked — vergangen
     * ist vergangen. #[Computed]: nie im State.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function pastEventGroups(): array
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return [];
        }

        $assignments = RecDispoAssignment::query()
            ->with('event')
            ->whereIn('rec_employee_id', $this->employeeIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereDate('datum', '<', now()->toDateString())
            // Kunde 01.09.: Der MA sieht NUR angeschriebene (oder bereits bestaetigte)
            // Einsaetze — exakt das, was RG versendet hat, nicht den ganzen Dispo-Bestand.
            // Nicht versendete Einbuchungen bleiben unsichtbar, bis HR den Versand ausloest.
            ->where(fn ($q) => $q->whereNotNull('reminder_sent_at')->orWhereNotNull('confirmed_at'))
            ->orderByDesc('datum')->orderByDesc('von')
            ->limit(30)
            ->get()
            // Was oben noch als laufend steht (Schicht ueber Mitternacht), ist
            // nicht gleichzeitig vergangen — Abgesagtes/Verschwundenes steht oben
            // nie, darum bleibt es hier.
            ->reject(fn ($a) => $a->declined_at === null && $a->missing_since === null && $a->deletion_marked_at === null
                && DispoEinsatzAktuell::istAktuell($a->datum->format('Y-m-d'), $a->von, $a->bis, now()))
            ->values();

        // Kunde 28.10.: Anhaenge bleiben auch bei vergangenen Einsaetzen abrufbar
        // (Berichte nach dem Check-out). Der Download prueft nur Person und Sperre.
        $attachments = $this->attachmentsByEvent($assignments->pluck('rec_dispo_event_id')->unique()->all());

        $groups = [];
        foreach ($assignments as $assignment) {
            $event = $assignment->event;
            $key = $event->id;

            $groups[$key] ??= [
                'name'        => $event->name ?? $event->einsatz_ref,
                'attachments' => $attachments[$event->id] ?? [],
                'days'        => [],
            ];

            $groups[$key]['days'][] = [
                'datum'     => $assignment->datum->format('d.m.Y'),
                'confirmed' => $assignment->confirmed_at !== null,
            ];
        }

        return array_values($groups);
    }

    /**
     * Download-Links je VA (Dateien der ganzen Identitaets-Gruppe), fuer die
     * laufenden und die vergangenen Einsaetze gleich.
     *
     * @param list<int> $eventIds
     * @return array<int, list<array{name: string, url: string, is_new: bool}>>
     */
    private function attachmentsByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        return RecDispoAttachment::query()
            ->whereIn('rec_employee_id', $this->employeeIds)
            ->whereIn('rec_dispo_event_id', $eventIds)
            ->orderBy('id')
            ->get()
            ->groupBy('rec_dispo_event_id')
            ->map(fn ($atts) => $atts->map(fn ($att) => [
                'name'   => (string) $att->original_filename,
                'url'    => route('recruiting.public.employee-assignments.attachment', ['token' => $this->token, 'uuid' => $att->uuid]),
                'is_new' => $this->isNewSince($att->created_at),
            ])->values()->all())
            ->all();
    }

    /** Sammel-Bestaetigen: ALLE kommenden Auftrags-Einsaetze dieser VA (idempotent). */
    public function confirm(int $eventId): void
    {
        if ($this->employeeId === null || $this->portalLocked) {
            return;
        }

        // Frisch pruefen — die Sperre kann nach mount() gesetzt worden sein
        // (Eskalations-Cron laeuft unabhaengig vom Request); ohne diesen
        // Re-Check koennte ein bereits offener Tab die Sperre umgehen.
        // Gesperrt, wenn irgendein Datensatz der Gruppe gesperrt ist.
        if (RecEmployee::query()->whereIn('id', $this->employeeIds)->whereNotNull('portal_locked_at')->exists()) {
            $this->portalLocked = true;
            return;
        }

        // Spiegel zu eventGroups(): bestaetigbar ist genau, was oben steht —
        // auch die Schicht vom Vortag, solange sie noch laeuft (Kunde 28.10.).
        $ids = RecDispoAssignment::query()
            ->where('rec_dispo_event_id', $eventId)
            ->whereIn('rec_employee_id', $this->employeeIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            // Absage-Riegel (Kunde 04.09.): auch ein noch offener alter Tab
            // kann einen abgemeldeten Einsatz nicht mehr bestaetigen.
            ->whereNull('declined_at')
            ->whereNull('confirmed_at')
            ->whereDate('datum', '>=', DispoEinsatzAktuell::abDatum(now()))
            // Nur Angeschriebenes ist bestaetigbar (Kunde 01.09., Spiegel zu eventGroups).
            ->whereNotNull('reminder_sent_at')
            ->get(['id', 'datum', 'von', 'bis'])
            ->filter(fn ($a) => DispoEinsatzAktuell::istAktuell($a->datum->format('Y-m-d'), $a->von, $a->bis, now()))
            ->pluck('id')
            ->all();

        RecDispoAssignment::query()
            ->whereIn('id', $ids)
            ->whereNull('confirmed_at')
            ->whereNull('declined_at')
            ->update([
                'confirmed_at'          => now(),
                // Runde 4 (#2): Zeiten zum Bestaetigungszeitpunkt merken — Vergleichsbasis
                // fuer spaetere Lieferungen. Marker der Rebestaetigung loeschen.
                'confirmed_datum'       => \Illuminate\Support\Facades\DB::raw('datum'),
                'confirmed_von'         => \Illuminate\Support\Facades\DB::raw('von'),
                'confirmed_bis'         => \Illuminate\Support\Facades\DB::raw('bis'),
                'reconfirm_required_at' => null,
                'reconfirm_previous'    => null,
            ]);

        unset($this->eventGroups);
    }

    /**
     * Die Einsaetze, die der Mitarbeiter sieht — EINE Regel fuer die
     * Einsatz-Seite (eventGroups) und die Kachel „Nächster Einsatz“ auf der
     * Portal-Startseite (29.10.2026), damit beide nie Verschiedenes sagen.
     *
     * @param list<int> $employeeIds Identitaets-Gruppe (DispoIdentityResolver::groupFor)
     * @return \Illuminate\Support\Collection<int, RecDispoAssignment>
     */
    public static function sichtbareEinsaetze(array $employeeIds, \DateTimeInterface $jetzt): \Illuminate\Support\Collection
    {
        return RecDispoAssignment::query()
            ->with('event')
            ->whereIn('rec_employee_id', $employeeIds)
            ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
            ->whereNull('missing_since')
            ->whereNull('deletion_marked_at')
            // Absage (Kunde 04.09.): abgemeldete Einsaetze verschwinden von der
            // Einsatz-Seite — gleiche Behandlung wie die Stufe-3-Rausnahme.
            ->whereNull('declined_at')
            // Kunde 28.10.: Ein Einsatz bleibt oben, bis die Schicht wirklich vorbei
            // ist (Ende + Nachlauf, auch nach Mitternacht) — nicht nur bis zum Tag.
            // Die Datenbank holt ab dem Vortag, der Filter entscheidet je Zeile.
            ->whereDate('datum', '>=', DispoEinsatzAktuell::abDatum($jetzt))
            // Kunde 01.09.: Der MA sieht NUR angeschriebene (oder bereits bestaetigte)
            // Einsaetze — exakt das, was RG versendet hat, nicht den ganzen Dispo-Bestand.
            // Nicht versendete Einbuchungen bleiben unsichtbar, bis HR den Versand ausloest.
            ->where(fn ($q) => $q->whereNotNull('reminder_sent_at')->orWhereNotNull('confirmed_at'))
            ->orderBy('datum')->orderBy('von')
            ->get()
            ->filter(fn ($a) => DispoEinsatzAktuell::istAktuell($a->datum->format('Y-m-d'), $a->von, $a->bis, $jetzt))
            ->values();
    }

    public function render()
    {
        return view('recruiting::livewire.public.employee-assignments')
            ->layout('platform::layouts.guest');
    }
}
