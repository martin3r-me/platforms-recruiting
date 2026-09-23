<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Staatsangehoerigkeit als eigene Spalte. Bis hierher gab es sie an
    // rec_employees nicht: der Bewerber liefert `nationalitaet`, der Wert ging
    // bei der MA-Anlage verloren, und der ZAS-Export schickte stattdessen
    // birth_country als `Nation` — eine falsche Staatsangehoerigkeit im
    // Lohnsystem. Gleicher Lookup wie birth_country (geburtsland, ISO-Codes).
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'nationality')) {
                $table->string('nationality', 64)->nullable()->after('birth_country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('nationality');
        });
    }
};
