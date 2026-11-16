<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlauf jeder Aenderung an einem Zeitdatensatz (Spec 2026-11-16 §2.1).
 * NUR ANFUEGEN: kein updated_at, kein Bearbeiten, kein Loeschen. Kein FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_zeit_verlauf')) {
            return;
        }

        Schema::create('rec_zeit_verlauf', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rec_zeit_eintrag_id')->index();
            $table->string('feld', 30);          // kommen_at|gehen_at|pause_minuten|bemerkung|gesperrt_at
            $table->text('alt')->nullable();
            $table->text('neu')->nullable();
            $table->string('art', 20);           // selbst|stellvertretend|korrektur|sperre
            $table->string('akteur_typ', 40);
            $table->unsignedBigInteger('akteur_id')->nullable();
            $table->string('akteur_name');       // Schnappschuss
            $table->string('grund')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_zeit_verlauf');
    }
};
