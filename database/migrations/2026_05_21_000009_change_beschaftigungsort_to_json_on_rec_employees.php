<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * beschaftigungsort kommt aus dem P3-Bewerber-Form als Multi-Lookup
     * (Bewerber waehlt mehrere Wunsch-Standorte). Wurde aber bisher als
     * VARCHAR(64) auf rec_employees geschrieben → 500er sobald ein Array
     * gemappt wird.
     *
     * Migration auf JSON (analog art_der_tatigkeit) damit Mehrfachauswahl
     * sauber persistiert wird.
     */
    public function up(): void
    {
        // Vorhandene Single-String-Werte erhalten: in JSON-Array umwandeln.
        DB::statement("
            UPDATE rec_employees
            SET beschaftigungsort = JSON_ARRAY(beschaftigungsort)
            WHERE beschaftigungsort IS NOT NULL
              AND beschaftigungsort <> ''
              AND beschaftigungsort NOT LIKE '[%'
        ");

        Schema::table('rec_employees', function (Blueprint $table) {
            $table->json('beschaftigungsort')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rueckwaertskonvertierung: erstes Array-Element zurueck in VARCHAR.
        DB::statement("
            UPDATE rec_employees
            SET beschaftigungsort = JSON_UNQUOTE(JSON_EXTRACT(beschaftigungsort, '$[0]'))
            WHERE beschaftigungsort IS NOT NULL
              AND JSON_VALID(beschaftigungsort)
        ");

        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('beschaftigungsort', 64)->nullable()->change();
        });
    }
};
