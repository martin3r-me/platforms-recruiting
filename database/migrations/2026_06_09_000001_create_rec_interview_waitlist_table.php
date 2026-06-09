<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_interview_waitlist', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            // Snapshot der bestätigten beschaftigungsort-Werte beim Eintrag.
            $table->json('wunschorte');
            $table->dateTime('enrolled_at')->useCurrent();
            // notified_at: "nur 1x"-Guard. NULL = noch nie benachrichtigt.
            $table->dateTime('notified_at')->nullable();
            // fulfilled_at: Bewerber hat gebucht. cancelled_at: Reject/Park/Abmeldung.
            $table->dateTime('fulfilled_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Eine "offene" Zeile pro Bewerber wird im Model/Code geführt
            // (fulfilled_at & cancelled_at = NULL). Index unterstützt das Finden.
            $table->index(['team_id', 'fulfilled_at', 'cancelled_at']);
            $table->index('rec_applicant_id');
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_interview_waitlist');
    }
};
