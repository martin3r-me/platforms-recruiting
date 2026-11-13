<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoAssignment;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoLieferungsStand;
use Platform\Recruiting\Support\Filialen;

/**
 * Disposition → Veranstaltungen: VAs aus dem ZAS-Webexport.
 * Kommende zuerst; Vergangene per Toggle. Team-los (siehe Migration).
 */
class Index extends Component
{
    // Kunde 03.09.: Filter leben in der URL — Browser-Zurueck aus einer VA
    // stellt die gefilterte Liste wieder her (vorher: Reset bei jedem Zurueck).
    #[\Livewire\Attributes\Url]
    public bool $showPast = false;

    #[\Livewire\Attributes\Url]
    public string $dateFrom = '';

    #[\Livewire\Attributes\Url]
    public string $dateTo = '';

    #[\Livewire\Attributes\Url]
    public string $filialeFilter = '';

    /**
     * Standard-Ansicht = naechster Tag (Kunden-Feedback 2): beim ersten Oeffnen
     * ohne gesetzten Filter wird von=bis=morgen vorbelegt. Mit dem
     * Ueberlappungs-Filter erscheinen damit alle VAs, die morgen stattfinden
     * (inkl. laufender Mehrtages-VAs). Filter leeren -> wieder alles Kommende.
     */
    public function mount(): void
    {
        if ($this->dateFrom === '' && $this->dateTo === '') {
            $tomorrow = now()->addDay()->toDateString();
            $this->dateFrom = $tomorrow;
            $this->dateTo = $tomorrow;
        }
    }

    /**
     * Filter-Optionen: nur tatsaechlich vorkommende Filialnummern, beschriftet
     * ueber die zentrale Map (Fallback: Roh-Text bzw. die Nummer selbst).
     *
     * @return array<int, string> Nummer => Anzeige-Label
     */
    #[Computed]
    public function filialeOptions(): array
    {
        $present = RecDispoEvent::query()
            ->whereNotNull('filial_nr')
            ->distinct()
            ->orderBy('filial_nr')
            ->pluck('filial_nr')
            ->all();

        $options = [];
        foreach ($present as $nr) {
            $nr = (int) $nr;
            $options[$nr] = Filialen::code($nr) ?? ('#' . $nr);
        }

        return $options;
    }

    /**
     * Tagesweise blaettern (Kunde 26.09.): verschiebt BEIDE Datumsfelder um
     * $days, ein gewaehlter Zeitraum behaelt also seine Laenge. Leere oder
     * ungueltige Felder starten bei heute.
     */
    public function shiftDates(int $days): void
    {
        if ($days === 0) {
            return;
        }
        $from = $this->isValidDate($this->dateFrom)
            ? \Illuminate\Support\Carbon::parse($this->dateFrom)
            : \Illuminate\Support\Carbon::today();
        $to = $this->isValidDate($this->dateTo)
            ? \Illuminate\Support\Carbon::parse($this->dateTo)
            : $from->copy();

        $this->dateFrom = $from->copy()->addDays($days)->toDateString();
        $this->dateTo   = $to->copy()->addDays($days)->toDateString();

        // Rueckwaerts blaettern soll auch etwas zeigen: liegt der Zeitraum
        // komplett in der Vergangenheit, wuerde sonst der Kommend-Filter greifen
        // und die Liste waere leer.
        if ($this->dateTo < now()->toDateString()) {
            $this->showPast = true;
        }
    }

    /** Zurueck auf heute (nach weitem Blaettern). */
    public function jumpToToday(): void
    {
        $this->dateFrom = now()->toDateString();
        $this->dateTo   = now()->toDateString();
    }

    private function isValidDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    /** Gemeinsamer Zaehl-Rahmen: ohne Verschwundene/Geloeschte, bei Datumsfilter nur der Zeitraum. */
    private function countScope($q)
    {
        $q->whereNull('missing_since')->whereNull('deletion_marked_at');

        return $this->dateScope($q);
    }

