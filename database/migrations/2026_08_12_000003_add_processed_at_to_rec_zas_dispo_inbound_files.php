<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verarbeitungs-Pipeline (Step 2 der Dispo-Reihe): parse_status nutzt
     * jetzt zusaetzlich processed | failed; processed_at = Zeitpunkt des
     * letzten (erfolgreichen oder fehlgeschlagenen) Import-Laufs.
     */
    public function up(): void
    {
        Schema::table('rec_zas_dispo_inbound_files', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('parse_status');
        });
    }

    public function down(): void
    {
        Schema::table('rec_zas_dispo_inbound_files', function (Blueprint $table) {
            $table->dropColumn('processed_at');
        });
    }
};
