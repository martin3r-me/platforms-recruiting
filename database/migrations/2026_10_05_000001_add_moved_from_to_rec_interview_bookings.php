<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teilnehmer zwischen Schulungsterminen derselben Stelle verschieben
 * (05.10.2026). Die Buchungszeile wandert mit, statt storniert und neu
 * angelegt zu werden. Diese Spalten halten nur den LETZTEN Umzug fest, damit
 * die Teilnehmerliste des Zieltermins „aus Termin X" zeigen kann. Die volle
 * Historie (wer, wann, Kommentar, jeder Umzug) steht im Bewerber-Verlauf
 * (rec_auto_pilot_logs, type=booking_moved).
 *
 * Kein Fremdschluessel: ein spaeter geloeschter Quelltermin darf die
 * Buchung nicht mitreissen oder blockieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('moved_from_interview_id')->nullable()->after('rec_interview_id');
            $table->dateTime('moved_at')->nullable()->after('moved_from_interview_id');
            $table->unsignedBigInteger('moved_by_user_id')->nullable()->after('moved_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_bookings', function (Blueprint $table) {
            $table->dropColumn(['moved_from_interview_id', 'moved_at', 'moved_by_user_id']);
        });
    }
};
