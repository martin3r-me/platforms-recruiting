<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welche Sitzungen bei einer Konto-Anmeldung mit "Auf diesem Geraet
 * angemeldet bleiben" geoeffnet wurden (Platform\Recruiting\Support\
 * LangeSitzung). Nur diese bekommen 180 Tage statt 2 Stunden, und nur diese
 * nimmt die eigene Aufraeumung (recruiting:sitzungen-aufraeumen) von der
 * kurzen Frist aus.
 *
 * KEIN Ablaufdatum in der Zeile: die Frist gleitet mit sessions.last_activity
 * (180 Tage seit der letzten Benutzung), und die Zeile wird mit ihrer Sitzung
 * geraeumt. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_portal_lange_sitzungen')) {
            return;
        }

        Schema::create('rec_portal_lange_sitzungen', function (Blueprint $table) {
            $table->string('session_id', 255)->primary();
            $table->unsignedBigInteger('rec_person_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_portal_lange_sitzungen');
    }
};
