<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pflicht-Vorlaufzeit aus dem Sende-Dialog des Disponenten
     * („X Minuten vorher da sein"); letzte Eingabe gewinnt.
     * Einsatz-Seite zeigt: Sei um (von − vorlauf_minuten) da.
     */
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('vorlauf_minuten')->nullable()->after('dresscode');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn('vorlauf_minuten');
        });
    }
};
