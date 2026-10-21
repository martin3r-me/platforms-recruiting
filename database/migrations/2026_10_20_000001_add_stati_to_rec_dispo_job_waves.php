<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs anbieten — Statusfilter GO/MA (Spec 2026-10-20). Je Welle die gewaehlten
 * ZAS-Stati, z. B. ["GO"]. NULL = Welle von vor der Aenderung = GO + MA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_waves', function (Blueprint $t) {
            $t->json('stati')->nullable()->after('gruppen');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_waves', fn (Blueprint $t) => $t->dropColumn('stati'));
    }
};
