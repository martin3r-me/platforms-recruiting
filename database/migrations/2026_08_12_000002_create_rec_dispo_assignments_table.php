<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Einbuchungen aus dem ZAS-Webexport ({Dispo}).
     *
     * ds_ref (DS-ID ZAS) ist der eindeutige Einbuchungs-Schluessel — an ihm
     * haengt in Step 2 die Loesch-Markierung fuer den ZAS-Abruf.
     *
     * rec_employee_id: BEWUSST OHNE Foreign-Key-Constraint (Entkopplungs-
     * Leitplanke 4 der Spec) — Sollbruchstelle fuer den spaeteren polymorphen
     * Personen-Verweis im Staffing-Modul.
     *
     * missing_since: zukuenftige Einbuchung fehlte im letzten Vollbestand
     * (kein Hard-Delete — Historie bleibt, Storno kommt eh als status_id=3).
     */
    public function up(): void
    {
        Schema::create('rec_dispo_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('ds_ref')->unique(); // DS-ID ZAS, z. B. 830363
            $table->foreignId('rec_dispo_event_id')
                ->constrained('rec_dispo_events')
                ->cascadeOnDelete();

            $table->string('pnr_raw');                              // wie geliefert, z. B. RG14
            $table->unsignedBigInteger('rec_employee_id')->nullable(); // kein FK-Constraint!

            $table->date('datum');
            $table->string('von', 8)->nullable();  // HH:MM
            $table->string('bis', 8)->nullable();
            $table->unsignedTinyInteger('status_id')->default(0); // 0 Angebot/1 Auftrag/2 Beendet/3 Storno
            $table->string('taetigkeit')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->json('source_meta')->nullable();

            $table->timestamps();

            $table->index('rec_employee_id', 'idx_rec_dispo_assign_employee');
            $table->index('datum', 'idx_rec_dispo_assign_datum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_assignments');
    }
};
