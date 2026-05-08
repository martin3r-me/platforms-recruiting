<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Markiert Bewerber als Test-Datensatz. Wird im ZAS-Bewerber-Export
     * als Filter genutzt — Test-Bewerber landen nicht im CSV-Output und
     * werden vom Backfill-Command ignoriert.
     *
     * Default false. Im normalen Recruiting-Flow taucht das Flag nicht
     * auf, fuer Test-Records setzt der Entwickler es manuell (per Tinker,
     * MCP-Tool oder kuenftiger UI).
     *
     * Indexed weil der Endpoint-Query bei jeder Auslieferung darauf
     * filtert.
     *
     * Siehe docs/meingedeck/zas-applicant-export.md
     */
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->boolean('is_test')
                ->default(false)
                ->after('is_active');
            $table->index('is_test', 'idx_rec_applicants_is_test');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex('idx_rec_applicants_is_test');
            $table->dropColumn('is_test');
        });
    }
};
