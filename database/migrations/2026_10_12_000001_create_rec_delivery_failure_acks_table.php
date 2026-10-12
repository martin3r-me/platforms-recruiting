<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Zustellproblem erledigt" je WhatsApp-Thread (Reiter "Nicht zugestellt").
 *
 * Gemerkt wird die ID der gescheiterten Nachricht, die HR abgehakt hat —
 * scheitert spaeter eine NEUERE Nachricht, taucht der Thread wieder auf.
 * Bewusst getrennt von rec_conversation_handled: der Erledigt-Stempel dort
 * haengt am letzten Eingang, ein Zustellproblem am letzten Ausgang.
 * Kein Fremdschluessel ueber die Modulgrenze (Threads liegen in platform-crm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_delivery_failure_acks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('comms_whatsapp_thread_id')->unique();
            $table->unsignedBigInteger('comms_whatsapp_message_id');
            $table->timestamp('acknowledged_at');
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_delivery_failure_acks');
    }
};
