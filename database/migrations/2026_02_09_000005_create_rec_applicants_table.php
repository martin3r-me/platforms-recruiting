<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_applicants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('rec_applicant_status_id')->nullable()->constrained('rec_applicant_statuses')->nullOnDelete();
            $table->tinyInteger('progress')->default(0);
            $table->text('notes')->nullable();
            $table->date('applied_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_pilot')->default(false);
            $table->timestamp('auto_pilot_completed_at')->nullable();
            $table->foreignId('auto_pilot_state_id')->nullable()->constrained('rec_auto_pilot_states')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
            $table->index(['auto_pilot', 'auto_pilot_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_applicants');
    }
};
