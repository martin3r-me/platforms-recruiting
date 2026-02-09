<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_applicant_posting', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            $table->foreignId('rec_posting_id')->constrained('rec_postings')->cascadeOnDelete();
            $table->date('applied_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['rec_applicant_id', 'rec_posting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_applicant_posting');
    }
};
