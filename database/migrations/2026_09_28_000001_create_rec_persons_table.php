<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Mensch als eigene Zeile — bisher gab es ihn nur als Stempel.
 *
 * `rec_employees.person_key` markiert seit dem 09.09.2026, welche Datensaetze
 * derselbe Mensch sind. Gesetzt wird er aber NUR beim Paaren: wer eine
 * Anstellung hat, traegt NULL — das ist die Mehrheit. Als Anker fuer ein
 * Konto taugt er deshalb nicht (Spec 2026-09-28, Paragraph 4.3).
 *
 * Diese Zeile bekommt JEDER, auch ohne Konto und auch mit nur einer
 * Anstellung. Die Klammer ist damit von Tag eins vollstaendig.
 *
 * Die Anmeldespalten (phone, password_hash, ...) entstehen hier mit, werden
 * in Stufe 1 aber von nichts gelesen. Sie jetzt wegzulassen hiesse, spaeter
 * ein zweites Mal an einer dann befuellten Tabelle zu wandern.
 *
 * phone ist der spaetere Benutzername und deshalb je Team eindeutig — eine
 * Nummer darf nur an EINEM Konto haengen (Canvas 68, Eintrag 1740). NULL ist
 * erlaubt und mehrfach moeglich: wer keine Nummer hat, bekommt trotzdem eine
 * Personen-Zeile, er bekommt nur kein Konto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_persons')) {
            return;
        }

        Schema::create('rec_persons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();

            $table->string('phone', 32)->nullable();
            $table->string('password_hash')->nullable();
            $table->string('email')->nullable();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            // Gesetzt, wenn diese Zeile beim Zusammenlegen verloren hat. Sie
            // bleibt stehen und ihre Nummer bleibt gesperrt, damit eine neu
            // vergebene Handynummer nicht an alte Daten fuehrt (Canvas 1793).
            $table->unsignedBigInteger('merged_into_person_id')->nullable()->index();

            $table->timestamps();

            $table->unique(['team_id', 'phone'], 'rec_persons_team_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_persons');
    }
};
