<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->string('enrichment_status', 32)->nullable()->default(null)->after('auto_pilot_state_id');
            $table->index(['enrichment_status', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex(['enrichment_status', 'team_id']);
            $table->dropColumn('enrichment_status');
        });
    }
};
