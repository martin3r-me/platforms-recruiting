<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog der Waeschepakete (Kunde 29.09.2026): ein Paket ist ein Name plus
 * der Kleidungstext, den der Mitarbeiter liest.
 *
 * Bewusst eine eigene Tabelle statt eines Core-Lookups: ein Lookup-Wert ist
 * ein Label, hier gehoert der Text untrennbar dazu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_dress_packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id');
            // name: nur interne Auswahlhilfe, der Mitarbeiter sieht ihn nie.
            $table->string('name', 120);
            $table->text('items_text');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'is_active'], 'idx_rec_dispo_dress_team_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_dress_packages');
    }
};
