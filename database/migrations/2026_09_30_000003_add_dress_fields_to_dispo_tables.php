<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festschreiben und Hinweistext.
 *
 * An der Einbuchung: das beim Bestaetigungs-Versand aufgeloeste Paket. Aendert
 * jemand spaeter den Paketinhalt, darf sich nicht rueckwirkend aendern, was ein
 * Mitarbeiter bestaetigt hat.
 *
 * An der Veranstaltung: unser eigener Hinweistext (ersetzt den ZAS-Kasten,
 * sobald ein Paket greift) und die Kopie des ZAS-Textes, die beim Setzen
 * bestaetigt wurde — daran sieht die Dispo spaeter, ob ZAS seinen Text
 * seitdem geaendert hat.
 *
 * PFLEGEHINWEIS: Diese Spalten gehoeren NICHT in den Attribut-Satz des
 * Dispo-Import-Planners. Sonst raeumt die naechste Lieferung die Arbeit der
 * Dispo ab (ZasDispoWebexportImporter::updateOrCreate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('rec_dispo_dress_package_id')->nullable();
            $table->timestamp('dress_frozen_at')->nullable();
        });

        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->text('hinweis')->nullable();
            $table->text('dresscode_ack')->nullable();
            $table->timestamp('dresscode_ack_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn(['rec_dispo_dress_package_id', 'dress_frozen_at']);
        });

        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn(['hinweis', 'dresscode_ack', 'dresscode_ack_at']);
        });
    }
};
