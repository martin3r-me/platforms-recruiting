<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vordefinierte Veranstaltungsorte (Vorstellungsgespräche, Schulungen, etc.).
     * UI bietet einen Dropdown an deren label, gespeichert wird die full_address
     * im RecInterview.location-Free-Text-Feld — damit Templates und KPIs
     * weiterhin gegen einen einzigen string-Wert arbeiten.
     */
    public function up(): void
    {
        Schema::create('rec_event_locations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('full_address', 500);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->timestamps();

            $table->index(['team_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_event_locations');
    }
};
