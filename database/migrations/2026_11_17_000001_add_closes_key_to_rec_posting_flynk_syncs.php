<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enddatum, das FLYNK mit einer Meldung bekommen hat (17.11.2026).
 *
 * FLYNK laesst Anzeigen kuenftig selbst am Enddatum auslaufen. Damit muss ein
 * geaendertes Enddatum dort ankommen — bisher loeste nur geaenderter Inhalt
 * eine „aktualisieren"-Meldung aus. Die Spalte haelt je Meldung fest, welches
 * Enddatum mitging: `YYYY-MM-DD`, `none` (kein Enddatum) oder NULL (unbekannt).
 *
 * Bewusst eine eigene Spalte statt das Datum in den Inhalts-Hash zu nehmen:
 * sonst haetten alle laufenden Ausschreibungen beim ersten Lauf einen anderen
 * Hash und gingen als Welle „aktualisieren" an die Agentur.
 *
 * Bestand: die vorhandenen Zeilen bekommen das HEUTIGE Enddatum der
 * Ausschreibung als Ausgangswert. Das ist eine Annahme (was damals mitging,
 * ist nicht gespeichert), aber die einzige, mit der eine spaetere Aenderung
 * ueberhaupt erkannt wird — und sie loest selbst keine Meldung aus.
 *
 * `closes_assumed` markiert genau diese angenommenen Werte. Fuer das stille
 * Auslaufen (kein „entfernen", FLYNK nimmt selbst raus) zaehlen sie NICHT:
 * wurde das Enddatum vor dieser Migration geaendert, kennt FLYNK noch das
 * alte — dort muss weiter ein echtes „entfernen" raus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_posting_flynk_syncs', function (Blueprint $table) {
            $table->string('closes_key', 10)->nullable()->after('content_hash');
            $table->boolean('closes_assumed')->default(false)->after('closes_key');
        });

        $rows = DB::table('rec_posting_flynk_syncs as s')
            ->join('rec_postings as p', 'p.id', '=', 's.rec_posting_id')
            ->whereIn('s.event_type', ['publish', 'update'])
            ->select('s.id', 'p.closes_at')
            ->get();

        foreach ($rows as $row) {
            $date = $row->closes_at !== null ? substr((string) $row->closes_at, 0, 10) : '';
            DB::table('rec_posting_flynk_syncs')->where('id', $row->id)->update([
                'closes_key' => $date !== '' ? $date : 'none',
                'closes_assumed' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('rec_posting_flynk_syncs', function (Blueprint $table) {
            $table->dropColumn(['closes_key', 'closes_assumed']);
        });
    }
};
