<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absage-Flow (Kunde 04.09.): MA sagt ab / meldet sich krank -> Dispo erfasst
 * das im VA-Chat. Absage stoppt Eskalation + weitere Sendungen, optional
 * Portalsperre und Uebergabe an den HR-Desk (Clara). Dazu der reine
 * Doku-Haken "in ZAS rausgenommen" (wir schreiben nie nach ZAS).
 * Alles nullable — Historie bleibt dauerhaft an der Einbuchung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->timestamp('declined_at')->nullable();
            $table->string('declined_reason', 20)->nullable(); // krank | abgesagt
            $table->text('declined_note')->nullable();
            $table->unsignedBigInteger('declined_by_user_id')->nullable();
            $table->boolean('declined_portal_locked')->default(false);
            $table->timestamp('declined_hr_at')->nullable();
            $table->timestamp('declined_hr_done_at')->nullable();
            $table->unsignedBigInteger('declined_hr_done_by_user_id')->nullable();
            $table->timestamp('zas_removed_at')->nullable();
            $table->unsignedBigInteger('zas_removed_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'declined_at', 'declined_reason', 'declined_note', 'declined_by_user_id',
                'declined_portal_locked', 'declined_hr_at', 'declined_hr_done_at',
                'declined_hr_done_by_user_id', 'zas_removed_at', 'zas_removed_by_user_id',
            ]);
        });
    }
};
