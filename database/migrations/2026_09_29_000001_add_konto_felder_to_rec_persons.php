<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Kontofelder an der Personen-Zeile: Einladung und Einmalcode.
 *
 * Warum Hashes und keine Klartexte: Ein gestohlener Datenbankauszug darf
 * keine Konten aushaendigen. Der Einladungs-Token und der Einmalcode stehen
 * genau einmal im Klartext — in der Nachricht an den Menschen. In der
 * Datenbank liegt nur der Hash, genau wie schon bei password_hash.
 *
 * Warum code_zweck eine eigene Spalte ist: Ein Code fuer "Passwort
 * zuruecksetzen" darf keinen Nummernwechsel bestaetigen — sonst reicht ein
 * abgefangener Code aus dem einen Vorgang, um den anderen durchzufuehren.
 * Der Zweck steht deshalb daneben und wird beim Einloesen mitgeprueft.
 *
 * Warum code_neue_nummer eine eigene Spalte ist: Beim Nummernwechsel geht
 * der Code an die NEUE Nummer, die erst nach der Bestaetigung die echte
 * wird. Bis dahin muss sie irgendwo stehen, ohne die bestehende phone-Spalte
 * anzutasten.
 *
 * Spalten einzeln mit hasColumn-Wache (Muster:
 * 2026_09_28_000002_add_rec_person_id_to_rec_employees.php), down() als
 * echte Umkehrung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_persons', 'invite_token_hash')) {
                $table->string('invite_token_hash', 64)->nullable()->after('merged_into_person_id');
            }
            if (!Schema::hasColumn('rec_persons', 'invite_expires_at')) {
                $table->timestamp('invite_expires_at')->nullable()->after('invite_token_hash');
            }
            if (!Schema::hasColumn('rec_persons', 'invite_used_at')) {
                $table->timestamp('invite_used_at')->nullable()->after('invite_expires_at');
            }
            if (!Schema::hasColumn('rec_persons', 'code_hash')) {
                $table->string('code_hash', 64)->nullable()->after('invite_used_at');
            }
            if (!Schema::hasColumn('rec_persons', 'code_expires_at')) {
                $table->timestamp('code_expires_at')->nullable()->after('code_hash');
            }
            if (!Schema::hasColumn('rec_persons', 'code_versuche')) {
                $table->unsignedTinyInteger('code_versuche')->default(0)->after('code_expires_at');
            }
            if (!Schema::hasColumn('rec_persons', 'code_zweck')) {
                $table->string('code_zweck', 20)->nullable()->after('code_versuche');
            }
            if (!Schema::hasColumn('rec_persons', 'code_neue_nummer')) {
                $table->string('code_neue_nummer', 32)->nullable()->after('code_zweck');
            }
            if (!Schema::hasColumn('rec_persons', 'letzte_anmeldung_at')) {
                $table->timestamp('letzte_anmeldung_at')->nullable()->after('code_neue_nummer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_persons', function (Blueprint $table) {
            foreach ([
                'invite_token_hash', 'invite_expires_at', 'invite_used_at',
                'code_hash', 'code_expires_at', 'code_versuche', 'code_zweck',
                'code_neue_nummer', 'letzte_anmeldung_at',
            ] as $spalte) {
                if (Schema::hasColumn('rec_persons', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
