<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unterscheidet Vertragsvorlagen von Zertifikat-Vorlagen. Beide leben in
 * derselben Tabelle, damit Editor, Platzhalter-Engine und Verwaltungsseite
 * mitbenutzt werden koennen.
 *
 * Bewusst NOT NULL mit Default: ein dritter Zustand "unbekannt" muesste in
 * jeder Query mitgedacht werden. Der Bestand wird durch den Default korrekt
 * zu 'contract'.
 *
 * Idempotenz über Live-Guards: Pro DDL-Operation ein eigener hasColumn/hasIndex-Check.
 * Diese Guards sind echte Queries, die VOR der DDL-Ausführung laufen und verhindern,
 * dass doppelte ADD COLUMN oder ADD INDEX fehlerhafte Kommandos generieren.
 * Schema::hasColumn() und Schema::hasIndex() sind nicht Exception-basiert — ein
 * Retry nach fehlgeschlagenem DDL-Op sieht den Guard-Check erneut und überspringt
 * die Doppel-Operation stillschweigend (idempotent). Exception-Handler in der Closure
 * fangen DDL-Fehler nicht (SQL läuft erst nach der Closure in Builder::build).
 * Muster für Guard-pro-DDL aus 2026_05_19_000002_add_check_flag_and_additional_contract_...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_contract_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_contract_templates', 'type')) {
                $table->string('type', 20)->default('contract')->after('code');
            }

            if (!Schema::hasIndex('rec_contract_templates', 'rec_contract_templates_team_id_type_index')) {
                $table->index(['team_id', 'type']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_contract_templates', function (Blueprint $table) {
            if (Schema::hasIndex('rec_contract_templates', 'rec_contract_templates_team_id_type_index')) {
                $table->dropIndex('rec_contract_templates_team_id_type_index');
            }

            if (Schema::hasColumn('rec_contract_templates', 'type')) {
                $table->dropColumn('type');
            }
        });
    }
};
