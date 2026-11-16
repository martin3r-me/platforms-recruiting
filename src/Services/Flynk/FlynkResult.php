<?php

namespace Platform\Recruiting\Services\Flynk;

final class FlynkResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $httpStatus,
        public readonly ?string $taskId,
        public readonly bool $permanent,
        public readonly bool $rateLimited,
        public readonly bool $unauthorized,
        public readonly ?string $error,
        /**
         * Nur bei ok=true und mitgeschickter Datei: FLYNK hat die Aufgabe
         * angelegt (201), die Datei aber NICHT uebernommen (`skipped_files`
         * bzw. leere `attachments`). Der Text ist dann angekommen, die Datei nicht.
         */
        public readonly ?string $fileProblem = null,
    ) {
    }
}
