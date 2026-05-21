<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kostenstelle pro Stelle. Wird ueber rec_employees.rec_position_id
     * an den MA-ZAS-Export weitergereicht. Integer-only (ZAS-Format).
     * Leere Kostenstelle (NULL) → leerer CSV-Wert, nicht exportiert.
     */
    public function up(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_positions', 'cost_center')) {
                $table->unsignedInteger('cost_center')->nullable()->comment('Kostenstelle fuer ZAS-Export');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            if (Schema::hasColumn('rec_positions', 'cost_center')) {
                $table->dropColumn('cost_center');
            }
        });
    }
};
