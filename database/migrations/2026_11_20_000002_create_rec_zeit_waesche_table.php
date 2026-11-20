<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wäsche je Einbuchung (Markus, Folie 16 + Antworten 20.11.2026): welche Teile ein
 * Mitarbeiter bekommen und wie viele er zurückgegeben hat. Preis und Reinigung werden
 * bei der Ausgabe festgehalten (Schnappschuss) — eine spätere Preisänderung rechnet
 * alte Einsätze nicht um.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_zeit_waesche')) {
            return;
        }
        Schema::create('rec_zeit_waesche', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('rec_dispo_assignment_id')->index();
            $table->unsignedBigInteger('rec_employee_id')->index();
            $table->string('artikel_key', 30);
            $table->string('bezeichnung', 80);
            $table->unsignedInteger('preis_cent');
            $table->unsignedInteger('reinigung_lohn_cent');
            $table->boolean('leihe_kunde')->default(false);
            $table->unsignedSmallInteger('anzahl');
            $table->unsignedSmallInteger('zurueck')->default(0);
            $table->string('ausgegeben_von', 120);
            $table->string('geaendert_von', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_zeit_waesche');
    }
};
