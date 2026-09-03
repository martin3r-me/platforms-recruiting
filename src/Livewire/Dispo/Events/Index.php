<?php

namespace Platform\Recruiting\Livewire\Dispo\Events;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoEvent;
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

    private function isValidDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    public function render()
    {
        $query = RecDispoEvent::query()
            ->withCount([
                // Kunde 03.09.: verschwundene (aus dem ZAS-Bestand gefallene) und zur
                // Loeschung gemeldete Einbuchungen zaehlen nicht mehr mit — sonst
                // wirkt eine VA ewig "offen", obwohl niemand mehr zu bestaetigen ist.
                // Die Tabelle der VA-Seite zeigt beide weiterhin (mit Badge).
                'assignments' => fn ($q) => $q->whereNull('missing_since')->whereNull('deletion_marked_at'),
                'assignments as matched_count' => fn ($q) => $q->whereNull('missing_since')->whereNull('deletion_marked_at')->whereNotNull('rec_employee_id'),
                'assignments as confirmed_count' => fn ($q) => $q->whereNull('missing_since')->whereNull('deletion_marked_at')->whereNotNull('confirmed_at'),
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

        return view('recruiting::livewire.dispo.events.index', ['events' => $events, 'unreadByEvent' => $unreadByEvent])
            ->layout('platform::layouts.app');
    }
}
