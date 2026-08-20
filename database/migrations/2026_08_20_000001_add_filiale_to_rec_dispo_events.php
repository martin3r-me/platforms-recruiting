<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Strukturierte Filiale aus {Dispo2} Feld 8 (CGN/DUS/MGL…),
     * fuer Liste-Spalte + Filter.
     */
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->string('filiale')->nullable()->after('einsatzfirma');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn('filiale');
        });
    }
};
