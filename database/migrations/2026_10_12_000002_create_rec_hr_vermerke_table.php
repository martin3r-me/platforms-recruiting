<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlauf jeder HR-Handlung an Konto, Sperre oder Erinnerung (Spec HR-Bereich
 * 2026-10-12 §7.1). Haengt an Person ODER Anstellung, auch ohne Bewerbung —
 * rec_auto_pilot_logs kann das nicht (FK auf rec_applicants).
 *
 * NUR ANFUEGEN: kein updated_at, kein Bearbeiten, kein Loeschen. Kein FK
 * (Muster rec_conversation_handled) — verwaiste Zeilen sind harmlos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_hr_vermerke')) {
            return;
        }

        Schema::create('rec_hr_vermerke', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('rec_person_id')->nullable()->index();
            $table->unsignedBigInteger('rec_employee_id')->nullable()->index();
            $table->string('art', 40);
            $table->string('bezug', 60)->nullable();
            $table->text('notiz')->nullable();
            $table->string('ergebnis', 40)->nullable();
            $table->timestamp('ruhe_bis')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['team_id', 'rec_person_id', 'created_at'], 'rec_hr_vermerke_team_person_idx');
            $table->index(['team_id', 'rec_employee_id', 'created_at'], 'rec_hr_vermerke_team_akte_idx');
            $table->index(['rec_person_id', 'art', 'ruhe_bis'], 'rec_hr_vermerke_ruhe_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_hr_vermerke');
    }
};
