<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            // nullOnDelete, NICHT cascadeOnDelete: eine geloeschte Stelle darf keine
            // Bewerbung mitnehmen. Model-Events feuern bei DB-Kaskaden ausserdem
            // nicht (daher gibt es im Modul den Kaskaden-Observer fuer Phasen).
            $table->foreignId('rec_position_id')->nullable()->after('rec_phase_id')
                ->constrained('rec_positions')->nullOnDelete();
            $table->index(['team_id', 'rec_position_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicants', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'rec_position_id']);
            $table->dropConstrainedForeignId('rec_position_id');
        });
    }
};
