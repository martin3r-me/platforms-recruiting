<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolved Lookup-Werte zu ihren Labels fuer den ZAS-CSV-Export.
 *
 * Bei lookup-Feldern speichert CoreExtraFieldValue den machine-readable
 * `value`-String (z. B. "techniker_krankenkasse"). ZAS erwartet aber
 * den menschen-lesbaren `label` (z. B. "Techniker Krankenkasse").
 *
 * Implementierung mit pre-loaded Cache: beim Erstaufruf wird einmal
 * pro Lookup eine Map value→label aus core_lookup_values gezogen,
 * danach In-Memory-Lookup.
 */
class ZasLookupResolver
{
    /**
     * Cache: definition-id → ['value' => 'label']
     */
    protected array $labelMaps = [];

    /**
     * Cache: definition-id → lookup-id (oder null wenn kein Lookup)
     */
    protected array $definitionLookupIds = [];

    /**
     * Resolved den value eines Lookup-Feldes zum Label.
     *
     * @param  int    $definitionId  CoreExtraFieldDefinition.id
     * @param  mixed  $value         der gespeicherte Wert (string oder array bei Multi-Select)
     * @return string|null           das Label oder null wenn nicht aufloesbar
     */
    public function resolveLabel(int $definitionId, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Multi-Select: Array von Values → Komma-separierte Labels
        if (is_array($value)) {
            $labels = collect($value)
                ->map(fn ($v) => $this->resolveLabel($definitionId, $v))
                ->filter()
                ->all();
            return $labels === [] ? null : implode(', ', $labels);
        }

        $stringValue = (string) $value;

        if (!isset($this->labelMaps[$definitionId])) {
            $this->loadLabelMap($definitionId);
        }

        return $this->labelMaps[$definitionId][$stringValue] ?? $stringValue;
    }

    /**
     * Laedt die value→label Map fuer eine Definition aus core_lookup_values.
     */
    protected function loadLabelMap(int $definitionId): void
    {
        // 1. Lookup-ID aus den Definition-Options holen
        $options = DB::table('core_extra_field_definitions')
            ->where('id', $definitionId)
            ->value('options');

        $lookupId = null;
        if ($options) {
            $decoded = is_string($options) ? json_decode($options, true) : $options;
            $lookupId = $decoded['lookup_id'] ?? null;
        }

        $this->definitionLookupIds[$definitionId] = $lookupId;

        if (!$lookupId) {
            $this->labelMaps[$definitionId] = [];
            return;
        }

        // 2. value→label Map aus core_lookup_values
        $this->labelMaps[$definitionId] = DB::table('core_lookup_values')
            ->where('lookup_id', $lookupId)
            ->pluck('label', 'value')
            ->all();
    }
}
