<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Umfang der Taetigkeit" wird im P1-Bewerber-Form als Lookup
     * erfasst (Bewerber-Wunsch wie er arbeiten will). War bisher tot —
     * weder gemappt noch ausgewertet. Soll jetzt in den MA uebernommen
     * werden. ZAS-Export bleibt davon unberuehrt (Anstellungsart aus
     * hrData ist die ZAS-Wahrheit).
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('umfang_der_tatigkeit', 64)
                ->nullable()
                ->after('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('umfang_der_tatigkeit');
        });
    }
};
