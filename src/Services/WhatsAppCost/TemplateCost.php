<?php

namespace Platform\Recruiting\Services\WhatsAppCost;

final class TemplateCost
{
    public function __construct(
        public readonly string $templateName,
        public readonly int $count,
        public readonly float $cost,
    ) {}
}
