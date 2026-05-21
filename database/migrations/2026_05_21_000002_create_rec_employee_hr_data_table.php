<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HR-only-Daten-Tabelle (1:1 zu rec_employees).
     *
     * Trennung von rec_employees damit:
     *  - MA-Portal physisch keinen Zugriff auf HR-only-Felder hat
     *  - $employee->toArray() / toJson() leakt keine HR-Daten an Frontend
     *  - rec_employees bleibt schlank, kann unabhaengig wachsen
     *
     * Felder werden iterativ vom User ergaenzt — diese Migration legt
     * nur die Skeleton-Tabelle an mit FK + UUID + Timestamps.
     */
    public function up(): void
    {
        Schema::create('rec_employee_hr_data', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('rec_employee_id')->unique()->constrained('rec_employees')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            // HR-only Felder kommen iterativ als ALTER-TABLE-Folgemigrationen
            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_employee_hr_data');
    }
};
