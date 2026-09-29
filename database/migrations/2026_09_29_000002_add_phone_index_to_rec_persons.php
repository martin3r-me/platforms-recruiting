<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Index auf rec_persons.phone — allein, ohne team_id davor.
 *
 * Warum er gebraucht wird: Seit Ruling GD-2 sucht die Anmeldung die Nummer
 * ueber ALLE Teams, weil die Anmeldeseite oeffentlich ist und keinen
 * Team-Kontext hat (sie uebergibt teamId = null). Die Abfrage fragt dann
 * ALLEIN nach phone. Der bestehende unique(team_id, phone) traegt das nicht:
 * ein zusammengesetzter Index ist nur ueber sein ERSTES Feld benutzbar, ohne
 * team_id wird die Tabelle voll gelesen. Vorher schraenkte das Team ein.
 *
 * Bei heutiger Groesse ist das belanglos (ein paar tausend Zeilen gegen 170 ms
 * bcrypt im selben Aufruf). Es steht hier, weil die Anmeldeseite ein
 * oeffentlicher, durchprobierbarer Einstieg ist: dort will man nicht, dass
 * jeder Versuch die ganze Personentabelle liest.
 *
 * Warum eine ZWEITE Migration und keine Ergaenzung der ersten: Die Migration
 * aus Aufgabe 1 (2026_09_29_000001_add_konto_felder_to_rec_persons.php) kann
 * auf der Demo schon gelaufen sein, und eine nachtraeglich geaenderte
 * Migration laeuft dort nie wieder an — die Ergaenzung waere dann auf der
 * Demo unsichtbar geblieben.
 *
 * hasIndex-Wache pro DDL-Operation nach dem Muster der ersten Migration
 * (dort hasColumn) und aus
 * 2026_08_12_000001_add_type_to_rec_contract_templates.php; down() ist die
 * echte Umkehrung und ebenfalls bewacht.
 */
return new class extends Migration
{
    private const INDEX = 'rec_persons_phone_index';

    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasIndex('rec_persons', self::INDEX)) {
                $table->index('phone', self::INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (Schema::hasIndex('rec_persons', self::INDEX)) {
                $table->dropIndex(self::INDEX);
            }
        });
    }
};
