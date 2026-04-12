<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->foreignId('rec_phase_id')->nullable()->after('rec_applicant_status_id')
                ->constrained('rec_phases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rec_phase_id');
        });
    }
};
