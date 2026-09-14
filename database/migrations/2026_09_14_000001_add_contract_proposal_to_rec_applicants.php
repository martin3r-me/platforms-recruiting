<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lohn- und Laufzeit-VORSCHLAG des Schulungsleiters (Kundenwunsch 14.09.2026,
 * Mails Roesberg/Setzkorn 10.09.).
 *
 * Warum eigene Spalten und nicht einfach die Sperre loesen: zuschlag ist der
 * SCHARFE Wert, aus dem der Vertrag entsteht — den fuellt HR. Liegt ein Fall
 * am HR-Schreibtisch (Nicht-EU, Jugendschutz, Klaerung aus der Schulung), ist
 * er fuer den Schulungsleiter gesperrt, und genau das soll so bleiben. Er
 * bekommt daneben ein Vorschlagsfeld; HR uebernimmt per Klick oder tippt
 * etwas anderes. Ein Vorschlag allein macht KEINEN Versand moeglich
 * (ContractSendEligibility zaehlt weiter nur den scharfen Wert).
 *
 * Vertragsbeginn/-ende gab es bis hier ueberhaupt nicht als Spalte — beides
 * war reiner Formularzustand und wurde erst beim Versand auf den Vertrag
 * geschrieben. Fuer eine Empfehlung, die vom Schulungsleiter zu HR wandert,
 * muss sie die Seite ueberleben; die Vorschlagsspalten sind die ersten und
 * bleiben bewusst getrennt vom Versandweg.
 *
 * vorschlag_taken_at ist der Zeitpunkt der UEBERNAHME durch HR. Zusammen mit
 * vorschlag_at traegt er den Zustand (siehe ContractProposalState): korrigiert
 * der Schulungsleiter nach der Uebernahme, rutscht vorschlag_at nach vorne und
 * der Vorschlag gilt von allein wieder als offen — damit kein korrigierter
 * Wert still liegen bleibt, waehrend der alte rausgeht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_applicants', 'zuschlag_vorschlag')) {
                $table->decimal('zuschlag_vorschlag', 5, 2)->nullable()
                    ->comment('Empfehlung Schulungsleiter — NICHT vertragswirksam');
            }
            if (!Schema::hasColumn('rec_applicants', 'vertragsbeginn_vorschlag')) {
                $table->date('vertragsbeginn_vorschlag')->nullable();
            }
            if (!Schema::hasColumn('rec_applicants', 'vertragsende_vorschlag')) {
                $table->date('vertragsende_vorschlag')->nullable();
            }
            if (!Schema::hasColumn('rec_applicants', 'vorschlag_at')) {
                $table->timestamp('vorschlag_at')->nullable()
                    ->comment('zuletzt vom Schulungsleiter geaendert');
            }
            if (!Schema::hasColumn('rec_applicants', 'vorschlag_by')) {
                $table->unsignedBigInteger('vorschlag_by')->nullable()
                    ->comment('User-id des Schulungsleiters — fuer "von wem" am HR-Schreibtisch');
            }
            if (!Schema::hasColumn('rec_applicants', 'vorschlag_taken_at')) {
                $table->timestamp('vorschlag_taken_at')->nullable()
                    ->comment('von HR uebernommen am');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            foreach ([
                'zuschlag_vorschlag',
                'vertragsbeginn_vorschlag',
                'vertragsende_vorschlag',
                'vorschlag_at',
                'vorschlag_by',
                'vorschlag_taken_at',
            ] as $column) {
                if (Schema::hasColumn('rec_applicants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
