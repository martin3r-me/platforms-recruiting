<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs anbieten — Zusagen als Buchungen an ZAS uebergeben (Spec 2026-10-30 §3):
 * Marker je Zusage, gesetzt beim Abruf durch ZAS. Nullable: Bestand unveraendert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->timestamp('zas_exported_at')->nullable()->after('zas_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_offer_shifts', fn (Blueprint $t) => $t->dropColumn('zas_exported_at'));
    }
};
