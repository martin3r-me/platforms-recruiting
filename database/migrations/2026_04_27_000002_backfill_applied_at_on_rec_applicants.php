<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill rec_applicants.applied_at from earliest rec_applicant_posting.applied_at
     * for all applicants where it is currently NULL.
     *
     * Background: previously the manual create-modal allowed null applied_at, and some
     * historic data was created without setting the field. The pivot table reliably has
     * the date though, so we copy over the earliest pivot value as the canonical
     * "Erstbewerbungsdatum".
     */
    public function up(): void
    {
        $missing = DB::table('rec_applicants')
            ->whereNull('applied_at')
            ->pluck('id');

        foreach ($missing as $applicantId) {
            $earliest = DB::table('rec_applicant_posting')
                ->where('rec_applicant_id', $applicantId)
                ->whereNotNull('applied_at')
                ->min('applied_at');

            if ($earliest) {
                DB::table('rec_applicants')
                    ->where('id', $applicantId)
                    ->update(['applied_at' => $earliest]);
            }
        }
    }

    public function down(): void
    {
        // Backfill is non-reversible — we don't know which rows had null before.
    }
};
