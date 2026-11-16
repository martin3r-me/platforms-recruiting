<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zeiterfassung (Stempeln), Spec 2026-11-16 §2.1: ein Datensatz je Person und
 * Bezug. Person und Bezug sind nur Typ-Kuerzel + ID — kein FK (Muster
 * rec_hr_vermerke), damit der Kern spaeter umziehen kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rec_zeit_eintraege')) {
            return;
        }

        Schema::create('rec_zeit_eintraege', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('person_typ', 40);
            $table->unsignedBigInteger('person_id');
            $table->string('bezug_typ', 40);
            $table->unsignedBigInteger('bezug_id');
            $table->date('tag');
            $table->dateTime('soll_von_at')->nullable();
            $table->dateTime('soll_bis_at')->nullable();
            $table->dateTime('kommen_at')->nullable();
            $table->dateTime('gehen_at')->nullable();
            $table->unsignedInteger('pause_minuten')->nullable();
            $table->text('bemerkung')->nullable();
            $table->dateTime('gesperrt_at')->nullable();
            $table->string('gesperrt_grund')->nullable();
            $table->timestamps();

            $table->unique(['person_typ', 'person_id', 'bezug_typ', 'bezug_id'], 'rec_zeit_eintraege_person_bezug_uq');
            $table->index(['bezug_typ', 'bezug_id'], 'rec_zeit_eintraege_bezug_idx');
            $table->index(['person_typ', 'person_id', 'tag'], 'rec_zeit_eintraege_person_tag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_zeit_eintraege');
    }
};
