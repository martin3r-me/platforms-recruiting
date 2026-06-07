<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->timestamp('payroll_data_changed_at')->nullable()->index();
            $table->json('payroll_data_changed_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rec_employees', function (Blueprint $table) {
            $table->dropIndex(['payroll_data_changed_at']);
            $table->dropColumn(['payroll_data_changed_at', 'payroll_data_changed_fields']);
        });
    }
};
