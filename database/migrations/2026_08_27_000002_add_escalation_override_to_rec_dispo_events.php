<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Runde 3 (#5): Eskalation pro VA ueberschreibbar — Modus (Vortag/Einsatztag)
    // + drei Stufen-Uhrzeiten. null = Team-Default. Import fasst die Spalten nie an.
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->string('escalation_day', 12)->nullable()->after('alarm_message_id');
            $table->string('escalation_time_1', 5)->nullable()->after('escalation_day');
            $table->string('escalation_time_2', 5)->nullable()->after('escalation_time_1');
            $table->string('escalation_time_3', 5)->nullable()->after('escalation_time_2');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn(['escalation_day', 'escalation_time_1', 'escalation_time_2', 'escalation_time_3']);
        });
    }
};
