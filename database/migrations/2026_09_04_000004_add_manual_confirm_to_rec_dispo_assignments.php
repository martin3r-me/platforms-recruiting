<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manuell bestaetigen (Kunde 04.09., Esra-Fall): die Dispo kann eine Person
 * fuer eine VA per Knopf bestaetigen (telefonische Zusage, Meldung ueber
 * fremde Nummer). Die Spalte haelt fest, WER manuell bestaetigt hat —
 * null = Selbstbestaetigung ueber die Einsatz-Seite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('manually_confirmed_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn('manually_confirmed_by_user_id');
        });
    }
};
