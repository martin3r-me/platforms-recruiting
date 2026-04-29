<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add is_unrouted flag to mark applicants whose inbound mail did not
     * match any RecSourcePlatform pattern. These applicants are excluded
     * from the normal flow (Bewerber-Liste, Dashboard, AutoPilot,
     * Enrichment) and only visible in the dedicated Eingangs-Inbox.
     */
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->boolean('is_unrouted')->default(false)->after('is_active');
            $table->index(['team_id', 'is_unrouted', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'is_unrouted', 'created_at']);
            $table->dropColumn('is_unrouted');
        });
    }
};
