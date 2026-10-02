<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weiterleitungen Dispo → HR (Spec 02.10.2026).
 *
 * Eigene Tabelle, weil der HR-Thread beim Weiterleiten oft noch gar nicht
 * existiert — er entsteht erst mit der Erstnachricht. Die Texte liegen als
 * KOPIE in `messages`, damit die HR-Seite nie in die Dispo-Kanaele greift.
 * Kein Fremdschluessel ueber die Modulgrenze (Threads gehoeren platform-crm).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_conversation_forwards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            // Woher/wohin (ForwardTargets). Heute nur dispo -> hr; ein neues Ziel
            // ist ein neuer Wert, keine neue Tabelle.
            $table->string('source', 32)->default('dispo');
            $table->string('target', 32)->default('hr');
            $table->unsignedBigInteger('source_thread_id')->index();
            $table->unsignedBigInteger('rec_employee_id')->nullable();
            $table->string('phone', 32);
            $table->string('display_name', 190);
            $table->json('messages');
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('forwarded_by_user_id')->nullable();
            $table->string('forwarded_by_name', 190)->nullable();
            $table->timestamp('forwarded_at');
            $table->unsignedBigInteger('target_thread_id')->nullable()->index();
            $table->timestamp('first_contact_at')->nullable();
            $table->unsignedBigInteger('first_contact_by_user_id')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedBigInteger('done_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'target', 'done_at'], 'idx_rec_conv_fwd_team_target_done');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_conversation_forwards');
    }
};
