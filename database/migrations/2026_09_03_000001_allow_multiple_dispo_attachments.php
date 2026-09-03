<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Crew-Info (Kunde 03.09.): Einteilungen, Briefings, Plaene, Zugangscodes —
    // das sind MEHRERE Dokumente pro MA und VA. Der Unique-Index aus Runde 3
    // ("genau eine Datei") weicht einem normalen Index; die Ersetzen-Semantik
    // im Store entfaellt (hochladen = hinzufuegen, loeschen = gezielt je Datei).
    public function up(): void
    {
        Schema::table('rec_dispo_attachments', function (Blueprint $table) {
            $table->dropUnique(['rec_dispo_event_id', 'rec_employee_id']);
            $table->index(['rec_dispo_event_id', 'rec_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_attachments', function (Blueprint $table) {
            $table->dropIndex(['rec_dispo_event_id', 'rec_employee_id']);
            $table->unique(['rec_dispo_event_id', 'rec_employee_id']);
        });
    }
};
