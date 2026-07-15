<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            // NULL = Ort-Warteliste (Bestand, Verhalten unverändert).
            // Gesetzt = Termin-Warteliste: Bewerber wartet auf einen Platz
            // in genau diesem (vollen) Termin.
            $table->foreignId('rec_interview_id')
                ->nullable()
                ->after('rec_applicant_id')
                ->constrained('rec_interviews')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_waitlist', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rec_interview_id');
        });
    }
};
