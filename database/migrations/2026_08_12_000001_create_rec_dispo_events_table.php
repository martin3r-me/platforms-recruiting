<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VAs aus dem ZAS-Webexport ({Dispo2}, gruppiert ueber Einsatz-ID).
     *
     * Schlanke Kern-Spalten + source_meta-JSON fuer ZAS-Spezifika (Spec-Regel).
     * anfahrt/dresscode: vorbereitet, ZAS liefert sie noch nicht (nullable).
     * Team-los wie die Inbound-Tabelle; Team-Kontext kommt in Step 2 ueber
     * den gematchten RecEmployee. Siehe Spec 2026-08-12-zas-dispo-verarbeitung.
     */
    public function up(): void
    {
        Schema::create('rec_dispo_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('einsatz_ref')->unique(); // ZAS Einsatz-ID, z. B. RG19063
            $table->string('name')->nullable();       // Projektbezeichnung
            $table->text('venue_text')->nullable();   // Textfeld 2, <br/> -> \n
            $table->string('ort')->nullable();
            $table->string('einsatzfirma')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Vorbereitet — kommen spaeter von ZAS (Mail 10.08. an Herrn Michel)
            $table->text('anfahrt')->nullable();
            $table->text('dresscode')->nullable();

            $table->json('source_meta')->nullable();

            $table->timestamps();

            $table->index('ends_on', 'idx_rec_dispo_events_ends_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_events');
    }
};
