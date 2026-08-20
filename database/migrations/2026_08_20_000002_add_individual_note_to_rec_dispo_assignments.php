<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Individueller Hinweis pro Mitarbeiter, vom Disponenten auf VA-Ebene
     * eingegeben, erscheint auf der Bestaetigungsseite. Nicht aus ZAS.
     */
    public function up(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->text('individual_note')->nullable()->after('taetigkeit');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_assignments', function (Blueprint $table) {
            $table->dropColumn('individual_note');
        });
    }
};
