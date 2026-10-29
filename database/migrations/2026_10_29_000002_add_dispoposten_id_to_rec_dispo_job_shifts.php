<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-29: die ZAS-Postennummer wandert von der Bedarfsliste an die
 * Job-Schicht. Nullable, weil „andere Schichten“ (von Hand ergaenzt) und
 * reine Personen-Schichten keinen Posten haben. Grundlage fuer den spaeteren
 * Buchungs-Export an ZAS (Spalte „Dispo“).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->string('dispoposten_id', 32)->nullable()->after('taetigkeit')->index('idx_rec_dispo_job_shifts_posten');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->dropIndex('idx_rec_dispo_job_shifts_posten');
            $t->dropColumn('dispoposten_id');
        });
    }
};
