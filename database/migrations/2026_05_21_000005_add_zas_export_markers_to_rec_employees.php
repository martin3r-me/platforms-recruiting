<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ZAS-Export-Marker fuer Mitarbeiter.
     *
     * `zas_initial_exported_at`:
     *   NULL = MA noch nicht zu ZAS ausgeliefert → erscheint im
     *   Initial-Endpoint (/recruiting/zas/employees/initial.csv).
     *   Timestamp = wurde geliefert → verschwindet aus Initial,
     *   landet ab jetzt im Update-Endpoint sobald sich was aendert.
     *
     * `zas_changed_at`:
     *   Wird vom RecEmployeeExportObserver gesetzt bei Aenderungen an
     *   MA-Stammdaten oder HR-Daten. Update-Endpoint filtert auf
     *   `zas_changed_at IS NOT NULL AND zas_initial_exported_at IS NOT NULL`
     *   und nullt das Feld nach erfolgreicher Auslieferung.
     *
     * Indices: beide Felder werden in Filter-Queries genutzt — bei
     * groesseren MA-Mengen muss das schnell sein.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'zas_initial_exported_at')) {
                $table->timestamp('zas_initial_exported_at')->nullable();
                $table->index('zas_initial_exported_at', 'idx_rec_employees_zas_initial');
            }
            if (!Schema::hasColumn('rec_employees', 'zas_changed_at')) {
                $table->timestamp('zas_changed_at')->nullable();
                $table->index('zas_changed_at', 'idx_rec_employees_zas_changed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'zas_changed_at')) {
                $table->dropIndex('idx_rec_employees_zas_changed');
                $table->dropColumn('zas_changed_at');
            }
            if (Schema::hasColumn('rec_employees', 'zas_initial_exported_at')) {
                $table->dropIndex('idx_rec_employees_zas_initial');
                $table->dropColumn('zas_initial_exported_at');
            }
        });
    }
};
