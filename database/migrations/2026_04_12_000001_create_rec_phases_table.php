<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_phases', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('rec_position_id')->constrained('rec_positions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('order')->default(1);
            $table->json('auto_pilot_settings')->nullable();
            $table->boolean('auto_advance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['rec_position_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_phases');
    }
};
