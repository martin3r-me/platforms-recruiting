<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\DokumentKategorie;

/**
 * Infos mit "automatisch an neue Mitarbeiter" (Spec 2026-10-16 §4): beim
 * ersten Oeffnen des Portals bekommt die Person jede solche Info, deren
 * published_at VOR dem created_at ihrer Anstellung liegt — der Bestand ist
 * damit von selbst draussen (seine Anstellungen sind aelter als jede Info).
 * Eine Zeile je Person (Scope ueber person_key wie DokumentLeser), keine
 * WhatsApp (der Mensch ist gerade im Portal), Query Builder, idempotent.
 */
class DokumentAutoZustellung
{
    public function __construct(private readonly PersonScopeResolver $scope = new PersonScopeResolver())
    {
    }

    public function fuerErstesOeffnen(RecEmployee $employee): int
    {
        $createdAt = $employee->created_at;
        if ($createdAt === null) {
            return 0;
        }

        // Erst die billige Frage: gibt es im Team ueberhaupt passende Infos?
        // Laeuft bei jedem Seitenaufruf mit gueltiger Sitzung — ohne Treffer
        // bleibt es bei dieser einen Abfrage.
        $kandidaten = DB::table('rec_documents')
            ->where('team_id', (int) $employee->team_id)
            ->where('category', DokumentKategorie::INFO)
            ->where('auto_neue_mitarbeiter', true)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            // Strikt kleiner: gleicher Zeitpunkt zaehlt als Bestand.
            ->where('published_at', '<', $createdAt->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->pluck('id');
        if ($kandidaten->isEmpty()) {
            return 0;
        }

        $ids = $this->scope->forEmployee($employee)['ids'];
        $vorhanden = DB::table('rec_document_recipients')
            ->whereIn('rec_document_id', $kandidaten)
            ->whereIn('rec_employee_id', $ids)
            ->pluck('rec_document_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $neu = 0;
        foreach ($kandidaten as $dokumentId) {
            if (in_array((int) $dokumentId, $vorhanden, true)) {
                continue;
            }
            // uuid setzt das Modell selbst (creating-Hook).
            RecDocumentRecipient::create([
                'team_id'         => (int) $employee->team_id,
                'rec_document_id' => (int) $dokumentId,
                'rec_employee_id' => (int) $employee->id,
                'person_key'      => $employee->person_key,
            ]);
            $neu++;
        }
        if ($neu > 0) {
            Log::info('recruiting.dokumente.auto_zugestellt', ['employee_id' => (int) $employee->id, 'anzahl' => $neu]);
        }

        return $neu;
    }
}
