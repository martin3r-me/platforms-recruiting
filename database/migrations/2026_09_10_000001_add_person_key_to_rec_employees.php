<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // person_key: Gruppen-Marker „diese Arbeitsverhaeltnisse sind EIN Mensch"
    // (ZAS bedient zwei Firmen — RG- und MA-Datensatz derselben Person).
    // Kein FK, keine eigene Entitaet: gesetzt nur von drei kontrollierten
    // Wegen (Import-Auto-Link bei exaktem Namen+Geburtsdatum, Audit-Kommando,
    // Hand-Link), korrigierbar durch Nullen. Uebergangsloesung bis zur echten
    // Personen-Entitaet (dann: eine Person je Key, verlustfrei).
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->string('person_key', 36)->nullable()->after('rec_applicant_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn('person_key');
        });
    }
};
