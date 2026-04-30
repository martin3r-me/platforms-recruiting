<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks applicants that came in via the legacy CSV-import (or any future
     * bulk import). Stays NULL for the normal inbound-mail / form pipeline.
     *
     * Use case: filter `WHERE import_source IS NULL` in later exports so
     * imported "starting bench" employees don't show up alongside fresh
     * applicants. Also used in InterviewBookings\Index::availableApplicants
     * to allow imports to be booked into any Schulung regardless of the
     * Termin's rec_position_id (imports have no position binding).
     */
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->string('import_source', 40)
                ->nullable()
                ->after('source_platform_id');
            $table->index('import_source');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex(['import_source']);
            $table->dropColumn('import_source');
        });
    }
};
