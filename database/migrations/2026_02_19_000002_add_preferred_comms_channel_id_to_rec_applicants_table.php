<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->foreignId('preferred_comms_channel_id')->nullable()->after('auto_pilot_state_id')
                ->constrained('comms_channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_comms_channel_id');
        });
    }
};
