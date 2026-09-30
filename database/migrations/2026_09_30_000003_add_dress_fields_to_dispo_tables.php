<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Festschreiben und Hinweistext.
 *
 * An der Einbuchung: das beim Bestaetigungs-Versand aufgeloeste Paket — als
 * Referenz UND als Textkopie. Aendert jemand spaeter den Paketinhalt, darf
 * sich nicht rueckwirkend aendern, was ein Mitarbeiter bestaetigt hat; die
 * blosse Referenz haette genau das zugelassen, weil die Einsatz-Seite
 * items_text live aus dem Paket liest.
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
            // Textkopie, nicht nur die Referenz: die Pflegemaske schreibt
            // items_text IN den bestehenden Paket-Datensatz. Ohne Kopie
            // aenderte sich rueckwirkend, was ein Mitarbeiter bereits
            // bestaetigt hat — genau das schliesst die Spec aus.
            $table->text('dress_items_text')->nullable();
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
            $table->dropColumn(['rec_dispo_dress_package_id', 'dress_frozen_at', 'dress_items_text']);
        });

        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn(['hinweis', 'dresscode_ack', 'dresscode_ack_at']);
        });
    }
};
