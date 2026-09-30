<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Sperre nach einem gestoppten Notfall-Antrag (Ruling GD-13 zu Weg 4,
 * Spec §5).
 *
 * WAS SIE HEILT: ohne sie ist das Stopp-Recht von HR ein Einmal-Recht. Wer
 * gestoppt wird, kann sofort neu beantragen — die Nachweis-Bremse zaehlt nur
 * FEHLversuche und wird bei Erfolg geleert, ein erfolgreicher Antragsteller
 * laeuft also nie in sie hinein. HR stoppt, der Antragsteller stellt neu,
 * ohne Ende. Ein Stopp-Recht, das man aussitzen kann, ist keines.
 *
 * WARUM SIE NIEMANDEN AUSSPERRT: fuer genau diesen Fall gibt es Weg 5. Wenn
 * HR einen Antrag stoppt, hat HR den Menschen im Blick — und kann die neue
 * Nummer selbst eintragen (recruiting:konto-zuruecksetzen --person --nummer).
 * Gesperrt sind sieben Tage lang NUR die Selbstbedienung ueber Weg 4, nicht
 * die Anmeldung, nicht Weg 3 und nicht der HR-Weg.
 *
 * WARUM EINE SPALTE UND KEIN CACHE-EINTRAG, obwohl die uebrigen Bremsen
 * dieses Zweiges im Cache liegen: die dortigen sind KOSTENBREMSEN — ein
 * geleerter Cache setzt sie zurueck, und das ist verschmerzbar (so steht es
 * auch im Docblock des Einmalcode-Senders). Diese hier ist die ENTSCHEIDUNG
 * EINES MENSCHEN gegen einen laufenden Uebernahmeversuch. Sie muss ein
 * `cache:clear` ueberleben, sonst hebt ein Wartungsbefehl sie lautlos auf.
 * Sieben Tage waeren fuer den Cache ausserdem eine ungewoehnlich lange
 * Lebensdauer.
 *
 * WARUM EINE VIERTE MIGRATION und keine Ergaenzung der dritten: die
 * Migrationen aus Aufgabe 1, 7 und 9 koennen auf der Demo schon gelaufen
 * sein, und eine nachtraeglich geaenderte Migration laeuft dort nie wieder
 * an.
 *
 * hasColumn-Wache wie in den drei Migrationen davor, down() als echte
 * Umkehrung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'notfall_gesperrt_bis')) {
                $table->timestamp('notfall_gesperrt_bis')->nullable()->after('wechsel_quelle');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (Schema::hasColumn('rec_persons', 'notfall_gesperrt_bis')) {
                $table->dropColumn('notfall_gesperrt_bis');
            }
        });
    }
};
