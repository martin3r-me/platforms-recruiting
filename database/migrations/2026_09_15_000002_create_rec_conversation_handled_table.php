<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Erledigt-Stempel fuer WhatsApp-Konversationen (Spec 15.09.2026).
 *
 * Warum eine eigene Tabelle statt einer Spalte an comms_whatsapp_threads:
 * die Thread-Tabelle liegt in platform-crm und wird von drei Modulen geteilt;
 * "HR hat das abgehakt" ist ein reiner Recruiting-Begriff. Kein Fremdschluessel
 * ueber die Modulgrenze — verwaiste Zeilen sind moeglich und harmlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_conversation_handled', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('comms_whatsapp_thread_id')->unique();
            $table->timestamp('handled_at');
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->string('handled_reason', 32)->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_conversation_handled');
    }
};
