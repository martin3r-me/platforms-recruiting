<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zugang zum Agenda-Lohnportal (Markus 28.10.2026). true = Zugang laut
     * Agenda-Liste, false = keiner, null = unbekannt. Erstbestand per
     * einmaligem Abgleich (Query Builder); HR pflegt danach in der Akte.
     *
     * Nicht ZAS-relevant: steht nicht in RELEVANT_EMPLOYEE_FIELDS, setzt
     * also keinen Export-Marker.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->boolean('agenda_zugang')->nullable()->after('is_test');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('agenda_zugang');
        });
    }
};
