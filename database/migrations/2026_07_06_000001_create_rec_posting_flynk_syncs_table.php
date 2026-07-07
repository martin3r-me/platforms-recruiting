<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_posting_flynk_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_posting_id')->constrained('rec_postings')->cascadeOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->string('event_type', 16);
            $table->unsignedInteger('seq')->default(0);
            $table->string('content_hash', 64)->default('');
            $table->string('flynk_task_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->boolean('permanent_failure')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['rec_posting_id', 'generation', 'event_type', 'seq'],
                'rec_posting_flynk_unique'
            );
            $table->index(['rec_posting_id', 'status'], 'rec_posting_flynk_posting_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_posting_flynk_syncs');
    }
};
