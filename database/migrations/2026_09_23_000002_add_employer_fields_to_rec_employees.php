<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Haupt-/Nebenarbeitgeber (Clara-Liste 28.08.2026: "Hauptarbeitgeber /
    // Nebenarbeitgeber fehlt"). Zwei Spalten, nicht eine: Rheingedeck kann
    // Hauptarbeitgeber sein UND der Mitarbeiter nebenher woanders arbeiten.
    //
    // is_main_employer ist dreiwertig (ja / nein / unbeantwortet) — deshalb
    // nullable ohne Default. Ein Default false hiesse "wir sind es nicht",
    // und das Portal wuerde bei jedem Bestands-MA sofort den anderen
    // Arbeitgeber einfordern.
    //
    // Beide Felder gehen vorerst NICHT nach ZAS (Rueckfrage an den Kunden
    // offen) und stehen deshalb auch nicht in RELEVANT_EMPLOYEE_FIELDS.
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'is_main_employer')) {
                $table->boolean('is_main_employer')->nullable();
            }
            if (!Schema::hasColumn('rec_employees', 'other_employer')) {
                $table->string('other_employer', 128)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn(['is_main_employer', 'other_employer']);
        });
    }
};
