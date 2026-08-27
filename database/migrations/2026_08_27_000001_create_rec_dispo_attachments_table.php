<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Runde 3 (#8): genau EINE Datei pro Mitarbeiter und Veranstaltung, die der MA
    // ueber seine Einsatz-Seite abruft. Einsatz-Ebene, KEINE persoenliche
    // Dokumentenablage (die kommt mit dem MA-Portal). rec_employee_id ohne FK
    // (Entkopplungs-Leitplanke wie rec_dispo_assignments).
    public function up(): void
    {
        Schema::create('rec_dispo_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('rec_dispo_event_id')->constrained('rec_dispo_events')->cascadeOnDelete();
            $table->unsignedBigInteger('rec_employee_id');
            $table->string('disk', 50);
            $table->string('stored_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['rec_dispo_event_id', 'rec_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_dispo_attachments');
    }
};
