<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Markiert Mitarbeiter als Test-Datensatz (Kommando recruiting:test-ma).
     * Test-MA duerfen nie zu ZAS und nicht ins Telefonbuch; die Filter
     * dafuer haengen an dieser Spalte.
     *
     * Default false. Analog zu rec_applicants.is_test.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->boolean('is_test')
                ->default(false)
                ->after('is_active');
            $table->index('is_test', 'idx_rec_employees_is_test');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropIndex('idx_rec_employees_is_test');
            $table->dropColumn('is_test');
        });
    }
};
