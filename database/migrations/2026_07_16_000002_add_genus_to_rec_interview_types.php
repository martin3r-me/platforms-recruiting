<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_interview_types', function (Blueprint $table) {
            // Genus des Namens fuer Bewerber-Saetze (der/die/das).
            // Nullable ohne Default: fehlt es, faellt das Wording im Code
            // komplett auf "Termin" zurueck (TerminWort::fromParts).
            $table->string('genus', 1)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('rec_interview_types', function (Blueprint $table) {
            $table->dropColumn('genus');
        });
    }
};
