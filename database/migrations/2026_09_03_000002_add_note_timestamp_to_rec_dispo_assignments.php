<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Crew-Info (Kunde 03.09.): Zeitpunkt der letzten Hinweis-Aenderung — die
    // Einsatz-Seite hebt Hinweise hervor, die seit dem letzten Besuch des MA
    // neu/geaendert sind. null = Hinweis stammt aus der Zeit vor dem Feature.
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->timestamp('individual_note_updated_at')->nullable()->after('individual_note');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn('individual_note_updated_at');
        });
    }
};
