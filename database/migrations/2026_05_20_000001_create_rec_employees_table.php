<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mitarbeiter-Entitaet (RecEmployee) — entsteht bei Phase-4-Abschluss
     * im Recruiting-Funnel (Trigger via Phase-Config-Flag
     * `creates_employee_on_completion`).
     *
     * Strikt opt-in: Production-Stationen ohne den Flag bleiben unberuehrt.
     * Felder werden bei Anlage aus extra_field_values + crm_contact +
     * rec_applicant_legal_statuses gemappt. Optionale/leere Felder werden
     * im MA-Portal /mitarbeiter/{token} nachgepflegt.
     *
     * Schema ist MVP — weitere Felder (ZAS-Export-Relevante wie Konfession,
     * Familienstand, Steuerklasse etc.) kommen als spaetere ALTER-TABLE-
     * Migrationen sobald die finale Field-Liste vom User vorliegt.
     */
    public function up(): void
    {
        Schema::create('rec_employees', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('rec_applicant_id')->nullable()->constrained('rec_applicants')->nullOnDelete();
            $table->foreignId('rec_position_id')->nullable()->constrained('rec_positions')->nullOnDelete();

            // ZAS-Integration
            $table->string('zas_id', 64)->nullable();

            // Stammdaten (bei Anlage gemappt aus extra_fields/crm)
            $table->string('first_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('identity_card_number', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 64)->nullable();

            // Adresse
            $table->string('street', 255)->nullable();
            $table->string('zip', 16)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country_code', 8)->nullable();

            // Stelle/Taetigkeit
            $table->string('beschaftigungsort', 64)->nullable();
            $table->json('art_der_tatigkeit')->nullable();

            // Bankdaten (zumeist im Portal nachgetragen)
            $table->string('iban', 64)->nullable();
            $table->string('bic', 32)->nullable();
            $table->string('steuer_id', 32)->nullable();
            $table->string('sozialversicherungsnummer', 32)->nullable();

            // Legal-Status (gemappt aus rec_applicant_legal_statuses)
            $table->boolean('is_eu_citizen')->nullable();
            $table->unsignedBigInteger('nationalpass_file_id')->nullable();
            $table->unsignedBigInteger('aufenthaltstitel_front_file_id')->nullable();
            $table->unsignedBigInteger('aufenthaltstitel_back_file_id')->nullable();
            $table->unsignedBigInteger('visumsblatt_file_id')->nullable();
            $table->unsignedBigInteger('zusatzblatt_file_id')->nullable();
            $table->unsignedBigInteger('immatrikulation_file_id')->nullable();

            // Portal-Auth
            $table->string('portal_token', 64)->unique()->nullable();
            $table->dateTime('portal_verified_at')->nullable();

            // Lifecycle
            $table->boolean('is_active')->default(true);
            $table->date('employed_since')->nullable();
            $table->dateTime('employment_ended_at')->nullable();

            // Audit
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
            $table->index('rec_applicant_id');
            $table->index('zas_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_employees');
    }
};
