<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicant_legal_statuses', function (Blueprint $table) {
            $table->unsignedBigInteger('nationalpass_file_id')->nullable()->after('is_eu_citizen');
            $table->unsignedBigInteger('aufenthaltstitel_front_file_id')->nullable()->after('nationalpass_file_id');
            $table->unsignedBigInteger('aufenthaltstitel_back_file_id')->nullable()->after('aufenthaltstitel_front_file_id');
            $table->unsignedBigInteger('visumsblatt_file_id')->nullable()->after('aufenthaltstitel_back_file_id');
            $table->unsignedBigInteger('zusatzblatt_file_id')->nullable()->after('visumsblatt_file_id');
            $table->unsignedBigInteger('immatrikulation_file_id')->nullable()->after('zusatzblatt_file_id');
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicant_legal_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'nationalpass_file_id',
                'aufenthaltstitel_front_file_id',
                'aufenthaltstitel_back_file_id',
                'visumsblatt_file_id',
                'zusatzblatt_file_id',
                'immatrikulation_file_id',
            ]);
        });
    }
};
