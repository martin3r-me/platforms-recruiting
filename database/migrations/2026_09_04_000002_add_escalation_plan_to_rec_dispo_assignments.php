<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eskalation pro Sendung (Kunde 04.09., Nachzuegler): der Versand kann den
 * Empfaengern einen eigenen Eskalationsplan stempeln (drei konkrete
 * Zeitpunkte). Der Eskalations-Lauf nimmt: Zeilen-Plan, falls vorhanden —
 * sonst VA-Plan. Nullable = folgt dem VA-Plan (Bestand unveraendert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->timestamp('escalation_due_1_at')->nullable();
            $table->timestamp('escalation_due_2_at')->nullable();
            $table->timestamp('escalation_due_3_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn(['escalation_due_1_at', 'escalation_due_2_at', 'escalation_due_3_at']);
        });
    }
};
