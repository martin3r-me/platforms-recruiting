<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Verknuepfte Alarm-Nachricht (16-Uhr-Alarm ans Diensthandy) fuer die Fehler-Anzeige.
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->unsignedBigInteger('alarm_message_id')->nullable()->after('ansprechpartner');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn('alarm_message_id');
        });
    }
};
