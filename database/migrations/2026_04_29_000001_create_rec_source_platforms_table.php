<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_source_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('url', 255)->nullable();
            $table->string('match_pattern', 255);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(100);
            $table->timestamps();

            $table->index(['team_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_source_platforms');
    }
};
