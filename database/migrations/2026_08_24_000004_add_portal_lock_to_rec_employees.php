<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Sperrung des gesamten MA-Portals nach 16-Uhr-Rausnahme; HR entsperrt.
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->timestamp('portal_locked_at')->nullable();
            $table->string('portal_locked_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropColumn(['portal_locked_at', 'portal_locked_reason']);
        });
    }
};
