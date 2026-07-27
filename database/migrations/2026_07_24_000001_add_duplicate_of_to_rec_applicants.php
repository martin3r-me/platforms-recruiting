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
            // Gesetzt vom Auto-Pilot-Dedup-Guard. Bloßes Leeren wird beim nächsten
            // Send-Versuch re-geflaggt, solange das Original aktiv ist — auflösen per
            // Deaktivieren einer Seite oder Auto-Pilot-Abschalten.
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
