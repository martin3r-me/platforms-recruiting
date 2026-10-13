<?php

namespace Platform\Recruiting\Services\Applicants;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Services\Zas\ZasLookupResolver;

/**
 * „Ich bin“ (Schüler, Azubi, Teilzeit, angestellt …) für viele Bewerber auf
 * einmal — für die Schulungsnachbereitung (Kunde 13.10.2026): Der
 * Schulungsleiter trägt den Zuschlag von Hand ein und muss dafür sehen, wer
 * einen Hauptjob, eine Ausbildung oder Teilzeit hat, ohne jeden Bewerber
 * einzeln zu öffnen.
 *
 * Ein Query für alle Werte, Label über die Lookup-Liste des Feldes (gleicher
 * Weg wie der ZAS-Export). Das Feld kann je Stelle/Phase eine eigene
 * Definition haben; gezählt wird jede Definition mit dem Namen `ich_bin`,
 * bei mehreren gewinnt der zuletzt gespeicherte Wert.
 */
class IchBinLabels
{
    public const FIELD = 'ich_bin';

    /**
     * @param iterable<int|string|null> $applicantIds
     * @return array<int, string> applicant_id => Label (nur Bewerber mit Angabe)
     */
    public static function forApplicants(iterable $applicantIds): array
    {
        $ids = [];
        foreach ($applicantIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('core_extra_field_values as v')
            ->join('core_extra_field_definitions as d', 'd.id', '=', 'v.definition_id')
            ->where('v.fieldable_type', 'rec_applicant')
            ->whereIn('v.fieldable_id', array_keys($ids))
            ->where('d.name', self::FIELD)
            ->whereNotNull('v.value')
            ->where('v.value', '!=', '')
            ->orderByDesc('v.updated_at')
            ->orderByDesc('v.id')
            ->get(['v.fieldable_id', 'v.definition_id', 'v.value']);

        $resolver = new ZasLookupResolver();
        $out = [];
        foreach ($rows as $row) {
            $applicantId = (int) $row->fieldable_id;
            if (isset($out[$applicantId])) {
                continue;
            }
            $value = self::decode($row->value);
            $label = trim((string) ($resolver->resolveLabel((int) $row->definition_id, $value) ?? ''));
            if ($label === '') {
                $label = is_array($value) ? implode(', ', array_map('strval', $value)) : trim((string) $value);
            }
            if ($label !== '') {
                $out[$applicantId] = $label;
            }
        }

        return $out;
    }

    private static function decode(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}
