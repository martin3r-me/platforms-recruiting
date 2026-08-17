<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interviews', function (Blueprint $table) {
            // Fuer welche Ausschreibung diese Schulung stattfindet. Nullable,
            // damit Bestandstermine weiterlaufen — der Titel bleibt Rueckfall.
            // nullOnDelete: eine geloeschte Ausschreibung darf den Termin nicht
            // mitnehmen.
            $table->foreignId('rec_posting_id')->nullable()->after('rec_position_id')
                ->constrained('rec_postings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_interviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rec_posting_id');
        });
    }
};
