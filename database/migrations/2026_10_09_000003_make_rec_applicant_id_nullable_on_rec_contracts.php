<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vertrag aus der Mitarbeiterakte (Spec 2026-10-09 §2.2): ein MA-Datensatz
 * aus ZAS hat oft KEINE Bewerbung. rec_contracts.rec_applicant_id war seit
 * 2026_04_15 NOT NULL — ein solcher Vertrag liess sich nicht speichern.
 *
 * Keine neue Spalte, nur die Nullbarkeit. Fremdschluessel und Indizes
 * bleiben (unter SQLite baut Laravel die Tabelle dabei neu auf und uebernimmt
 * beides — geprueft am 09.10.2026).
 *
 * down(): scheitert, sobald Vertraege ohne Bewerbung existieren — gewollt,
 * ein stilles Loeschen waere schlimmer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('rec_applicant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rec_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('rec_applicant_id')->nullable(false)->change();
        });
    }
};
