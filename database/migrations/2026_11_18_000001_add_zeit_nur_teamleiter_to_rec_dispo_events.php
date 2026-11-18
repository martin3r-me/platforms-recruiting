<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zeiterfassung: Haken „Zeiten erfasst nur der Teamleiter“ an der Veranstaltung (Markus 17.11.2026:
 * „Check in gesperrt bedeutet, dass der Teamleiter/Kunde es zu 100% uebernehmen will/muss, Haken
 * dafuer im Projekt gesetzt, der Mitarbeiter kann nichts machen und sieht nur die erfassten Zeiten“).
 *
 * Gehoert uns, nicht ZAS: der Import fasst die Spalte nicht an.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('rec_dispo_events', 'zeit_nur_teamleiter')) {
            return;
        }
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->boolean('zeit_nur_teamleiter')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rec_dispo_events', 'zeit_nur_teamleiter')) {
            Schema::table('rec_dispo_events', function (Blueprint $table) {
                $table->dropColumn('zeit_nur_teamleiter');
            });
        }
    }
};
