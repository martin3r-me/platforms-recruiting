<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Runde 4 (#4): Eskalationsmodus "datum" — frei gewaehlter Tag, an dem alle
    // kommenden Einsatztage der VA eskaliert werden. null = nicht gesetzt.
    // Import fasst die Spalte nie an.
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->date('escalation_date')->nullable()->after('escalation_time_3');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn('escalation_date');
        });
    }
};
