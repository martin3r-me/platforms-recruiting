<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verspaetet-Marker (Kunde 07.09.): Check-in-Hilfe in der VA-Tabelle —
 * pro Einbuchung an-/abwaehlbar, reine Dokumentation (wer, wann).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->timestamp('late_marked_at')->nullable();
            $table->unsignedBigInteger('late_marked_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn(['late_marked_at', 'late_marked_by_user_id']);
        });
    }
};
