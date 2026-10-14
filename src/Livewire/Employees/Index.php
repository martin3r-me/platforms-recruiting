<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Models\RecPosition;

/**
 * HR-Backend-Liste aller Mitarbeiter (rec_employees).
 *
 * Losgeloest vom Bewerber-Funnel. Filterbar nach Stelle + Status +
 * Such-Text + Zeitraum der ZAS-Umstellung auf MA (Kundenwunsch 2026-08-18:
 * "alle anschreiben, die in einem bestimmten Zeitraum umgestellt wurden"). Click auf MA fuehrt zur Show-Page wo HR alle Felder
 * editieren kann (auch die Login-stabilen first_name/last_name/
 * birth_date/identity_card_number, im MA-Portal sind die verboten).
 */
class Index extends Component
{
    /**
     * Obergrenze fuer die Filter "Inaktiv" und "Alle": seit ZAS die inaktiven
     * Altbestaende liefert (~10.000), wuerde die Liste sonst alles laden.
     * Greift nach Suche und Filtern — wer jemanden sucht, findet ihn.
     */
    public const LIST_LIMIT = 500;

    public string $search = '';
    public ?int $positionFilter = null;
    public string $activeFilter = 'active'; // 'active' | 'inactive' | 'all'

    /** Firmenzugehoerigkeit (RG/MA) — leer heisst alle. */
    public string $companyFilter = '';

    /**
     * Zeitraum "auf MA umgestellt" — Y-m-d-Strings direkt aus den
     * <input type="date">-Feldern. Bewusst KEINE Datums-Objekte: an einen
     * date-Cast gebundene Livewire-Properties verlieren die Eingabe.
     */
    public string $maSinceFrom = '';
    public string $maSinceTo   = '';

    public function updatingSearch(): void
    {
        // reset zu Page 1 bei Such-Aenderung — falls wir spaeter Pagination einbauen
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->positionFilter = null;
        $this->activeFilter = 'active';
        $this->companyFilter = '';
        $this->maSinceFrom = '';
        $this->maSinceTo = '';
    }

    #[Computed]
    public function positions()
    {
        return RecPosition::forTeam(auth()->user()->currentTeam->id)
            ->orderBy('title')
            ->get(['id', 'title']);
    }

    /**
     * Zeilen der Liste plus Hinweis, ob die Obergrenze gegriffen hat.
     *
     * @return array{rows: Collection, capped: bool}
     */
    #[Computed]
    public function employeeList(): array
    {
        $teamId = auth()->user()->currentTeam->id;

        // hrData eager laden: die Liste zeigt das MA-Datum pro Zeile.
        $query = RecEmployee::with(['position', 'applicant', 'hrData'])
            ->where('team_id', $teamId)
            ->statusMaSinceBetween($this->maSinceFrom, $this->maSinceTo);

        if ($this->activeFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->activeFilter === 'inactive') {
            $query->where('is_active', false);
        }

        if ($this->companyFilter !== '') {
            $query->where('company', $this->companyFilter);
        }

        if ($this->positionFilter) {
            $query->where('rec_position_id', $this->positionFilter);
        }

        if ($this->search !== '') {
            $needle = '%' . $this->search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('first_name', 'like', $needle)
                  ->orWhere('last_name', 'like', $needle)
                  ->orWhere('email', 'like', $needle)
                  ->orWhere('phone', 'like', $needle);
            });
        }

        $query->orderBy('last_name')->orderBy('first_name');

        // Eine Zeile mehr holen, um "mehr als die Grenze" zu erkennen.
        $limit = self::listLimit($this->activeFilter);
        if ($limit !== null) {
            $query->limit($limit + 1);
        }

        [$rows, $capped] = self::capRows($query->get(), $limit);

        return ['rows' => $rows, 'capped' => $capped];
    }

    #[Computed]
    public function employees(): Collection
    {
        return $this->employeeList['rows'];
    }

    #[Computed]
    public function employeesCapped(): bool
    {
        return $this->employeeList['capped'];
    }

    /** Obergrenze je Aktiv-Filter; null = ungekappt ("Aktiv"). */
    public static function listLimit(string $activeFilter): ?int
    {
        return $activeFilter === 'active' ? null : self::LIST_LIMIT;
    }

    /**
     * Schneidet auf die Grenze und meldet, ob mehr da war.
     *
     * @return array{0: Collection, 1: bool}
     */
    public static function capRows(Collection $rows, ?int $limit): array
    {
        if ($limit === null || $rows->count() <= $limit) {
            return [$rows, false];
        }

        return [$rows->take($limit)->values(), true];
    }

    public function render()
    {
        return view('recruiting::livewire.employees.index')
            ->layout('platform::layouts.app');
    }
}
