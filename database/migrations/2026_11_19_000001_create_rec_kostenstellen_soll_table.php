<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soll-Mitarbeiterzahl je Kostenstelle (Niederlassung) mit „gültig ab“ (Markus 19.11.2026:
 * „Sollzahlen liegen auch in ZAS, die würde ich manuell im HCM pflegen, wenn du mir Felder dazu
 * gibst. Immer je Kostenstelle“).
 *
 * Eine Zeile je Änderung, nie überschreiben: der Report eines Tages liest das Soll, das an diesem
 * Tag galt. `herkunft` ist heute immer 'manuell'; die spätere Ableitung aus Umsatzbudget und
 * Stundenumsatz schreibt dieselbe Tabelle mit 'berechnet' (Fundament, Mail 18.11.2026).
 *
 * `kostenstelle` ist der Hunderterblock (100 = DUS & ES, 200 = MGL, 400 = CGN …), dieselbe Regel
 * wie die Dispo (KostenstellenBloecke).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_kostenstellen_soll')) {
            return;
        }
        Schema::create('rec_kostenstellen_soll', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedInteger('kostenstelle');
            // null = ab diesem Tag kein Soll mehr (Feld geleert)
            $table->unsignedInteger('soll')->nullable();
            $table->date('gueltig_ab');
            $table->string('herkunft', 16)->default('manuell');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'kostenstelle', 'gueltig_ab'], 'rec_ks_soll_team_ks_ab_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_kostenstellen_soll');
    }
};
