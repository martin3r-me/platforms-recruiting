<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('uuid');
        });

        // Bestehende Bewerber mit Tokens versehen
        $applicants = DB::table('rec_applicants')->whereNull('public_token')->get();
        foreach ($applicants as $applicant) {
            do {
                $token = bin2hex(random_bytes(16));
            } while (DB::table('rec_applicants')->where('public_token', $token)->exists());

            DB::table('rec_applicants')
                ->where('id', $applicant->id)
                ->update(['public_token' => $token]);
        }
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
};
