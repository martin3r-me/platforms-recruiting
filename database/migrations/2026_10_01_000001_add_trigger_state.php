<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Der Zustand, den die Nachrichtenregeln brauchen — mehr nicht.
 *
 * Die Aufgabenliste selbst wird NICHT gespeichert (Spec 3.2): sie aendert
 * sich taeglich, weil Nachweise ablaufen und Einsaetze dazukommen. Eine
 * gespeicherte Liste waere nach einer Nacht falsch.
 *
 * Die Signatur haengt an der PERSON, nicht an der Anstellung — sonst bekaeme
 * ein Mensch mit zwei Anstellungen zwei Nachrichten mit demselben Inhalt.
 * Die Erinnerung haengt an der EINBUCHUNG, weil sie sich auf genau diesen
 * Einsatz bezieht.
 *
 * Eigene Datei statt Aenderung an einer bestehenden: eine Migration, die
 * irgendwo schon gelaufen ist, laeuft dort nie wieder an. Spalten einzeln
 * mit hasColumn-Wache, down() als echte Umkehrung (Muster:
 * 2026_09_29_000001_add_konto_felder_to_rec_persons.php).
 *
 * ZU DEN after()-ANKERN: SQLite ignoriert after() stillschweigend, die
 * Spaltenreihenfolge der Produktion kann die Testumgebung also nicht
 * herstellen. Was sie haelt, ist der Rest: TriggerStateSchemaTest faehrt
 * diese Migration ZUSAETZLICH gegen die MySQL-Grammatik (in pretend, ohne
 * Server) und prueft die erzeugte DDL samt Ankern und Laenge — und dass die
 * Anker wirklich im Schema stehen, das die uebrigen Migrationen bauen. Ein
 * toter Anker, der auf MySQL die Migration braeche, faellt damit auf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'aufgaben_signatur')) {
                $table->string('aufgaben_signatur', 64)->nullable()->after('letzte_anmeldung_at');
            }
            if (!Schema::hasColumn('rec_persons', 'aufgaben_gemeldet_at')) {
                $table->timestamp('aufgaben_gemeldet_at')->nullable()->after('aufgaben_signatur');
            }
        });

        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at')) {
                $table->timestamp('aufgaben_erinnert_at')->nullable()->after('reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            foreach (['aufgaben_signatur', 'aufgaben_gemeldet_at'] as $spalte) {
                if (Schema::hasColumn('rec_persons', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });

        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('rec_dispo_assignments', 'aufgaben_erinnert_at')) {
                $table->dropColumn('aufgaben_erinnert_at');
            }
        });
    }
};
