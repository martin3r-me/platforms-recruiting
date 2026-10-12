<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ab wann laufende Portal-Sitzungen dieser Person nicht mehr gelten (Spec
 * HR-Bereich §9.6, Entscheidung 12.10.: ein zurueckgesetztes Konto darf
 * keinen Fremden eingeloggt lassen). Gesetzt nur von KontoWriter (HR-Zuruecksetzen, Aufheben der Notfallsperre),
 * gelesen nur von PortalAuth::sitzungGueltig(). Eigene Migration, weil die
 * Konto-Migrationen auf der Demo schon gelaufen sein koennen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'sitzungen_ungueltig_ab')) {
                $table->timestamp('sitzungen_ungueltig_ab')->nullable()->after('notfall_gesperrt_bis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (Schema::hasColumn('rec_persons', 'sitzungen_ungueltig_ab')) {
                $table->dropColumn('sitzungen_ungueltig_ab');
            }
        });
    }
};
