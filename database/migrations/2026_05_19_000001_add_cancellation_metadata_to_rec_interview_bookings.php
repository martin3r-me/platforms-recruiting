<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuegt Cancellation-Metadaten auf rec_interview_bookings hinzu, damit
 * unterschieden werden kann ob ein Booking vom Bewerber selbst, durch HR
 * oder vom System gecancelled wurde. Wird im HR-Schreibtisch + Bewerber-
 * Detail angezeigt — im Schulungs-Index bewusst nicht (irrelevant fuer
 * Schulungsleiter).
 *
 * cancelled_by Werte (string nullable, kein DB-Enum-Constraint):
 *  - 'applicant'  Bewerber hat aktiv abgesagt (Form-Button oder Reminder-Nein)
 *  - 'hr'         HR hat im UI manuell auf cancelled gesetzt
 *  - 'system'     Automatischer System-Cancel (zukuenftige Erweiterung)
 *  - NULL         Booking ist nicht cancelled
 *
 * cancelled_at separat vom updated_at, weil semantisch eigener Datenpunkt
 * (Buchung kann ge-updated werden ohne dass sie gecancelled wurde).
 *
 * Keine Backfill-Migration der bestehenden 'cancelled'-Bookings — die
 * bleiben mit NULL in beiden Spalten (keine retroaktive Zuordnung moeglich).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->string('cancelled_by', 32)->nullable()->after('notes');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            $table->index(['cancelled_by', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->dropIndex(['cancelled_by', 'cancelled_at']);
            $table->dropColumn(['cancelled_by', 'cancelled_at']);
        });
    }
};
