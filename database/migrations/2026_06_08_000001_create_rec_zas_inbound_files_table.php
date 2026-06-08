<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadaten-Log fuer von ZAS eingehende CSV-Dateien.
     *
     * Gegenstueck zu den drei Pull-Export-Endpoints: hier schickt ZAS uns
     * per POST /recruiting/zas/inbound eine CSV. Phase 1 nimmt nur an und
     * legt die Rohdatei weg — die eigentliche Verarbeitung (Spalten-Mapping)
     * kommt spaeter, sobald wir wissen was wirklich ankommt.
     *
     * Die Rohdatei liegt auf dem Storage-Disk (Pfad in `stored_path`/`disk`);
     * diese Tabelle haelt nur Metadaten + erkannte Struktur fuer die spaetere
     * Auswertung.
     *
     * `status`: received → processed | failed  (Verarbeitungs-Pipeline Phase 2)
     * `is_test`: via ?dry_run=true gesetzt — trennt Verbindungstests von Echt-Lieferungen.
     */
    public function up(): void
    {
        Schema::create('rec_zas_inbound_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Herkunft / Roh-Ablage
            $table->string('original_filename')->nullable();
            $table->string('disk');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // Erkannte Struktur (Best-Effort-Parse beim Empfang)
            $table->string('delimiter', 8)->nullable();
            $table->json('header_columns')->nullable();
            $table->unsignedInteger('row_count')->nullable();

            // Klassifikation + Verarbeitungs-Status
            $table->boolean('is_test')->default(false);
            $table->string('status')->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();

            // Diagnostik
            $table->string('received_ip', 45)->nullable();

            $table->timestamps();

            $table->index('status', 'idx_rec_zas_inbound_status');
            $table->index('created_at', 'idx_rec_zas_inbound_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_zas_inbound_files');
    }
};
