<?php

namespace Platform\Recruiting\Services\Dashboard;

/**
 * Zählt gefüllte/gesamt Extra-Felder für den Dashboard-Badge.
 * Semantik identisch zum bisherigen Dashboard::getExtraFieldCounts():
 * total = Anzahl geltender Definitionen, filled = Werte, die nicht
 * null/''/[]/'[]' sind. Pure: Arrays rein, Array raus.
 */
class ExtraFieldCounts
{
    /**
     * @param array $definitionIds  IDs aller für den Bewerber geltenden Definitionen
     * @param array<int|string, mixed> $valuesByDefinitionId  definition_id => typed_value
     * @return array{filled: int, total: int}
     */
    public static function forApplicant(array $definitionIds, array $valuesByDefinitionId): array
    {
        $filled = 0;
        foreach ($definitionIds as $definitionId) {
            $value = $valuesByDefinitionId[$definitionId] ?? null;
            if ($value !== null && $value !== '' && $value !== [] && $value !== '[]') {
                $filled++;
            }
        }

        return ['filled' => $filled, 'total' => count($definitionIds)];
    }
}
