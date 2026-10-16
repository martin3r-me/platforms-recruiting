<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentVerlauf;

/**
 * Infos mit "automatisch an neue Mitarbeiter" (Spec 2026-10-16 §4): beim
 * ersten Oeffnen des Portals bekommt die Person jede solche Info, wenn sie
 * NEU ist. "Neu" heisst (Ruling Schlussreview I-4):
 *  1. die geoeffnete Anstellung kam ueber unser Recruiting
 *     (rec_applicant_id IS NOT NULL) — von ZAS gelieferte Anstellungen ohne
 *     Bewerbung (Stichtags-Vollimport, Nachlieferungen) loesen die Automatik
 *     nie aus, auch wenn ihre Zeile erst nach der Info entsteht;
 *  2. die FRUEHESTE Anstellung der Person (alle IDs aus dem Personen-Scope,
 *     RG+MA) wurde NACH published_at angelegt (strikt). Eine Bestandsperson,
 *     die spaeter eine zweite Anstellung bekommt, bleibt Bestand.
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
        if ($createdAt === null || $employee->rec_applicant_id === null) {
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
            // Strikt kleiner: gleicher Zeitpunkt zaehlt als Bestand. Die eigene
            // Anstellung ist eine Obergrenze fuer die frueheste der Person —
            // der genaue Vergleich folgt unten, nur wenn es Kandidaten gibt.
            ->where('published_at', '<', $createdAt->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->pluck('published_at', 'id');
        if ($kandidaten->isEmpty()) {
            return 0;
        }

        $ids = $this->scope->forEmployee($employee)['ids'];
        $frueheste = DB::table('rec_employees')->whereIn('id', $ids)->min('created_at');
        if ($frueheste === null) {
            return 0;
        }
        $frueheste = \Carbon\Carbon::parse($frueheste);
        $kandidaten = $kandidaten
            ->filter(fn ($publishedAt) => \Carbon\Carbon::parse($publishedAt)->lt($frueheste))
            ->keys();
        if ($kandidaten->isEmpty()) {
            return 0;
        }
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
            $zeile = RecDocumentRecipient::create([
                'team_id'         => (int) $employee->team_id,
                'rec_document_id' => (int) $dokumentId,
                'rec_employee_id' => (int) $employee->id,
                'person_key'      => $employee->person_key,
                // Nie anschreiben: der Mensch ist gerade im Portal (Spec §7).
                'notify_error'    => DokumentHinweisSender::STATUS_NICHT_NOETIG,
            ]);
            // Verlauf: Automatik, kein HR-User.
            DokumentVerlauf::schreibe((int) $employee->team_id, (int) $dokumentId, (int) $zeile->id, DokumentVerlauf::AUTO_ZUGESTELLT, null, [
                'employee_id' => (int) $employee->id,
            ]);
            $neu++;
        }
        if ($neu > 0) {
            Log::info('recruiting.dokumente.auto_zugestellt', ['employee_id' => (int) $employee->id, 'anzahl' => $neu]);
        }

        return $neu;
    }
}
