<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->boolean('is_on_hr_desk')->default(false)->after('is_parked');
            $table->timestamp('rejected_at')->nullable()->after('is_on_hr_desk');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn(['is_on_hr_desk', 'rejected_at']);
        });
    }
};
