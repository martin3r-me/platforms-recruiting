<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verlauf je Dokument als Nachweis (Sebastian 16.10.2026: "wer was wie
 * gesendet hat, von welchem User"): bereitgestellt, Versand, WhatsApp jetzt,
 * erneut gesendet, zurueckgezogen, Automatik beendet, automatisch zugestellt.
 * Schreibt nur DokumentVerlauf::schreibe().
 *
 * NUR ANFUEGEN: kein updated_at, kein Bearbeiten, kein Loeschen. Kein FK —
 * weder auf users (Core-Tabelle, Muster rec_hr_vermerke) noch auf die
 * Dokumente: ein Verlauf ueberlebt auch ein geloeschtes Dokument.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_document_events')) {
            return;
        }

        Schema::create('rec_document_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('rec_document_id')->index();
            $table->unsignedBigInteger('rec_document_recipient_id')->nullable()->index();
            $table->string('aktion', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_document_events');
    }
};
