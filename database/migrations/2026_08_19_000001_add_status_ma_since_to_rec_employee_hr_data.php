<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "MA-Status seit" (Kundenwunsch 2026-08-18): Tag, an dem ein Mitarbeiter
     * in ZAS von Status GO auf MA umgestellt wurde. Leer = steht nicht auf MA.
     *
     * Quelle ist ausschliesslich ZAS (Spalte StatusMASeit im Inbound) — bei uns
     * readonly, damit HR-Maske und ZAS nicht auseinanderlaufen. Geht bewusst
     * NICHT in den ZAS-Export zurueck: ZAS besitzt das Feld, ein Echo waere
     * sinnlos und wuerde nur Re-Exporte ausloesen.
     */
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employee_hr_data', 'status_ma_since')) {
                $table->date('status_ma_since')->nullable()
                    ->comment('Tag der ZAS-Umstellung GO→MA (ZAS: StatusMASeit); leer = nicht MA');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employee_hr_data', 'status_ma_since')) {
                $table->dropColumn('status_ma_since');
            }
        });
    }
};
