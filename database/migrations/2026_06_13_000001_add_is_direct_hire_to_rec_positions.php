<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->boolean('is_direct_hire')->default(false)->after('is_active');
            $table->index(['team_id', 'is_direct_hire']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_positions', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'is_direct_hire']);
            $table->dropColumn('is_direct_hire');
        });
    }
};
