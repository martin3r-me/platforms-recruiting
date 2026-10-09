<?php

namespace Platform\Recruiting\Services;

/** Was HR im Fenster "Vertrag erstellen" eintraegt (Spec §2.1). Datumswerte Y-m-d. */
final class VertragsAngaben
{
    public function __construct(
        public readonly string $beginn,
        public readonly ?string $ende,
        public readonly float $zuschlag,
    ) {
    }
}
