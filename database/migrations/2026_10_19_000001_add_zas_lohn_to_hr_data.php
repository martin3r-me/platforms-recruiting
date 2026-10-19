<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Grundlohn + Zuschlag laut ZAS (Abgleich 19.10.2026: beide Spalten kamen
    // in jeder Lieferung gefuellt und wurden verworfen).
    //
    // Reine Lesewerte wie das Tagekonto: gehoeren ZAS, gehen nie zurueck,
    // sind bei uns schreibgeschuetzt. Unser eigener Grundlohn bleibt unserer —
    // diese Spalten sind Anzeige und Plausibilitaet. null = noch nichts
    // geliefert; 0 = bei ZAS nicht gepflegt.
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employee_hr_data', 'zas_grundlohn')) {
                $table->decimal('zas_grundlohn', 6, 2)->nullable();
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'zas_zuschlag')) {
                $table->decimal('zas_zuschlag', 6, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->dropColumn(['zas_grundlohn', 'zas_zuschlag']);
        });
    }
};
