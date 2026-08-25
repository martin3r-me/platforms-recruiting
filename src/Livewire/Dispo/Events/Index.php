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
    public bool $showPast = false;

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $filialeFilter = '';

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
                'assignments',
                'assignments as matched_count' => fn ($q) => $q->whereNotNull('rec_employee_id'),
                'assignments as confirmed_count' => fn ($q) => $q->whereNotNull('confirmed_at'),
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

        return view('recruiting::livewire.dispo.events.index', ['events' => $events])
            ->layout('platform::layouts.app');
    }
}
