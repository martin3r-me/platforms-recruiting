<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_phase_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            // Historie eines geloeschten Bewerbers ist wertlos; Team-Loeschung
            // raeumt so konsistent ab (Spec §5, FK-Loeschverhalten).
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            // nullOnDelete PFLICHT: cascadeOnDelete wuerde die Historie genau in dem
            // Moment loeschen, fuer den der Name-Snapshot gebaut wurde.
            $table->foreignId('rec_position_id')->nullable()->constrained('rec_positions')->nullOnDelete();
            $table->foreignId('from_phase_id')->nullable()->constrained('rec_phases')->nullOnDelete();
            $table->foreignId('to_phase_id')->nullable()->constrained('rec_phases')->nullOnDelete();
            $table->string('from_phase_name')->nullable();
            $table->string('to_phase_name')->nullable();
            $table->string('trigger', 20)->default('unknown');
            $table->string('source', 10)->default('live'); // live|backfill
            // Idempotenz-Schluessel des Backfills (Spec §5)
            $table->foreignId('source_log_id')->nullable()->unique()->constrained('rec_auto_pilot_logs')->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['team_id', 'occurred_at']);
            $table->index(['rec_applicant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_phase_transitions');
    }
};
