<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkPostingState
{
    public function __construct(
        public readonly bool $isOpen,
        public readonly string $contentHash,
        public readonly int $generation,
        public readonly bool $publishRowExists,
        public readonly bool $publishSent,
        public readonly bool $closeRowExists,
        public readonly ?string $lastDeliverableContentHash,
        /**
         * Startdatum erreicht (oder keins gesetzt). Bremst NUR die erste
         * „veroeffentlichen"-Meldung — eine bereits gemeldete Anzeige wird durch
         * ein nachtraeglich in die Zukunft gesetztes Startdatum nicht entfernt.
         */
        public readonly bool $hasStarted = true,
        /**
         * Enddatum der Ausschreibung jetzt (`YYYY-MM-DD` oder `none`) und das
         * Enddatum, das mit der letzten Meldung an FLYNK ging. NULL = unbekannt
         * → kein Vergleich. FLYNK laesst Anzeigen selbst am Enddatum auslaufen,
         * ein geaendertes Datum muss dort also ankommen.
         */
        public readonly ?string $closesKey = null,
        public readonly ?string $lastDeliverableClosesKey = null,
        /**
         * Die Anzeige ist am Enddatum ausgelaufen, das FLYNK kennt, und FLYNK
         * nimmt sie selbst von der Seite: „entfernen" wird nur bei uns
         * verbucht, nicht gesendet.
         */
        public readonly bool $expiresSilently = false,
    ) {
    }
}
