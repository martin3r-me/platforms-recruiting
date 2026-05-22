<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personalnummer (PNr) wird in ZAS vergeben und manuell von HR ins
     * MA-Backend uebertragen. Default NULL → Hr. Michel kann am leeren
     * Feld 'Neuanlage' erkennen, am befuellten 'Update mit PNr'.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('personnel_number', 32)
                ->nullable()
                ->after('recruited_by_personnel_number');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('personnel_number');
        });
    }
};
