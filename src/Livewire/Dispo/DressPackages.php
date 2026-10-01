<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoDressPackage;
use Platform\Recruiting\Models\RecDispoEventDress;

/**
 * Disposition → Wäschepakete: Katalog pflegen.
 *
 * PITFALL-AUFLAGE (wie Dispo\Settings): schlichte Inputs mit wire:model und
 * explizitem Speichern — NICHT x-ui-input-select + @entangle.
 *
 * Geloescht wird nie, nur deaktiviert: bestehende Zuordnungen und
 * festgeschriebene Einbuchungen brauchen den Datensatz weiter.
 *
 * SCHEMA-ENTSCHEIDUNG: `items_text` bleibt in der Datenbank ein
 * Semikolon-getrennter Text — kein Schema-Umbau, keine Migration. Die Chips
 * in dieser Maske sind reine Bedienung: beim Laden (edit()/duplicate()) wird
 * der Text aufgesplittet, beim Speichern (save()) mit '; ' wieder
 * zusammengesetzt. Grund: Resolver (DispoDressResolver), die Einsatz-Seite,
 * die Vorschau im Sende-Fenster und vor allem die beim Versand eingefrorene
 * Textkopie rec_dispo_assignments.dress_items_text lesen alle genau dieses
 * eine Feld — eine zweite Form desselben Inhalts (z. B. eine JSON-Spalte
 * nebenher) waere eine Fehlerquelle ohne Gewinn.
 *
 * TESTBARKEIT: save() validiert ueber addError() statt $this->validate() —
 * in der handgebauten Capsule-Testsuite dieses Moduls ist kein
 * Illuminate\Validation\Factory gebunden. Verhalten fuer die Dispo ist
 * identisch (inline Fehlermeldung unter dem Feld), nur der Mechanismus
 * folgt dem bereits in Show::saveDress() etablierten addError()-Muster.
 */
class DressPackages extends Component
{
    public string $name = '';
    public ?int $editingId = null;
    public bool $saved = false;

    /** @var list<string> Teile des gerade bearbeiteten Pakets, in Reihenfolge. */
    public array $items = [];

    public string $newItem = '';

    /** @var array<int, bool> Paket-ID => Rueckfrage "wirklich ausmustern?" ist gerade sichtbar. */
    public array $confirmingRetire = [];

