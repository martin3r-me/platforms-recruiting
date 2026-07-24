<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            // Standby-Marker: gesetzt = Buchung existiert weiter (status bleibt
            // 'booked'), belegt aber keinen Platz mehr. Nur auf status='booked'
            // gueltig — Invariante via saving-Guard im Model.
            $table->timestamp('seat_released_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->dropColumn('seat_released_at');
        });
    }
};
