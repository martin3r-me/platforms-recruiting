<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stellenanzeige als Datei an der Ausschreibung (FLYNK, 16.11.2026): HR haengt
 * die fertige Anzeige (PDF/DOCX) an, der FLYNK-Abgleich schickt sie als
 * `files[]` mit. Genau EINE Datei je Ausschreibung; alles nullable, der Bestand
 * bleibt unveraendert (ohne Datei laeuft der Abgleich wie bisher als JSON).
 *
 * `anzeige_hash` (SHA-256 des Inhalts) geht in den Inhalts-Hash des Abgleichs
 * ein — nur darueber loest ein Dateitausch eine „aktualisieren"-Meldung aus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_postings', function (Blueprint $t) {
            $t->string('anzeige_disk', 40)->nullable();
            $t->string('anzeige_path', 255)->nullable();
            $t->string('anzeige_filename', 255)->nullable();
            $t->string('anzeige_mime', 120)->nullable();
            $t->unsignedBigInteger('anzeige_size')->nullable();
            $t->string('anzeige_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_postings', fn (Blueprint $t) => $t->dropColumn([
            'anzeige_disk', 'anzeige_path', 'anzeige_filename', 'anzeige_mime', 'anzeige_size', 'anzeige_hash',
        ]));
    }
};
