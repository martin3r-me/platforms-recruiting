<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 2026-10-29: die ZAS-Postennummer wandert von der Bedarfsliste an die
 * Job-Schicht. Nullable, weil „andere Schichten“ (von Hand ergaenzt) und
 * reine Personen-Schichten keinen Posten haben. Grundlage fuer den spaeteren
 * Buchungs-Export an ZAS (Spalte „Dispo“).
 *
 * Der Eindeutigkeits-Index nimmt die Posten-Nummer mit auf: zwei Posten mit
 * gleichem Beginn und gleicher Taetigkeit am selben Tag (z. B. 14:00–23:30 und
 * 14:00–18:00) sind zwei Schichten (Review I-1). Zeilen ohne Posten (NULL)
 * bleiben wie bisher eindeutig ueber Beginn + Taetigkeit; mehrere NULL im
 * Unique-Index sind in MySQL und SQLite erlaubt, kollidieren also nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->string('dispoposten_id', 32)->nullable()->after('taetigkeit')->index('idx_rec_dispo_job_shifts_posten');
        });
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->dropUnique('rec_dispo_job_shifts_eindeutig');
            $t->unique(['request_id', 'rec_dispo_event_id', 'datum', 'von', 'taetigkeit', 'dispoposten_id'], 'rec_dispo_job_shifts_eindeutig');
        });
    }

    public function down(): void
    {
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->dropUnique('rec_dispo_job_shifts_eindeutig');
            $t->unique(['request_id', 'rec_dispo_event_id', 'datum', 'von', 'taetigkeit'], 'rec_dispo_job_shifts_eindeutig');
        });
        Schema::table('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->dropIndex('idx_rec_dispo_job_shifts_posten');
            $t->dropColumn('dispoposten_id');
        });
    }
};
