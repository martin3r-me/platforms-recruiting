<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kennzeichen „Sammelstelle" an der Stelle.
 *
 * Eine Sammelstelle ist der Auffangbehaelter fuer Bewerbungen ohne erkennbare
 * Anzeige (heute: „Sonstiges"). Ihre Anzeige ist ein PLATZHALTER, kein
 * Bewerbungsweg — niemand hat sich auf sie beworben. Genau deshalb darf sie
 * beim Umschluesseln verschwinden, waehrend eine echte Anzeige stehenbleibt:
 * die sagt, woher die Bewerbung kam, und das aendert sich nicht.
 *
 * Das Kennzeichen steht bewusst als Spalte da und nicht als ID im Quelltext.
 * Sonst ist der zweite Sammelbehaelter (z.B. fuer eine neue Region) wieder ein
 * Sonderfall, den jemand im Code nachtragen muss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->boolean('is_sammelstelle')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->dropColumn('is_sammelstelle');
        });
    }
};
