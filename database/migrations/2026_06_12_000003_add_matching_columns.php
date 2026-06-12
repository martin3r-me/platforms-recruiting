<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicant_posting', function (Blueprint $table) {
            $table->string('matched_via', 30)->nullable()->after('notes');
            $table->string('match_confidence', 10)->nullable()->after('matched_via');
        });

        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->foreignId('suggested_posting_id')->nullable()->constrained('rec_postings')->nullOnDelete();
            $table->text('match_reason')->nullable();
        });

        Schema::table('rec_source_platforms', function (Blueprint $table) {
            $table->string('ref_parser', 40)->nullable()->after('match_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicant_posting', function (Blueprint $table) {
            $table->dropColumn(['matched_via', 'match_confidence']);
        });
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggested_posting_id');
            $table->dropColumn('match_reason');
        });
        Schema::table('rec_source_platforms', function (Blueprint $table) {
            $table->dropColumn('ref_parser');
        });
    }
};
