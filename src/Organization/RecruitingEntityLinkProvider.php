<?php

namespace Platform\Recruiting\Organization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecPosition;
use Platform\Organization\Contracts\EntityLinkProvider;

class RecruitingEntityLinkProvider implements EntityLinkProvider
{
    public function morphAliases(): array
    {
        return ['rec_applicant', 'rec_position'];
    }

    public function linkTypeConfig(): array
    {
        return [
            'rec_applicant' => ['label' => 'Bewerber', 'singular' => 'Bewerber', 'icon' => 'user-plus', 'route' => null],
            'rec_position' => ['label' => 'Positionen', 'singular' => 'Position', 'icon' => 'briefcase', 'route' => null],
        ];
    }

    public function applyEagerLoading(Builder $query, string $morphAlias, string $fqcn): void
    {
        match ($morphAlias) {
            'rec_applicant' => $query->withCount('postings'),
            'rec_position' => $query->withCount('postings'),
            default => null,
        };
    }

    public function extractMetadata(string $morphAlias, mixed $model): array
    {
        return match ($morphAlias) {
            'rec_applicant' => [
                'is_active' => (bool) ($model->is_active ?? false),
                'progress' => (int) ($model->progress ?? 0),
                'posting_count' => (int) ($model->postings_count ?? 0),
                'applied_at' => $model->applied_at?->format('d.m.Y'),
            ],
            'rec_position' => [
                'is_active' => (bool) ($model->is_active ?? false),
                'posting_count' => (int) ($model->postings_count ?? 0),
            ],
            default => [],
        };
    }

    public function metadataDisplayRules(): array
    {
        return [
            'rec_applicant' => [
                ['field' => 'applied_at', 'format' => 'prefixed_text', 'prefix' => 'beworben'],
                ['field' => 'posting_count', 'format' => 'count', 'suffix' => 'Stellen'],
                ['field' => 'progress', 'format' => 'percentage', 'suffix' => 'Fortschritt'],
                ['field' => 'is_active', 'format' => 'boolean_active'],
            ],
            'rec_position' => [
                ['field' => 'posting_count', 'format' => 'count', 'suffix' => 'Ausschreibungen'],
                ['field' => 'is_active', 'format' => 'boolean_active'],
            ],
        ];
    }

    public function timeTrackableCascades(): array
    {
        return [];
    }

    public function activityChildren(string $morphAlias, array $linkableIds): array
    {
        return [];
    }

    public function metrics(string $morphAlias, array $linksByEntity): array
    {
        return match ($morphAlias) {
            'rec_applicant' => $this->applicantMetrics($linksByEntity),
            'rec_position' => $this->positionMetrics($linksByEntity),
            default => [],
        };
    }

    protected function applicantMetrics(array $linksByEntity): array
    {
        $allIds = [];
        foreach ($linksByEntity as $ids) {
            $allIds = array_merge($allIds, $ids);
        }
        $allIds = array_values(array_unique($allIds));

        if (empty($allIds)) {
            return [];
        }

        $applicants = RecApplicant::whereIn('id', $allIds)
            ->select('id', 'is_active', 'is_parked')
            ->get()
            ->keyBy('id');

        // Applicants that have at least one signed contract
        $hiredIds = DB::table('rec_contracts')
            ->whereIn('rec_applicant_id', $allIds)
            ->whereNotNull('signed_at')
            ->distinct()
            ->pluck('rec_applicant_id')
            ->flip()
            ->all();

        $result = [];
        foreach ($linksByEntity as $entityId => $ids) {
            $total = 0;
            $active = 0;
            $hired = 0;

            foreach ($ids as $id) {
                $applicant = $applicants[$id] ?? null;
                if (!$applicant) {
                    continue;
                }
                $total++;
                if ($applicant->is_active && !$applicant->is_parked) {
                    $active++;
                }
                if (isset($hiredIds[$id])) {
                    $hired++;
                }
            }

            $result[$entityId] = [
                'rec_applicants_total' => $total,
                'rec_applicants_active' => $active,
                'rec_applicants_hired' => $hired,
            ];
        }

        return $result;
    }

    protected function positionMetrics(array $linksByEntity): array
    {
        $allIds = [];
        foreach ($linksByEntity as $ids) {
            $allIds = array_merge($allIds, $ids);
        }
        $allIds = array_values(array_unique($allIds));

        if (empty($allIds)) {
            return [];
        }

        $positions = RecPosition::whereIn('id', $allIds)
            ->select('id', 'is_active')
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($linksByEntity as $entityId => $ids) {
            $total = 0;
            $active = 0;

            foreach ($ids as $id) {
                $position = $positions[$id] ?? null;
                if (!$position) {
                    continue;
                }
                $total++;
                if ($position->is_active) {
                    $active++;
                }
            }

            $result[$entityId] = [
                'rec_positions_total' => $total,
                'rec_positions_active' => $active,
            ];
        }

        return $result;
    }
}
