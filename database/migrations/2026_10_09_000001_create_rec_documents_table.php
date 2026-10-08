<?php
// database/migrations/2026_10_09_000001_create_rec_documents_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumente, die HR Mitarbeitern bereitstellt (Spec 2026-10-08, §2.1): die
 * PDF liegt auf dem Dispo-Anhang-Disk, hier stehen Titel, Kategorie, die
 * verlangte Aktion und die eingefrorene Pruefsumme. Ein Dokument, viele
 * Zustellungen (rec_document_recipients). Die Datei ist nach dem Anlegen
 * unveraenderlich — es gibt keine "Datei ersetzen"-Aktion.
 *
 * dateTime statt timestamp: unter explicit_defaults_for_timestamp=OFF bekaeme
 * die erste NOT-NULL-timestamp-Spalte ON UPDATE CURRENT_TIMESTAMP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->uuid('uuid')->unique();
            $table->string('title', 255);
            $table->string('category', 30);
            $table->string('action', 20);
            $table->string('disk', 50);
            $table->string('stored_path', 500);
            $table->string('original_filename', 255);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('rec_dispo_event_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_documents');
    }
};
