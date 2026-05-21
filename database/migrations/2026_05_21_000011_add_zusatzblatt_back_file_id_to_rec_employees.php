<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zusatzblatt Arbeitsgenehmigung wird im P3-Bewerber-Form mit
     * Vorder- UND Rueckseite erfasst. Bisher hatte rec_employees nur
     * EIN zusatzblatt_file_id (= Vorderseite), die Rueckseite ging
     * verloren. ZAS-Export braucht die Rueckseite nicht — ist rein
     * HR-Backend-sichtbar.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('zusatzblatt_back_file_id')
                ->nullable()
                ->after('zusatzblatt_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('zusatzblatt_back_file_id');
        });
    }
};
