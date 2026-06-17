<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Konsolidiert die ZAS-Personalnummer auf EINE Spalte: personnel_number.
     *
     * Bisher: ZAS-Import schrieb in zas_id, gelesen wurde zas_id ausschliesslich
     * vom ZAS-Export. personnel_number (HR-Backend + Lohn-Export) ist die sichtbare,
     * breiter genutzte Spalte → bleibt. zas_id (Single-Purpose) entfaellt.
     *
     * Vorhandene zas_id-Werte werden nach personnel_number uebernommen (backfillt
     * u.a. bereits importierte MA), danach wird zas_id entfernt.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('rec_employees', 'zas_id')) {
            return;
        }

        // Backfill: zas_id -> personnel_number, wo personnel_number noch leer ist.
        DB::table('rec_employees')
            ->whereNotNull('zas_id')
            ->where('zas_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('personnel_number')->orWhere('personnel_number', '');
            })
            ->update(['personnel_number' => DB::raw('zas_id')]);

        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropIndex('rec_employees_zas_id_index');
            $table->dropColumn('zas_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('rec_employees', 'zas_id')) {
            return;
        }

        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('zas_id', 64)->nullable()->after('rec_position_id');
            $table->index('zas_id');
        });
    }
};