    /** Nur der Zeitraum des Datumsfilters — fuer Zahlen, die gerade die Geloeschten/Verschwundenen brauchen. */
    private function dateScope($q)
    {
        if ($this->dateFrom !== '' && $this->isValidDate($this->dateFrom)) {
            $q->whereDate('datum', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '' && $this->isValidDate($this->dateTo)) {
            $q->whereDate('datum', '<=', $this->dateTo);
        }

        return $q;
    }

    /**
     * Zwei Marken der Zeile (Sheran 13.11.2026), beide im Zeitraum des Filters
     * und nur ab heute — fuer vergangene Tage ist nichts mehr zu tun, und sie
     * verschwinden aus keiner Lieferung mehr (die Marke bliebe sonst ewig).
     *  - rausnehmen_count: zur Loeschung gemeldet, in ZAS aber noch drin (kein
     *    Haken „ZAS raus“, nicht aus der Lieferung verschwunden). Diese
     *    Einbuchungen zaehlen bei „gesamt“ bewusst nicht mit — ohne die Marke
     *    stand dort „0 gesamt · 0 bestätigt“, und nur die Alarm-WhatsApp sagte,
     *    dass jemand rausgenommen werden muss.
     *  - nicht_angeschrieben_count: Auftrag, offen und ohne Bestaetigungs-
     *    Nachricht — an einem TAG, fuer den in dieser VA schon versendet wurde.
     *    Ohne diesen Bezug waere es der Normalzustand (Mehrtaeger werden oft
     *    tageweise verschickt). Der Beleg darf an einer verschwundenen Zeile
     *    haengen: nach einem Personenwechsel liegt die alte Besetzung samt
     *    Versand-Stempel dort ab. Zeilen ohne Mitarbeiter-Zuordnung zaehlen mit.
     */
    private function mitHandlungsbedarf($query)
    {
        $heute = now()->toDateString();

        return $query->withCount([
            'assignments as rausnehmen_count' => fn ($q) => $this->dateScope($q)
                ->whereDate('datum', '>=', $heute)
                ->whereNotNull('deletion_marked_at')->whereNull('zas_removed_at')->whereNull('missing_since'),
            'assignments as nicht_angeschrieben_count' => fn ($q) => $this->countScope($q)
                ->whereDate('datum', '>=', $heute)
                ->where('status_id', RecDispoAssignment::STATUS_AUFTRAG)
                ->whereNull('reminder_sent_at')->whereNull('confirmed_at')->whereNull('declined_at')
                ->whereExists(fn ($s) => $s->from('rec_dispo_assignments as gesendet')
                    ->whereColumn('gesendet.rec_dispo_event_id', 'rec_dispo_assignments.rec_dispo_event_id')
                    ->whereColumn('gesendet.datum', 'rec_dispo_assignments.datum')
                    ->whereNotNull('gesendet.reminder_sent_at')),
        ]);
    }

    /**
     * Spalte „Bestätigung versendet“ (Markus 12.11.2026): wann zuletzt und an
     * wie viele. Gleicher Zaehl-Rahmen wie „gesamt/bestätigt“, damit die Zahlen
     * einer Zeile zusammenpassen. Der Stempel wird bei Neubestaetigung und
     * Personenwechsel geleert — die Spalte zeigt den aktuellen Stand, keine Historie.
     */
    private function mitVersandStand($query)
    {
        return $query
            ->withMax(['assignments as reminder_last_sent_at' => fn ($q) => $this->countScope($q)], 'reminder_sent_at')
            ->withCount(['assignments as reminder_sent_count' => fn ($q) => $this->countScope($q)->whereNotNull('reminder_sent_at')]);
    }

    /**
     * „ZAS-Stand“ (Spec 2026-11-04): von wann die Einbuchungen sind. Laeuft
     * mit dem 30-s-Poll der Seite mit, damit eine verarbeitete Lieferung von
     * selbst erscheint. Nebeninformation: darf die VA-Liste nicht abschiessen.
     *
     * @return array{text:string, stufe:string}
     */
    #[Computed]
    public function zasStand(): array
    {
        try {
            return ZasDispoLieferungsStand::anzeige();
        } catch (\Throwable $e) {
            return ['text' => 'ZAS-Stand: nicht ermittelbar', 'stufe' => 'leer'];
        }
    }

    public function render()
    {
        $query = RecDispoEvent::query()
            ->withCount([
                // Kunde 03.09.: verschwundene (aus dem ZAS-Bestand gefallene) und zur
                // Loeschung gemeldete Einbuchungen zaehlen nicht mehr mit — sonst
                // wirkt eine VA ewig "offen", obwohl niemand mehr zu bestaetigen ist.
                // Die Tabelle der VA-Seite zeigt beide weiterhin (mit Badge).
                // Kunde 07.09.: bei gesetztem Datumsfilter zaehlen NUR die Tage im
                // Zeitraum — sonst fasst die Zeile einer Mehrtages-VA alles zusammen.
                'assignments' => fn ($q) => $this->countScope($q),
                'assignments as matched_count' => fn ($q) => $this->countScope($q)->whereNotNull('rec_employee_id'),
                'assignments as confirmed_count' => fn ($q) => $this->countScope($q)->whereNotNull('confirmed_at'),
            ])
            // Roll-up-Warnicon: irgendein Stufen- oder Alarm-Versand dieser VA fehlgeschlagen.
            // Als korrelierte EXISTS-Subqueries statt Eager-Load je Nachricht — kein N+1.
            ->withExists([
                'assignments as has_failed_send' => fn ($q) => $q->where(function ($q2) {
                    $q2->whereHas('reminderMessage', fn ($m) => $m->where('status', 'failed'))
                        ->orWhereHas('escalation1Message', fn ($m) => $m->where('status', 'failed'))
                        ->orWhereHas('escalation2Message', fn ($m) => $m->where('status', 'failed'));
                }),
                'alarmMessage as alarm_failed' => fn ($q) => $q->where('status', 'failed'),
            ]);
        $this->mitVersandStand($query);
        $this->mitHandlungsbedarf($query);

        if (!$this->showPast) {
            $query->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()->toDateString()));
        }

