<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interviews', function (Blueprint $table) {
            $table->string('language', 5)->default('de')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interviews', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
