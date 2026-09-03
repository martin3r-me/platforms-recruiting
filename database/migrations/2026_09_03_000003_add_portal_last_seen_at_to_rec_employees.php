<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Crew-Info (Kunde 03.09.): letzter Besuch der Einsatz-Seite (pro
    // Datensatz; die Seite liest/setzt die ganze Identitaetsgruppe). Grundlage
    // der NEU-Hervorhebung: neu = created/updated NACH dem vorherigen Besuch.
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->timestamp('portal_last_seen_at')->nullable()->after('portal_locked_reason');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('portal_last_seen_at');
        });
    }
};
