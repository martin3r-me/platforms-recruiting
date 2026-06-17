<?php

namespace Platform\Recruiting\Services\WhatsAppCost;

final class WhatsAppCostReport
{
    /** @param TemplateCost[] $templates */
    public function __construct(
        public readonly int $totalCount,
        public readonly float $totalCost,
        public readonly int $manualCount,
        public readonly float $manualCost,
        public readonly int $automaticCount,
        public readonly float $automaticCost,
        public readonly array $templates,
        public readonly string $currency,
    ) {}

    /**
     * @param array<int, array{template_name: ?string, is_manual: bool, count: int}> $rows
     */
    public static function fromRows(array $rows, float $pricePerTemplate, string $currency): self
    {
        $manualCount = 0;
        $automaticCount = 0;
        $perTemplate = []; // name => count

        foreach ($rows as $row) {
            $count = (int) $row['count'];
            if ($row['is_manual']) {
                $manualCount += $count;
            } else {
                $automaticCount += $count;
            }
            $name = $row['template_name'] ?? '(ohne Template)';
            $perTemplate[$name] = ($perTemplate[$name] ?? 0) + $count;
        }

        $cost = static fn (int $n): float => round($n * $pricePerTemplate, 2);

        $templates = [];
        foreach ($perTemplate as $name => $count) {
            $templates[] = new TemplateCost($name, $count, $cost($count));
        }
        usort($templates, static fn (TemplateCost $a, TemplateCost $b) => $b->count <=> $a->count);

        $totalCount = $manualCount + $automaticCount;

        return new self(
            totalCount: $totalCount,
            totalCost: $cost($totalCount),
            manualCount: $manualCount,
            manualCost: $cost($manualCount),
            automaticCount: $automaticCount,
            automaticCost: $cost($automaticCount),
            templates: $templates,
            currency: $currency,
        );
    }
}
