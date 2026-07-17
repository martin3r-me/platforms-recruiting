<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arbeitsschutz-Felder (Kundenwunsch ZAS, 2026-07-17):
     * Ersthelfer (Haken + Schein gueltig bis) und Sicherheitsbeauftragter
     * (nur Haken). Keine Pflichtfelder — alle nullable. Gehen in den
     * ZAS-Export und werden beim Inbound neuer MA uebernommen.
     */
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_employees', 'is_first_aider')) {
                $table->boolean('is_first_aider')->nullable()->comment('Ersthelfer (ZAS: Ersthelfer)');
            }
            if (!Schema::hasColumn('rec_employees', 'first_aider_valid_until')) {
                $table->date('first_aider_valid_until')->nullable()->comment('Ersthelfer-Schein gueltig bis (ZAS: ErsthelferBis)');
            }
            if (!Schema::hasColumn('rec_employees', 'is_safety_officer')) {
                $table->boolean('is_safety_officer')->nullable()->comment('Sicherheitsbeauftragter (ZAS: Sicherheitsbeauftragter)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            foreach (['is_first_aider', 'first_aider_valid_until', 'is_safety_officer'] as $col) {
                if (Schema::hasColumn('rec_employees', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
