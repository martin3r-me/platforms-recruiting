<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Eine Zeile je (eingehende Nachricht, Veranstaltung) — auch ohne Treffer.
    // Speist Markierung, Zaehler und Nachvollziehbarkeit (Spec 2026-10-08).
    public function up(): void
    {
        Schema::create('rec_dispo_decline_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedInteger('filial_nr')->nullable();
            $table->unsignedBigInteger('rec_dispo_event_id');
            $table->unsignedBigInteger('rec_employee_id');            // kanonisch
            $table->unsignedBigInteger('comms_whatsapp_message_id');
            $table->string('outcome', 20);                            // decline|no_decline|pattern_skip|failed
            $table->boolean('used_llm')->default(false);
            $table->string('confidence', 10)->nullable();
            $table->json('assignment_ids')->nullable();
            $table->string('reason', 500)->nullable();
            $table->text('excerpt')->nullable();
            $table->unsignedBigInteger('alarm_message_id')->nullable();
            $table->string('review_status', 20)->nullable();          // open|accepted|dismissed
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['comms_whatsapp_message_id', 'rec_dispo_event_id'], 'uq_rec_dispo_decline_checks_msg_event');
            $table->index(['rec_dispo_event_id', 'review_status'], 'idx_rec_dispo_decline_checks_event_review');
            $table->index(['team_id', 'filial_nr', 'created_at'], 'idx_rec_dispo_decline_checks_counter');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_decline_checks');
    }
};