    #[Computed]
    public function packages(): \Illuminate\Support\Collection
    {
        return RecDispoDressPackage::query()
            ->where('team_id', $this->teamId())
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    /** @return array<int, int> Paket-ID => Anzahl Veranstaltungen (distinct), in denen es zugeordnet ist. */
    #[Computed]
    public function usageCounts(): array
    {
        $ids = $this->packages->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        return RecDispoEventDress::query()
            ->whereIn('rec_dispo_dress_package_id', $ids)
            ->selectRaw('rec_dispo_dress_package_id, count(distinct rec_dispo_event_id) as cnt')
            ->groupBy('rec_dispo_dress_package_id')
            ->pluck('cnt', 'rec_dispo_dress_package_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Vorschlaege fuer das Eingabefeld: unterschiedliche Teile aus den
     * Paketen dieses Teams, ohne die bereits in $items enthaltenen,
     * gefiltert nach $newItem (Teilstring, Gross-/Kleinschreibung egal),
     * hoechstens 8, alphabetisch.
     *
     * @return list<string>
     */
    #[Computed]
    public function itemSuggestions(): array
    {
        $needle = mb_strtolower(trim($this->newItem));
        $existingLower = array_map(static fn ($i) => mb_strtolower($i), $this->items);

        $texts = RecDispoDressPackage::query()
            ->where('team_id', $this->teamId())
            ->pluck('items_text');

        $byLower = [];
        foreach ($texts as $text) {
            foreach ($this->splitItems((string) $text) as $part) {
                $lower = mb_strtolower($part);
                if (in_array($lower, $existingLower, true)) {
                    continue;
                }
                if ($needle !== '' && !str_contains($lower, $needle)) {
                    continue;
                }
                // Erste begegnete Schreibweise gewinnt bei Gross-/Kleinschreibungs-Dubletten.
                $byLower[$lower] ??= $part;
            }
        }

        $suggestions = array_values($byLower);
        sort($suggestions, SORT_FLAG_CASE | SORT_STRING);

        return array_slice($suggestions, 0, 8);
    }

    public function addItem(): void
    {
        $this->resetErrorBag('newItem');

        $value = trim($this->newItem);
        if ($value === '') {
            return;
        }

        if (str_contains($value, ';')) {
            $this->addError('newItem', 'Ein Teil darf kein Semikolon enthalten — sonst wuerden beim naechsten Oeffnen daraus zwei.');
            return;
        }

        $lower = mb_strtolower($value);
        foreach ($this->items as $existing) {
            if (mb_strtolower($existing) === $lower) {
                $this->addError('newItem', 'Dieses Teil ist schon in der Liste.');
                return;
            }
        }

        $this->items[] = $value;
        $this->newItem = '';
    }

    /** Uebernimmt einen Vorschlag als Chip — derselbe Weg wie addItem(). */
    public function addSuggestion(string $value): void
    {
        $this->newItem = $value;
        $this->addItem();
    }

    public function removeItem(int $index): void
    {
        if (!array_key_exists($index, $this->items)) {
            return;
        }
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function moveItem(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (!array_key_exists($index, $this->items) || !array_key_exists($target, $this->items)) {
            return; // Rand: No-op.
        }

        [$this->items[$index], $this->items[$target]] = [$this->items[$target], $this->items[$index]];
    }

    public function edit(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $this->editingId = $package->id;
        $this->name = (string) $package->name;
        $this->items = $this->splitItems((string) $package->items_text);
        $this->newItem = '';
        $this->saved = false;
        $this->resetErrorBag();
    }

    /** Fuellt das Formular mit "<Name> (Kopie)" und denselben Teilen — speichert NICHT. */
    public function duplicate(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $this->editingId = null;
        $this->name = trim((string) $package->name) . ' (Kopie)';
        $this->items = $this->splitItems((string) $package->items_text);
        $this->newItem = '';
        $this->saved = false;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['name', 'items', 'newItem', 'editingId', 'saved']);
        $this->resetErrorBag();
    }

    public function save(): void
    {
        if (!$this->validateForSave()) {
            return;
        }

        $itemsText = implode('; ', $this->items);

        if ($this->editingId !== null) {
            RecDispoDressPackage::query()
                ->where('team_id', $this->teamId())
                ->findOrFail($this->editingId)
                ->update(['name' => trim($this->name), 'items_text' => $itemsText]);
        } else {
            RecDispoDressPackage::create([
                'team_id'    => $this->teamId(),
                'name'       => trim($this->name),
                'items_text' => $itemsText,
                'sort_order' => (int) RecDispoDressPackage::query()->where('team_id', $this->teamId())->max('sort_order') + 1,
            ]);
        }

        unset($this->packages, $this->usageCounts, $this->itemSuggestions);
        $this->reset(['name', 'items', 'newItem', 'editingId']);
        $this->saved = true;
    }

    /** Ausmustern mit Rueckfrage, sobald das Paket irgendwo zugeordnet ist. */
    public function requestRetire(int $id): void
    {
        $usage = $this->usageCounts[$id] ?? 0;
        if ($usage > 0) {
            $this->confirmingRetire[$id] = true;
            return;
        }

        $this->toggleActive($id);
    }

    public function confirmRetire(int $id): void
    {
        unset($this->confirmingRetire[$id]);
        $this->toggleActive($id);
    }

    public function cancelRetire(int $id): void
    {
        unset($this->confirmingRetire[$id]);
    }

    public function toggleActive(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $package->update(['is_active' => !$package->is_active]);
        unset($this->packages);
    }

    /**
     * Tauscht sort_order mit dem Vorgaenger in der aktuell sichtbaren
     * Sortierung (sort_order, dann name).
     */
    public function moveUp(int $id): void
    {
        $this->swapWithNeighbor($id, -1);
    }

    /** Tauscht sort_order mit dem Nachfolger in der aktuell sichtbaren Sortierung. */
    public function moveDown(int $id): void
    {
        $this->swapWithNeighbor($id, 1);
    }

    /**
     * Seeder (Array-Index) und diese Maske (bisher max+1) koennen bei
     * sort_order Gleichstaende erzeugen — ein simpler Tausch zweier
     * gleicher Werte waere dann wirkungslos. Deshalb erst die GESAMTE
     * Teamliste in der aktuell sichtbaren Reihenfolge lueckenlos
     * durchnummerieren (0..n-1), danach erst die zwei Nachbarn tauschen.
     * Das Ergebnis ist damit garantiert eindeutig.
     */
    private function swapWithNeighbor(int $id, int $direction): void
    {
        $list = $this->packages->values();
        $ids = $list->pluck('id')->all();
        $index = array_search($id, $ids, true);
        if ($index === false) {
            return;
        }

        $target = $index + $direction;
        if ($target < 0 || $target >= count($ids)) {
            return; // Rand: No-op.
        }

        foreach ($list as $position => $package) {
            if ((int) $package->sort_order !== $position) {
                $package->update(['sort_order' => $position]);
            }
        }

        $list[$index]->update(['sort_order' => $target]);
        $list[$target]->update(['sort_order' => $index]);

        unset($this->packages);
    }

    private function validateForSave(): bool
    {
        $this->resetErrorBag(['name', 'items']);
        $ok = true;

        $name = trim($this->name);
        if ($name === '') {
            $this->addError('name', 'Bitte einen Namen eingeben.');
            $ok = false;
        } elseif (mb_strlen($name) > 120) {
            $this->addError('name', 'Name darf hoechstens 120 Zeichen haben.');
            $ok = false;
        }

        if ($this->items === []) {
            $this->addError('items', 'Mindestens ein Teil muss vorhanden sein.');
            $ok = false;
        } else {
            // Grenze liegt bewusst auf dem ZUSAMMENGESETZTEN Text (vormals
            // itemsText: max:2000), nicht auf der Zahl der Chips: der
            // Mitarbeiter liest auf der Einsatz-Seite eine Zeile, keine
            // Liste — die Chips sind nur unsere Bedienung dafuer.
            $joined = implode('; ', $this->items);
            if (mb_strlen($joined) > 2000) {
                $this->addError('items', 'Die Kleidung ist als ein Satz zu lang (max. 2000 Zeichen) — bitte kuerzen.');
                $ok = false;
            }
        }

        return $ok;
    }

    /** @return list<string> */
    private function splitItems(string $text): array
    {
        $parts = array_map('trim', explode(';', $text));

        return array_values(array_filter($parts, static fn ($p) => $p !== ''));
    }

    private function teamId(): int
    {
        return (int) (config('recruiting.zas.inbound_team_id') ?: auth()->user()->currentTeam->id);
    }

    public function render()
    {
        return view('recruiting::livewire.dispo.dress-packages')
            ->layout('platform::layouts.app');
    }
}
