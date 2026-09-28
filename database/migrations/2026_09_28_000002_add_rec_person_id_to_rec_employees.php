<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Klammer: welche Person steckt hinter dieser Anstellung?
 *
 * Bewusst KEIN Fremdschluessel-Constraint auf Datenbankebene — im Modul ist
 * das durchgaengig so (rec_employee_id in rec_employee_proofs ebenso). Die
 * Zuordnung haelt PersonLinker zusammen, und der ist der einzige Schreiber.
 *
 * NICHT in RELEVANT_EMPLOYEE_FIELDS: Die Spalte geht ZAS nichts an und
 * duerfte keinen Update-Marker setzen. Geschrieben wird ohnehin nur ueber
 * den Query Builder, damit der Backfill nicht den halben Bestand in die
 * updates.csv spuelt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'rec_person_id')) {
                $table->unsignedBigInteger('rec_person_id')->nullable()->after('person_key')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (Schema::hasColumn('rec_employees', 'rec_person_id')) {
                $table->dropColumn('rec_person_id');
            }
        });
    }
};
