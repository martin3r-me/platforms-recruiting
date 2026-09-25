<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 70-Tage-Kreislauf (Markus 24.09.2026, Punkt 3+4).
    //
    // Bewusst in rec_employee_hr_data und nicht in rec_employees: Das
    // Mitarbeiter-Portal sieht diese Tabelle nie. Die Zahlen sind
    // Dispositions- und Abrechnungsdaten, keine Angaben, die der Mitarbeiter
    // pflegt.
    //
    // ROLLENVERTEILUNG (Entscheidung 25.09.2026): Von den drei Werten
    // besitzen wir genau EINEN — short_term_days_allowed, den Startwert, und
    // den nur einmal. Die beiden anderen fuehrt ZAS ("da wird eingebucht")
    // und liefert sie uns; sie sind bei uns ueberall schreibgeschuetzt, auch
    // fuer HR, und gehen nie zurueck an ZAS.
    //
    // Alle drei nullable ohne Default: null heisst "keine Grundlage" und ist
    // etwas anderes als 0. Ein Vertrag ohne §15-Erklaerung darf nicht als
    // "nichts gearbeitet, also volle Grenze" durchgehen — ZAS zaehlt davon
    // herunter.
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employee_hr_data', 'short_term_days_allowed')) {
                $table->unsignedSmallInteger('short_term_days_allowed')->nullable();
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'short_term_days_worked')) {
                $table->unsignedSmallInteger('short_term_days_worked')->nullable();
            }
            if (!Schema::hasColumn('rec_employee_hr_data', 'short_term_days_remaining')) {
                $table->unsignedSmallInteger('short_term_days_remaining')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            $table->dropColumn([
                'short_term_days_allowed',
                'short_term_days_worked',
                'short_term_days_remaining',
            ]);
        });
    }
};
