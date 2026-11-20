<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zusage austragen (Spec 2026-11-20): die Austragung ist eine Absage mit Grund
 * „ausgetragen“; hier nur, wann zugesagt war (fuer „rueckgaengig“) und wer
 * ausgetragen hat. Beide nullable: Bestand unveraendert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->timestamp('ausgetragen_zusage_at')->nullable()->after('declined_note');
            $t->unsignedBigInteger('ausgetragen_by_user_id')->nullable()->after('ausgetragen_zusage_at');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_offer_shifts', fn (Blueprint $t) => $t->dropColumn(['ausgetragen_zusage_at', 'ausgetragen_by_user_id']));
    }
};
