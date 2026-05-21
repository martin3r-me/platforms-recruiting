<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schul- und Immatrikulationsbescheinigung waren bisher EIN Feld
     * (immatrikulation_file_id). HR braucht beide separat — Auszubildende
     * und Studierende sollen unterschiedlich erkennbar sein. ZAS-Export
     * setzt BeschErforderlich=Ja sobald eines von beiden gesetzt ist.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('schulbescheinigung_file_id')
                ->nullable()
                ->after('immatrikulation_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('schulbescheinigung_file_id');
        });
    }
};
