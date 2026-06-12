<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Selbstheilend: Erster Deploy scheiterte am zu langen auto-generierten
        // Unique-Namen (MySQL-Limit 64 Zeichen), nachdem CREATE TABLE bereits
        // durch war. Leere Resttabelle entfernen, damit das Re-Run sauber läuft.
        Schema::dropIfExists('rec_posting_external_refs');

        Schema::create('rec_posting_external_refs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_posting_id')->constrained('rec_postings')->cascadeOnDelete();
            $table->foreignId('rec_source_platform_id')->constrained('rec_source_platforms')->cascadeOnDelete();
            $table->string('external_ref');
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rec_source_platform_id', 'external_ref'], 'rec_posting_ext_refs_source_ref_unique');
            $table->index('rec_posting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_posting_external_refs');
    }
};
