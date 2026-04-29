<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->foreignId('source_platform_id')
                ->nullable()
                ->after('public_token')
                ->constrained('rec_source_platforms')
                ->nullOnDelete();
            $table->index(['team_id', 'source_platform_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropForeign(['source_platform_id']);
            $table->dropIndex(['team_id', 'source_platform_id']);
            $table->dropColumn('source_platform_id');
        });
    }
};
