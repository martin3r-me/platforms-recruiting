<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Das Kontingent der kurzfristigen Beschaeftigung gilt JE KALENDERJAHR.
    // Ohne diese Spalte waere der gespeicherte Startwert eine Zahl ohne
    // Bezug: steht im Maerz 2027 eine 50, war das der Startwert fuer 2026.
    //
    // Sie steuert ausserdem den Waechter. "Nur wenn leer" ist innerhalb
    // eines Jahres richtig und ueber den Jahreswechsel falsch: unterschreibt
    // jemand im neuen Jahr einen neuen Arbeitsvertrag und erklaert dabei
    // bereits geleistete Tage, muss neu gerechnet werden.
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employee_hr_data', 'short_term_days_allowed_year')) {
                $table->unsignedSmallInteger('short_term_days_allowed_year')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->dropColumn('short_term_days_allowed_year');
        });
    }
};
