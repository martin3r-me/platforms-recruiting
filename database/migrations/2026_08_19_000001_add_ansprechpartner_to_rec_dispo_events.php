<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vor-Ort-Ansprechpartner pro VA, im Sende-Modal eingegeben (nicht aus
     * ZAS — Import fasst es nie an). Erscheint auf der Einsatz-Seite als
     * "Dein Ansprechpartner ist …".
     */
    public function up(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->string('ansprechpartner')->nullable()->after('vorlauf_minuten');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_events', function (Blueprint $table) {
            $table->dropColumn('ansprechpartner');
        });
    }
};
