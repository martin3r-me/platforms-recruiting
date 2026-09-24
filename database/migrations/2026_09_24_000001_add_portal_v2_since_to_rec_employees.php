<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer sieht schon das neue Portal?
 *
 * NULL = altes Portal, Zeitstempel = neues. Gate 5 aus Canvas 67 verlangt eine
 * benannte Testgruppe vor der Freischaltung fuer alle — die Umstellung ist
 * damit eine bewusste Handlung je Mensch und im Datensatz nachvollziehbar.
 *
 * Bewusst ein Zeitstempel und keine Liste in den Einstellungen: Man sieht, WANN
 * jemand umgestellt wurde, das Zuruecknehmen ist ein NULL, und „alle" ist
 * derselbe Befehl wie „diese zwoelf".
 *
 * NICHT in RELEVANT_EMPLOYEE_FIELDS: Das Feld geht ZAS nichts an, und es duerfte
 * keinen Update-Marker setzen — sonst spuelte die erste Umstellungswelle die
 * Testmitarbeiter in die updates.csv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'portal_v2_since')) {
                $table->timestamp('portal_v2_since')->nullable()->after('portal_last_seen_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'portal_v2_since')) {
                $table->dropColumn('portal_v2_since');
            }
        });
    }
};
