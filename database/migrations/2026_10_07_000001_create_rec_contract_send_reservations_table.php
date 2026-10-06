<?php
// database/migrations/2026_10_07_000001_create_rec_contract_send_reservations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertragsversand vormerken (Spec 06.10.2026, §2).
 *
 * Wer beim Klick „Versenden" noch im Onboarding steckt, wird hier mit den
 * eingetragenen Vertragsdaten vorgemerkt; nach Vervollstaendigung versendet
 * der Job automatisch. Hoechstens EINE offene Zeile je Bewerber
 * (completed_at + cancelled_at leer) — erzwungen im Service, nicht per Index,
 * weil abgeschlossene Zeilen Historie sind.
 *
 * dateTime statt timestamp: unter explicit_defaults_for_timestamp=OFF
 * bekaeme die erste NOT-NULL-timestamp-Spalte ON UPDATE CURRENT_TIMESTAMP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_contract_send_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('rec_applicant_id')->index();
            $table->unsignedBigInteger('rec_interview_booking_id')->nullable();
            $table->date('vertragsbeginn')->nullable();
            $table->date('vertragsende')->nullable();
            $table->string('source', 32)->default('nachbereitung');
            $table->unsignedBigInteger('reserved_by_user_id')->nullable();
            // Name beim Vormerken festhalten: Verlauf und Spalte sagen „vorgemerkt von Clara",
            // auch wenn der Benutzer spaeter umbenannt oder geloescht wird.
            $table->string('reserved_by_name', 190)->nullable();
            $table->dateTime('reserved_at');
            $table->dateTime('last_reminder_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('last_attempt_result', 500)->nullable();
            // Belegt waehrend des automatischen Versands (ausserhalb jeder Transaktion):
            // ein zweiter Lauf binnen 15 min sendet nicht noch einmal.
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_contract_send_reservations');
    }
};
