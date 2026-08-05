<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bewertung am Bewerber (Spec §1): fuenf Kriterien à 1-5 Sterne, Freitext,
 * Waeschepaket und Qualifikation. Erfasst wird ab Buchungsstatus 'attended';
 * bei der Mitarbeiter-Erst-Anlage wandern die Werte einmalig auf
 * rec_employee_hr_data.
 *
 * Alle Spalten nullable ohne Default — "leer" ist NULL, niemals [] (Spec F7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
            ] as $column) {
                if (!Schema::hasColumn('rec_applicants', $column)) {
                    $table->unsignedTinyInteger($column)->nullable()->comment('1-5 Sterne');
                }
            }

            if (!Schema::hasColumn('rec_applicants', 'evaluation_note')) {
                $table->text('evaluation_note')->nullable()->comment('Bewertungstext des Schulungsleiters');
            }
            if (!Schema::hasColumn('rec_applicants', 'linen_package_items')) {
                $table->json('linen_package_items')->nullable();
            }
            if (!Schema::hasColumn('rec_applicants', 'qualifications')) {
                $table->json('qualifications')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            foreach ([
                'rating_erscheinungsbild',
                'rating_fachkompetenz',
                'rating_auffassungsgabe',
                'rating_auftreten',
                'rating_teamintegration',
                'evaluation_note',
                'linen_package_items',
                'qualifications',
            ] as $column) {
                if (Schema::hasColumn('rec_applicants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
