<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Block B des finalen Phasen-Logik-Designs — Datenmodell-Erweiterung
 * fuer den HR-Schreibtisch-Workflow bei nicht-EU-Buergern.
 *
 * Neue Spalten auf rec_applicant_legal_statuses:
 *
 *  - legal_status_checked_at (datetime nullable)
 *    NULL = ungepruefte (rot im Schulungs-Index, Vertragsvorlage disabled)
 *    Timestamp = gepruefte (gruen im Schulungs-Index, Verträge versendbar)
 *    Bewusst ohne Audit-User-Tracking (Simpel-Variante laut Spec).
 *
 *  - additional_contract_template_id (FK rec_contract_templates.id, nullable)
 *    Optionaler Zusatzvertrag den HR beim Pruefen waehlt (typisch
 *    AT-* Aufenthaltstitel-bezogen). SendContractsService laed ihn beim
 *    Versand zusaetzlich zu AV + IFSG. ON DELETE SET NULL — wenn das
 *    Template geloescht wird, bleibt der LegalStatus intact, nur ohne
 *    Zusatzvertrag-Referenz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rec_applicant_legal_statuses', function (Blueprint $table) {
            $table->timestamp('legal_status_checked_at')->nullable()
                ->after('immatrikulation_file_id');

            $table->foreignId('additional_contract_template_id')->nullable()
                ->after('legal_status_checked_at')
                ->constrained('rec_contract_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicant_legal_statuses', function (Blueprint $table) {
            $table->dropForeign(['additional_contract_template_id']);
            $table->dropColumn([
                'additional_contract_template_id',
                'legal_status_checked_at',
            ]);
        });
    }
};
