<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->unsignedInteger('auto_pilot_reminder_count')->default(0)->after('auto_pilot_state_id');
            $table->timestamp('auto_pilot_last_reminder_at')->nullable()->after('auto_pilot_reminder_count');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn(['auto_pilot_reminder_count', 'auto_pilot_last_reminder_at']);
        });
    }
};
