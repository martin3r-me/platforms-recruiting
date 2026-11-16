<?php

namespace Platform\Recruiting\Zeit;

use Carbon\CarbonImmutable;

/** Person + Bezug + Plan-Schicht, vom Aufrufer gebaut. Der Kern kennt nur Typ-Kuerzel und IDs. */
final class ZeitBezug
{
    public function __construct(
        public readonly string $personTyp,
        public readonly int $personId,
        public readonly string $bezugTyp,
        public readonly int $bezugId,
        public readonly string $tag, // Y-m-d
        public readonly ?CarbonImmutable $sollVon,
        public readonly ?CarbonImmutable $sollBis,
        public readonly ?int $teamId = null,
    ) {
    }
}
