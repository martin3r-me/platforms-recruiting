<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiktionsbescheinigung Vorder- + Rueckseite am MA. Wird in P3 als
     * optionales Feld vom Bewerber hochgeladen (fuer non-EU); beim
     * MA-Anlegen wird der file_id ueber extra_field_values gemappt.
     * Im ZAS-MA-Export liefern wir die als UplFiktion + UplFiktion2.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'fiktionsbescheinigung_front_file_id')) {
                $table->unsignedBigInteger('fiktionsbescheinigung_front_file_id')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'fiktionsbescheinigung_back_file_id')) {
                $table->unsignedBigInteger('fiktionsbescheinigung_back_file_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            foreach (['fiktionsbescheinigung_front_file_id', 'fiktionsbescheinigung_back_file_id'] as $col) {
                if (Schema::hasColumn('rec_employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
