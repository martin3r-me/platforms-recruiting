<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runde 2 „Jobs anbieten“ (Spec 2026-10-14): Endzeit an der Schicht (nur
 * Anzeige, NICHT Teil des Unique-Index) und Ruecknahme-Stempel an der Zusage.
 * Bestand: bis = '' zeigt weiter nur den Beginn; withdrawn_at bleibt null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->string('bis', 8)->default('')->after('von');
        });
        Schema::table('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->timestamp('withdrawn_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_shifts', fn (Blueprint $t) => $t->dropColumn('bis'));
        Schema::table('rec_dispo_job_offer_shifts', fn (Blueprint $t) => $t->dropColumn('withdrawn_at'));
    }
};
