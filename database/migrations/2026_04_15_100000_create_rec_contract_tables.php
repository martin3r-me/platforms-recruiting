<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->longText('content')->nullable();
            $table->json('field_mappings')->nullable();
            $table->boolean('requires_signature')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_active']);
            $table->index('name');
        });

        Schema::create('rec_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            $table->foreignId('rec_contract_template_id')->constrained('rec_contract_templates')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->longText('personalized_content')->nullable();
            $table->text('signature_data')->nullable();
            $table->json('pre_signing_data')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['rec_applicant_id', 'status']);
            $table->index(['team_id', 'status']);
            $table->index('rec_contract_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_contracts');
        Schema::dropIfExists('rec_contract_templates');
    }
};
