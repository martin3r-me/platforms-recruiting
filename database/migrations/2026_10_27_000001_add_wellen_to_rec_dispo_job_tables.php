<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs anbieten — Wellenplan, Gesellschaft, gedeckt, Kann nicht (Spec 2026-10-27).
 * Alle Spalten nullable/Default: Bestand verhaelt sich wie bisher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_requests', function (Blueprint $t) {
            $t->unsignedInteger('welle_max')->nullable()->after('anlass');
            $t->unsignedInteger('welle_abstand_minuten')->nullable()->after('welle_max');
        });
        Schema::table('rec_dispo_job_waves', function (Blueprint $t) {
            $t->json('firmen')->nullable()->after('stati');
            $t->boolean('auto')->default(false)->after('firmen');
        });
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->timestamp('gedeckt_manuell_at')->nullable()->after('closed_at');
        });
        Schema::table('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->timestamp('declined_at')->nullable()->after('zas_assignment_id');
            $t->string('declined_reason', 32)->nullable()->after('declined_at');
            $t->string('declined_note', 200)->nullable()->after('declined_reason');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_offer_shifts', fn (Blueprint $t) => $t->dropColumn(['declined_at', 'declined_reason', 'declined_note']));
        Schema::table('rec_dispo_job_shifts', fn (Blueprint $t) => $t->dropColumn('gedeckt_manuell_at'));
        Schema::table('rec_dispo_job_waves', fn (Blueprint $t) => $t->dropColumn(['firmen', 'auto']));
        Schema::table('rec_dispo_job_requests', fn (Blueprint $t) => $t->dropColumn(['welle_max', 'welle_abstand_minuten']));
    }
};
