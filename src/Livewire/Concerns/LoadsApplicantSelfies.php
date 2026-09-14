<?php

namespace Platform\Recruiting\Livewire\Concerns;

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

/**
 * Die Bewerber-Selfies der Teilnehmerliste — geteilt zwischen der grossen
 * Nachbereitung und der schlanken Teamleiter-Ansicht (14.09.2026).
 *
 * Fuer Teamleiter ist das Foto kein Beiwerk: Wird eine Schulung in zwei
 * Gruppen geteilt, ordnet er Namen ueberhaupt erst ueber das Gesicht zu.
 *
 * VERTRAG: die nutzende Komponente bietet eine Computed `bookings`, deren
 * Elemente `applicant` tragen.
 */
trait LoadsApplicantSelfies
{
    /**
     * Selfies aller sichtbaren Bewerber in VIER Queries (Spec §3a) — pro Zeile
     * waeren es drei, bei 25 Teilnehmern also 75 zusaetzliche Abfragen.
     *
     * Extra-Feld ist 'selfie_upload'; der Wert ist eine File-ID oder ein
     * JSON-Array von File-IDs. Angezeigt wird die Thumbnail-Variante, sonst das
     * Original. Die signierten URLs laufen nach 60 Minuten ab — die Blade hat
     * deshalb einen onerror-Fallback auf den Platzhalter.
     *
     * @return array<int, array{url: string, is_image: bool}>
     */
    #[Computed]
    public function selfies(): array
    {
        $applicantIds = $this->bookings->pluck('applicant.id')->filter()->unique()->values();
        if ($applicantIds->isEmpty()) {
            return [];
        }

        // 1) Definitions-IDs des Feldes 'selfie_upload'
        $definitionIds = DB::table('core_extra_field_definitions')
            ->where('name', 'selfie_upload')
            ->pluck('id');
        if ($definitionIds->isEmpty()) {
            return [];
        }

        // 2) Feldwerte je Bewerber. Spalten- und Morph-Namen wie im bewaehrten
        //    Pfad ZasFieldResolver::preloadExtraFields() (:447-451):
        //    fieldable_type = 'rec_applicant', fieldable_id, definition_id, value.
        //    Der Unique-Index (definition_id, fieldable_type, fieldable_id)
        //    garantiert einen Wert pro Bewerber und Definition.
        $rawValues = DB::table('core_extra_field_values')
            ->whereIn('definition_id', $definitionIds)
            ->whereIn('fieldable_id', $applicantIds)
            ->where('fieldable_type', 'rec_applicant')
            ->pluck('value', 'fieldable_id');

        $fileIdByApplicant = [];
        foreach ($rawValues as $applicantId => $raw) {
            $fileId = $this->firstFileIdFromRawValue($raw);
            if ($fileId !== null) {
                $fileIdByApplicant[(int) $applicantId] = $fileId;
            }
        }
        if ($fileIdByApplicant === []) {
            return [];
        }

        // 3) ContextFiles
        $files = \Platform\Core\Models\ContextFile::whereIn('id', array_values($fileIdByApplicant))
            ->get()
            ->keyBy('id');

        // 4) Thumbnail-Varianten. Pro Datei koennen mehrere thumbnail_%-Varianten
        //    existieren; keyBy() wuerde die LETZTE der DB-Reihenfolge nehmen, also
        //    nicht deterministisch — die angezeigte Bildgroesse haette je nach
        //    Zeilenfolge gewechselt. Deshalb explizit: 'thumbnail_4_3' bevorzugt
        //    (F13), sonst die Variante mit der kleinsten id.
        $variants = \Platform\Core\Models\ContextFileVariant::whereIn('context_file_id', array_values($fileIdByApplicant))
            ->where('variant_type', 'like', 'thumbnail_%')
            ->orderBy('id')
            ->get()
            ->groupBy('context_file_id')
            ->map(fn ($group) => $group->firstWhere('variant_type', 'thumbnail_4_3') ?? $group->first());

        $result = [];
        foreach ($fileIdByApplicant as $applicantId => $fileId) {
            $file = $files->get($fileId);
            if (!$file || !$file->isImage()) {
                continue;
            }
            $variant = $variants->get($fileId);
            $result[$applicantId] = [
                'url'      => $variant?->url ?? $file->url,
                'is_image' => true,
            ];
        }

        return $result;
    }

    /**
     * Extra-Field-Werte koennen skalar (eine File-ID) oder JSON-Array
     * (Multi-File) sein — die erste ID gewinnt.
     */
    private function firstFileIdFromRawValue($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $candidate) {
                    if ((int) $candidate > 0) {
                        return (int) $candidate;
                    }
                }
            }
            return null;
        }

        return (int) $raw > 0 ? (int) $raw : null;
    }
}
