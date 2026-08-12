<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unterscheidet Vertragsvorlagen von Zertifikat-Vorlagen. Beide leben in
 * derselben Tabelle, damit Editor, Platzhalter-Engine und Verwaltungsseite
 * mitbenutzt werden koennen.
 *
 * ACHTUNG: DIESE SPALTE HAT KEINEN KONSUMENTEN. Der Zertifikat-Inhalt steht
 * seit dem Zuschnitt des Pakets als festes HTML in TrainingCertificateHtml,
 * nicht als Vorlage in dieser Tabelle — jede Zeile traegt also 'contract', und
 * nichts filtert je auf 'certificate'. Die Spalte bleibt bewusst stehen: sie
 * ist zusammen mit den Invarianten in RecContractTemplate (siehe deren
 * Klassen-Docblock) der billige Teil des Rueckwegs, falls HR den Text spaeter
 * doch selbst aendern soll. Ein toter Schalter, dessen Totsein der
 * Soll-Zustand ist — nicht ein Symptom, dem man nachgehen muss. In diesem Repo
 * hat so etwas schon einmal Zeit gekostet (config('recruiting.sidebar')),
 * deshalb steht es hier statt nur im Chatverlauf.
 * Der Index (team_id, type) ist damit ebenfalls ohne Nutzer. Er kostet
 * Schreibaufwand, aber ein spaeteres Nachziehen kostet eine Migration auf einer
 * dann gewachsenen Tabelle — bewusst behalten.
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
