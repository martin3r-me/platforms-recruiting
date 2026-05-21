<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Iteration 4 — drei neue HR-only-Felder:
     *  - linen_package_items (JSON Multi-Lookup: Hemd/Schuerze/Waesche allg.)
     *  - star_rating (smallint 1-5)
     *  - qualifications (JSON Multi-Lookup: Servicekraft, Cateringhilfe, etc.)
     *
     * MA-Portal sieht NIE — nur HR-Backend.
     */
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employee_hr_data', 'linen_package_items')) {
                $table->json('linen_package_items')->nullable();
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'star_rating')) {
                $table->unsignedTinyInteger('star_rating')->nullable()->comment('1-5 Sterne, HR-only');
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'qualifications')) {
                $table->json('qualifications')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            foreach (['linen_package_items', 'star_rating', 'qualifications'] as $col) {
                if (Schema::hasColumn('rec_employee_hr_data', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
