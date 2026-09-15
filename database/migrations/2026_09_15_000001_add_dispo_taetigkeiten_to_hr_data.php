<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispo-Taetigkeiten aus ZAS (Kunde 15.09.): die dem MA in ZAS fuer die Dispo
 * zugewiesenen Taetigkeiten (Spalte DispoTaetigkeiten im MA-Export, komma-
 * getrennt). Eigenes Feld NEBEN 'qualifications' — das bestehende Feld bleibt
 * unangetastet und wird spaeter ggf. hierhin ueberfuehrt.
 *
 * ZAS ist fuer dieses Feld fuehrend: jede Lieferung ersetzt den Stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->json('dispo_taetigkeiten')->nullable();
            $table->timestamp('dispo_taetigkeiten_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->dropColumn(['dispo_taetigkeiten', 'dispo_taetigkeiten_synced_at']);
        });
    }
};
