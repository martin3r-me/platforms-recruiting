<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Angeschrieben an"-Protokoll (Vorfall RG19734, 04.09.): der Versand stempelt
 * die tatsaechlich verwendete Nummer an die Einbuchung. Weicht die aktuelle
 * Akten-Nummer spaeter ab (HR-Korrektur nach dem Versand), zaehlt die Zeile
 * als Zustellproblem: roter Chip, ⚠-Filter, Nur-Zustellfehler-Versand,
 * Stufe-3-Verschonung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->string('reminder_sent_to', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_to');
        });
    }
};
