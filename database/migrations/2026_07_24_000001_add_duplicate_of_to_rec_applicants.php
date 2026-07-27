<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            // Mögliche Dublette: zeigt auf den Bewerber, der den Chat "besitzt".
            // Gesetzt vom Auto-Pilot-Dedup-Guard; manuell leeren = Freigabe.
            $table->foreignId('duplicate_of_applicant_id')
                ->nullable()
                ->constrained('rec_applicants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_applicant_id');
        });
    }
};