        // Zeitraum-Filter = "findet im Zeitraum statt" (Ueberlappung), nicht nur
        // Startdatum: eine VA erscheint, wenn sie mindestens eine Einbuchung
        // (Schicht) an einem Tag im gewaehlten Bereich hat. Sonst fielen mehrtaegige
        // VAs raus, die vor "von" beginnen aber in den Zeitraum reinlaufen.
        $hasFrom = $this->dateFrom !== '' && $this->isValidDate($this->dateFrom);
        $hasTo   = $this->dateTo !== '' && $this->isValidDate($this->dateTo);
        if ($hasFrom || $hasTo) {
            $query->whereHas('assignments', function ($q) use ($hasFrom, $hasTo) {
                if ($hasFrom) {
                    $q->whereDate('datum', '>=', $this->dateFrom);
                }
                if ($hasTo) {
                    $q->whereDate('datum', '<=', $this->dateTo);
                }
            });
        }

        $query->when(
            $this->filialeFilter !== '' && ctype_digit($this->filialeFilter),
            fn ($q) => $q->where('filial_nr', (int) $this->filialeFilter)
        );

        $events = $query->orderByRaw('starts_on IS NULL, starts_on ASC')->get();

        // Runde 4 (#1): ungelesene Threads je VA (Personen, nicht Datensaetze) — wenige Queries fuer alle VAs.
        // Nebeninformation: ein Fehler in der Kommunikations-Aufloesung (fehlender
        // Kanal, CRM-Ausfall) darf die VA-Liste nicht abschiessen (Muster DispoUnreadCounter).
        try {
            $unreadByEvent = app(\Platform\Recruiting\Services\Zas\Dispo\DispoThreadDirectory::class)->unreadByEvent(
                \Platform\Recruiting\Services\Zas\Dispo\DispoChannelResolver::dispoChannelIds(),
                $events->pluck('id')->map(fn ($v) => (int) $v)->all(),
                now()->toDateString()
            );
        } catch (\Throwable $e) {
            $unreadByEvent = [];
            \Illuminate\Support\Facades\Log::warning('dispo_unread_by_event_failed', [
                'events' => $events->count(), 'error' => $e->getMessage(),
            ]);
        }

        // Absage-Erkennung (Spec 2026-10-08): offene Meldungen je VA (Personen).
        // Gleiche Regel wie oben — eine Nebeninformation schiesst die Liste nicht ab.
        try {
            $declineByEvent = app(\Platform\Recruiting\Services\Zas\Dispo\DispoDeclineReview::class)
                ->openCountsByEvent($events->pluck('id')->map(fn ($v) => (int) $v)->all());
        } catch (\Throwable $e) {
            $declineByEvent = [];
            \Illuminate\Support\Facades\Log::warning('dispo_decline_by_event_failed', ['error' => $e->getMessage()]);
        }

        return view('recruiting::livewire.dispo.events.index', ['events' => $events, 'unreadByEvent' => $unreadByEvent, 'declineByEvent' => $declineByEvent])
            ->layout('platform::layouts.app');
    }
}
