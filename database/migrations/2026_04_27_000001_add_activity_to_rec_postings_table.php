<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_postings', function (Blueprint $table) {
            $table->string('activity', 60)->nullable()->after('description');
            $table->index(['activity', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_postings', function (Blueprint $table) {
            $table->dropIndex(['activity', 'team_id']);
            $table->dropColumn('activity');
        });
    }
};
