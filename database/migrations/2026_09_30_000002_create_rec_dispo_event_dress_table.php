<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welches Waeschepaket in welcher Veranstaltung gilt — optional eingeschraenkt
 * auf eine Taetigkeit.
 *
 * `taetigkeit` ist NOT NULL mit Leerstring als Sentinel fuer „gilt VA-weit".
 * Mit NULL waere der Unique-Index wirkungslos (MySQL laesst beliebig viele
 * NULLs zu) und die VA-weite Vorgabe mehrfach anlegbar — die Aufloesung waere
 * dann nicht mehr eindeutig.
 *
 * Der Wert wird so gespeichert, wie er in den Einbuchungen steht: Freitext aus
 * ZAS, keine Normalisierung. Schreibvarianten sind verschiedene Zeilen; das
 * faengt spaeter die Gruppen-Stufe ab, nicht Raten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_event_dress', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('rec_dispo_event_id')
                ->constrained('rec_dispo_events')
                ->cascadeOnDelete();
            $table->string('taetigkeit')->default('');
            $table->unsignedBigInteger('rec_dispo_dress_package_id');
            $table->timestamps();

            $table->unique(['rec_dispo_event_id', 'taetigkeit'], 'uniq_rec_dispo_event_dress');
            $table->index('rec_dispo_dress_package_id', 'idx_rec_dispo_event_dress_package');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_event_dress');
    }
};
