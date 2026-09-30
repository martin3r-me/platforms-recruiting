<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ArbeitstageRest kommt aus ZAS als Differenz „Tage erlaubt − Tage gearbeitet"
 * und kann NEGATIV werden, sobald jemand mehr gearbeitet hat als erlaubt —
 * und genau dieser Fall ist der teure (die kurzfristige Beschaeftigung verliert
 * rueckwirkend ihren Status).
 *
 * Die Spalte war unsigned. Ein negativer Wert waere im Import in einen
 * Datenbankfehler gelaufen und haette die GANZE Zeile gekippt (Muster des
 * 22001-Vorfalls vom 25.08.2026: ein Nebenfeld riss den kompletten Menschen
 * aus dem Import). Deshalb vorzeichenbehaftet, bevor die erste Lieferung kommt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rec_employee_hr_data', 'short_term_days_remaining')) {
            return;
        }

        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->smallInteger('short_term_days_remaining')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('rec_employee_hr_data', 'short_term_days_remaining')) {
            return;
        }

        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->unsignedSmallInteger('short_term_days_remaining')->nullable()->change();
        });
    }
};
