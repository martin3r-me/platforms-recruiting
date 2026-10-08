<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispo-Taetigkeiten aus ZAS. Quelle sind die Bloecke {Dispo4} (Katalog) und
 * {Dispo5} (Zuordnung je Mitarbeiter) des Dispo-Webexports — NICHT die Spalte
 * DispoTaetigkeiten im MA-Export, fuer die dieses Feld urspruenglich gebaut
 * wurde (Kunde 15.09.2026). Diese Spalte kam in 0 von 579 Lieferungen vor; der
 * Pfad wurde am 08.10.2026 entfernt, siehe
 * docs/superpowers/specs/2026-10-08-zas-qualifikationen-dispo5-design.md.
 *
 * Eigenes Feld NEBEN 'qualifications': jenes pflegen wir selbst und
 * exportieren es als Spalte Qualifikation ZU ZAS. Beides in einem Feld hiesse,
 * ZAS seine eigenen Daten als Aenderung zurueckzuschicken.
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
