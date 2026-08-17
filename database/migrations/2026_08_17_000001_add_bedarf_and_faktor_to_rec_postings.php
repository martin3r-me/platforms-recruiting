<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_postings', function (Blueprint $table) {
            // Personalziel dieser Ausschreibung. NULL = nicht gepflegt → keine
            // Erfuellungs-Ampel (Spec: nichts wird geraten).
            $table->unsignedInteger('bedarf')->nullable()->after('activity');
            // Bewerbungen pro Einstellung. Freie Zahl, KEIN Enum 1-5: der
            // gemessene Wert liegt bei 7-10 und damit ausserhalb.
            $table->decimal('bewerbungs_faktor', 4, 1)->nullable()->after('bedarf');
        });
    }

    public function down(): void
    {
        Schema::table('rec_postings', function (Blueprint $table) {
            $table->dropColumn(['bedarf', 'bewerbungs_faktor']);
        });
    }
};
