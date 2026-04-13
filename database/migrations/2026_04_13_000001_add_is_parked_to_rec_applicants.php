<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->boolean('is_parked')->default(false)->after('is_active');
            $table->timestamp('parked_at')->nullable()->after('is_parked');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn(['is_parked', 'parked_at']);
        });
    }
};
