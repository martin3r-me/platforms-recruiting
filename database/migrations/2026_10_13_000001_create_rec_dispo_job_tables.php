<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispo „Jobs anbieten“ (Spec 2026-10-12): Anfrage -> Schichten, Wellen,
 * Angebote (eine Person je Welle), Angebot<->Schicht mit Zusage und ZAS-Haken.
 * Keine Fremdschluessel ueber Modulgrenzen; Mitarbeiter-IDs als blosse Zahlen
 * (Muster rec_dispo_assignments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_dispo_job_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('team_id')->index();
            $t->unsignedInteger('filial_nr');
            $t->date('zeitraum_von');
            $t->date('zeitraum_bis');
            $t->string('modus', 16)->default('sammeln');
            $t->unsignedBigInteger('whatsapp_template_id')->nullable();
            $t->string('status', 16)->default('entwurf')->index();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('rec_dispo_job_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedBigInteger('rec_dispo_event_id')->index();
            $t->date('datum');
            $t->string('von', 8)->default('');
            $t->string('taetigkeit', 190);
            $t->unsignedInteger('gesucht')->default(0);
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'rec_dispo_event_id', 'datum', 'von', 'taetigkeit'], 'rec_dispo_job_shifts_eindeutig');
        });

        Schema::create('rec_dispo_job_waves', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedInteger('nr');
            $t->json('ma_bloecke');
            $t->json('gruppen');
            $t->boolean('nur_passende_taetigkeit')->default(true);
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->uuid('job_uuid')->nullable();
            $t->json('summary')->nullable();
            $t->timestamps();
        });

        Schema::create('rec_dispo_job_offers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('wave_id')->index();
            $t->unsignedBigInteger('request_id')->index();
            $t->unsignedBigInteger('rec_employee_id')->index();
            $t->unsignedBigInteger('anchor_employee_id')->index();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('send_error', 500)->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'anchor_employee_id'], 'rec_dispo_job_offers_eine_je_person');
        });

        Schema::create('rec_dispo_job_offer_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('offer_id')->index();
            $t->unsignedBigInteger('shift_id')->index();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('zas_entered_at')->nullable();
            $t->unsignedBigInteger('zas_entered_by_user_id')->nullable();
            $t->unsignedBigInteger('zas_assignment_id')->nullable();
            $t->timestamps();
            $t->unique(['offer_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        foreach (['rec_dispo_job_offer_shifts', 'rec_dispo_job_offers', 'rec_dispo_job_waves', 'rec_dispo_job_shifts', 'rec_dispo_job_requests'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
