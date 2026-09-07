<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // confirmed_at: wann die Buchung (erstmals) bestaetigt wurde. Der Status
    // traegt diese Information nicht dauerhaft — nach der Schulung wird er mit
    // attended/no_show ueberschrieben. Gestempelt vom Status-Mutator im Model,
    // Bestand via recruiting:backfill-booking-confirmations (Log + aktueller
    // Status; manuell Bestaetigte, die schon ueberschrieben wurden, sind nicht
    // rekonstruierbar — der Spalten-Tooltip sagt das).
    public function up(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
