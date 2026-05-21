<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * country_code war VARCHAR(8) — gedacht fuer ISO-Codes (DE), aber das
     * UI-Label "Land" erwartet Freitext ("Deutschland"). 11+ Zeichen
     * crashen mit Data-too-long. VARCHAR(64) reicht fuer alle Laendernamen.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('country_code', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('country_code', 8)->nullable()->change();
        });
    }
};
