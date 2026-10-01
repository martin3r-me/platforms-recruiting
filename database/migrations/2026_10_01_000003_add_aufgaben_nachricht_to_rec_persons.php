<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WELCHE Nachricht zuletzt rausging — nicht nur WANN (ET-23).
 *
 * `aufgaben_gemeldet_at` sagt, wann zuletzt gesendet wurde. Was es nicht
 * sagt: ob die Nachricht angekommen ist. Der Status `sent` entsteht allein
 * aus dem HTTP-Ergebnis des Annahme-Aufrufs bei Meta; dass eine Nachricht
 * NICHT zugestellt werden konnte, meldet Meta erst spaeter per Webhook
 * (Fall 131026, in diesem Haus belegt). Haekelte der Einsatz-Trigger auf
 * `sent` hin ab, bekaeme der Betroffene nie wieder etwas — dieselbe
 * Sackgasse wie die nie geraeumte Signatur, nur ueber einen anderen Weg.
 *
 * Mit dieser Spalte liest der naechste Lauf die gespeicherte Nachricht nach.
 * Steht sie inzwischen auf `failed`, wird die Signatur geraeumt und es gibt
 * einen zweiten Versuch.
 *
 * DAS MUSTER IST NICHT NEU: `rec_dispo_assignments.reminder_message_id`
 * haelt genau so die Bestaetigungs-Nachricht fest, und
 * `RecDispoAssignment::hasActiveDeliveryFailure()` liest ihren Status aus
 * demselben Grund nach.
 *
 * KEIN FOREIGN-KEY-CONSTRAINT auf comms_whatsapp_messages — wie bei
 * reminder_message_id, das ebenfalls ohne Constraint auf die Tabelle des
 * Fremdmoduls zeigt. Ein Loeschen alter Nachrichten darf keine Personen-
 * Zeile mitreissen; ein toter Verweis wird hier als "nichts nachzulesen"
 * behandelt.
 *
 * Eigene Datei statt Aenderung an 2026_10_01_000001: eine Migration, die
 * irgendwo schon gelaufen ist, laeuft dort nie wieder an. hasColumn-Wache in
 * up() und down(), down() als echte Umkehrung (Muster:
 * 2026_09_29_000001_add_konto_felder_to_rec_persons.php).
 *
 * ZUM after()-ANKER: SQLite ignoriert after() stillschweigend. Die erzeugte
 * MySQL-DDL prueft TriggerStateSchemaTest, indem es diese Migration
 * zusaetzlich gegen die MySQL-Grammatik faehrt (pretend, ohne Server).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'aufgaben_nachricht_id')) {
                $table->unsignedBigInteger('aufgaben_nachricht_id')->nullable()->after('aufgaben_gemeldet_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (Schema::hasColumn('rec_persons', 'aufgaben_nachricht_id')) {
                $table->dropColumn('aufgaben_nachricht_id');
            }
        });
    }
};
