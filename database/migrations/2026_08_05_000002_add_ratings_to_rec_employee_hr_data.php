<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gegenstuecke der Bewerber-Bewertungsfelder auf der HR-Schicht (Spec §1/N1).
 * linen_package_items und qualifications existieren hier bereits (Migration
 * 2026_05_21_000004) — neu sind nur die fuenf Kriterien und der Freitext.
 *
 * Das alte star_rating bleibt unangetastet: Altdaten bleiben lesbar, die
 * ZAS-Spalte Sternebewertung laeuft weiter, es wird nur nicht mehr neu
 * geschrieben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
            ] as $column) {
                if (!Schema::hasColumn('rec_employee_hr_data', $column)) {
                    $table->unsignedTinyInteger($column)->nullable()->comment('1-5 Sterne, HR-only');
                }
            }

            if (!Schema::hasColumn('rec_employee_hr_data', 'evaluation_note')) {
                $table->text('evaluation_note')->nullable()->comment('Bewertungstext, HR-only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_employee_hr_data', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
                'evaluation_note',
            ] as $column) {
                if (Schema::hasColumn('rec_employee_hr_data', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
