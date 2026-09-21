<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // KLAERUNG IM DISPO-ABGLEICH (Kundenwunsch 21.09.2026): „ohne Einsatz" ist
    // die Arbeitsliste der Schulungs-Detailansicht. Wer dort ohne Grund steht
    // (faengt spaeter an, mit der Dispo geklaert), wird abgehakt und wandert in
    // einen vierten Topf — kein Einsatz, aber auch kein Handlungsbedarf.
    //
    // An der BUCHUNG, nicht am Bewerber: die Arbeitsliste ist die einzelne
    // Schulung, und wer in einem halben Jahr eine zweite Runde mitmacht, soll
    // das alte Haekchen nicht mitbringen.
    //
    // einsatz_wiedervorlage_am ist ein DATUM, kein Zeitstempel: „wieder
    // anzeigen ab" ist eine Tagesaussage. Leer heisst dauerhaft — die Regel
    // steht in Support\EinsatzClarification.
    public function up(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->timestamp('einsatz_geklaert_at')->nullable()->after('confirmed_at');
            $table->string('einsatz_geklaert_note', 500)->nullable()->after('einsatz_geklaert_at');
            $table->date('einsatz_wiedervorlage_am')->nullable()->after('einsatz_geklaert_note');
            $table->unsignedBigInteger('einsatz_geklaert_by')->nullable()->after('einsatz_wiedervorlage_am');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'einsatz_geklaert_at',
                'einsatz_geklaert_note',
                'einsatz_wiedervorlage_am',
                'einsatz_geklaert_by',
            ]);
        });
    }
};
