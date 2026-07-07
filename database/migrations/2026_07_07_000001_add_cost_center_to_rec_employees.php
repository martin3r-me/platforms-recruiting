<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kostenstelle direkt am Mitarbeiter (Quelle: ZAS-Import oder HR-Pflege).
     *
     * Export-Vorrang: MA-Feld gewinnt, sonst Fallback position->cost_center
     * (siehe ZasEmployeeFieldResolver). Bewusst string statt integer (wie an
     * der Stelle): externe ZAS-Werte duerfen nicht an Typ-Zwang scheitern,
     * fuehrende Nullen bleiben erhalten.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'cost_center')) {
                $table->string('cost_center', 32)->nullable()->after('personnel_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'cost_center')) {
                $table->dropColumn('cost_center');
            }
        });
    }
};
