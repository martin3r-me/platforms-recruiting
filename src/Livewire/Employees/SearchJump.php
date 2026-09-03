<?php

namespace Platform\Recruiting\Livewire\Employees;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Sprung-Suche fuer die MA-Detailseite (Kundenwunsch 2026-09-03: "innerhalb
 * des Mitarbeiters einen anderen suchen, ohne eine Ebene zurueck").
 *
 * Absichtlich eine eigene Mini-Komponente und kein Anbau an Show: die ist
 * schon gross, und die Box laesst sich so ueberall in eine Actionbar haengen.
 *
 * Gesucht wird ueber dieselben Spalten wie in der Liste (Index), aber OHNE
 * Aktiv-Filter — HR muss ausgeschiedene MA auch direkt anspringen koennen;
 * die Trefferzeile weist "inaktiv" dann aus.
 */
class SearchJump extends Component
{
    /** Unter dieser Laenge wird gar nicht gesucht (sonst halbe Tabelle). */
    public const MIN_CHARS = 2;

    /** Sichtbare Treffer; was darueber liegt, meldet die Fusszeile. */
    public const LIMIT = 8;

    /**
     * Der gerade offene MA — faellt aus den Treffern raus. Gesperrt: das
     * Property steuert eine Query-Bedingung, die soll kein $wire.set drehen.
     */
    #[Locked]
    public ?int $currentId = null;

    public string $search = '';

    public function mount(?int $currentId = null): void
    {
        $this->currentId = $currentId;
    }

    public function clear(): void
    {
        $this->search = '';
    }

    /**
     * Treffer-Query oder null, wenn der Suchtext zu kurz ist.
     *
     * Bewusst statisch und mit uebergebener Team-ID: so ist die Suche ohne
     * Livewire- und Auth-Gerippe testbar.
     */
    private static function searchQuery(int $teamId, string $search, ?int $currentId): ?Builder
    {
        $needle = trim($search);

        if (mb_strlen($needle) < self::MIN_CHARS) {
            return null;
        }

        $like = '%' . $needle . '%';

        return RecEmployee::query()
            ->where('team_id', $teamId)
            ->when($currentId, fn (Builder $q) => $q->where('id', '!=', $currentId))
            ->where(function (Builder $q) use ($like) {
                $q->where('first_name', 'like', $like)
                  ->orWhere('last_name', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('phone', 'like', $like);
            });
    }

    /** @return EloquentCollection<int, RecEmployee> */
    public static function matches(int $teamId, string $search, ?int $currentId): EloquentCollection
    {
        $query = self::searchQuery($teamId, $search, $currentId);

        if (!$query) {
            return new EloquentCollection();
        }

        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'is_active']);
    }

    public static function countMatches(int $teamId, string $search, ?int $currentId): int
    {
        $query = self::searchQuery($teamId, $search, $currentId);

        return $query ? $query->count() : 0;
    }

    #[Computed]
    public function results(): EloquentCollection
    {
        return self::matches($this->teamId(), $this->search, $this->currentId);
    }

    /**
     * Nur aufrufen, wenn das Limit ausgeschoepft ist — sonst kostet die
     * Fusszeile bei jedem Tastendruck eine zweite Query ohne Aussage.
     */
    #[Computed]
    public function totalCount(): int
    {
        return self::countMatches($this->teamId(), $this->search, $this->currentId);
    }

    #[Computed]
    public function hasQuery(): bool
    {
        return mb_strlen(trim($this->search)) >= self::MIN_CHARS;
    }

    private function teamId(): int
    {
        return (int) auth()->user()->currentTeam->id;
    }

    public function render()
    {
        return view('recruiting::livewire.employees.search-jump');
    }
}
