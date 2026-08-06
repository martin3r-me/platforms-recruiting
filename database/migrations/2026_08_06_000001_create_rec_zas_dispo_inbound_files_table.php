<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadaten-Log fuer von ZAS eingehende Dispo-Dateien (Veranstaltungen +
     * eingebuchtes Personal), POST /recruiting/zas/dispo-inbound.
     *
     * Analog rec_zas_inbound_files (Mitarbeiter-Inbound): Phase 1 nimmt nur an
     * und legt die Rohdatei weg — Verarbeitung (VA-/Einsatz-Models, Matching)
     * kommt in Phase 2, sobald klar ist welche Spalten ZAS liefert.
     *
     * Bewusst team-los: das Bearer-Token traegt keinen Team-Kontext. Nebeneffekt:
     * jeder eingeloggte User jedes Teams sieht die Sichtung — akzeptiert bis zum
     * Rheingedeck-Disponenten-Zugang (siehe Spec, Zielbild Punkt 6).
     *
     * parse_status: Phase 1 schreibt nur viewable | unparseable.
     * `pending` ist Reserve fuer die Verarbeitungs-Pipeline in Phase 2.
     */
    public function up(): void
    {
        Schema::create('rec_zas_dispo_inbound_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Quelle (fix 'zas' — vorbereitet auf weitere Quellen im Staffing-Modul)
            $table->string('source', 32)->default('zas');

            // Herkunft / Roh-Ablage (Rohdatei 1:1 auf dem Disk, hier nur Metadaten)
            $table->string('original_filename')->nullable();
            $table->string('disk');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Erkannte Struktur (Best-Effort beim Empfang)
            $table->string('detected_format', 16)->nullable(); // csv | json | null = unbekannt
            $table->string('delimiter', 8)->nullable();
            $table->json('header_columns')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            // Klassifikation + Status
            $table->boolean('is_test')->default(false);
            $table->string('parse_status', 16)->default('unparseable');
            $table->text('notes')->nullable();

            // Diagnostik
            $table->string('received_ip', 45)->nullable();

            $table->timestamps();

            $table->index('created_at', 'idx_rec_zas_dispo_inbound_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_zas_dispo_inbound_files');
    }
};
