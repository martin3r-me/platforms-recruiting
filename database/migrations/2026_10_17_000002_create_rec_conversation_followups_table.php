<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-Nachverfolgungs-Markierung je WhatsApp-Thread (S-Themen).
 * Keine Fremdschluessel ueber die Modulgrenze (Muster rec_conversation_handled).
 */
return new class extends Migration
{
    public function up(): void
    {
    Schema::create('rec_conversation_followups', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('team_id')->index();
        $table->unsignedBigInteger('comms_whatsapp_thread_id')->index();
        $table->unsignedBigInteger('marked_by_user_id')->nullable();
        $table->timestamp('marked_at');
        $table->timestamp('cleared_at')->nullable();
        $table->unsignedBigInteger('cleared_by_user_id')->nullable();
        $table->timestamps();
        $table->index(['comms_whatsapp_thread_id', 'cleared_at']);
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_conversation_followups');
    }
};
