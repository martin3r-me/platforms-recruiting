<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anlass einer Anfrage „Jobs anbieten“ (vier_wochen / naechste_woche / dringend,
 * Kunde 13.10.2026). Bestehende Anfragen bleiben leer (Anzeige „—“).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_requests', function (Blueprint $table) {
            $table->string('anlass', 32)->nullable()->after('modus');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_requests', function (Blueprint $table) {
            $table->dropColumn('anlass');
        });
    }
};
