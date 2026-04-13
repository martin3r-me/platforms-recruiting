<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_interview_types', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_active']);
            $table->index('name');
        });

        Schema::create('rec_interviews', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('interview_type_id')->nullable()->constrained('rec_interview_types')->cascadeOnDelete();
            $table->foreignId('rec_position_id')->nullable()->constrained('rec_positions')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('min_participants')->nullable();
            $table->unsignedInteger('max_participants')->nullable();
            $table->string('status')->default('planned');
            $table->unsignedBigInteger('reminder_wa_template_id')->nullable();
            $table->unsignedInteger('reminder_hours_before')->nullable();
            $table->json('reminder_wa_template_variables')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_active']);
            $table->index(['interview_type_id', 'starts_at']);
            $table->index('rec_position_id');
            $table->index('starts_at');
            $table->index('status');
        });

        Schema::create('rec_interview_user', function (Blueprint $table) {
            $table->foreignId('rec_interview_id')->constrained('rec_interviews')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['rec_interview_id', 'user_id']);
        });

        Schema::create('rec_interview_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_interview_id')->constrained('rec_interviews')->cascadeOnDelete();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            $table->string('status')->default('registered');
            $table->text('notes')->nullable();
            $table->dateTime('booked_at')->nullable()->useCurrent();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rec_interview_id', 'rec_applicant_id']);
            $table->index(['rec_interview_id', 'status']);
            $table->index('rec_applicant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_interview_bookings');
        Schema::dropIfExists('rec_interview_user');
        Schema::dropIfExists('rec_interviews');
        Schema::dropIfExists('rec_interview_types');
    }
};
