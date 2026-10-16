<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themen-Auswahl je WhatsApp-Thread (S-Themen).
 * Keine Fremdschluessel ueber die Modulgrenze (Muster rec_conversation_handled).
 */
return new class extends Migration
{
    public function up(): void
    {
    Schema::create('rec_conversation_topics', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->index();
        $table->unsignedBigInteger('comms_whatsapp_thread_id')->index();
        $table->string('topic', 16)->nullable();          // dispo | lohn | hr
        $table->string('state', 24);                       // asked | chosen | akte_hint_sent | lohn_info_sent | routed
        $table->unsignedBigInteger('dispo_channel_id')->nullable();
        $table->unsignedBigInteger('origin_message_id')->nullable();   // der getippte Eingang, der die Frage ausgeloest hat
        $table->unsignedBigInteger('ask_message_id')->nullable();      // unsere Frage (comms_whatsapp_messages.id)
        $table->unsignedBigInteger('last_click_message_id')->nullable(); // Idempotenz des Webhooks bei Klick-Wiederholung
        $table->timestamp('asked_at');
        $table->timestamp('chosen_at')->nullable();
        $table->timestamps();
        $table->index(['comms_whatsapp_thread_id', 'asked_at']);
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_conversation_topics');
    }
};
