<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_applicant_legal_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rec_applicant_id')->unique()->constrained('rec_applicants')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->boolean('is_eu_citizen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_applicant_legal_statuses');
    }
};
