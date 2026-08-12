<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausgestellte Schulungszertifikate. Bewusst KEINE rec_contracts-Zeile:
 * die wuerde hasAnyContractSent() wahr machen (Versand-Guards des
 * Nicht-EU-Umbaus) und in Portal-, Employees-Show- und ZAS-Vertragslisten
 * auftauchen.
 *
 * personalized_content ist ein Snapshot — die Huelle (Layout, Assets) steckt
 * NICHT darin, sondern wird beim Rendern aufgeloest. Muster wie beim
 * Firmenstempel in Vertraegen.
 *
 * Unique auf (rec_applicant_id, rec_contract_template_id): ein Zertifikat pro
 * Person pro Vorlage. Eine zweite Schulungsart bleibt damit moeglich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rec_training_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('rec_applicant_id')->constrained('rec_applicants')->cascadeOnDelete();
            $table->foreignId('rec_contract_template_id')->constrained('rec_contract_templates')->cascadeOnDelete();
            $table->longText('personalized_content')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('wa_sent_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['rec_applicant_id', 'rec_contract_template_id'],
                'rec_training_cert_applicant_tpl_unique'
            );
            $table->index(['team_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_training_certificates');
    }
};
