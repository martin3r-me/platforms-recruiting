<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            $table->boolean('show_in_dashboard')->default(true)->after('is_active');
            $table->index(['team_id', 'show_in_dashboard']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_phases', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'show_in_dashboard']);
            $table->dropColumn('show_in_dashboard');
        });
    }
};
