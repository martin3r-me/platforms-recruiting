<?php

namespace Platform\Recruiting\Livewire\Dispo;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Platform\Recruiting\Models\RecDispoDressPackage;

/**
 * Disposition → Wäschepakete: Katalog pflegen.
 *
 * PITFALL-AUFLAGE (wie Dispo\Settings): schlichte Inputs mit wire:model und
 * explizitem Speichern — NICHT x-ui-input-select + @entangle.
 *
 * Geloescht wird nie, nur deaktiviert: bestehende Zuordnungen und
 * festgeschriebene Einbuchungen brauchen den Datensatz weiter.
 */
class DressPackages extends Component
{
    public string $name = '';
    public string $itemsText = '';
    public ?int $editingId = null;
    public bool $saved = false;

    #[Computed]
    public function packages(): \Illuminate\Support\Collection
    {
        return RecDispoDressPackage::query()
            ->where('team_id', $this->teamId())
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    public function edit(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $this->editingId = $package->id;
        $this->name = (string) $package->name;
        $this->itemsText = (string) $package->items_text;
        $this->saved = false;
    }

    public function cancel(): void
    {
        $this->reset(['name', 'itemsText', 'editingId', 'saved']);
    }

    public function save(): void
    {
        $this->validate([
            'name'      => 'required|string|max:120',
            'itemsText' => 'required|string|max:2000',
        ], [], ['name' => 'Name', 'itemsText' => 'Kleidung']);

        if ($this->editingId !== null) {
            RecDispoDressPackage::query()
                ->where('team_id', $this->teamId())
                ->findOrFail($this->editingId)
                ->update(['name' => trim($this->name), 'items_text' => trim($this->itemsText)]);
        } else {
            RecDispoDressPackage::create([
                'team_id'    => $this->teamId(),
                'name'       => trim($this->name),
                'items_text' => trim($this->itemsText),
                'sort_order' => (int) RecDispoDressPackage::query()->where('team_id', $this->teamId())->max('sort_order') + 1,
            ]);
        }

        unset($this->packages);
        $this->reset(['name', 'itemsText', 'editingId']);
        $this->saved = true;
    }

    public function toggleActive(int $id): void
    {
        $package = RecDispoDressPackage::query()->where('team_id', $this->teamId())->findOrFail($id);
        $package->update(['is_active' => !$package->is_active]);
        unset($this->packages);
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
