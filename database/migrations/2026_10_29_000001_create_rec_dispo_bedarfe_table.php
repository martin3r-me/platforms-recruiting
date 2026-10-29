<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bedarfsliste aus dem ZAS-Webexport (Block {Dispo2}, Spec 2026-10-29):
 * ein Dispoposten = Bedarf fuer eine Taetigkeit an einem Tag einer VA mit
 * `anzahl` Plaetzen. Mehrere Zeitvarianten desselben Postens kommen bei ZAS
 * als mehrere Zeilen mit derselben Nummer — hier EIN Datensatz, die Varianten
 * in `zeiten`, von/bis = frueheste Variante.
 *
 * Bisher wurde der Block nur fuer die Kopfdaten der VA gelesen und sonst
 * verworfen; Schritt 2 von „Jobs anbieten“ sah dadurch keine Schicht, in der
 * noch niemand steht (Markus/Sheran 29.10., VA RG20407).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_bedarfe', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rec_dispo_event_id')->constrained('rec_dispo_events')->cascadeOnDelete();
            $t->string('dispoposten_id', 32)->unique(); // ZAS-Nummer, z. B. 121000 — Spalte „Dispo“ im Buchungs-Import von ZAS
            $t->date('datum');
            $t->string('von', 8)->default('');
            $t->string('bis', 8)->default('');
            $t->json('zeiten')->nullable();            // alle Zeitvarianten [{von,bis}], sortiert
            $t->string('taetigkeit', 190)->default('');
            $t->string('taetigkeit_id', 32)->nullable(); // z. B. RG27
            $t->unsignedInteger('anzahl')->default(0);
            $t->unsignedTinyInteger('status_id')->default(0);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('missing_since')->nullable();
            $t->timestamps();

            $t->index('datum', 'idx_rec_dispo_bedarfe_datum');
            $t->index(['rec_dispo_event_id', 'datum'], 'idx_rec_dispo_bedarfe_event_datum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_bedarfe');
    }
};
