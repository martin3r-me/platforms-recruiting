<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filialnummer aus {Dispo2} Feld 9 (100/200/400) — der kanonische
     * Schluessel je Filiale (== rec_positions.cost_center). Anzeige/Code
     * werden zentral ueber Platform\Recruiting\Support\Filialen aufgeloest;
     * die Text-Spalte `filiale` bleibt als Fallback/Audit.
     */
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->unsignedInteger('filial_nr')->nullable()->after('filiale');
            $table->index('filial_nr');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropIndex(['filial_nr']);
            $table->dropColumn('filial_nr');
        });
    }
};
