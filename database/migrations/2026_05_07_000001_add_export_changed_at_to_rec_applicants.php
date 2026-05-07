<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delta-Marker fuer den ZAS-Bewerber-Export.
     *
     * NULL = keine Aenderung seit letztem Export. Timestamp = Aenderungen liegen
     * vor (seit wann) und werden beim naechsten Pull ausgeliefert.
     *
     * Wird vom RecApplicantExportObserver gesetzt (saved-Events auf Applicant,
     * Extra-Field-Values, Contracts, InterviewBookings) und vom
     * ZasExportController nach erfolgreicher Auslieferung wieder genullt.
     *
     * Index: der Endpoint filtert WHERE export_changed_at IS NOT NULL — bei
     * grossen Bewerber-Mengen muss der Filter schnell sein.
     *
     * Siehe docs/meingedeck/zas-applicant-export.md
     */
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->timestamp('export_changed_at')
                ->nullable()
                ->after('updated_at');
            $table->index('export_changed_at', 'idx_rec_applicants_export_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex('idx_rec_applicants_export_changed_at');
            $table->dropColumn('export_changed_at');
        });
    }
};
