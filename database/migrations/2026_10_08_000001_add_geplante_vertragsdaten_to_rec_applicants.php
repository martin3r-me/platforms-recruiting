<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsbeginn/-ende aus der Schulungsnachbereitung sofort speichern
 * (08.10.2026, Feedback MGL-Runde 07.10.: zwei Laptops, die Daten des einen
 * kamen beim anderen nie an und mussten neu gesetzt werden). Bisher lebten
 * die Eingaben nur im Livewire-Zustand des Browserfensters bis zum Versand.
 *
 * Nur die Planung VOR dem Versand. Nach dem Versand ist der Vertrag die
 * Wahrheit (Extra-Felder am AV-Vertrag) — diese Spalten werden dann nicht
 * mehr gelesen. Kein ZAS-Bezug: geschrieben wird per Query-Builder, ohne
 * Export-Marker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_applicants', 'vertragsbeginn_geplant')) {
                $table->date('vertragsbeginn_geplant')->nullable();
            }
            if (!Schema::hasColumn('rec_applicants', 'vertragsende_geplant')) {
                $table->date('vertragsende_geplant')->nullable();
            }
            // Marker „wurde in der Nachbereitung gesetzt" — unterscheidet
            // „geleert" (beide NULL, Marker gesetzt) von „nie geplant".
            if (!Schema::hasColumn('rec_applicants', 'vertragsdaten_geplant_at')) {
                $table->dateTime('vertragsdaten_geplant_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn(['vertragsbeginn_geplant', 'vertragsende_geplant', 'vertragsdaten_geplant_at']);
        });
    }
};
