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
    /**
     * MySQL-Identifier-Limit: max. 64 Zeichen. Auto-generierter Name
     * 'rec_applicant_legal_statuses_additional_contract_template_id_foreign'
     * waere 67 Zeichen → expliziter kuerzerer Constraint-Name noetig.
     */
    private const FK_NAME = 'rec_legalstatus_addl_tpl_fk';

    /**
     * Idempotent: Die erste Variante dieser Migration ist auf einigen
     * Umgebungen halb durchgelaufen (MySQL ist nicht transaktional bei
     * DDL — wenn der lange FK-Name Fehler 1059 wirft, ist die vorherige
     * ADD COLUMN bereits committed). Beim Retry darf der ADD-COLUMN nicht
     * nochmal versuchen sonst Fehler 1060 Duplicate column. Daher pro
     * Spalte ein Schema::hasColumn-Check.
     */
    public function up(): void
    {
        Schema::table('rec_applicant_legal_statuses', function (Blueprint $table) {
            if (!Schema::hasColumn('rec_applicant_legal_statuses', 'legal_status_checked_at')) {
                $table->timestamp('legal_status_checked_at')->nullable()
                    ->after('immatrikulation_file_id');
            }

            if (!Schema::hasColumn('rec_applicant_legal_statuses', 'additional_contract_template_id')) {
                $table->unsignedBigInteger('additional_contract_template_id')->nullable()
                    ->after('legal_status_checked_at');

                $table->foreign('additional_contract_template_id', self::FK_NAME)
                    ->references('id')->on('rec_contract_templates')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rec_applicant_legal_statuses', function (Blueprint $table) {
            if (Schema::hasColumn('rec_applicant_legal_statuses', 'additional_contract_template_id')) {
                try {
                    $table->dropForeign(self::FK_NAME);
                } catch (\Throwable) {
                    // FK existiert moeglicherweise nicht — Rollback soll trotzdem die Column dropen
                }
                $table->dropColumn('additional_contract_template_id');
            }
            if (Schema::hasColumn('rec_applicant_legal_statuses', 'legal_status_checked_at')) {
                $table->dropColumn('legal_status_checked_at');
            }
        });
    }
};
